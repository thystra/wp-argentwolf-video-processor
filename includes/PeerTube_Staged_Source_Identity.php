<?php
/**
 * File: includes/PeerTube_Staged_Source_Identity.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use RuntimeException;

/**
 * Immutable identity for one plugin-managed staged source.
 *
 * Only a relative managed-storage path plus size/hash commitment is durable.
 * The absolute host path is never persisted in an upload operation.
 */
final class PeerTube_Staged_Source_Identity
{
    public const KIND = 'wordpress_staging';
    private const MAX_RELATIVE_PATH_BYTES = 1024;

    /** @return array{kind:string,relative_path:string,sha256:string,bytes:int}|null */
    public static function capture(string $path): ?array
    {
        try {
            $path = Storage::assert_managed_path($path);
        } catch (RuntimeException) {
            return null;
        }

        if (! is_file($path) || is_link($path)) {
            return null;
        }

        $path_before = @lstat($path);
        if (! is_array($path_before) || ! self::valid_regular_stat($path_before)) {
            return null;
        }

        $relative = self::relative_path($path);
        if ('' === $relative) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exact read-only hashing must bind one already-confined staged file descriptor; WP_Filesystem does not provide equivalent inode-stable streaming semantics.
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return null;
        }

        try {
            $before = fstat($handle);
            if (
                ! is_array($before)
                || ! self::valid_regular_stat($before)
                || ! self::same_object($path_before, $before)
            ) {
                return null;
            }

            $context = hash_init('sha256');
            $read = hash_update_stream($context, $handle);
            if (! is_int($read) || $read !== (int) $before['size']) {
                return null;
            }
            $sha256 = hash_final($context);

            $after = fstat($handle);
            $path_after = @lstat($path);
            if (
                ! is_array($after)
                || ! self::valid_regular_stat($after)
                || ! is_array($path_after)
                || ! self::valid_regular_stat($path_after)
                || ! self::same_stat($before, $after)
                || ! self::same_object($after, $path_after)
                || 1 !== preg_match('/^[a-f0-9]{64}$/D', $sha256)
            ) {
                return null;
            }

            $identity = array(
                'kind'          => self::KIND,
                'relative_path' => $relative,
                'sha256'        => $sha256,
                'bytes'         => (int) $before['size'],
            );

            return self::valid($identity) ? $identity : null;
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the read-only staged-source stream opened above.
            fclose($handle);
        }
    }

    /** @param mixed $identity */
    public static function valid(mixed $identity): bool
    {
        if (
            ! is_array($identity)
            || array('kind', 'relative_path', 'sha256', 'bytes') !== array_keys($identity)
            || self::KIND !== ($identity['kind'] ?? null)
            || ! is_string($identity['relative_path'] ?? null)
            || '' === $identity['relative_path']
            || strlen($identity['relative_path']) > self::MAX_RELATIVE_PATH_BYTES
            || str_starts_with($identity['relative_path'], '/')
            || str_contains($identity['relative_path'], "\0")
            || ! is_string($identity['sha256'] ?? null)
            || 1 !== preg_match('/^[a-f0-9]{64}$/D', $identity['sha256'])
            || ! is_int($identity['bytes'] ?? null)
            || $identity['bytes'] < 1
        ) {
            return false;
        }

        $segments = explode('/', $identity['relative_path']);
        foreach ($segments as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $identity */
    public static function matches(array $identity): bool
    {
        if (! self::valid($identity)) {
            return false;
        }

        $path = self::absolute_path($identity['relative_path']);
        if ('' === $path) {
            return false;
        }

        $current = self::capture($path);
        return is_array($current) && $current === $identity;
    }

    public static function absolute_path(string $relative_path): string
    {
        if (
            '' === $relative_path
            || strlen($relative_path) > self::MAX_RELATIVE_PATH_BYTES
            || str_starts_with($relative_path, '/')
            || str_contains($relative_path, "\0")
        ) {
            return '';
        }

        foreach (explode('/', $relative_path) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return '';
            }
        }

        try {
            return Storage::assert_managed_path(
                rtrim(Storage::root(), '/') . '/' . $relative_path
            );
        } catch (RuntimeException) {
            return '';
        }
    }

    private static function relative_path(string $path): string
    {
        try {
            $root = rtrim(Storage::root(), '/');
        } catch (RuntimeException) {
            return '';
        }
        if (! str_starts_with($path, $root . '/')) {
            return '';
        }

        $relative = substr($path, strlen($root) + 1);
        return is_string($relative)
            && '' !== $relative
            && strlen($relative) <= self::MAX_RELATIVE_PATH_BYTES
                ? $relative
                : '';
    }

    /** @param array<string,mixed> $stat */
    private static function valid_regular_stat(array $stat): bool
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

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private static function same_stat(array $before, array $after): bool
    {
        foreach (array('dev', 'ino', 'mode', 'size', 'mtime', 'ctime') as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
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

// EOF: includes/PeerTube_Staged_Source_Identity.php
