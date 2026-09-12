<?php
/**
 * File: includes/PeerTube_Event_Repository.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/**
 * Durable, bounded operator-facing PeerTube publication history.
 *
 * Callers provide only non-secret operational context. This repository applies
 * a second allow-list/redaction boundary before writing anything to the model
 * event table so bearer tokens, cookies, passwords, and request bodies cannot
 * become diagnostics accidentally.
 */
final class PeerTube_Event_Repository
{
    public const TABLE_SUFFIX = Model_Activator::EVENTS_TABLE;
    public const MAX_RECENT = 20;
    public const MAX_MESSAGE_BYTES = 1024;
    private const MAX_ACTION_BYTES = 512;
    private const MAX_CONTEXT_BYTES = 4096;

    /** @var list<string> */
    private const CONTEXT_KEYS = array(
        'remote_uuid',
        'service_status',
        'error_status',
        'error_code',
        'confirmed_bytes',
        'source_bytes',
        'request_start',
        'request_bytes',
        'phase',
        'serving_health',
        'fallback_backend',
        'operator_user_id',
    );

    /**
     * @param array<string,mixed> $context
     */
    public function record(
        int $video_id,
        int $pipeline_step,
        string $event_code,
        string $severity,
        string $message,
        int $now,
        int $task_id = 0,
        string $operation_id = '',
        int $remote_asset_id = 0,
        string $backend_id = '',
        int $http_status = 0,
        string $automatic_action = '',
        string $operator_action = '',
        array $context = array()
    ): bool {
        if ($video_id < 1 || $pipeline_step < 1 || $pipeline_step > 7 || $now < 1) {
            return false;
        }

        $event_code = self::token($event_code, 64);
        if ('' === $event_code || ! in_array($severity, array('info','warning','error'), true)) {
            return false;
        }

        $message = self::clean_text($message, self::MAX_MESSAGE_BYTES);
        if ('' === $message) {
            return false;
        }

        $operation_id = self::operation_id($operation_id);
        $backend_id = Backend_Identity::sanitize($backend_id);
        $http_status = $http_status >= 100 && $http_status <= 599 ? $http_status : 0;
        $automatic_action = self::clean_text($automatic_action, self::MAX_ACTION_BYTES);
        $operator_action = self::clean_text($operator_action, self::MAX_ACTION_BYTES);
        $context = self::context($context);
        $context_json = array() === $context ? '' : wp_json_encode($context, JSON_UNESCAPED_SLASHES);
        if (! is_string($context_json) || strlen($context_json) > self::MAX_CONTEXT_BYTES) {
            $context_json = '';
        }

        global $wpdb;
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned append-only diagnostic event table.
            $inserted = $wpdb->insert(
                $wpdb->prefix . self::TABLE_SUFFIX,
                array(
                    'video_post_id'    => $video_id,
                    'task_id'          => $task_id > 0 ? $task_id : null,
                    'operation_id'     => '' !== $operation_id ? $operation_id : null,
                    'remote_asset_id'  => $remote_asset_id > 0 ? $remote_asset_id : null,
                    'backend_id'       => '' !== $backend_id ? $backend_id : null,
                    'pipeline_step'    => $pipeline_step,
                    'event_code'       => $event_code,
                    'severity'         => $severity,
                    'http_status'      => $http_status > 0 ? $http_status : null,
                    'message'          => $message,
                    'automatic_action' => '' !== $automatic_action ? $automatic_action : null,
                    'operator_action'  => '' !== $operator_action ? $operator_action : null,
                    'context_json'     => '' !== $context_json ? $context_json : null,
                    'created_at'       => gmdate('Y-m-d H:i:s', $now),
                ),
                array('%d','%d','%s','%d','%s','%d','%s','%s','%d','%s','%s','%s','%s','%s')
            );
        } catch (Throwable) {
            return false;
        }

