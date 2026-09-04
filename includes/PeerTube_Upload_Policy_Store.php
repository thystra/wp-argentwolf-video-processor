<?php
/**
 * File: includes/PeerTube_Upload_Policy_Store.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use stdClass;
use Throwable;

/**
 * Small non-secret, non-autoloaded operational policy store keyed by backend.
 *
 * Upload tuning is intentionally kept outside the backend identity descriptor:
 * changing segmentation must not rewrite connection identity, destination, or
 * credential authority. Each backend owns a separate option so unrelated
 * backend settings cannot overwrite one another.
 */
final class PeerTube_Upload_Policy_Store
{
    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const REFUSED = 'refused';
    public const INDETERMINATE = 'indeterminate';

    private const VERSION = 1;
    private const OPTION_PREFIX = 'argent_video_processor_peertube_upload_policy_';

    public function __construct(private readonly Backend_Registry $registry)
    {
    }

    public function chunk_mib(string $backend_id): int
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if ('' === $backend_id || 'local' === $backend_id) {
            return PeerTube_Upload_Policy::DEFAULT_CHUNK_MIB;
        }

        try {
            $stored = get_option(self::option_name($backend_id), null);
        } catch (Throwable) {
            return PeerTube_Upload_Policy::DEFAULT_CHUNK_MIB;
        }

        $policy = self::sanitize_record($stored);
        return is_array($policy)
            ? $policy['chunk_mib']
            : PeerTube_Upload_Policy::DEFAULT_CHUNK_MIB;
    }

    public function save_chunk_mib(string $backend_id, mixed $chunk_mib): string
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        $chunk_mib = PeerTube_Upload_Policy::chunk_mib($chunk_mib);
        if ('' === $backend_id || 'local' === $backend_id || null === $chunk_mib) {
            return self::REFUSED;
        }

        try {
            $descriptor = $this->registry->get($backend_id);
        } catch (Throwable) {
            return self::INDETERMINATE;
        }
        if (! self::active_peertube_descriptor($descriptor, $backend_id)) {
            return self::REFUSED;
        }

        $option = self::option_name($backend_id);
        $desired = array('version' => self::VERSION, 'chunk_mib' => $chunk_mib);
        $sentinel = new stdClass();

        try {
            $before = get_option($option, $sentinel);
            if ($sentinel === $before) {
                if (add_option($option, $desired, '', false)) {
                    return self::APPLIED;
                }

                // A concurrent creator may have won after the absent read.
                $current = get_option($option, $sentinel);
                return $desired === $current ? self::PRESENT : self::INDETERMINATE;
            }

            $current = self::sanitize_record($before);
            if (null === $current) {
                // Never overwrite malformed or future-version policy state.
                return self::REFUSED;
            }
            if ($desired === $current) {
                return self::PRESENT;
            }

            update_option($option, $desired, false);
            $after = get_option($option, $sentinel);
            return $desired === $after ? self::APPLIED : self::INDETERMINATE;
        } catch (Throwable) {
            return self::INDETERMINATE;
        }
    }

    private static function option_name(string $backend_id): string
    {
        return self::OPTION_PREFIX . $backend_id;
    }

    /** @return array{version:int,chunk_mib:int}|null */
    private static function sanitize_record(mixed $record): ?array
    {
        if (! is_array($record) || array('version', 'chunk_mib') !== array_keys($record)) {
            return null;
        }
        $chunk_mib = PeerTube_Upload_Policy::chunk_mib($record['chunk_mib'] ?? null);
        if (self::VERSION !== ($record['version'] ?? null) || null === $chunk_mib) {
            return null;
        }

        return array('version' => self::VERSION, 'chunk_mib' => $chunk_mib);
    }

    /** @param array<string,mixed>|null $descriptor */
    private static function active_peertube_descriptor(?array $descriptor, string $backend_id): bool
    {
        return is_array($descriptor)
            && $backend_id === ($descriptor['id'] ?? null)
            && Backend_Registry::PEERTUBE_TYPE === ($descriptor['type'] ?? null)
            && 'active' === ($descriptor['state'] ?? null);
    }
}

// EOF: includes/PeerTube_Upload_Policy_Store.php
