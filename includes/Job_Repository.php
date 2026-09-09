<?php
/**
 * File: includes/Job_Repository.php
 */

declare(strict_types=1);

namespace ArgentVideo;

// This class is the authoritative repository for a dedicated, highly mutable
// worker queue table. Object caching would return stale coordination state.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
final class Job_Repository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'argent_video_jobs';
    }

    /** @return array<string, mixed>|null */
    public function find_by_attachment(int $attachment_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE attachment_id = %d', $this->table, $attachment_id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function find(int $job_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table, $job_id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function enqueue(
        int $attachment_id,
        string $source_path,
        string $signature,
        string $profile,
        bool $force = false
    ): int {
        global $wpdb;

        $existing = $this->find_by_attachment($attachment_id);
        $now = current_time('mysql', true);

        if (
            ! $force
            && is_array($existing)
            && 'complete' === ($existing['status'] ?? '')
            && hash_equals((string) $existing['source_signature'], $signature)
            && hash_equals((string) $existing['profile'], $profile)
        ) {
            return (int) $existing['id'];
        }

        if (is_array($existing)) {
            $wpdb->update(
                $this->table,
                array(
                    'source_path'      => $source_path,
                    'source_signature' => $signature,
                    'profile'          => $profile,
                    'status'           => 'queued',
                    'lock_token'       => null,
                    'locked_at'        => null,
                    'started_at'       => null,
                    'completed_at'     => null,
                    'error_message'    => null,
                    'updated_at'       => $now,
                ),
                array('id' => (int) $existing['id']),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'),
                array('%d')
            );
            return (int) $existing['id'];
        }

        $wpdb->insert(
            $this->table,
            array(
                'attachment_id'    => $attachment_id,
                'source_path'      => $source_path,
                'source_signature' => $signature,
                'profile'          => $profile,
                'status'           => 'queued',
                'attempts'         => 0,
                'created_at'       => $now,
                'updated_at'       => $now,
            ),
            array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        return (int) $wpdb->insert_id;
    }

    /** @return array<string, mixed>|null */
    public function claim_next(): ?array
    {
        global $wpdb;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $job_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM %i WHERE status = 'queued' ORDER BY created_at ASC, id ASC LIMIT 1",
                    $this->table
                )
            );

            if ($job_id < 1) {
                return null;
            }

            $token = wp_generate_uuid4();
            $now = current_time('mysql', true);
            $updated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE %i
                     SET status = 'processing', lock_token = %s, locked_at = %s,
                         started_at = %s, attempts = attempts + 1, updated_at = %s
                     WHERE id = %d AND status = 'queued'",
                    $this->table,
                    $token,
                    $now,
                    $now,
                    $now,
                    $job_id
                )
            );

            if (1 === $updated) {
                return $this->find($job_id);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $outputs */
    public function complete(int $job_id, array $outputs): void
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->update(
            $this->table,
            array(
                'status'        => 'complete',
                'lock_token'    => null,
                'locked_at'     => null,
                'completed_at'  => $now,
                'output_json'   => wp_json_encode($outputs, JSON_UNESCAPED_SLASHES),
                'error_message' => null,
                'updated_at'    => $now,
            ),
            array('id' => $job_id),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );
    }

    public function fail(int $job_id, string $message): void
    {
        global $wpdb;
        $wpdb->update(
            $this->table,
            array(
                'status'        => 'failed',
                'lock_token'    => null,
                'locked_at'     => null,
                'error_message' => function_exists('mb_substr') ? mb_substr($message, 0, 8000) : substr($message, 0, 8000),
                'updated_at'    => current_time('mysql', true),
            ),
            array('id' => $job_id),
            array('%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );
    }

    public function cancel(int $attachment_id): bool
    {
        global $wpdb;
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i
                 SET status = 'cancelled', updated_at = %s
                 WHERE attachment_id = %d AND status IN ('queued', 'failed')",
                $this->table,
                current_time('mysql', true),
                $attachment_id
            )
        );

        return 1 === $updated;
    }

    public function cancel_claimed(int $job_id, string $lock_token): bool
    {
        if ($job_id < 1 || 1 !== preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $lock_token)) {
            return false;
        }

        global $wpdb;
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i
                 SET status = 'cancelled', lock_token = NULL, locked_at = NULL,
                     completed_at = %s, error_message = NULL, updated_at = %s
                 WHERE id = %d AND status = 'processing' AND lock_token = %s",
                $this->table,
                current_time('mysql', true),
                current_time('mysql', true),
                $job_id,
                $lock_token
            )
        );

        return 1 === $updated;
    }

    public function delete_by_attachment(int $attachment_id): void
    {
        global $wpdb;
        $wpdb->delete($this->table, array('attachment_id' => $attachment_id), array('%d'));
    }

    public function recover_stale(int $minutes): int
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($minutes * MINUTE_IN_SECONDS));

        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i
                 SET status = 'queued', lock_token = NULL, locked_at = NULL,
                     error_message = %s, updated_at = %s
                 WHERE status = 'processing' AND locked_at < %s",
                $this->table,
                'Recovered after a stale worker lock.',
                current_time('mysql', true),
                $cutoff
            )
        );
    }

    public function count(string $status = ''): int
    {
        global $wpdb;
        if ('' === $status) {
            return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $this->table));
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE status = %s', $this->table, $status)
        );
    }

    /** @return list<array<string, mixed>> */
    public function list(int $limit = 50, string $status = ''): array
    {
        global $wpdb;
        $limit = max(1, min(500, $limit));

        if ('' !== $status) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE status = %s ORDER BY updated_at DESC LIMIT %d",
                    $this->table,
                    $status,
                    $limit
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare('SELECT * FROM %i ORDER BY updated_at DESC LIMIT %d', $this->table, $limit),
                ARRAY_A
            );
        }

        return is_array($rows) ? $rows : array();
    }
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

// EOF: includes/Job_Repository.php
