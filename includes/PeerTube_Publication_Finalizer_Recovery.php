<?php
/**
 * File: includes/PeerTube_Publication_Finalizer_Recovery.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/**
 * Local-only recovery boundary for publication finalizers that failed before
 * any provider mutation was transmitted.
 *
 * A `mutation_not_sent` event is the authority that makes an exact task retry
 * safe: the previous finalizer proved that no consequential PUT left WordPress.
 * This service never performs provider HTTP or creates a new upload operation.
 */
final class PeerTube_Publication_Finalizer_Recovery
{
    public const NONE = 'none';
    public const RESUMABLE = 'resumable';
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
            || '3' === (string) ($lifecycle['target_privacy_id'] ?? '')
            || '' !== (string) ($execution['applied_manifest_sha256'] ?? '')
            || (string) ($execution['manifest']['plan_sha256'] ?? '') !== (string) ($lifecycle['plan_sha256'] ?? '')
            || (string) ($execution['backend_id'] ?? '') !== (string) ($lifecycle['backend_id'] ?? '')
            || (int) ($execution['remote_asset_id'] ?? 0) < 1
            || '' === (string) ($execution['remote_uuid'] ?? '')
            || '' === (string) ($execution['operation_id'] ?? '')
        ) {
            return self::result(self::NONE);
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
        if (
            ! is_array($task)
            || Task_Repository::STATUS_FAILED !== ($task['status'] ?? null)
            || PeerTube_Publication_Task_Coordinator::TASK_FINALIZE !== ($task['task_type'] ?? null)
            || $video_id !== (int) ($task['video_post_id'] ?? 0)
            || (string) ($lifecycle['backend_id'] ?? '') !== (string) ($task['backend_id'] ?? '')
            || (int) ($task['attempts'] ?? 0) >= (int) ($task['max_attempts'] ?? 0)
            || ! self::payload_matches((string) ($task['payload_json'] ?? ''), $generation, $plan_sha)
        ) {
            return self::result(self::NONE);
        }

        $event = $this->events->latest_for_task((int) $task['id']);
        if (! is_array($event) || 'mutation_not_sent' !== ($event['event_code'] ?? null)) {
            return self::result(self::NONE);
        }

        return self::result(
            self::RESUMABLE,
            (int) $task['id'],
            $generation,
            (int) ($lifecycle['anchor_post_id'] ?? 0)
        );
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

    /** @return array{status:string,task_id:int,generation:int,anchor_post_id:int} */
    private static function result(string $status, int $task_id = 0, int $generation = 0, int $anchor_post_id = 0): array
    {
        return compact('status','task_id','generation','anchor_post_id');
    }
}

// EOF: includes/PeerTube_Publication_Finalizer_Recovery.php
