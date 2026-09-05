<?php
/**
 * File: includes/PeerTube_Upload_Runtime_Budget.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Conservative wall-clock guards for streamed PeerTube upload work.
 *
 * Budgets are intentionally generous: one minute per 128 MiB, with a one-hour
 * floor and six-hour ceiling. The worker only observes the process budget at a
 * durable request boundary; it never interrupts a byte-bearing request in
 * flight. Individual streamed requests use the same size-derived timeout.
 */
final class PeerTube_Upload_Runtime_Budget
{
    public const BASE_BYTES = 134217728; // 128 MiB.
    public const SECONDS_PER_BASE = 60;
    public const MIN_SECONDS = 3600;
    public const MAX_SECONDS = 21600;

    public static function process_seconds(int $source_bytes): int
    {
        return self::seconds_for_bytes($source_bytes);
    }

    public static function request_seconds(int $segment_bytes): int
    {
        return self::seconds_for_bytes($segment_bytes);
    }

    private static function seconds_for_bytes(int $bytes): int
    {
        if ($bytes < 1) {
            return self::MIN_SECONDS;
        }

        $units = intdiv($bytes - 1, self::BASE_BYTES) + 1;
        if ($units >= intdiv(self::MAX_SECONDS, self::SECONDS_PER_BASE)) {
            return self::MAX_SECONDS;
        }

        $seconds = $units * self::SECONDS_PER_BASE;
        return max(self::MIN_SECONDS, min(self::MAX_SECONDS, $seconds));
    }
}

// EOF: includes/PeerTube_Upload_Runtime_Budget.php
