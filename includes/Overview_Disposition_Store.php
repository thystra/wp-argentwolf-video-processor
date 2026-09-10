<?php
/**
 * File: includes/Overview_Disposition_Store.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Administrator presentation state for current Overview issues; audit evidence is never deleted. */
final class Overview_Disposition_Store
{
    public const OPTION = 'argent_video_processor_overview_dispositions';
    public const REVIEWED = 'reviewed';
    public const DISMISSED = 'dismissed';
    private const MAX_ENTRIES = 500;

    public function state(string $fingerprint): string
    {
        $fingerprint = self::fingerprint($fingerprint);
        if ('' === $fingerprint) {
            return '';
        }
        $all = $this->all();
        $entry = $all[$fingerprint] ?? null;
        $state = is_array($entry) && is_string($entry['state'] ?? null) ? $entry['state'] : '';
        return in_array($state, array(self::REVIEWED, self::DISMISSED), true) ? $state : '';
    }

    public function set(string $fingerprint, string $state, int $video_id, int $now): bool
    {
        $fingerprint = self::fingerprint($fingerprint);
        if ('' === $fingerprint || ! in_array($state, array(self::REVIEWED, self::DISMISSED), true) || $video_id < 1 || $now < 1) {
            return false;
        }
        $all = $this->all();
        $all[$fingerprint] = array(
            'state' => $state,
            'video_id' => $video_id,
            'updated_at' => $now,
        );
        if (count($all) > self::MAX_ENTRIES) {
            uasort($all, static fn (array $a, array $b): int => ((int) ($b['updated_at'] ?? 0)) <=> ((int) ($a['updated_at'] ?? 0)));
            $all = array_slice($all, 0, self::MAX_ENTRIES, true);
        }
        return update_option(self::OPTION, $all, false);
    }

    public function clear(string $fingerprint): bool
    {
        $fingerprint = self::fingerprint($fingerprint);
        if ('' === $fingerprint) {
            return false;
        }
        $all = $this->all();
        if (! array_key_exists($fingerprint, $all)) {
            return true;
        }
        unset($all[$fingerprint]);
        return update_option(self::OPTION, $all, false);
    }

    /** @param list<string> $active_fingerprints */
    public function prune(array $active_fingerprints): void
    {
        $keep = array_fill_keys(array_values(array_filter(array_map(array(self::class, 'fingerprint'), $active_fingerprints))), true);
        $all = $this->all();
        $next = array_intersect_key($all, $keep);
        if ($next !== $all) {
            update_option(self::OPTION, $next, false);
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function all(): array
    {
        $raw = get_option(self::OPTION, array());
        return is_array($raw) ? $raw : array();
    }

    private static function fingerprint(string $value): string
    {
        $value = strtolower(trim($value));
        return 1 === preg_match('/^[a-f0-9]{64}$/D', $value) ? $value : '';
    }
}

// EOF: includes/Overview_Disposition_Store.php
