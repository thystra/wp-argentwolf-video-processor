<?php
/** File: includes/Local_Delivery_Evidence.php */
declare(strict_types=1);
namespace ArgentVideo;

use RuntimeException;

/**
 * Positively verified local HLS delivery evidence used before source pruning.
 *
 * This class never creates media. It validates only AWVP-managed HLS output
 * already recorded on the attachment and returns a compact identity that can be
 * re-proved immediately before an original source is removed.
 */
final class Local_Delivery_Evidence
{
    public const VERSION = 1;
    private const MAX_RENDITIONS = 8;
    private const MAX_PLAYLIST_BYTES = 2097152;
    private const MAX_REFERENCES_PER_RENDITION = 10000;

    /** @return array{version:int,attachment_id:int,hls_url:string,file_count:int,bytes:int,tree_sha256:string}|array{} */
    public static function capture(int $attachment_id): array
    {
        if ($attachment_id < 1) {
            return array();
        }
        $outputs = get_post_meta($attachment_id, '_argent_video_outputs', true);
        $hls = is_array($outputs) && is_array($outputs['hls'] ?? null) ? $outputs['hls'] : array();
        $path = is_string($hls['path'] ?? null) ? $hls['path'] : '';
        $directory = is_string($hls['directory'] ?? null) ? $hls['directory'] : '';
        $url = is_string($hls['url'] ?? null) ? $hls['url'] : '';
        $renditions = is_array($hls['renditions'] ?? null) ? $hls['renditions'] : array();
        if ('' === $path || '' === $directory || '' === $url || array() === $renditions || count($renditions) > self::MAX_RENDITIONS) {
            return array();
        }

        try {
            $path = Storage::assert_managed_path($path);
            $directory = Storage::assert_managed_path($directory);
        } catch (RuntimeException) {
            return array();
        }
        if (wp_normalize_path(dirname($path)) !== wp_normalize_path($directory)
            || 'master.m3u8' !== basename($path)
            || ! is_file($path)
            || (int) @filesize($path) < 1
        ) {
            return array();
        }
        try {
            if (Storage::url_for_path($path) !== $url) {
                return array();
            }
        } catch (RuntimeException) {
            return array();
        }

        $master = self::read_playlist($path);
        if (null === $master || ! str_contains($master, '#EXTM3U') || ! str_contains($master, '#EXT-X-STREAM-INF')) {
            return array();
        }
        $master_refs = self::plain_playlist_references($master);
        if (array() === $master_refs || count($master_refs) !== count($renditions)) {
            return array();
        }

        $files = array();
        if (! self::record_file($path, $files, true)) {
            return array();
        }
        $expected_master_refs = array();
        $labels = array();

        foreach ($renditions as $rendition) {
            if (! is_array($rendition)) {
                return array();
            }
            $label = is_string($rendition['label'] ?? null) ? $rendition['label'] : '';
            $playlist = is_string($rendition['path'] ?? null) ? $rendition['path'] : '';
            $playlist_url = is_string($rendition['url'] ?? null) ? $rendition['url'] : '';
            if ('' === $label || 1 !== preg_match('/\A[a-zA-Z0-9._-]{1,64}\z/D', $label) || isset($labels[$label]) || '' === $playlist || '' === $playlist_url) {
                return array();
            }
            $labels[$label] = true;
            try {
                $playlist = Storage::assert_managed_path($playlist);
            } catch (RuntimeException) {
                return array();
            }
            $expected_directory = wp_normalize_path($directory . '/' . $label);
            if (wp_normalize_path(dirname($playlist)) !== $expected_directory || 'index.m3u8' !== basename($playlist)) {
                return array();
            }
            try {
                if (Storage::url_for_path($playlist) !== $playlist_url) {
                    return array();
                }
                Adaptive_HLS::validate_media_playlist($playlist);
            } catch (RuntimeException) {
                return array();
            }
            $contents = self::read_playlist($playlist);
            if (null === $contents || ! self::record_file($playlist, $files, true)) {
                return array();
            }
            $references = self::media_references($contents);
            if (array() === $references || count($references) > self::MAX_REFERENCES_PER_RENDITION) {
                return array();
            }
            foreach ($references as $reference) {
                $referenced = self::relative_file($playlist, $reference);
                if ('' === $referenced || ! self::record_file($referenced, $files, false)) {
                    return array();
                }
            }
            $expected_master_refs[] = rawurlencode($label) . '/index.m3u8';
        }

        sort($master_refs, SORT_STRING);
        sort($expected_master_refs, SORT_STRING);
        if ($master_refs !== $expected_master_refs) {
            return array();
        }

        ksort($files, SORT_STRING);
        $parts = array();
        $total = 0;
        foreach ($files as $relative => $identity) {
            $total += (int) $identity['bytes'];
            $parts[] = $relative . '|' . (string) $identity['bytes'] . '|' . (string) $identity['device'] . '|' . (string) $identity['inode'] . '|' . (string) $identity['mtime'] . '|' . (string) $identity['ctime'] . '|' . (string) $identity['sha256'];
        }
        if (array() === $parts || $total < 1) {
            return array();
        }
        return array(
            'version'       => self::VERSION,
            'attachment_id' => $attachment_id,
            'hls_url'       => $url,
            'file_count'    => count($files),
            'bytes'         => $total,
            'tree_sha256'   => hash('sha256', "awvp-local-delivery-tree:v1:\n" . implode("\n", $parts)),
        );
    }

