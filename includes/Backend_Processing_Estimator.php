<?php
/**
 * File: includes/Backend_Processing_Estimator.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Bounded advisory processing-readiness estimator per backend.
 *
 * This is deliberately not publication authority. It keeps only recent,
 * non-secret turnaround observations and provides a rough ETA used for retry
 * cadence/operator messaging while the public serving URL is not ready yet.
 */
final class Backend_Processing_Estimator
{
    public const OPTION = 'argent_video_processor_backend_processing_history';
    public const VERSION = 1;
    public const MAX_SAMPLES = 10;
    public const MAX_SAMPLE_AGE = 7776000; // 90 days.
    public const MAX_TURNAROUND_SECONDS = 604800; // 7 days.
    public const MIN_PROBE_DELAY = 60;
    public const MAX_PROBE_DELAY = 900;

    private const MIB = 1048576;

    /**
     * Record one completed upload-accepted -> remotely ready observation.
     *
     * The interval deliberately ends at the durable ready-verification point,
     * not at eventual public cutover. Finalizer/operator delay is therefore not
     * allowed to contaminate backend processing estimates. Metrics are advisory
     * only; losing a concurrent metrics update cannot affect publication,
     * serving authority, or retention safety.
     */
    public function observe(string $backend_id, int $source_bytes, int $turnaround_seconds, int $observed_at): bool
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id
            || $source_bytes < 1 || $turnaround_seconds < 1
            || $turnaround_seconds > self::MAX_TURNAROUND_SECONDS || $observed_at < 1) {
            return false;
        }

        $history = $this->history($observed_at);
        $samples = $history[$backend_id] ?? array();
        $samples[] = array(
            'bytes'       => $source_bytes,
            'seconds'     => $turnaround_seconds,
            'observed_at' => $observed_at,
        );
        usort($samples, static fn (array $a, array $b): int => $a['observed_at'] <=> $b['observed_at']);
        if (count($samples) > self::MAX_SAMPLES) {
            $samples = array_slice($samples, -self::MAX_SAMPLES);
        }
        $history[$backend_id] = $samples;
        ksort($history);

        $value = array('version' => self::VERSION, 'backends' => $history);
        update_option(self::OPTION, $value, false);
        return $value === $this->raw_value($observed_at);
    }

    /**
     * @return array{
     *   seconds:int,
     *   estimated_ready_at:int,
     *   confidence:string,
     *   basis:string,
     *   sample_count:int,
     *   same_bucket_count:int
     * }
     */
    public function estimate(string $backend_id, int $source_bytes, int $processing_started_at, int $now): array
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id || $source_bytes < 1 || $processing_started_at < 1 || $now < $processing_started_at) {
            return self::empty_estimate();
        }

        $samples = $this->history($now)[$backend_id] ?? array();
        $bucket = self::size_bucket($source_bytes);
        $same = array_values(array_filter($samples, static fn (array $sample): bool => self::size_bucket((int) $sample['bytes']) === $bucket));
        $basis_samples = array() !== $same ? $same : self::nearest_samples($samples, $source_bytes, 5);
        $basis = array() !== $same ? 'recent_same_size_band' : (array() !== $basis_samples ? 'recent_nearest_size' : 'size_fallback');

        if (array() === $basis_samples) {
            $seconds = self::fallback_seconds($source_bytes);
        } else {
            $projected = array();
            foreach ($basis_samples as $sample) {
                $sample_bytes = max(1, (int) $sample['bytes']);
                $sample_seconds = max(1, (int) $sample['seconds']);
                // Size is an imperfect proxy for duration/codec complexity. A
                // sub-linear projection avoids pretending that a 5 GB file is
                // exactly 500x slower than a 10 MB file while still scaling the
                // estimate materially with source size.
                $ratio = $source_bytes / $sample_bytes;
                $projected[] = (int) round($sample_seconds * pow($ratio, 0.80));
            }
            sort($projected, SORT_NUMERIC);
            $seconds = self::median_int($projected);
            $fallback = self::fallback_seconds($source_bytes);
            // Historical evidence leads, but bound gross extrapolation when
            // the recent set has no similarly-sized observations.
            if (array() === $same) {
                $seconds = max((int) floor($fallback / 3), min($fallback * 3, $seconds));
            }
        }

        $seconds = max(60, min(self::MAX_TURNAROUND_SECONDS, $seconds));
        $same_count = count($same);
        $sample_count = count($samples);
        $confidence = match (true) {
            $same_count >= 5 => 'high',
            $same_count >= 2 || $sample_count >= 4 => 'medium',
            default => 'low',
        };

        return array(
            'seconds'             => $seconds,
            'estimated_ready_at'  => $processing_started_at + $seconds,
            'confidence'          => $confidence,
            'basis'               => $basis,
            'sample_count'        => $sample_count,
            'same_bucket_count'   => $same_count,
        );
    }


    /**
     * Reset advisory readiness history for one backend or for every backend.
     * This intentionally does not touch task/event/upload journals or serving
     * authority. The estimator is operational telemetry, not audit evidence.
     */
    public function reset(?string $backend_id, int $now): bool
    {
        if ($now < 1) {
            return false;
        }
        if (null === $backend_id || '' === $backend_id) {
            delete_option(self::OPTION);
            $stored = get_option(self::OPTION, null);
            return null === $stored || false === $stored;
        }

        $backend_id = Backend_Identity::sanitize($backend_id);
        if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id) {
            return false;
        }
        $history = $this->history($now);
        unset($history[$backend_id]);
        if (array() === $history) {
            delete_option(self::OPTION);
            $stored = get_option(self::OPTION, null);
            return null === $stored || false === $stored;
        }
        ksort($history);
        $value = array('version' => self::VERSION, 'backends' => $history);
        update_option(self::OPTION, $value, false);
        return $value === $this->raw_value($now);
    }

    /**
     * Human-facing size bands backed by the exact estimator bucket boundaries.
     *
     * @return list<array{bucket:int,min_bytes:int,max_bytes:int,representative_bytes:int}>
     */
    public static function size_bands(): array
    {
        return array(
            array('bucket' => 1, 'min_bytes' => 1, 'max_bytes' => 64 * self::MIB, 'representative_bytes' => 32 * self::MIB),
            array('bucket' => 2, 'min_bytes' => 64 * self::MIB + 1, 'max_bytes' => 256 * self::MIB, 'representative_bytes' => 128 * self::MIB),
            array('bucket' => 3, 'min_bytes' => 256 * self::MIB + 1, 'max_bytes' => 1024 * self::MIB, 'representative_bytes' => 512 * self::MIB),
            array('bucket' => 4, 'min_bytes' => 1024 * self::MIB + 1, 'max_bytes' => 4096 * self::MIB, 'representative_bytes' => 2048 * self::MIB),
            array('bucket' => 5, 'min_bytes' => 4096 * self::MIB + 1, 'max_bytes' => PHP_INT_MAX, 'representative_bytes' => 8192 * self::MIB),
        );
    }

    /**
     * Return one display-ready estimate summary per exact size bucket.
     *
     * @return list<array{bucket:int,min_bytes:int,max_bytes:int,representative_bytes:int,seconds:int,confidence:string,basis:string,sample_count:int,same_bucket_count:int}>
     */
    public function summaries(string $backend_id, int $now): array
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id || $now < 1) {
            return array();
        }
        $out = array();
        foreach (self::size_bands() as $band) {
            $estimate = $this->estimate($backend_id, $band['representative_bytes'], $now, $now);
            $out[] = array_merge($band, array(
                'seconds' => (int) $estimate['seconds'],
                'confidence' => (string) $estimate['confidence'],
                'basis' => (string) $estimate['basis'],
                'sample_count' => (int) $estimate['sample_count'],
                'same_bucket_count' => (int) $estimate['same_bucket_count'],
            ));
        }
        return $out;
    }

    public static function next_probe_delay(int $estimated_ready_at, int $now): int
    {
        if ($now < 1 || $estimated_ready_at < 1) {
            return self::MIN_PROBE_DELAY;
        }
        $remaining = $estimated_ready_at - $now;
        if ($remaining <= 120) {
            return self::MIN_PROBE_DELAY;
        }
        if ($remaining <= 600) {
            return 120;
        }
        if ($remaining <= 1800) {
            return 300;
        }
        return max(self::MIN_PROBE_DELAY, min(self::MAX_PROBE_DELAY, (int) floor($remaining / 4)));
    }

    public static function unusually_long(int $estimated_ready_at, int $processing_started_at, int $now): bool
    {
        if ($estimated_ready_at < 1 || $processing_started_at < 1 || $now < $processing_started_at) {
            return false;
        }
        $estimated = max(60, $estimated_ready_at - $processing_started_at);
        $threshold = $processing_started_at + max($estimated * 2, $estimated + 1800);
        return $now >= $threshold;
    }

    /** @return array<string,list<array{bytes:int,seconds:int,observed_at:int}>> */
    public function history(int $now): array
    {
        $value = $this->raw_value($now);
        return is_array($value) ? $value['backends'] : array();
    }

    /** @return array{version:int,backends:array<string,list<array{bytes:int,seconds:int,observed_at:int}>>}|null */
    private function raw_value(int $now): ?array
    {
        $stored = get_option(self::OPTION, null);
        if (! is_array($stored) || self::VERSION !== ($stored['version'] ?? null) || ! is_array($stored['backends'] ?? null)) {
            return array('version' => self::VERSION, 'backends' => array());
        }
        $cutoff = max(0, $now - self::MAX_SAMPLE_AGE);
        $out = array();
        foreach ($stored['backends'] as $backend_id => $samples) {
            if (! is_string($backend_id) || $backend_id !== Backend_Identity::sanitize($backend_id)
                || Backend_Registry::LOCAL_ID === $backend_id || ! is_array($samples)) {
                continue;
            }
            $clean = array();
            foreach ($samples as $sample) {
                if (! is_array($sample) || array('bytes','seconds','observed_at') !== array_keys($sample)
                    || ! is_int($sample['bytes']) || $sample['bytes'] < 1
                    || ! is_int($sample['seconds']) || $sample['seconds'] < 1 || $sample['seconds'] > self::MAX_TURNAROUND_SECONDS
                    || ! is_int($sample['observed_at']) || $sample['observed_at'] < $cutoff || $sample['observed_at'] > $now) {
                    continue;
                }
                $clean[] = $sample;
            }
            usort($clean, static fn (array $a, array $b): int => $a['observed_at'] <=> $b['observed_at']);
            if (count($clean) > self::MAX_SAMPLES) {
                $clean = array_slice($clean, -self::MAX_SAMPLES);
            }
            if (array() !== $clean) {
                $out[$backend_id] = $clean;
            }
        }
        ksort($out);
        return array('version' => self::VERSION, 'backends' => $out);
    }

    /** @param list<array{bytes:int,seconds:int,observed_at:int}> $samples @return list<array{bytes:int,seconds:int,observed_at:int}> */
    private static function nearest_samples(array $samples, int $source_bytes, int $limit): array
    {
        usort($samples, static function (array $a, array $b) use ($source_bytes): int {
            $distance_a = abs(log(max(1, (int) $a['bytes']) / $source_bytes, 2));
            $distance_b = abs(log(max(1, (int) $b['bytes']) / $source_bytes, 2));
            if ($distance_a === $distance_b) {
                return ((int) $b['observed_at']) <=> ((int) $a['observed_at']);
            }
            return $distance_a <=> $distance_b;
        });
        return array_slice($samples, 0, max(1, $limit));
    }

    public static function size_bucket(int $bytes): int
    {
        return match (true) {
            $bytes <= 64 * self::MIB => 1,
            $bytes <= 256 * self::MIB => 2,
            $bytes <= 1024 * self::MIB => 3,
            $bytes <= 4096 * self::MIB => 4,
            default => 5,
        };
    }

    private static function fallback_seconds(int $bytes): int
    {
        return match (true) {
            $bytes <= 64 * self::MIB => 300,
            $bytes <= 256 * self::MIB => 900,
            $bytes <= 1024 * self::MIB => 2700,
            $bytes <= 4096 * self::MIB => 7200,
            default => 14400,
        };
    }

    /** @param list<int> $values */
    private static function median_int(array $values): int
    {
        if (array() === $values) {
            return 0;
        }
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);
        return 1 === $count % 2
            ? (int) $values[$middle]
            : (int) round(((int) $values[$middle - 1] + (int) $values[$middle]) / 2);
    }

    /** @return array{seconds:int,estimated_ready_at:int,confidence:string,basis:string,sample_count:int,same_bucket_count:int} */
    private static function empty_estimate(): array
    {
        return array(
            'seconds' => 0,
            'estimated_ready_at' => 0,
            'confidence' => 'low',
            'basis' => 'unavailable',
            'sample_count' => 0,
            'same_bucket_count' => 0,
        );
    }
}

// EOF: includes/Backend_Processing_Estimator.php