        return 1 === $inserted;
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $video_id, int $limit = self::MAX_RECENT): array
    {
        if ($video_id < 1) {
            return array();
        }
        $limit = max(1, min(self::MAX_RECENT, $limit));
        global $wpdb;
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded plugin-owned diagnostics history.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id,video_post_id,task_id,operation_id,remote_asset_id,backend_id,pipeline_step,event_code,severity,http_status,message,automatic_action,operator_action,context_json,created_at FROM %i WHERE video_post_id = %d ORDER BY id DESC LIMIT %d',
                    $wpdb->prefix . self::TABLE_SUFFIX,
                    $video_id,
                    $limit
                ),
                ARRAY_A
            );
        } catch (Throwable) {
            return array();
        }
        if (! is_array($rows)) {
            return array();
        }

        $result = array();
        foreach ($rows as $row) {
            $normalized = self::normalize_row($row);
            if (null !== $normalized) {
                $result[] = $normalized;
            }
        }
        return $result;
    }

    /** @return array<string,mixed>|null */
    public function latest_for_task(int $task_id): ?array
    {
        if ($task_id < 1) {
            return null;
        }
        global $wpdb;
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact task-id lookup in plugin-owned diagnostics table.
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT id,video_post_id,task_id,operation_id,remote_asset_id,backend_id,pipeline_step,event_code,severity,http_status,message,automatic_action,operator_action,context_json,created_at FROM %i WHERE task_id = %d ORDER BY id DESC LIMIT 1',
                    $wpdb->prefix . self::TABLE_SUFFIX,
                    $task_id
                ),
                ARRAY_A
            );
        } catch (Throwable) {
            return null;
        }
        return is_array($row) ? self::normalize_row($row) : null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed>|null */
    private static function normalize_row(array $row): ?array
    {
        $step = is_numeric($row['pipeline_step'] ?? null) ? (int) $row['pipeline_step'] : 0;
        $video_id = is_numeric($row['video_post_id'] ?? null) ? (int) $row['video_post_id'] : 0;
        $event_code = self::token($row['event_code'] ?? '', 64);
        $severity = is_string($row['severity'] ?? null) ? $row['severity'] : '';
        $message = self::clean_text($row['message'] ?? '', self::MAX_MESSAGE_BYTES);
        if ($video_id < 1 || $step < 1 || $step > 7 || '' === $event_code || '' === $message || ! in_array($severity,array('info','warning','error'),true)) {
            return null;
        }
        $context = array();
        if (is_string($row['context_json'] ?? null) && '' !== $row['context_json']) {
            try {
                $decoded = json_decode($row['context_json'], true, 6, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $context = self::context($decoded);
                }
            } catch (Throwable) {
                $context = array();
            }
        }
        return array(
            'id' => is_numeric($row['id'] ?? null) ? max(0,(int)$row['id']) : 0,
            'video_post_id' => $video_id,
            'task_id' => is_numeric($row['task_id'] ?? null) ? max(0,(int)$row['task_id']) : 0,
            'operation_id' => self::operation_id($row['operation_id'] ?? ''),
            'remote_asset_id' => is_numeric($row['remote_asset_id'] ?? null) ? max(0,(int)$row['remote_asset_id']) : 0,
            'backend_id' => Backend_Identity::sanitize($row['backend_id'] ?? ''),
            'pipeline_step' => $step,
            'event_code' => $event_code,
            'severity' => $severity,
            'http_status' => is_numeric($row['http_status'] ?? null) && (int)$row['http_status'] >= 100 && (int)$row['http_status'] <= 599 ? (int)$row['http_status'] : 0,
            'message' => $message,
            'automatic_action' => self::clean_text($row['automatic_action'] ?? '', self::MAX_ACTION_BYTES),
            'operator_action' => self::clean_text($row['operator_action'] ?? '', self::MAX_ACTION_BYTES),
            'context' => $context,
            'created_at' => is_string($row['created_at'] ?? null) && strlen($row['created_at']) <= 32 ? $row['created_at'] : '',
        );
    }

    /** @param array<string,mixed> $context @return array<string,int|string> */
    private static function context(array $context): array
    {
        $clean = array();
        foreach (self::CONTEXT_KEYS as $key) {
            if (! array_key_exists($key, $context)) {
                continue;
            }
            $value = $context[$key];
            if (in_array($key, array('confirmed_bytes','source_bytes','request_start','request_bytes','operator_user_id'), true)) {
                if (is_int($value) && $value >= 0) {
                    $clean[$key] = $value;
                }
                continue;
            }
            if ('remote_uuid' === $key) {
                if (is_string($value) && 1 === preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $value)) {
                    $clean[$key] = $value;
                }
                continue;
            }
            $value = self::token($value, 191);
            if ('' !== $value) {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }

    private static function operation_id(mixed $value): string
    {
        return is_string($value) && 1 === preg_match('/^upload_[a-f0-9]{32}$/D', $value) ? $value : '';
    }

    private static function token(mixed $value, int $maximum): string
    {
        if (! is_string($value) || '' === $value || strlen($value) > $maximum) {
            return '';
        }
        return 1 === preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $value) ? $value : '';
    }

    private static function clean_text(mixed $value, int $maximum): string
    {
        if (! is_string($value) || '' === $value) {
            return '';
        }
        $value = preg_replace('/Bearer\s+[^\s,;]+/i', 'Bearer [redacted]', $value) ?? '';
        $value = preg_replace('/\b(access_token|refresh_token|authorization|cookie|password)\b\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $value) ?? '';
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
        $value = trim($value);
        return strlen($value) <= $maximum ? $value : substr($value, 0, $maximum);
    }
}

// EOF: includes/PeerTube_Event_Repository.php
