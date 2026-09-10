<?php
/**
 * File: includes/Remote_Publication_Health_Repository.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/** Durable provider-independent public-serving health observations. */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
final class Remote_Publication_Health_Repository
{
    public const TABLE_SUFFIX = 'argent_video_publication_health';
    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const CONFLICT = 'conflict';
    public const INDETERMINATE = 'indeterminate';
    public const HEALTHY_INTERVAL = 21600; // 6 hours.
    public const FIRST_FAILURE_RETRY = 900; // 15 minutes.
    public const SECOND_FAILURE_RETRY = 2700; // 45 minutes; reaches one hour from first failure.
    public const THIRD_FAILURE_RETRY = 3600; // 1 hour; reaches two-hour email boundary.
    public const LATER_FAILURE_RETRY = 7200; // 2 hours.
    public const RECOVERY_SUCCESSES = 2;

    private string $table;
    private string $assets_table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . self::TABLE_SUFFIX;
        $this->assets_table = $wpdb->prefix . Remote_Asset_Repository::TABLE_SUFFIX;
    }

    /** @return array<string,mixed>|null */
    public function find(int $remote_asset_id): ?array
    {
        if ($remote_asset_id < 1) {
            return null;
        }
        global $wpdb;
        try {
            $row = $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM %i WHERE remote_asset_id = %d LIMIT 1', $this->table, $remote_asset_id),
                ARRAY_A
            );
        } catch (Throwable) {
            return null;
        }
        return is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function for_video(int $video_post_id): array
    {
        if ($video_post_id < 1) {
            return array();
        }
        global $wpdb;
        try {
            $rows = $wpdb->get_results(
                $wpdb->prepare('SELECT * FROM %i WHERE video_post_id = %d ORDER BY remote_asset_id ASC', $this->table, $video_post_id),
                ARRAY_A
            );
        } catch (Throwable) {
            return array();
        }
        if (! is_array($rows)) {
            return array();
        }
        $out = array();
        foreach ($rows as $row) {
            if (is_array($row) && (int) ($row['remote_asset_id'] ?? 0) > 0) {
                $out[(int) $row['remote_asset_id']] = $row;
            }
        }
        return $out;
    }

    /**
     * Select bounded verified publications due for a public-serving probe.
     *
     * @return list<array<string,mixed>>
     */
    public function due_publications(int $now, int $limit = 20): array
    {
        if ($now < 1) {
            return array();
        }
        $limit = max(1, min(100, $limit));
        global $wpdb;
        $timestamp = gmdate('Y-m-d H:i:s', $now);
        try {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT a.* FROM %i a LEFT JOIN %i h ON h.remote_asset_id = a.id
                     WHERE a.state = 'ready' AND a.last_verified_at IS NOT NULL AND a.embed_url IS NOT NULL AND a.embed_url <> ''
                       AND (h.next_check_at IS NULL OR h.next_check_at <= %s)
                     ORDER BY COALESCE(h.next_check_at, '1970-01-01 00:00:00') ASC, a.id ASC LIMIT %d",
                    $this->assets_table,
                    $this->table,
                    $timestamp,
                    $limit
                ),
                ARRAY_A
            );
        } catch (Throwable) {
            return array();
        }
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : array();
    }

    public function record(
        int $remote_asset_id,
        int $video_post_id,
        string $backend_id,
        Serving_Viability $observation,
        int $now,
        bool $initial_verified = false,
        int $next_check_override = 0
    ): string {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if ($remote_asset_id < 1 || $video_post_id < 1 || '' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id || $now < 1) {
            return self::CONFLICT;
        }
        $current = $this->find($remote_asset_id);
        if (is_array($current)
            && ($video_post_id !== (int) ($current['video_post_id'] ?? 0) || $backend_id !== (string) ($current['backend_id'] ?? ''))) {
            return self::CONFLICT;
        }
        $status = $observation->status();
        $was_eligible = is_array($current) && 1 === (int) ($current['eligible'] ?? 0);
        $previous_status = is_array($current) ? (string) ($current['status'] ?? '') : '';
        $previous_failures = is_array($current) ? max(0, (int) ($current['failure_streak'] ?? 0)) : 0;
        $previous_successes = is_array($current) ? max(0, (int) ($current['success_streak'] ?? 0)) : 0;
        $timestamp = gmdate('Y-m-d H:i:s', $now);

        if (Serving_Viability::HEALTHY === $status) {
            $recovering = is_array($current) && Serving_Viability::HEALTHY !== $previous_status;
            $successes = $recovering ? 1 : min(65535, $previous_successes + 1);
            if ($initial_verified || ! is_array($current) || $was_eligible) {
                $eligible = 1;
            } else {
                $eligible = $successes >= self::RECOVERY_SUCCESSES ? 1 : 0;
            }
            $failure_since = null;
            $failures = 0;
            $last_healthy_at = $timestamp;
            $next_check = $now + ($initial_verified ? self::FIRST_FAILURE_RETRY : ($eligible ? self::HEALTHY_INTERVAL : self::FIRST_FAILURE_RETRY));
        } elseif (Serving_Viability::PROCESSING === $status) {
            // Expected provider processing is not a broken-publication failure.
            // It is ineligible to serve until visitor-facing qualification
            // passes, but it must not start failure escalation/email timers.
            $successes = 0;
            $eligible = 0;
            $failures = 0;
            $failure_since = null;
            $last_healthy_at = is_array($current) && is_string($current['last_healthy_at'] ?? null) && '' !== $current['last_healthy_at']
                ? $current['last_healthy_at']
                : null;
            $next_check = $next_check_override > $now
                ? $next_check_override
                : $now + self::FIRST_FAILURE_RETRY;
        } else {
            $successes = 0;
            $eligible = 0;
            $failures = min(65535, $previous_failures + 1);
            $failure_since = is_array($current) && is_string($current['failure_since'] ?? null) && '' !== $current['failure_since']
                ? $current['failure_since']
                : $timestamp;
            $last_healthy_at = is_array($current) && is_string($current['last_healthy_at'] ?? null) && '' !== $current['last_healthy_at']
                ? $current['last_healthy_at']
                : null;
            $next_check = $now + match (true) {
                1 === $failures => self::FIRST_FAILURE_RETRY,
                2 === $failures => self::SECOND_FAILURE_RETRY,
                3 === $failures => self::THIRD_FAILURE_RETRY,
                default => self::LATER_FAILURE_RETRY,
            };
        }

        $value = array(
            'remote_asset_id'    => $remote_asset_id,
            'video_post_id'      => $video_post_id,
            'backend_id'         => $backend_id,
            'status'             => $status,
            'eligible'           => $eligible,
            'failure_since'      => $failure_since,
            'last_checked_at'    => $timestamp,
            'last_healthy_at'    => $last_healthy_at,
            'success_streak'     => $successes,
            'failure_streak'     => $failures,
            'http_status'        => $observation->http_status() > 0 ? $observation->http_status() : null,
            'reason_code'        => '' !== $observation->reason_code() ? $observation->reason_code() : null,
            'message'            => '' !== $observation->message() ? $observation->message() : null,
            'next_check_at'      => gmdate('Y-m-d H:i:s', $next_check),
            'updated_at'         => $timestamp,
        );

        global $wpdb;
        try {
            if (! is_array($current)) {
                $inserted = $wpdb->insert(
                    $this->table,
                    $value,
                    array('%d','%d','%s','%s','%d','%s','%s','%s','%d','%d','%d','%s','%s','%s','%s')
                );
                $written = false !== $inserted;
            } else {
                $written = false !== $wpdb->update(
                    $this->table,
                    $value,
                    array('remote_asset_id' => $remote_asset_id),
                    array('%d','%d','%s','%s','%d','%s','%s','%s','%d','%d','%d','%s','%s','%s','%s'),
                    array('%d')
                );
            }
        } catch (Throwable) {
            $written = false;
        }
        $after = $this->find($remote_asset_id);
        if (self::matches($after, $value)) {
            return $written ? self::APPLIED : self::PRESENT;
        }
        return $written ? self::CONFLICT : self::INDETERMINATE;
    }

    /** @param array<string,mixed>|null $row @param array<string,mixed> $expected */
    private static function matches(?array $row, array $expected): bool
    {
        if (! is_array($row)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            $actual = $row[$key] ?? null;
            if (is_int($value)) {
                if ($value !== (int) $actual) {
                    return false;
                }
            } elseif (null === $value) {
                if (null !== $actual && '' !== $actual) {
                    return false;
                }
            } elseif ((string) $value !== (string) $actual) {
                return false;
            }
        }
        return true;
    }
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

// EOF: includes/Remote_Publication_Health_Repository.php
