<?php
/**
 * File: includes/Worker_Log_Repository.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use RuntimeException;

// This class is the authoritative repository for bounded worker diagnostic history.
// Object caching is inappropriate for launch/run coordination and retention cleanup.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
final class Worker_Log_Repository
{
    public const TABLE_SUFFIX = 'argentwolf_video_processor_logs';
    public const MAX_CAPTURE_BYTES = 524288;
    public const CAPTURE_HEAD_BYTES = 65536;
    public const INCOMPLETE_GRACE_SECONDS = 120;

    private const CAPTURE_BASENAME = 'argentwolf-video-processor-worker-capture.tmp';
    private const CAPTURE_PREFIX = 'argentwolf-video-processor-worker-capture-';

    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public function create(string $trigger_source): int
    {
        global $wpdb;

        $allowed = array('automatic', 'manual', 'cli');
        if (! in_array($trigger_source, $allowed, true)) {
            $trigger_source = 'unknown';
        }

        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(
            $this->table,
            array(
                'trigger_source' => $trigger_source,
                'status'         => 'launching',
                'created_at'     => $now,
                'updated_at'     => $now,
            ),
            array('%s', '%s', '%s', '%s')
        );
        if (false === $inserted || (int) $wpdb->insert_id < 1) {
            throw new RuntimeException('Could not create the worker diagnostic record.');
        }

        return (int) $wpdb->insert_id;
    }

    public function allocate_capture(int $run_id): string
    {
        global $wpdb;

        if (! function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $path = wp_tempnam(self::CAPTURE_BASENAME);
        if ('' === $path || ! is_file($path) || ! wp_is_writable($path)) {
            throw new RuntimeException('Could not allocate the temporary worker diagnostic capture.');
        }

        $updated = $wpdb->update(
            $this->table,
            array(
                'capture_path' => $path,
                'updated_at'   => current_time('mysql', true),
            ),
            array(
                'id'     => $run_id,
                'status' => 'launching',
            ),
            array('%s', '%s'),
            array('%d', '%s')
        );
        if (false === $updated || 1 !== $updated) {
            wp_delete_file($path);
            throw new RuntimeException('Could not attach the temporary diagnostic capture to the worker record.');
        }

        return $path;
    }

    public function mark_launched(int $run_id, int $pid): void
    {
        global $wpdb;

        if ($run_id < 1 || $pid < 1) {
            return;
        }

        $wpdb->update(
            $this->table,
            array(
                'pid'        => $pid,
                'updated_at' => current_time('mysql', true),
            ),
            array(
                'id'     => $run_id,
                'status' => 'launching',
            ),
            array('%d', '%s'),
            array('%d', '%s')
        );
    }

    public function mark_running(int $run_id, int $pid): void
    {
        global $wpdb;

        $now = current_time('mysql', true);
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i
                 SET status = 'running', pid = %d, started_at = %s, updated_at = %s
                 WHERE id = %d AND status = 'launching'",
                $this->table,
                max(0, $pid),
                $now,
                $now,
                $run_id
            )
        );
        if (1 !== $updated) {
            throw new RuntimeException('Worker diagnostic record was not available for this run.');
        }
    }

    /** @param array{processed:int,failed:int,recovered:int} $result */
    public function complete(int $run_id, array $result): void
    {
        global $wpdb;

        $capture = $this->capture_snapshot($run_id);
        $now = current_time('mysql', true);
        $message = sprintf(
            'Worker complete: %d processed, %d failed, %d stale recovered.',
            (int) $result['processed'],
            (int) $result['failed'],
            (int) $result['recovered']
        );
        if ('' !== $capture['note']) {
            $message .= ' ' . $capture['note'];
        }

        $updated = $wpdb->update(
            $this->table,
            array(
                'status'            => 'complete',
                'exit_code'         => 0,
                'jobs_processed'    => max(0, (int) $result['processed']),
                'jobs_failed'       => max(0, (int) $result['failed']),
                'jobs_recovered'    => max(0, (int) $result['recovered']),
                'message'           => $this->bounded_message($message),
                'diagnostic_output' => $capture['output'],
                'capture_path'      => $capture['remaining_path'],
                'completed_at'      => $now,
                'updated_at'        => $now,
            ),
            array('id' => $run_id),
            array('%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );
        if (1 !== $updated) {
            // Preserve the temporary capture unless exactly one durable run record
            // accepted the diagnostic payload. Zero affected rows is not persistence.
            return;
        }

        $this->cleanup_persisted_capture($run_id, $capture['remaining_path']);
        $this->prune();
    }

    public function fail(int $run_id, string $message, int $exit_code = 1): void
    {
        global $wpdb;

        if ($run_id < 1) {
            return;
        }

        $capture = $this->capture_snapshot($run_id);
        if ('' !== $capture['note']) {
            $message .= ' ' . $capture['note'];
        }
        $now = current_time('mysql', true);

        $updated = $wpdb->update(
            $this->table,
            array(
                'status'            => 'failed',
                'exit_code'         => $exit_code,
                'message'           => $this->bounded_message($message),
                'diagnostic_output' => $capture['output'],
                'capture_path'      => $capture['remaining_path'],
                'completed_at'      => $now,
                'updated_at'        => $now,
            ),
            array('id' => $run_id),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );
        if (1 !== $updated) {
            // Preserve the temporary capture unless exactly one durable run record
            // accepted the diagnostic payload. Zero affected rows is not persistence.
            return;
        }

        $this->cleanup_persisted_capture($run_id, $capture['remaining_path']);
        $this->prune();
    }

    public function reconcile_incomplete(bool $worker_active): int
    {
        global $wpdb;

        $this->cleanup_finished_captures();
        if ($worker_active) {
            return 0;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - self::INCOMPLETE_GRACE_SECONDS);
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM %i
                 WHERE status IN ('launching', 'running') AND updated_at < %s
                 ORDER BY id ASC
                 LIMIT 25",
                $this->table,
                $cutoff
            )
        );
        if (! is_array($ids)) {
            return 0;
        }

        $recovered = 0;
        foreach ($ids as $id) {
            $run_id = (int) $id;
            if ($run_id < 1) {
                continue;
            }
            $this->fail(
                $run_id,
                'Recovered an incomplete detached worker run after no active worker lock was found.',
                1
            );
            $recovered++;
        }

        return $recovered;
    }

    /** @return list<array<string, mixed>> */
    public function list(int $limit = 20): array
    {
        global $wpdb;

        $limit = max(1, min(100, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, trigger_source, status, pid, exit_code,
                        jobs_processed, jobs_failed, jobs_recovered, message,
                        LEFT(diagnostic_output, 8192) AS diagnostic_excerpt,
                        started_at, completed_at, created_at
                 FROM %i
                 ORDER BY created_at DESC, id DESC
                 LIMIT %d",
                $this->table,
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    public function clear_retained(): int
    {
        global $wpdb;

        $this->cleanup_finished_captures();

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i
                 WHERE status IN ('complete', 'failed') AND capture_path IS NULL",
                $this->table
            )
        );
    }

    /** @param array<string, mixed>|null $settings */
    public function prune(?array $settings = null): void
    {
        global $wpdb;

        $settings ??= Settings::all();
        $success_limit = max(0, min(500, (int) ($settings['worker_log_success_limit'] ?? 10)));
        $error_limit = max(0, min(1000, (int) ($settings['worker_log_error_limit'] ?? 100)));

        $this->cleanup_finished_captures();

        if (0 === $error_limit) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM %i
                     WHERE (status = 'failed' OR (status = 'complete' AND jobs_failed > 0))
                       AND capture_path IS NULL",
                    $this->table
                )
            );
        } else {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM %i
                     WHERE (status = 'failed' OR (status = 'complete' AND jobs_failed > 0))
                       AND capture_path IS NULL
                       AND id NOT IN (
                           SELECT id FROM (
                               SELECT id FROM %i
                               WHERE (status = 'failed' OR (status = 'complete' AND jobs_failed > 0))
                                 AND capture_path IS NULL
                               ORDER BY created_at DESC, id DESC
                               LIMIT %d
                           ) AS argentwolf_video_processor_retained_errors
                       )",
                    $this->table,
                    $this->table,
                    $error_limit
                )
            );
        }

        if (0 === $success_limit) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM %i
                     WHERE status = 'complete' AND jobs_failed = 0 AND capture_path IS NULL",
                    $this->table
                )
            );
        } else {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM %i
                     WHERE status = 'complete' AND jobs_failed = 0
                       AND capture_path IS NULL
                       AND id NOT IN (
                           SELECT id FROM (
                               SELECT id FROM %i
                               WHERE status = 'complete' AND jobs_failed = 0
                                 AND capture_path IS NULL
                               ORDER BY created_at DESC, id DESC
                               LIMIT %d
                           ) AS argentwolf_video_processor_retained_successes
                       )",
                    $this->table,
                    $this->table,
                    $success_limit
                )
            );
        }
    }

    /** @return array{output:string,note:string,remaining_path:?string} */
    private function capture_snapshot(int $run_id): array
    {
        $row = $this->find($run_id);
        $path = is_array($row) ? (string) ($row['capture_path'] ?? '') : '';
        if ('' === $path) {
            return array('output' => '', 'note' => '', 'remaining_path' => null);
        }
        if (! is_file($path)) {
            return array(
                'output'         => '',
                'note'           => 'The temporary diagnostic capture was already absent.',
                'remaining_path' => null,
            );
        }

        $safe_path = $this->safe_capture_path($path);
        if ('' === $safe_path) {
            return array(
                'output'         => '',
                'note'           => 'The stored temporary capture path failed its safety check and was not read or deleted.',
                'remaining_path' => null,
            );
        }

        return array(
            'output'         => $this->read_capture($safe_path),
            'note'           => '',
            'remaining_path' => $safe_path,
        );
    }

    private function cleanup_persisted_capture(int $run_id, ?string $path): void
    {
        global $wpdb;

        if (null === $path || '' === $path) {
            return;
        }

        $safe_path = $this->safe_capture_path($path);
        if ('' === $safe_path) {
            $wpdb->update(
                $this->table,
                array(
                    'capture_path' => null,
                    'updated_at'   => current_time('mysql', true),
                ),
                array('id' => $run_id),
                array('%s', '%s'),
                array('%d')
            );
            return;
        }

        wp_delete_file($safe_path);
        if (is_file($safe_path)) {
            return;
        }

        $wpdb->update(
            $this->table,
            array(
                'capture_path' => null,
                'updated_at'   => current_time('mysql', true),
            ),
            array('id' => $run_id),
            array('%s', '%s'),
            array('%d')
        );
    }

    private function cleanup_finished_captures(): void
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, capture_path FROM %i
                 WHERE status IN ('complete', 'failed')
                   AND capture_path IS NOT NULL
                   AND capture_path <> ''
                 ORDER BY id ASC
                 LIMIT 25",
                $this->table
            ),
            ARRAY_A
        );
        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $run_id = (int) ($row['id'] ?? 0);
            $path = $this->safe_capture_path((string) ($row['capture_path'] ?? ''));
            if ($run_id < 1) {
                continue;
            }

            if ('' === $path) {
                $wpdb->update(
                    $this->table,
                    array(
                        'capture_path' => null,
                        'updated_at'   => current_time('mysql', true),
                    ),
                    array('id' => $run_id),
                    array('%s', '%s'),
                    array('%d')
                );
                continue;
            }

            if (is_file($path)) {
                wp_delete_file($path);
            }
            if (! is_file($path)) {
                $wpdb->update(
                    $this->table,
                    array(
                        'capture_path' => null,
                        'updated_at'   => current_time('mysql', true),
                    ),
                    array('id' => $run_id),
                    array('%s', '%s'),
                    array('%d')
                );
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function find(int $run_id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table, $run_id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function safe_capture_path(string $path): string
    {
        if ('' === $path) {
            return '';
        }

        $real_path = realpath($path);
        $real_temp = realpath(get_temp_dir());
        if (false === $real_path || false === $real_temp) {
            return '';
        }

        $normalized_path = wp_normalize_path($real_path);
        $normalized_temp = trailingslashit(wp_normalize_path($real_temp));
        if (! str_starts_with($normalized_path, $normalized_temp)) {
            return '';
        }
        if (! str_starts_with(basename($normalized_path), self::CAPTURE_PREFIX)) {
            return '';
        }

        return $normalized_path;
    }

    private function read_capture(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        $size = filesize($path);
        if (false === $size || 0 === $size) {
            return '';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded head/tail reading prevents unbounded worker diagnostics from entering memory or the database.
        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return '';
        }

        if ($size <= self::MAX_CAPTURE_BYTES) {
            $content = stream_get_contents($handle);
        } else {
            $head = fread($handle, self::CAPTURE_HEAD_BYTES);
            $marker = "\n\n[ArgentWolf Video Processor diagnostic output truncated]\n\n";
            $tail_bytes = self::MAX_CAPTURE_BYTES - self::CAPTURE_HEAD_BYTES - strlen($marker);
            fseek($handle, -$tail_bytes, SEEK_END);
            $tail = stream_get_contents($handle);
            $content = (false === $head ? '' : $head)
                . $marker
                . (false === $tail ? '' : $tail);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the bounded diagnostic stream opened above.
        fclose($handle);

        if (false === $content) {
            return '';
        }

        $content = str_replace("\0", '', $content);
        return wp_check_invalid_utf8($content, true);
    }

    private function bounded_message(string $message): string
    {
        $message = trim($message);
        return function_exists('mb_substr') ? mb_substr($message, 0, 8000) : substr($message, 0, 8000);
    }
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

// EOF: includes/Worker_Log_Repository.php
