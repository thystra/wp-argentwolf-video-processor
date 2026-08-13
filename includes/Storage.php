<?php
/**
 * File: includes/Storage.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use RuntimeException;

final class Storage
{
    private const DIRECTORY = 'argentwolf-video-processor';

    /** @return array<string, mixed> */
    private static function uploads(): array
    {
        $uploads = wp_upload_dir();

        if (
            ! is_array($uploads)
            || ! empty($uploads['error'])
            || empty($uploads['basedir'])
            || empty($uploads['baseurl'])
        ) {
            throw new RuntimeException('WordPress uploads storage is unavailable.');
        }

        return $uploads;
    }

    public static function root(): string
    {
        $uploads = self::uploads();
        return rtrim(wp_normalize_path((string) $uploads['basedir']), '/') . '/' . self::DIRECTORY;
    }

    public static function attachment_directory(int $attachment_id): string
    {
        if ($attachment_id <= 0) {
            throw new RuntimeException('Attachment storage requires a positive attachment ID.');
        }

        return self::assert_managed_path(self::root() . '/' . $attachment_id);
    }

    public static function ensure_attachment_directory(int $attachment_id): string
    {
        $directory = self::attachment_directory($attachment_id);
        self::make_directory($directory);
        return $directory;
    }

    public static function make_directory(string $directory): string
    {
        $directory = self::assert_managed_path($directory);

        if (! is_dir($directory) && ! wp_mkdir_p($directory)) {
            throw new RuntimeException('Could not create managed video storage directory: ' . $directory);
        }

        $directory = self::assert_managed_path($directory);
        if (! is_dir($directory)) {
            throw new RuntimeException('Managed video storage directory was not created: ' . $directory);
        }

        return $directory;
    }

    public static function assert_managed_path(string $path): string
    {
        if ('' === $path || str_contains($path, "\0")) {
            throw new RuntimeException('Managed video storage path is empty or invalid.');
        }

        $root = rtrim(wp_normalize_path(self::root()), '/');
        $normalized = rtrim(wp_normalize_path($path), '/');

        if ($normalized === $root || ! str_starts_with($normalized, $root . '/')) {
            throw new RuntimeException('Path is outside the ArgentWolf Video Processor uploads directory.');
        }

        $relative = substr($normalized, strlen($root) + 1);
        if (false === $relative || '' === $relative) {
            throw new RuntimeException('Managed video storage path must be below the plugin storage root.');
        }

        $parts = explode('/', $relative);
        foreach ($parts as $part) {
            if ('' === $part || '.' === $part || '..' === $part) {
                throw new RuntimeException('Managed video storage path contains an unsafe path segment.');
            }
        }

        self::assert_no_symlink_components($root, $parts);

        if (is_dir($root)) {
            $real_root = realpath($root);
            if (false === $real_root) {
                throw new RuntimeException('Could not resolve the managed video storage root.');
            }
            $real_root = rtrim(wp_normalize_path($real_root), '/');

            $existing = $normalized;
            while (
                $existing !== $root
                && ! file_exists($existing)
                && ! is_link($existing)
            ) {
                $parent = rtrim(wp_normalize_path(dirname($existing)), '/');
                if ($parent === $existing) {
                    break;
                }
                $existing = $parent;
            }

            if (is_link($existing)) {
                throw new RuntimeException('Managed video storage path traverses a symbolic link.');
            }

            if (file_exists($existing)) {
                $real_existing = realpath($existing);
                if (false === $real_existing) {
                    throw new RuntimeException('Could not resolve a managed video storage path.');
                }
                $real_existing = rtrim(wp_normalize_path($real_existing), '/');

                if (
                    $real_existing !== $real_root
                    && ! str_starts_with($real_existing, $real_root . '/')
                ) {
                    throw new RuntimeException('Managed video storage path resolves outside the plugin storage root.');
                }
            }
        }

        return $normalized;
    }

    public static function is_managed_path(string $path): bool
    {
        try {
            self::assert_managed_path($path);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function url_for_path(string $path): string
    {
        $path = self::assert_managed_path($path);
        $uploads = self::uploads();
        $base_dir = rtrim(wp_normalize_path((string) $uploads['basedir']), '/');

        if (! str_starts_with($path, $base_dir . '/')) {
            throw new RuntimeException('Managed derivative is outside the WordPress uploads directory.');
        }

        $relative = substr($path, strlen($base_dir) + 1);
        $encoded = implode(
            '/',
            array_map(
                static fn(string $segment): string => rawurlencode($segment),
                explode('/', $relative)
            )
        );

        return trailingslashit((string) $uploads['baseurl']) . $encoded;
    }

    public static function write_file(string $path, string $contents): void
    {
        $path = self::assert_managed_path($path);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- HLS manifests are plugin-generated files inside the validated managed uploads boundary.
        if (false === file_put_contents($path, $contents, LOCK_EX)) {
            throw new RuntimeException('Could not write managed video storage file: ' . $path);
        }
    }

    public static function delete_file(string $path): void
    {
        $path = self::assert_managed_path($path);

        if (! is_file($path)) {
            return;
        }

        wp_delete_file($path);
        clearstatcache(true, $path);

        if (is_file($path)) {
            throw new RuntimeException('Could not delete managed video storage file: ' . $path);
        }
    }

    public static function rename_path(string $source, string $destination): void
    {
        $source = self::assert_managed_path($source);
        $destination = self::assert_managed_path($destination);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem atomic promotion is required for validated media.
        if (! @rename($source, $destination)) {
            throw new RuntimeException(
                'Could not atomically move managed video storage path into place: ' . $destination
            );
        }
    }

    public static function remove_tree(string $directory): void
    {
        $directory = self::assert_managed_path($directory);

        if (! is_dir($directory)) {
            return;
        }

        self::assert_tree_without_symlinks($directory);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = self::assert_managed_path($item->getPathname());

            if ($item->isDir()) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Recursive cleanup is confined to the validated plugin-owned uploads tree.
                if (! @rmdir($path) && is_dir($path)) {
                    throw new RuntimeException('Could not remove managed video storage directory: ' . $path);
                }
            } else {
                self::delete_file($path);
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Recursive cleanup is confined to the validated plugin-owned uploads tree.
        if (! @rmdir($directory) && is_dir($directory)) {
            throw new RuntimeException('Could not remove managed video storage directory: ' . $directory);
        }
    }

    /** @param list<string> $parts */
    private static function assert_no_symlink_components(string $root, array $parts): void
    {
        $current = $root;

        if (is_link($current)) {
            throw new RuntimeException('Managed video storage root must not be a symbolic link.');
        }

        foreach ($parts as $part) {
            $current .= '/' . $part;
            if (is_link($current)) {
                throw new RuntimeException('Managed video storage path traverses a symbolic link.');
            }
        }
    }

    private static function assert_tree_without_symlinks(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException('Managed video storage tree contains a symbolic link.');
            }
            self::assert_managed_path($item->getPathname());
        }
    }
}

// EOF: includes/Storage.php
