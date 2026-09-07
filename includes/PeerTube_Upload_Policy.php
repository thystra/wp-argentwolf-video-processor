<?php
/**
 * File: includes/PeerTube_Upload_Policy.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Backend-scoped PeerTube upload segmentation policy.
 *
 * The policy controls transport segmentation only. It does not authorize an
 * upload, retry an uncertain request, or alter the staged-upload state
 * machine. A value of zero means "one segment containing all remaining
 * bytes"; it does not select PeerTube's separate non-resumable endpoint.
 */
final class PeerTube_Upload_Policy
{
    public const DEFAULT_CHUNK_MIB = 128;
    public const MAX_CHUNK_MIB = 8192;

    private const MIB = 1048576;

    public static function chunk_mib(mixed $value): ?int
    {
        if (is_int($value)) {
            $chunk_mib = $value;
        } elseif (is_string($value) && 1 === preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value)) {
            $chunk_mib = (int) $value;
            if ((string) $chunk_mib !== $value) {
                return null;
            }
        } else {
            return null;
        }

        return $chunk_mib >= 0 && $chunk_mib <= self::MAX_CHUNK_MIB
            ? $chunk_mib
            : null;
    }

    /**
     * Calculate the next request size without materializing source bytes.
     *
     * Zero means the complete remaining source. Nonzero values are converted
     * from MiB with an explicit integer-overflow guard and then bounded by the
     * remaining source length.
     */
    public static function bytes_for_remaining(int $chunk_mib, int $remaining): int
    {
        if ($remaining < 1 || null === self::chunk_mib($chunk_mib)) {
            return 0;
        }
        if (0 === $chunk_mib) {
            return $remaining;
        }
        if ($chunk_mib > intdiv(PHP_INT_MAX, self::MIB)) {
            return 0;
        }

        return min($remaining, $chunk_mib * self::MIB);
    }
}

// EOF: includes/PeerTube_Upload_Policy.php
