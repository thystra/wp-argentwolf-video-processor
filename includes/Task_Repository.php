<?php
/**
 * File: includes/Task_Repository.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/**
 * Durable queue repository for AWVP 2.0 asynchronous tasks.
 *
 * This table is distinct from the attachment-centric 1.x Job_Repository. Task
 * rows coordinate bounded asynchronous work but are never the sole authority
 * for remote identity, publication state, or credentials.
 */
// This class is the authoritative repository for the generic 2.0 task queue.
// Object caching would return stale coordination state.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
final class Task_Repository
{
    public const TABLE_SUFFIX = 'argent_video_tasks';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_FAILED = 'failed';

    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const CONFLICT = 'conflict';
    public const INDETERMINATE = 'indeterminate';
    public const EXHAUSTED = 'exhausted';

    public const MAX_ATTEMPTS = 65535;

    private const MAX_PAYLOAD_BYTES = 16384;
    private const MAX_ERROR_BYTES = 8000;
    private const MAX_RECOVERY_BATCH = 100;
    private const MAX_CLAIM_TYPES = 32;

    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /** @return array{status:string,task_id:int} */
    public function enqueue(
        string $task_type,
        ?int $video_post_id,
        ?int $remote_asset_id,
        ?string $backend_id,
        string $idempotency_key,
        array $payload,
        int $run_after,
        int $now,
        int $priority = 100,
        int $max_attempts = 5
    ): array {
        if (
            ! self::valid_task_type($task_type)
            || ! self::valid_optional_id($video_post_id)
            || ! self::valid_optional_id($remote_asset_id)
            || ! self::valid_optional_backend_id($backend_id)
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $idempotency_key)
            || ! self::valid_payload($payload)
            || $run_after < 1
            || $now < 1
            || $priority < 0
            || $priority > 65535
            || $max_attempts < 1
            || $max_attempts > self::MAX_ATTEMPTS
        ) {
            return self::enqueue_result(self::CONFLICT);
        }

        $payload_json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (! is_string($payload_json) || strlen($payload_json) > self::MAX_PAYLOAD_BYTES) {
            return self::enqueue_result(self::CONFLICT);
        }

        $existing = $this->find_by_idempotency_key($idempotency_key);
        if (is_array($existing)) {
            return self::matches_enqueue(
                $existing,
                $task_type,
                $video_post_id,
                $remote_asset_id,
                $backend_id,
                $idempotency_key,
                $payload_json,
                $priority,
                $max_attempts
            )
                ? self::enqueue_result(self::PRESENT, (int) $existing['id'])
                : self::enqueue_result(self::CONFLICT);
        }

        global $wpdb;
        $timestamp = gmdate('Y-m-d H:i:s', $now);
        $run_at = gmdate('Y-m-d H:i:s', $run_after);
        try {
            $inserted = $wpdb->insert(
                $this->table,
                array(
                    'task_type' => $task_type,
                    'video_post_id' => $video_post_id,
                    'remote_asset_id' => $remote_asset_id,
                    'backend_id' => $backend_id,
                    'idempotency_key' => $idempotency_key,
                    'status' => self::STATUS_QUEUED,
                    'priority' => $priority,
                    'run_after' => $run_at,
                    'attempts' => 0,
                    'max_attempts' => $max_attempts,
                    'lock_token' => null,
                    'locked_at' => null,
                    'started_at' => null,
                    'completed_at' => null,
                    'payload_json' => $payload_json,
                    'error_message' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ),
                array(
                    '%s','%d','%d','%s','%s','%s','%d','%s','%d',
                    '%d','%s','%s','%s','%s','%s','%s','%s','%s',
                )
            );
        } catch (Throwable) {
            $inserted = false;
        }

        if (1 === $inserted && (int) $wpdb->insert_id > 0) {
            $row = $this->find((int) $wpdb->insert_id);
            if (is_array($row) && self::matches_enqueue(
                $row,
                $task_type,
                $video_post_id,
                $remote_asset_id,
                $backend_id,
                $idempotency_key,
                $payload_json,
                $priority,
                $max_attempts
            )) {
                return self::enqueue_result(self::APPLIED, (int) $row['id']);
            }
        }

        // A concurrent exact insert may have won the idempotency-key race.
        $existing = $this->find_by_idempotency_key($idempotency_key);
        if (is_array($existing)) {
            return self::matches_enqueue(
                $existing,
                $task_type,
                $video_post_id,
                $remote_asset_id,
                $backend_id,
                $idempotency_key,
                $payload_json,
                $priority,
                $max_attempts
            )
                ? self::enqueue_result(self::PRESENT, (int) $existing['id'])
                : self::enqueue_result(self::CONFLICT);
        }

