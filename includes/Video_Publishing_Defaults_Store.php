<?php
/**
 * File: includes/Video_Publishing_Defaults_Store.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use stdClass;

/** Durable non-autoloaded store for R46 publishing defaults/support presets. */
final class Video_Publishing_Defaults_Store
{
    public const OPTION = 'argent_video_processor_video_publishing_defaults';
    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const REFUSED = 'refused';
    public const INDETERMINATE = 'indeterminate';

    public function __construct(private readonly Backend_Registry $registry)
    {
    }

    /**
     * Missing option is the deliberate upgrade-safe default and never writes.
     * A present malformed/future value fails closed as null.
     *
     * @return array<string,mixed>|null
     */
    public function get(): ?array
    {
        $sentinel = new stdClass();
        $stored = get_option(self::OPTION, $sentinel);
        if ($sentinel === $stored) {
            return Video_Publishing_Defaults::defaults();
        }

        $sanitized = Video_Publishing_Defaults::sanitize($stored);
        return array() === $sanitized ? null : $sanitized;
    }

    /** @return array{status:string} */
    public function save(mixed $value): array
    {
        $desired = Video_Publishing_Defaults::sanitize($value);
        if (array() === $desired || ! $this->references_are_valid($desired)) {
            return array('status' => self::REFUSED);
        }

        $sentinel = new stdClass();
        $before = get_option(self::OPTION, $sentinel);
        if ($sentinel !== $before) {
            $current = Video_Publishing_Defaults::sanitize($before);
            if (array() === $current) {
                // Never overwrite malformed/future state merely because an
                // administrator submitted a currently-known form.
                return array('status' => self::REFUSED);
            }
            if ($current === $desired) {
                return array('status' => self::PRESENT);
            }
        }

        if ($sentinel === $before) {
            if (add_option(self::OPTION, $desired, '', false)) {
                return array('status' => self::APPLIED);
            }
            $current = get_option(self::OPTION, $sentinel);
            return $desired === $current
                ? array('status' => self::PRESENT)
                : array('status' => self::INDETERMINATE);
        }

        update_option(self::OPTION, $desired, false);
        $after = get_option(self::OPTION, $sentinel);
        if ($desired === $after) {
            return array('status' => self::APPLIED);
        }
        return array('status' => self::INDETERMINATE);
    }

    /** @param array<string,mixed> $settings */
    private function references_are_valid(array $settings): bool
    {
        $destination = $settings['default_destination'] ?? array();
        $default_backend = is_array($destination)
            ? Backend_Identity::sanitize($destination['backend_id'] ?? null)
            : '';
        if ('' === $default_backend) {
            return false;
        }
        if (Backend_Registry::LOCAL_ID !== $default_backend && ! $this->active_peertube($default_backend)) {
            return false;
        }

        $overrides = $settings['backend_overrides'] ?? null;
        if (! is_array($overrides)) {
            return false;
        }
        foreach ($overrides as $backend_id => $override) {
            if (! is_string($backend_id) || ! is_array($override) || ! $this->active_peertube($backend_id)) {
                return false;
            }
        }

        return true;
    }

    private function active_peertube(string $backend_id): bool
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id) {
            return false;
        }
        $descriptor = $this->registry->get($backend_id);
        return is_array($descriptor)
            && Backend_Registry::PEERTUBE_TYPE === ($descriptor['type'] ?? null)
            && 'active' === ($descriptor['state'] ?? null)
            && '' !== PeerTube_Connection_Input::destination_id($descriptor['default_destination'] ?? null);
    }
}

// EOF: includes/Video_Publishing_Defaults_Store.php
