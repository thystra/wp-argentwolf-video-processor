<?php
/**
 * File: includes/Backend_Serving_Priority_Store.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Backend-level serving priority policy. Local is immutable priority zero. */
final class Backend_Serving_Priority_Store
{
    public const OPTION = 'argent_video_processor_serving_priorities';
    public const VERSION = 1;
    public const DEFAULT_REMOTE_PRIORITY = 500;
    public const MAX_PRIORITY = 10000;

    public function priority(string $backend_id): int
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if (Backend_Registry::LOCAL_ID === $backend_id) {
            return 0;
        }
        if ('' === $backend_id) {
            return 0;
        }
        $value = $this->all()[$backend_id] ?? self::DEFAULT_REMOTE_PRIORITY;
        return self::sanitize_priority($value);
    }

    /** @return array<string,int> */
    public function all(): array
    {
        $stored = get_option(self::OPTION, array());
        if (! is_array($stored) || self::VERSION !== (int) ($stored['version'] ?? 0) || ! is_array($stored['priorities'] ?? null)) {
            return array();
        }
        $out = array();
        foreach ($stored['priorities'] as $backend_id => $priority) {
            if (! is_string($backend_id) || Backend_Registry::LOCAL_ID === $backend_id
                || $backend_id !== Backend_Identity::sanitize($backend_id)) {
                continue;
            }
            $priority = self::sanitize_priority($priority);
            if ($priority > 0) {
                $out[$backend_id] = $priority;
            }
        }
        return $out;
    }

    public function save(string $backend_id, mixed $priority): bool
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        $priority = self::sanitize_priority($priority);
        if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id || $priority < 1) {
            return false;
        }
        $priorities = $this->all();
        $priorities[$backend_id] = $priority;
        ksort($priorities);
        $value = array('version' => self::VERSION, 'priorities' => $priorities);
        update_option(self::OPTION, $value, false);
        return $value === get_option(self::OPTION, null);
    }

    private static function sanitize_priority(mixed $value): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        return is_int($value) && $value >= 1 && $value <= self::MAX_PRIORITY ? $value : 0;
    }
}

// EOF: includes/Backend_Serving_Priority_Store.php
