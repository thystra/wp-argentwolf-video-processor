<?php
/** File: includes/PeerTube_Publication_Thumbnail.php */
declare(strict_types=1);
namespace ArgentVideo;

use RuntimeException;

/** Confined WordPress image attachment used as reviewed PeerTube publication metadata. */
final class PeerTube_Publication_Thumbnail
{
    public const MAX_BYTES = 10485760;
    private const MIME_TYPES = array('image/jpeg', 'image/png', 'image/webp');

    /**
     * Capture the exact bounded bytes that may cross the PeerTube multipart boundary.
     *
     * The absolute host path is never returned. Callers may persist only the hash,
     * size, MIME, and attachment identity; the content is an in-memory execution
     * projection used immediately by the detached publication worker.
     *
     * @return array{attachment_id:int,relative_path:string,mime:string,bytes:int,sha256:string,content:string}|null
     */
    public static function capture(int $attachment_id): ?array
    {
        if ($attachment_id < 1 || ! wp_attachment_is_image($attachment_id)) {
            return null;
        }

        $mime = (string) get_post_mime_type($attachment_id);
        if (! in_array($mime, self::MIME_TYPES, true)) {
            return null;
        }

        $path = get_attached_file($attachment_id, true);
        if (! is_string($path) || '' === $path) {
            return null;
        }

        try {
            [$path, $relative] = self::confined($path);
        } catch (RuntimeException) {
            return null;
        }

        $path_before = @stat($path);
        if (is_link($path) || ! is_array($path_before) || ! self::valid_stat($path_before)) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exact bounded read must bind one already-confined WordPress attachment descriptor; WP_Filesystem does not provide equivalent inode-stable semantics.
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return null;
        }

        try {
            $handle_before = fstat($handle);
            if (! is_array($handle_before) || ! self::same_stat($path_before, $handle_before)) {
                return null;
            }

            $content = stream_get_contents($handle, self::MAX_BYTES + 1);
            if (! is_string($content) || '' === $content || strlen($content) > self::MAX_BYTES) {
                return null;
            }

            $handle_after = fstat($handle);
            $path_after = @stat($path);
            if (
                ! is_array($handle_after)
                || ! is_array($path_after)
                || ! self::same_stat($handle_before, $handle_after)
                || ! self::same_stat($handle_after, $path_after)
                || strlen($content) !== (int) $handle_after['size']
            ) {
                return null;
            }

            return array(
                'attachment_id' => $attachment_id,
                'relative_path' => $relative,
                'mime'          => $mime,
                'bytes'         => strlen($content),
                'sha256'        => hash('sha256', $content),
                'content'       => $content,
            );
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the exact read-only thumbnail descriptor opened above.
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function same_stat(array $left, array $right): bool
    {
        foreach (array('dev','ino','mode','size','mtime','ctime') as $key) {
            if (($left[$key] ?? null) !== ($right[$key] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $stat */
    private static function valid_stat(array $stat): bool
    {
        return isset($stat['dev'],$stat['ino'],$stat['mode'],$stat['size'],$stat['mtime'],$stat['ctime'])
            && is_int($stat['dev']) && is_int($stat['ino']) && $stat['ino'] > 0
            && is_int($stat['mode']) && 0100000 === ($stat['mode'] & 0170000)
            && is_int($stat['size']) && $stat['size'] > 0 && $stat['size'] <= self::MAX_BYTES
            && is_int($stat['mtime']) && is_int($stat['ctime']);
    }

    /** @return array{0:string,1:string} */
    private static function confined(string $path): array
    {
        if ('' === $path || str_contains($path, "\0")) {
            throw new RuntimeException('Invalid thumbnail path.');
        }
        $uploads = wp_upload_dir();
        if (! is_array($uploads) || ! empty($uploads['error']) || ! is_string($uploads['basedir'] ?? null) || '' === $uploads['basedir']) {
            throw new RuntimeException('Uploads unavailable.');
        }

        $base = rtrim(wp_normalize_path((string) $uploads['basedir']), '/');
        $path = wp_normalize_path($path);
        if (! str_starts_with($path, $base . '/')) {
            throw new RuntimeException('Thumbnail outside uploads.');
        }

        $relative = substr($path, strlen($base) + 1);
        if (false === $relative || '' === $relative) {
            throw new RuntimeException('Invalid thumbnail relative path.');
        }
        foreach (explode('/', $relative) as $part) {
            if ('' === $part || '.' === $part || '..' === $part) {
                throw new RuntimeException('Unsafe thumbnail path segment.');
            }
        }

        $real_base = realpath($base);
        $real_path = realpath($path);
        if (false === $real_base || false === $real_path) {
            throw new RuntimeException('Thumbnail cannot be resolved.');
        }
        $real_base = rtrim(wp_normalize_path($real_base), '/');
        $real_path = wp_normalize_path($real_path);
        if (! str_starts_with($real_path, $real_base . '/')) {
            throw new RuntimeException('Resolved thumbnail outside uploads.');
        }

        $cursor = $base;
        foreach (explode('/', $relative) as $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor)) {
                throw new RuntimeException('Thumbnail traverses symbolic link.');
            }
        }

        return array($path, $relative);
    }
}
// EOF
