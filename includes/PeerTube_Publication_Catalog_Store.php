<?php
/**
 * File: includes/PeerTube_Publication_Catalog_Store.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use stdClass;

/** Non-autoloaded per-backend last-known-good publication-choice cache. */
final class PeerTube_Publication_Catalog_Store
{
    private const OPTION_PREFIX = 'argent_video_processor_pt_catalog_';

    /** @return array<string,mixed>|null */
    public function get(string $backend_id): ?array
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id) {
            return null;
        }
        $sentinel = new stdClass();
        $stored = get_option(self::option_name($backend_id), $sentinel);
        if ($sentinel === $stored) {
            return null;
        }
        $catalog = PeerTube_Publication_Catalog::sanitize($stored);
        return array() !== $catalog && $backend_id === $catalog['backend_id'] ? $catalog : null;
    }

    /** @return array<string,mixed>|null */
    public function get_for_context(string $backend_id, string $origin, int $secret_generation): ?array
    {
        $origin = PeerTube_Origin::sanitize($origin);
        $catalog = $this->get($backend_id);
        if (
            null === $catalog || '' === $origin || $secret_generation < 1
            || $origin !== $catalog['origin']
            || $secret_generation !== $catalog['secret_generation']
        ) {
            return null;
        }
        return $catalog;
    }

    public function save_last_known_good(string $backend_id, mixed $catalog): bool
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        $catalog = PeerTube_Publication_Catalog::sanitize($catalog);
        if (
            '' === $backend_id || array() === $catalog || $backend_id !== $catalog['backend_id']
            || true === $catalog['stale']
        ) {
            return false;
        }
        return $this->write_valid($backend_id, $catalog);
    }

    /**
     * Preserve provider data but persistently mark the last valid snapshot stale.
     *
     * @return array<string,mixed>|null
     */
    public function mark_stale(string $backend_id, int $now, string $reason): ?array
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        $catalog = $this->get($backend_id);
        if (null === $catalog || $now < $catalog['refreshed_at']) {
            return $catalog;
        }

        $candidate = $catalog;
        $candidate['stale'] = true;
        $candidate['stale_since'] = null === $catalog['stale_since'] ? $now : $catalog['stale_since'];
        $candidate['stale_reason'] = $reason;
        $candidate = PeerTube_Publication_Catalog::sanitize($candidate);
        if (array() === $candidate || ! $this->write_valid($backend_id, $candidate)) {
            return $catalog;
        }
        return $candidate;
    }

    public static function option_name(string $backend_id): string
    {
        return self::OPTION_PREFIX . substr(hash('sha256', $backend_id), 0, 32);
    }

    /** @param array<string,mixed> $catalog */
    private function write_valid(string $backend_id, array $catalog): bool
    {
        $option = self::option_name($backend_id);
        $sentinel = new stdClass();
        $before = get_option($option, $sentinel);
        if ($sentinel !== $before && null === $this->get($backend_id)) {
            // Preserve malformed/future state rather than overwriting it.
            return false;
        }
        if ($sentinel === $before) {
            if (! add_option($option, $catalog, '', false) && $catalog !== get_option($option, null)) {
                return false;
            }
        } else {
            update_option($option, $catalog, false);
        }
        if (function_exists('wp_set_option_autoload')) {
            wp_set_option_autoload($option, false);
        }
        return $catalog === get_option($option, null);
    }
}

// EOF: includes/PeerTube_Publication_Catalog_Store.php
