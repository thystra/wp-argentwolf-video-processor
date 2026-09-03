<?php
/**
 * File: includes/Remote_Asset_Repository.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/**
 * Durable authority for PeerTube remote-asset identity and observed state.
 *
 * The repository is intentionally idempotent around the crash window between
 * inserting/updating the relational row and advancing the staged-upload
 * option journal. A subsequent explicit request can observe the exact row and
 * finish the journal transition without creating a duplicate remote asset.
 */
// This class is the authoritative repository for high-churn remote media state.
// Object caching would return stale reconciliation data.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
final class Remote_Asset_Repository implements PeerTube_Remote_Asset_Store
{
    public const TABLE_SUFFIX = 'argent_video_remote_assets';

    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /** @param array<string,mixed> $operation @return array{status:string,remote_asset_id:int} */
    public function commit_created(array $operation, int $now): array
    {
        if (! self::valid_remote_created_operation($operation) || $now < (int) $operation['accepted_at']) {
            return self::result(self::CONFLICT);
        }

        $existing = $this->find_by_backend_remote(
            (string) $operation['backend_id'],
            (string) $operation['remote_identity']['uuid']
        );
        if (is_array($existing)) {
            return self::matches_operation($existing, $operation)
                ? self::result(self::PRESENT, (int) $existing['id'])
                : self::result(self::CONFLICT);
        }

        global $wpdb;
        $timestamp = gmdate('Y-m-d H:i:s', $now);
        try {
            $inserted = $wpdb->insert(
                $this->table,
                array(
                    'video_post_id'           => (int) $operation['video_post_id'],
                    'backend_id'              => (string) $operation['backend_id'],
                    'channel_id'              => (string) $operation['destination_id'],
                    'remote_id'               => (string) $operation['remote_identity']['uuid'],
                    'role'                    => 'secondary',
                    'state'                   => 'processing',
                    'desired_privacy'         => 'private',
                    'actual_privacy'          => null,
                    'remote_processing_state' => 'created',
                    'remote_url'              => null,
                    'embed_url'               => null,
                    'last_synced_at'          => null,
                    'last_verified_at'        => null,
                    'error_code'              => null,
                    'error_message'           => null,
                    'created_at'              => $timestamp,
                    'updated_at'              => $timestamp,
                ),
                array('%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')
            );
        } catch (Throwable) {
            $inserted = false;
        }

        if (false !== $inserted && (int) $wpdb->insert_id > 0) {
            $row = $this->find((int) $wpdb->insert_id);
            if (is_array($row) && self::matches_operation($row, $operation)) {
                return self::result(self::APPLIED, (int) $row['id']);
            }
        }

        // A concurrent exact insert may have won the unique backend/remote key.
        // Re-read it before classifying the result as indeterminate.
        $existing = $this->find_by_backend_remote(
            (string) $operation['backend_id'],
            (string) $operation['remote_identity']['uuid']
        );
        if (is_array($existing)) {
            return self::matches_operation($existing, $operation)
                ? self::result(self::PRESENT, (int) $existing['id'])
                : self::result(self::CONFLICT);
        }

        return self::result(self::INDETERMINATE);
    }

