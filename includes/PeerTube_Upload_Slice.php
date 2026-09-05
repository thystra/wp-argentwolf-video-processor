<?php
/**
 * File: includes/PeerTube_Upload_Slice.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * One already-verified, read-only slice of a plugin-managed staged source.
 *
 * The same file descriptor is retained from pre-transfer verification through
 * cURL streaming and post-transfer verification. The complete source is
 * re-hashed when the slice opens so a path swap cannot cross the local proof /
 * remote-send boundary. The selected slice hash is captured during that same
 * pass and compared with the bytes actually handed to cURL.
 */
final class PeerTube_Upload_Slice
{
    private const MAX_READ_BYTES = 1048576;

    /** @var resource|null */
    private $handle;
    /** @var array<string,int> */
    private array $initial_stat;
    /** @var resource|\HashContext|null */
    private $sent_hash;
    private int $remaining;
    private int $consumed = 0;
    private bool $read_failed = false;
    private ?string $sent_sha256 = null;

    /**
     * @param resource $handle
     * @param array<string,int> $initial_stat
     * @param resource|\HashContext $sent_hash
     */
    private function __construct(
        $handle,
        private readonly string $path,
        private readonly array $identity,
        private readonly int $slice_start,
        private readonly int $slice_bytes,
        private readonly string $expected_slice_sha256,
        array $initial_stat,
        $sent_hash
    ) {
        $this->handle = $handle;
        $this->initial_stat = $initial_stat;
        $this->sent_hash = $sent_hash;
        $this->remaining = $slice_bytes;
    }

    /** @param array<string,mixed> $identity */
    public static function open(array $identity, int $start, int $bytes): ?self
    {
        if (
            ! PeerTube_Staged_Source_Identity::valid($identity)
            || $start < 0
            || $bytes < 1
            || $start > PHP_INT_MAX - $bytes
            || $start + $bytes > $identity['bytes']
        ) {
            return null;
        }

        $path = PeerTube_Staged_Source_Identity::absolute_path($identity['relative_path']);
        if ('' === $path) {
            return null;
        }
        $path_before = @lstat($path);
        if (! is_array($path_before) || ! self::regular_stat($path_before)) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The upload stream must bind an exact already-confined staged file descriptor; WP_Filesystem cannot provide this invariant.
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return null;
        }