        return self::enqueue_result(self::INDETERMINATE);
    }

    /** @return array<string,mixed>|null */
    public function claim_next(int $now): ?array
    {
        return $this->claim_next_internal(null, $now);
    }

    /**
     * Claim only one of the explicitly owned task types.
     *
     * @param list<string> $task_types
     * @return array<string,mixed>|null
     */
    public function claim_next_of_types(array $task_types, int $now): ?array
    {
        $types = self::normalized_task_types($task_types);
        if (null === $types || array() === $types) {
            return null;
        }

        return $this->claim_next_internal($types, $now);
    }

    /**
     * @param list<string>|null $task_types Null means the legacy/generic queue view.
     * @return array<string,mixed>|null
     */
    private function claim_next_internal(?array $task_types, int $now): ?array
    {
        if ($now < 1) {
            return null;
        }

        global $wpdb;
        $timestamp = gmdate('Y-m-d H:i:s', $now);
        $type_sql = '';
        $select_args = array($this->table, $timestamp);
        if (is_array($task_types)) {
            $type_sql = ' AND task_type IN (' . implode(',', array_fill(0, count($task_types), '%s')) . ')';
            array_push($select_args, ...$task_types);
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $task_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM %i
                         WHERE status = 'queued' AND run_after <= %s AND attempts < max_attempts{$type_sql}
                         ORDER BY run_after ASC, priority ASC, id ASC LIMIT 1",
                        ...$select_args
                    )
                );
            } catch (Throwable) {
                return null;
            }

            if ($task_id < 1) {
                return null;
            }

            $current = $this->find($task_id);
            if (! is_array($current) || self::STATUS_QUEUED !== ($current['status'] ?? null)) {
                continue;
            }
            if (is_array($task_types) && ! in_array((string) ($current['task_type'] ?? ''), $task_types, true)) {
                continue;
            }

            $token = wp_generate_uuid4();
            if (! self::valid_lock_token($token)) {
                return null;
            }

            $started_at = self::nullable_string($current['started_at'] ?? null);
            if (null === $started_at) {
                $started_at = $timestamp;
            }

            try {
                $updated = $wpdb->update(
                    $this->table,
                    array(
                        'status' => self::STATUS_PROCESSING,
                        'lock_token' => $token,
                        'locked_at' => $timestamp,
                        'started_at' => $started_at,
                        'attempts' => ((int) ($current['attempts'] ?? 0)) + 1,
                        'error_message' => null,
                        'updated_at' => $timestamp,
                    ),
                    array(
                        'id' => $task_id,
                        'task_type' => (string) ($current['task_type'] ?? ''),
                        'status' => self::STATUS_QUEUED,
                        'lock_token' => null,
                    ),
                    array('%s','%s','%s','%s','%d','%s','%s'),
                    array('%d','%s','%s','%s')
                );
            } catch (Throwable) {
                return null;
            }

            if (1 === $updated) {
                $claimed = $this->find($task_id);
                if (
                    is_array($claimed)
                    && self::STATUS_PROCESSING === ($claimed['status'] ?? null)
                    && hash_equals($token, (string) ($claimed['lock_token'] ?? ''))
                    && (! is_array($task_types) || in_array((string) ($claimed['task_type'] ?? ''), $task_types, true))
                ) {
                    return $claimed;
                }
                return null;
            }
        }

        return null;
    }

    public function complete(int $task_id, string $lock_token, int $now): string
    {
        if ($task_id < 1 || ! self::valid_lock_token($lock_token) || $now < 1) {
            return self::CONFLICT;
        }

        $timestamp = gmdate('Y-m-d H:i:s', $now);
        return $this->locked_update(
            $task_id,
            $lock_token,
            array(
                'status' => self::STATUS_COMPLETE,
                'lock_token' => null,
                'locked_at' => null,
                'completed_at' => $timestamp,
                'error_message' => null,
                'updated_at' => $timestamp,
            ),
            array('%s','%s','%s','%s','%s','%s')
        );
    }

    public function fail(int $task_id, string $lock_token, string $message, int $now): string
    {
        if ($task_id < 1 || ! self::valid_lock_token($lock_token) || $now < 1) {
            return self::CONFLICT;
        }

        $timestamp = gmdate('Y-m-d H:i:s', $now);
        return $this->locked_update(
            $task_id,
            $lock_token,
            array(
                'status' => self::STATUS_FAILED,
                'lock_token' => null,
                'locked_at' => null,
                'completed_at' => $timestamp,
                'error_message' => self::bounded_error($message),
                'updated_at' => $timestamp,
            ),
            array('%s','%s','%s','%s','%s','%s')
        );
    }

    public function reschedule(
        int $task_id,
        string $lock_token,
        int $run_after,
        string $message,
        int $now
    ): string {
        if (
            $task_id < 1
            || ! self::valid_lock_token($lock_token)
            || $run_after < $now
            || $now < 1
        ) {
            return self::CONFLICT;
        }

        $current = $this->find($task_id);
        if (
            ! is_array($current)
            || self::STATUS_PROCESSING !== ($current['status'] ?? null)
            || ! hash_equals($lock_token, (string) ($current['lock_token'] ?? ''))
        ) {
            return self::CONFLICT;
        }

        if ((int) ($current['attempts'] ?? 0) >= (int) ($current['max_attempts'] ?? 0)) {
            $result = $this->fail($task_id, $lock_token, 'Task attempt limit reached.', $now);
            return self::APPLIED === $result ? self::EXHAUSTED : $result;
        }

        $timestamp = gmdate('Y-m-d H:i:s', $now);
        return $this->locked_update(
            $task_id,
            $lock_token,
            array(
                'status' => self::STATUS_QUEUED,
                'run_after' => gmdate('Y-m-d H:i:s', $run_after),
                'lock_token' => null,
                'locked_at' => null,
                'completed_at' => null,
                'error_message' => self::bounded_error($message),
                'updated_at' => $timestamp,
            ),
            array('%s','%s','%s','%s','%s','%s','%s')
        );
    }

    public function recover_stale(int $stale_before, int $now, int $limit = self::MAX_RECOVERY_BATCH): int
    {
        return $this->recover_stale_internal(null, $stale_before, $now, $limit);
    }

    /**
     * Recover stale locks only for explicitly owned task types.
     *
     * @param list<string> $task_types
     */
    public function recover_stale_of_types(
        array $task_types,
        int $stale_before,
        int $now,
        int $limit = self::MAX_RECOVERY_BATCH
    ): int {
        $types = self::normalized_task_types($task_types);
        if (null === $types || array() === $types) {
            return 0;
        }

        return $this->recover_stale_internal($types, $stale_before, $now, $limit);
    }

    /** @param list<string>|null $task_types */
    private function recover_stale_internal(
        ?array $task_types,
        int $stale_before,
        int $now,
        int $limit
    ): int {
        if ($stale_before < 1 || $now < 1 || $stale_before > $now) {
            return 0;
        }

        global $wpdb;
        $limit = max(1, min(self::MAX_RECOVERY_BATCH, $limit));
        $type_sql = '';
        $select_args = array($this->table, gmdate('Y-m-d H:i:s', $stale_before));
        if (is_array($task_types)) {
            $type_sql = ' AND task_type IN (' . implode(',', array_fill(0, count($task_types), '%s')) . ')';
            array_push($select_args, ...$task_types);
        }
        $select_args[] = $limit;

        try {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i
                     WHERE status = 'processing' AND locked_at < %s{$type_sql}
                     ORDER BY locked_at ASC, id ASC LIMIT %d",
                    ...$select_args
                ),
                ARRAY_A
            );
        } catch (Throwable) {
            return 0;
        }

        if (! is_array($rows)) {
            return 0;
        }

        $recovered = 0;
        foreach ($rows as $row) {
            $task_id = (int) ($row['id'] ?? 0);
            $token = (string) ($row['lock_token'] ?? '');
            $task_type = (string) ($row['task_type'] ?? '');
            if (
                $task_id < 1
                || ! self::valid_lock_token($token)
                || (is_array($task_types) && ! in_array($task_type, $task_types, true))
            ) {
                continue;
            }

            $result = $this->reschedule(
                $task_id,
                $token,
                $now,
                'Recovered after a stale task lock.',
                $now
            );
            if (in_array($result, array(self::APPLIED, self::EXHAUSTED), true)) {
                $recovered++;
            }
        }

        return $recovered;
    }

    /** @return array<string,mixed>|null */
    public function find(int $task_id): ?array
    {
        if ($task_id < 1) {
            return null;
        }

        global $wpdb;
        try {
            $row = $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $task_id),
                ARRAY_A
            );
        } catch (Throwable) {
            return null;
        }

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function find_by_idempotency_key(string $idempotency_key): ?array
    {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/D', $idempotency_key)) {
            return null;
        }

        global $wpdb;
        try {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE idempotency_key = %s LIMIT 1',
                    $this->table,
                    $idempotency_key
                ),
                ARRAY_A
            );
        } catch (Throwable) {
            return null;
        }

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $data @param list<string> $formats */
    private function locked_update(
        int $task_id,
        string $lock_token,
        array $data,
        array $formats
    ): string {
        global $wpdb;
        try {
            $updated = $wpdb->update(
                $this->table,
                $data,
                array(
                    'id' => $task_id,
                    'status' => self::STATUS_PROCESSING,
                    'lock_token' => $lock_token,
                ),
                $formats,
                array('%d','%s','%s')
            );
        } catch (Throwable) {
            return self::INDETERMINATE;
        }

        if (1 === $updated) {
            return self::APPLIED;
        }
        if (false === $updated) {
            return self::INDETERMINATE;
        }
        return self::CONFLICT;
    }

    /**
     * @param array<int,mixed> $task_types
     * @return list<string>|null
     */
    private static function normalized_task_types(array $task_types): ?array
    {
        if (count($task_types) < 1 || count($task_types) > self::MAX_CLAIM_TYPES) {
            return null;
        }

        $types = array();
        foreach ($task_types as $task_type) {
            if (! is_string($task_type) || ! self::valid_task_type($task_type)) {
                return null;
            }
            $types[$task_type] = true;
        }

        $normalized = array_keys($types);
        sort($normalized, SORT_STRING);
        return $normalized;
    }

    private static function valid_task_type(string $task_type): bool
    {
        return 1 === preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/D', $task_type);
    }

    private static function valid_optional_id(?int $value): bool
    {
        return null === $value || $value > 0;
    }

    private static function valid_optional_backend_id(?string $backend_id): bool
    {
        return null === $backend_id
            || 1 === preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $backend_id);
    }

    private static function valid_lock_token(string $token): bool
    {
        return 1 === preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            $token
        );
    }

    /** @param array<string|int,mixed> $payload */
    private static function valid_payload(array $payload, int $depth = 0): bool
    {
        if ($depth > 8 || count($payload) > 64) {
            return false;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                if (strlen($key) > 64 || str_contains($key, "\0")) {
                    return false;
                }
                $normalized = strtolower(str_replace('-', '_', $key));
                $collapsed = str_replace('_', '', $normalized);
                if (in_array(
                    $collapsed,
                    array('password','passphrase','accesstoken','refreshtoken','clientsecret','authorization','cookie','otp','nonce','bearer'),
                    true
                )) {
                    return false;
                }
            }

            if (is_array($value)) {
                if (! self::valid_payload($value, $depth + 1)) {
                    return false;
                }
                continue;
            }
            if (is_string($value)) {
                if (strlen($value) > 4096 || str_contains($value, "\0")) {
                    return false;
                }
                continue;
            }
            if (! is_int($value) && ! is_float($value) && ! is_bool($value) && null !== $value) {
                return false;
            }
        }

        return true;
    }

    private static function bounded_error(string $message): string
    {
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $message) ?? '';
        $message = preg_replace('/\b(Bearer|Basic)\s+[^\s]+/i', '$1 [redacted]', $message) ?? '';
        $message = preg_replace(
            '/\b(access_token|refresh_token|client_secret|password|otp)=([^\s&]+)/i',
            '$1=[redacted]',
            $message
        ) ?? '';
        return function_exists('mb_substr')
            ? mb_substr($message, 0, self::MAX_ERROR_BYTES)
            : substr($message, 0, self::MAX_ERROR_BYTES);
    }

    private static function nullable_string(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function matches_enqueue(
        array $row,
        string $task_type,
        ?int $video_post_id,
        ?int $remote_asset_id,
        ?string $backend_id,
        string $idempotency_key,
        string $payload_json,
        int $priority,
        int $max_attempts
    ): bool {
        return $task_type === ($row['task_type'] ?? null)
            && $video_post_id === self::nullable_positive_int($row['video_post_id'] ?? null)
            && $remote_asset_id === self::nullable_positive_int($row['remote_asset_id'] ?? null)
            && $backend_id === self::nullable_string($row['backend_id'] ?? null)
            && $idempotency_key === ($row['idempotency_key'] ?? null)
            && $priority === (int) ($row['priority'] ?? -1)
            && $max_attempts === (int) ($row['max_attempts'] ?? -1)
            && $payload_json === ($row['payload_json'] ?? null);
    }

    private static function nullable_positive_int(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    /** @return array{status:string,task_id:int} */
    private static function enqueue_result(string $status, int $task_id = 0): array
    {
        return array('status' => $status, 'task_id' => max(0, $task_id));
    }
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

// EOF: includes/Task_Repository.php