    /**
     * @param array<string,mixed> $operation
     * @param array<string,mixed> $observation
     */
    public function record_observation(
        int $remote_asset_id,
        array $operation,
        array $observation,
        int $now
    ): string {
        if ($remote_asset_id < 1 || ! self::valid_committed_operation($operation)
            || $remote_asset_id !== (int) $operation['remote_asset_id']
            || ! self::valid_observation($observation, $operation)
            || $now < (int) $operation['accepted_at']) {
            return self::CONFLICT;
        }

        $current = $this->find($remote_asset_id);
        if (! is_array($current) || ! self::matches_operation($current, $operation)) {
            return self::CONFLICT;
        }
        if (! self::legal_state_progression((string) ($current['state'] ?? ''), (string) $observation['state'])) {
            return self::CONFLICT;
        }
        global $wpdb;
        $timestamp = gmdate('Y-m-d H:i:s', $now);
        $verified_at = true === $observation['verified'] ? $timestamp : null;
        $updated = false;
        try {
            $updated = $wpdb->update(
                $this->table,
                array(
                    'state'                   => $observation['state'],
                    'actual_privacy'          => $observation['actual_privacy'],
                    'remote_processing_state' => $observation['remote_processing_state'],
                    'embed_url'               => $observation['embed_url'],
                    'last_synced_at'          => $timestamp,
                    'last_verified_at'        => $verified_at,
                    'error_code'              => '' === $observation['error_code'] ? null : $observation['error_code'],
                    'error_message'           => null,
                    'updated_at'              => $timestamp,
                ),
                array(
                    'id'                      => $remote_asset_id,
                    'backend_id'              => (string) $operation['backend_id'],
                    'remote_id'               => (string) $operation['remote_identity']['uuid'],
                    'state'                   => $current['state'] ?? null,
                    'actual_privacy'          => $current['actual_privacy'] ?? null,
                    'remote_processing_state' => $current['remote_processing_state'] ?? null,
                    'embed_url'               => $current['embed_url'] ?? null,
                    'last_synced_at'          => $current['last_synced_at'] ?? null,
                    'last_verified_at'        => $current['last_verified_at'] ?? null,
                    'error_code'              => $current['error_code'] ?? null,
                    'updated_at'              => $current['updated_at'] ?? null,
                ),
                array('%s','%s','%s','%s','%s','%s','%s','%s','%s'),
                array('%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')
            );
        } catch (Throwable) {
            $updated = false;
        }

        $after = $this->find($remote_asset_id);
        if (is_array($after) && self::matches_operation($after, $operation)
            && self::row_matches_observation($after, $observation)) {
            return 1 === $updated ? self::APPLIED : self::PRESENT;
        }
        if (false === $updated) {
            return self::INDETERMINATE;
        }
        return self::CONFLICT;
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
                $wpdb->prepare('SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $remote_asset_id),
                ARRAY_A
            );
        } catch (Throwable) {
            return null;
        }
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function find_by_backend_remote(string $backend_id, string $remote_id): ?array
    {
        global $wpdb;
        try {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE backend_id = %s AND remote_id = %s LIMIT 1',
                    $this->table,
                    $backend_id,
                    $remote_id
                ),
                ARRAY_A
            );
        } catch (Throwable) {
            return null;
        }
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $operation */
    private static function valid_remote_created_operation(array $operation): bool
    {
        return PeerTube_Staged_Upload_State_Machine::valid($operation)
            && PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_CREATED === $operation['phase']
            && 0 === $operation['remote_asset_id'];
    }

    /** @param array<string,mixed> $operation */
    private static function valid_committed_operation(array $operation): bool
    {
        return PeerTube_Staged_Upload_State_Machine::valid($operation)
            && in_array(
                $operation['phase'],
                array(
                    PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_COMMITTED,
                    PeerTube_Staged_Upload_State_Machine::PHASE_PROCESSING,
                    PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED,
                    PeerTube_Staged_Upload_State_Machine::PHASE_FAILED,
                ),
                true
            )
            && $operation['remote_asset_id'] > 0;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $operation */
    private static function matches_operation(array $row, array $operation): bool
    {
        return (int) ($row['id'] ?? 0) > 0
            && (int) ($row['video_post_id'] ?? 0) === (int) $operation['video_post_id']
            && (string) ($row['backend_id'] ?? '') === (string) $operation['backend_id']
            && (string) ($row['channel_id'] ?? '') === (string) $operation['destination_id']
            && (string) ($row['remote_id'] ?? '') === (string) $operation['remote_identity']['uuid']
            && in_array((string) ($row['role'] ?? ''), array('secondary','primary'), true)
            && 'private' === (string) ($row['desired_privacy'] ?? '');
    }

    /** @param array<string,mixed> $observation @param array<string,mixed> $operation */
    private static function valid_observation(array $observation, array $operation): bool
    {
        if (array('state','actual_privacy','remote_processing_state','embed_url','verified','error_code') !== array_keys($observation)
            || ! is_string($observation['state'])
            || ! in_array($observation['state'], array('processing','ready','failed','missing'), true)
            || ! is_string($observation['actual_privacy'])
            || ! in_array($observation['actual_privacy'], array('', 'private'), true)
            || ! is_string($observation['remote_processing_state'])
            || strlen($observation['remote_processing_state']) > 64
            || 1 === preg_match('/[\x00-\x1F\x7F]/', $observation['remote_processing_state'])
            || ! is_string($observation['embed_url']) || strlen($observation['embed_url']) > 2048
            || ! is_bool($observation['verified'])
            || ! is_string($observation['error_code']) || strlen($observation['error_code']) > 64
            || ('' !== $observation['error_code'] && 1 !== preg_match('/^[a-z0-9._-]+$/D', $observation['error_code']))) {
            return false;
        }

        if ('ready' === $observation['state']) {
            return true === $observation['verified']
                && '' === $observation['error_code']
                && 'private' === $observation['actual_privacy']
                && '1:published' === $observation['remote_processing_state']
                && self::valid_embed_for_operation($observation['embed_url'], $operation);
        }
        if ('processing' === $observation['state']) {
            return false === $observation['verified']
                && '' === $observation['error_code']
                && 'private' === $observation['actual_privacy']
                && in_array($observation['remote_processing_state'], array('2:to_transcode','6:moving_to_external_storage','9:studio_editing'), true)
                && self::valid_embed_for_operation($observation['embed_url'], $operation);
        }
        if ('failed' === $observation['state']) {
            return false === $observation['verified']
                && 'private' === $observation['actual_privacy']
                && 'peertube.remote.processing_failed' === $observation['error_code']
                && in_array($observation['remote_processing_state'], array('7:transcoding_failed','8:external_storage_move_failed'), true)
                && self::valid_embed_for_operation($observation['embed_url'], $operation);
        }
        return false === $observation['verified']
            && '' === $observation['actual_privacy']
            && 'missing' === $observation['remote_processing_state']
            && '' === $observation['embed_url']
            && 'peertube.remote.missing' === $observation['error_code'];
    }

    /** @param array<string,mixed> $operation */
    private static function valid_embed_for_operation(string $embed_url, array $operation): bool
    {
        $origin = is_string($operation['origin'] ?? null) ? $operation['origin'] : '';
        $prefix = $origin . '/videos/embed/';
        if ('' === $origin || ! str_starts_with($embed_url, $prefix)) {
            return false;
        }
        $suffix = substr($embed_url, strlen($prefix));
        return is_string($suffix) && 1 === preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $suffix);
    }

    private static function legal_state_progression(string $before, string $after): bool
    {
        if ($before === $after) {
            return true;
        }
        return 'processing' === $before && in_array($after, array('ready','failed','missing'), true);
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $observation */
    private static function row_matches_observation(array $row, array $observation): bool
    {
        $nullable = static fn (mixed $value): string => null === $value ? '' : (string) $value;
        return (string) ($row['state'] ?? '') === $observation['state']
            && $nullable($row['actual_privacy'] ?? null) === $observation['actual_privacy']
            && $nullable($row['remote_processing_state'] ?? null) === $observation['remote_processing_state']
            && $nullable($row['embed_url'] ?? null) === $observation['embed_url']
            && $nullable($row['error_code'] ?? null) === $observation['error_code']
            && (false === $observation['verified'] || '' !== $nullable($row['last_verified_at'] ?? null));
    }

    /** @return array{status:string,remote_asset_id:int} */
    private static function result(string $status, int $remote_asset_id = 0): array
    {
        return array('status' => $status, 'remote_asset_id' => max(0, $remote_asset_id));
    }
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

// EOF: includes/Remote_Asset_Repository.php