        $keep = false;
        try {
            $before = fstat($handle);
            if (
                ! is_array($before)
                || ! self::regular_stat($before)
                || ! self::same_object($path_before, $before)
                || (int) $before['size'] !== $identity['bytes']
            ) {
                return null;
            }

            $hashes = self::hash_source_and_slice($handle, $start, $bytes);
            if (
                null === $hashes
                || ! hash_equals($identity['sha256'], $hashes['source_sha256'])
            ) {
                return null;
            }

            $after_hash = fstat($handle);
            $path_after_hash = @lstat($path);
            if (
                ! is_array($after_hash)
                || ! self::regular_stat($after_hash)
                || ! is_array($path_after_hash)
                || ! self::regular_stat($path_after_hash)
                || ! self::same_stat($before, $after_hash)
                || ! self::same_object($after_hash, $path_after_hash)
                || 0 !== fseek($handle, $start, SEEK_SET)
            ) {
                return null;
            }

            $sent_hash = hash_init('sha256');
            $keep = true;
            return new self(
                $handle,
                $path,
                $identity,
                $start,
                $bytes,
                $hashes['slice_sha256'],
                $before,
                $sent_hash
            );
        } finally {
            if (! $keep) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the exact staged-source descriptor opened above.
                fclose($handle);
            }
        }
    }

    public function start(): int
    {
        return $this->slice_start;
    }

    public function bytes(): int
    {
        return $this->slice_bytes;
    }

    /**
     * cURL read callback boundary. Empty string means EOF to cURL.
     */
    public function read(int $requested_bytes): string
    {
        if (! is_resource($this->handle) || $this->read_failed || $requested_bytes < 1 || $this->remaining < 1) {
            return '';
        }

        $length = min($requested_bytes, $this->remaining, self::MAX_READ_BYTES);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reads the already-confined upload descriptor incrementally for cURL.
        $data = fread($this->handle, $length);
        if (false === $data || '' === $data) {
            $this->read_failed = true;
            return '';
        }

        $read = strlen($data);
        if ($read > $this->remaining || null === $this->sent_hash) {
            $this->read_failed = true;
            return '';
        }
        hash_update($this->sent_hash, $data);
        $this->consumed += $read;
        $this->remaining -= $read;
        return $data;
    }

    public function complete(): bool
    {
        if ($this->read_failed || $this->consumed !== $this->slice_bytes || 0 !== $this->remaining) {
            return false;
        }
        if (null === $this->sent_sha256) {
            if (null === $this->sent_hash) {
                return false;
            }
            $this->sent_sha256 = hash_final($this->sent_hash);
            $this->sent_hash = null;
        }

        return 1 === preg_match('/^[a-f0-9]{64}$/D', $this->sent_sha256)
            && hash_equals($this->expected_slice_sha256, $this->sent_sha256);
    }

    /**
     * Re-prove the exact open object after a positive transport response but
     * before a confirmed offset is persisted. When the remote reports final
     * creation, also re-hash the complete source before accepting identity.
     */
    public function verify_unchanged(bool $full_source = false): bool
    {
        if (! $this->complete() || ! is_resource($this->handle)) {
            return false;
        }

        $before = fstat($this->handle);
        $path_before = @lstat($this->path);
        if (
            ! is_array($before)
            || ! self::regular_stat($before)
            || ! is_array($path_before)
            || ! self::regular_stat($path_before)
            || ! self::same_stat($this->initial_stat, $before)
            || ! self::same_object($before, $path_before)
        ) {
            return false;
        }

        if ($full_source && ! self::hash_matches($this->handle, $this->identity['sha256'])) {
            return false;
        }

        $after = fstat($this->handle);
        $path_after = @lstat($this->path);
        return is_array($after)
            && self::regular_stat($after)
            && is_array($path_after)
            && self::regular_stat($path_after)
            && self::same_stat($this->initial_stat, $after)
            && self::same_object($after, $path_after);
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the exact staged-source descriptor owned by this slice.
            fclose($this->handle);
        }
        $this->handle = null;
        $this->sent_hash = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Hash the complete source and the selected slice in one sequential pass.
     *
     * @param resource $handle
     * @return array{source_sha256:string,slice_sha256:string}|null
     */
    private static function hash_source_and_slice($handle, int $slice_start, int $slice_bytes): ?array
    {
        if (0 !== fseek($handle, 0, SEEK_SET)) {
            return null;
        }

        $source = hash_init('sha256');
        $slice = hash_init('sha256');
        $offset = 0;
        $slice_end = $slice_start + $slice_bytes;
        while (! feof($handle)) {
            // Keep verification memory bounded independently of the configured upload segment.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reads the exact staged-source descriptor for integrity verification.
            $data = fread($handle, 1048576);
            if (false === $data) {
                return null;
            }
            if ('' === $data) {
                break;
            }

            $length = strlen($data);
            hash_update($source, $data);
            $block_end = $offset + $length;
            $overlap_start = max($offset, $slice_start);
            $overlap_end = min($block_end, $slice_end);
            if ($overlap_end > $overlap_start) {
                $relative = $overlap_start - $offset;
                hash_update($slice, substr($data, $relative, $overlap_end - $overlap_start));
            }
            $offset = $block_end;
        }

        if ($offset < $slice_end) {
            return null;
        }
        $source_sha256 = hash_final($source);
        $slice_sha256 = hash_final($slice);
        if (
            1 !== preg_match('/^[a-f0-9]{64}$/D', $source_sha256)
            || 1 !== preg_match('/^[a-f0-9]{64}$/D', $slice_sha256)
        ) {
            return null;
        }

        return array('source_sha256' => $source_sha256, 'slice_sha256' => $slice_sha256);
    }

    /** @param resource $handle */
    private static function hash_matches($handle, string $expected): bool
    {
        if (0 !== fseek($handle, 0, SEEK_SET)) {
            return false;
        }
        $context = hash_init('sha256');
        $read = hash_update_stream($context, $handle);
        if (! is_int($read)) {
            return false;
        }
        $hash = hash_final($context);
        return $read > 0 && 1 === preg_match('/^[a-f0-9]{64}$/D', $hash) && hash_equals($expected, $hash);
    }

    /** @param array<string,mixed> $stat */
    private static function regular_stat(array $stat): bool
    {
        return isset($stat['dev'], $stat['ino'], $stat['mode'], $stat['size'], $stat['mtime'], $stat['ctime'])
            && is_int($stat['dev'])
            && is_int($stat['ino'])
            && is_int($stat['mode'])
            && 0100000 === ($stat['mode'] & 0170000)
            && is_int($stat['size'])
            && $stat['size'] > 0
            && is_int($stat['mtime'])
            && is_int($stat['ctime']);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function same_stat(array $left, array $right): bool
    {
        foreach (array('dev', 'ino', 'mode', 'size', 'mtime', 'ctime') as $key) {
            if (($left[$key] ?? null) !== ($right[$key] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function same_object(array $left, array $right): bool
    {
        return ($left['dev'] ?? null) === ($right['dev'] ?? null)
            && ($left['ino'] ?? null) === ($right['ino'] ?? null)
            && ($left['mode'] ?? null) === ($right['mode'] ?? null);
    }
}

// EOF: includes/PeerTube_Upload_Slice.php
