<?php
/**
 * File: includes/PeerTube_Publication_Finalizer_Recovery.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/**
 * Local-only recovery boundary for publication-finalization work.
 *
 * A failed task is retryable only when `mutation_not_sent` proves that no
 * consequential provider request left WordPress. A wholly missing current-
 * generation finalizer may be recreated from an already `ready_verified`
 * upload. This service never performs provider HTTP, creates an upload
 * operation, or advances the publication generation.
 */
final class PeerTube_Publication_Finalizer_Recovery
{
    public const NONE = 'none';
    public const RESUMABLE = 'resumable';
    public const MISSING = 'missing';
    public const TERMINAL = 'terminal';
    public const APPLIED = Task_Repository::APPLIED;
    public const PRESENT = Task_Repository::PRESENT;
    public const REFUSED = 'refused';
    public const INDETERMINATE = Task_Repository::INDETERMINATE;

    public function __construct(
        private readonly Task_Repository $tasks,
        private readonly PeerTube_Staged_Upload_Operation_Store $operations,
        private readonly PeerTube_Event_Repository $events
    ) {
    }

    /** @return array{status:string,task_id:int,generation:int,anchor_post_id:int} */
    public function status(int $video_id): array
    {
        if ($video_id < 1) {
            return self::result(self::NONE);
        }

        self::refresh_post_meta_cache($video_id);
        $lifecycle = PeerTube_Publication_Lifecycle::sanitize(
            get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE, true)
        );
        $execution = PeerTube_Publication_Execution::sanitize(
            get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_EXECUTION, true)
        );
        if (
            array() === $lifecycle
            || array() === $execution
            || true === ($lifecycle['task_pending'] ?? false)
            || true !== ($lifecycle['upload_authorized'] ?? false)
            || true !== ($lifecycle['reveal_authorized'] ?? false)
            || 'publish' !== (string) ($lifecycle['wordpress_status'] ?? '')
            || ! in_array((string) ($lifecycle['target_privacy_id'] ?? ''), array('1','2'), true)
            || (string) ($execution['manifest']['plan_sha256'] ?? '') !== (string) ($lifecycle['plan_sha256'] ?? '')
            || (string) ($execution['backend_id'] ?? '') !== (string) ($lifecycle['backend_id'] ?? '')
            || (int) ($execution['remote_asset_id'] ?? 0) < 1
            || '' === (string) ($execution['remote_uuid'] ?? '')
            || '' === (string) ($execution['operation_id'] ?? '')
        ) {
            return self::result(self::NONE);
        }

        if (metadata_exists('post', $video_id, Video_Meta::SERVING_AUTHORITY)) {
            $authority = Video_Serving_Authority::sanitize(
                get_post_meta($video_id, Video_Meta::SERVING_AUTHORITY, true)
            );
            if (array() !== $authority
                && (int) ($authority['generation'] ?? 0) === (int) ($lifecycle['generation'] ?? 0)
                && hash_equals((string) ($lifecycle['plan_sha256'] ?? ''), (string) ($authority['plan_sha256'] ?? ''))) {
                return self::result(self::NONE);
            }
        }

        $operation = $this->operations->get((string) $execution['operation_id']);
        if (
            ! is_array($operation)
            || ! PeerTube_Staged_Upload_State_Machine::valid($operation)
            || PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED !== ($operation['phase'] ?? null)
            || (int) ($operation['remote_asset_id'] ?? 0) !== (int) $execution['remote_asset_id']
            || (string) ($operation['remote_identity']['uuid'] ?? '') !== (string) $execution['remote_uuid']
        ) {
            return self::result(self::NONE);
        }

        $generation = (int) ($lifecycle['generation'] ?? 0);
        $plan_sha = (string) ($lifecycle['plan_sha256'] ?? '');
        $key = PeerTube_Publication_Task_Coordinator::finalize_idempotency_key($video_id, $generation, $plan_sha);
        if ('' === $key) {
            return self::result(self::NONE);
        }
        $task = $this->tasks->find_by_idempotency_key($key);
        if (! is_array($task)) {
            return self::result(
                self::MISSING,
                0,
                $generation,
                (int) ($lifecycle['anchor_post_id'] ?? 0)
            );
        }
        if (PeerTube_Publication_Task_Coordinator::TASK_FINALIZE !== ($task['task_type'] ?? null)
            || $video_id !== (int) ($task['video_post_id'] ?? 0)
            || (string) ($lifecycle['backend_id'] ?? '') !== (string) ($task['backend_id'] ?? '')
            || ! self::payload_matches((string) ($task['payload_json'] ?? ''), $generation, $plan_sha)) {
            return self::result(self::TERMINAL, (int) ($task['id'] ?? 0), $generation, (int) ($lifecycle['anchor_post_id'] ?? 0));
        }

        if (in_array((string) ($task['status'] ?? ''), array(Task_Repository::STATUS_QUEUED, Task_Repository::STATUS_PROCESSING), true)) {
            return self::result(self::NONE);
        }

        if (Task_Repository::STATUS_FAILED === ($task['status'] ?? null)
            && '' === (string) ($execution['applied_manifest_sha256'] ?? '')
            && (int) ($task['attempts'] ?? 0) < (int) ($task['max_attempts'] ?? 0)) {
            $event = $this->events->latest_for_task((int) $task['id']);
            if (is_array($event) && 'mutation_not_sent' === ($event['event_code'] ?? null)) {
                return self::result(
                    self::RESUMABLE,
                    (int) $task['id'],
                    $generation,
                    (int) ($lifecycle['anchor_post_id'] ?? 0)
                );
            }
        }

        return self::result(
            self::TERMINAL,
            (int) ($task['id'] ?? 0),
            $generation,
            (int) ($lifecycle['anchor_post_id'] ?? 0)
        );
    }


    public function restore_missing(int $video_id, int $now): string
    {
        if ($now < 1) {
            return self::REFUSED;
        }
        $state = $this->status($video_id);
        if (self::MISSING !== ($state['status'] ?? null)) {
            return self::REFUSED;
        }

        self::refresh_post_meta_cache($video_id);
        $lifecycle = PeerTube_Publication_Lifecycle::sanitize(
            get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE, true)
        );
        if (array() === $lifecycle
            || (int) ($lifecycle['generation'] ?? 0) !== (int) ($state['generation'] ?? 0)) {
            return self::REFUSED;
        }

        $generation = (int) $lifecycle['generation'];
        $plan_sha = (string) $lifecycle['plan_sha256'];
        $key = PeerTube_Publication_Task_Coordinator::finalize_idempotency_key($video_id, $generation, $plan_sha);
        if ('' === $key) {
            return self::REFUSED;
        }
        $payload = array(
            'version' => PeerTube_Publication_Task_Coordinator::PAYLOAD_VERSION,
            'generation' => $generation,
            'plan_sha256' => $plan_sha,
        );
        $queued = $this->tasks->enqueue(
            PeerTube_Publication_Task_Coordinator::TASK_FINALIZE,
            $video_id,
            null,
            (string) $lifecycle['backend_id'],
            $key,
            $payload,
            $now + 15,
            $now,
            110,
            720
        );
        $status = is_string($queued['status'] ?? null) ? (string) $queued['status'] : self::INDETERMINATE;
        if (self::APPLIED === $status) {
            return self::APPLIED;
        }
        if (self::PRESENT !== $status) {
            return $status;
        }

        $task = $this->tasks->find_by_idempotency_key($key);
        return is_array($task)
            && in_array((string) ($task['status'] ?? ''), array(Task_Repository::STATUS_QUEUED, Task_Repository::STATUS_PROCESSING), true)
            ? self::PRESENT
            : self::REFUSED;
    }

    public function resume(int $video_id, int $now): string
    {
        if ($now < 1) {
            return self::REFUSED;
        }
        $state = $this->status($video_id);
        if (self::RESUMABLE !== $state['status'] || $state['task_id'] < 1) {
            return self::REFUSED;
        }
        self::refresh_post_meta_cache($video_id);
        $lifecycle = PeerTube_Publication_Lifecycle::sanitize(
            get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE, true)
        );
        if (array() === $lifecycle) {
            return self::REFUSED;
        }
        $key = PeerTube_Publication_Task_Coordinator::finalize_idempotency_key(
            $video_id,
            (int) $state['generation'],
            (string) $lifecycle['plan_sha256']
        );
        if ('' === $key) {
            return self::REFUSED;
        }
        return $this->tasks->retry_failed_exact(
            (int) $state['task_id'],
            PeerTube_Publication_Task_Coordinator::TASK_FINALIZE,
            $key,
            $now
        );
    }

    private static function payload_matches(string $json, int $generation, string $plan_sha): bool
    {
        if ('' === $json || strlen($json) > 16384) {
            return false;
        }
        try {
            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }
        return is_array($payload)
            && array('version','generation','plan_sha256') === array_keys($payload)
            && PeerTube_Publication_Task_Coordinator::PAYLOAD_VERSION === ($payload['version'] ?? null)
            && $generation === ($payload['generation'] ?? null)
            && hash_equals($plan_sha, is_string($payload['plan_sha256'] ?? null) ? $payload['plan_sha256'] : '');
    }

    private static function refresh_post_meta_cache(int $video_id): void
    {
        if ($video_id > 0 && function_exists('wp_cache_delete')) {
            wp_cache_delete($video_id, 'post_meta');
        }
    }

    /** @return array{status:string,task_id:int,generation:int,anchor_post_id:int} */
    private static function result(string $status, int $task_id = 0, int $generation = 0, int $anchor_post_id = 0): array
    {
        return compact('status','task_id','generation','anchor_post_id');
    }
}

// EOF: includes/PeerTube_Publication_Finalizer_Recovery.php