    /** @return array<string,mixed> */
    public static function sanitize(mixed $value): array
    {
        if (! is_array($value)
            || array('version','attachment_id','hls_url','file_count','bytes','tree_sha256') !== array_keys($value)
            || self::VERSION !== ($value['version'] ?? null)
            || ! is_int($value['attachment_id'] ?? null) || $value['attachment_id'] < 1
            || ! is_string($value['hls_url'] ?? null) || '' === $value['hls_url']
            || ! is_int($value['file_count'] ?? null) || $value['file_count'] < 3
            || ! is_int($value['bytes'] ?? null) || $value['bytes'] < 1
            || ! is_string($value['tree_sha256'] ?? null) || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $value['tree_sha256'])
        ) {
            return array();
        }
        return $value;
    }

    public static function sha256(array $evidence): string
    {
        $evidence = self::sanitize($evidence);
        if (array() === $evidence) {
            return '';
        }
        $json = wp_json_encode($evidence, JSON_UNESCAPED_SLASHES);
        return is_string($json) ? hash('sha256', 'awvp-local-delivery-evidence:v1:' . $json) : '';
    }

    public static function matches(int $attachment_id, array $evidence): bool
    {
        $expected = self::sanitize($evidence);
        if (array() === $expected || $attachment_id !== (int) $expected['attachment_id']) {
            return false;
        }
        $current = self::capture($attachment_id);
        return array() !== $current && hash_equals(self::sha256($expected), self::sha256($current));
    }

    /** Lightweight render-time check; destructive cleanup uses capture()/matches(). */
    public static function available_hls_url(int $attachment_id): string
    {
        if ($attachment_id < 1) {
            return '';
        }
        $outputs = get_post_meta($attachment_id, '_argent_video_outputs', true);
        $hls = is_array($outputs) && is_array($outputs['hls'] ?? null) ? $outputs['hls'] : array();
        $path = is_string($hls['path'] ?? null) ? $hls['path'] : '';
        $url = is_string($hls['url'] ?? null) ? $hls['url'] : '';
        if ('' === $path || '' === $url) {
            return '';
        }
        try {
            $path = Storage::assert_managed_path($path);
            if (! is_file($path) || (int) @filesize($path) < 1 || Storage::url_for_path($path) !== $url) {
                return '';
            }
        } catch (RuntimeException) {
            return '';
        }
        return $url;
    }

    /** @param array<string,array{bytes:int,device:int,inode:int,mtime:int,ctime:int,sha256:string}> $files */
    private static function record_file(string $path, array &$files, bool $hash_contents): bool
    {
        try {
            $path = Storage::assert_managed_path($path);
        } catch (RuntimeException) {
            return false;
        }
        if (! is_file($path) || is_link($path)) {
            return false;
        }
        $stat = @stat($path);
        if (! is_array($stat) || (int) ($stat['size'] ?? 0) < 1 || (int) ($stat['mtime'] ?? 0) < 1) {
            return false;
        }
        $relative = self::managed_relative($path);
        if ('' === $relative) {
            return false;
        }
        $sha256 = '';
        if ($hash_contents) {
            $hash = hash_file('sha256', $path);
            if (! is_string($hash) || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $hash)) {
                return false;
            }
            $sha256 = $hash;
        }
        $files[$relative] = array(
            'bytes'=>(int)$stat['size'],
            'device'=>(int)($stat['dev']??0),
            'inode'=>(int)($stat['ino']??0),
            'mtime'=>(int)$stat['mtime'],
            'ctime'=>(int)($stat['ctime']??0),
            'sha256'=>$sha256,
        );
        return true;
    }

    private static function managed_relative(string $path): string
    {
        try {
            $root = rtrim(wp_normalize_path(Storage::root()), '/');
            $path = wp_normalize_path(Storage::assert_managed_path($path));
        } catch (RuntimeException) {
            return '';
        }
        if (! str_starts_with($path, $root . '/')) {
            return '';
        }
        $relative = substr($path, strlen($root) + 1);
        return is_string($relative) && '' !== $relative ? $relative : '';
    }

    private static function read_playlist(string $path): ?string
    {
        $size = @filesize($path);
        if (! is_int($size) || $size < 1 || $size > self::MAX_PLAYLIST_BYTES) {
            return null;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only validation of a confined plugin-managed HLS playlist.
        $contents = file_get_contents($path);
        return is_string($contents) && strlen($contents) === $size ? $contents : null;
    }

    /** @return list<string> */
    private static function plain_playlist_references(string $contents): array
    {
        $references = array();
        foreach (preg_split('/\r?\n/', $contents) ?: array() as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            if (! self::safe_relative_reference($line)) {
                return array();
            }
            $references[] = $line;
        }
        return array_values(array_unique($references));
    }

    /** @return list<string> */
    private static function media_references(string $contents): array
    {
        $references = self::plain_playlist_references($contents);
        foreach (preg_split('/\r?\n/', $contents) ?: array() as $line) {
            $line = trim($line);
            if (! str_starts_with($line, '#EXT-X-MAP:')) {
                continue;
            }
            if (1 !== preg_match('/\bURI="([^"]+)"/', $line, $match) || ! self::safe_relative_reference($match[1])) {
                return array();
            }
            $references[] = $match[1];
        }
        return array_values(array_unique($references));
    }

    private static function safe_relative_reference(string $reference): bool
    {
        if ('' === $reference || str_contains($reference, "\0") || str_contains($reference, '?') || str_contains($reference, '#')) {
            return false;
        }
        $decoded = rawurldecode($reference);
        if ('' === $decoded || str_starts_with($decoded, '/') || str_contains($decoded, '://')) {
            return false;
        }
        foreach (explode('/', $decoded) as $part) {
            if ('' === $part || '.' === $part || '..' === $part) {
                return false;
            }
        }
        return true;
    }

    private static function relative_file(string $playlist, string $reference): string
    {
        if (! self::safe_relative_reference($reference)) {
            return '';
        }
        $decoded = rawurldecode($reference);
        try {
            return Storage::assert_managed_path(wp_normalize_path(dirname($playlist) . '/' . $decoded));
        } catch (RuntimeException) {
            return '';
        }
    }
}
// EOF
