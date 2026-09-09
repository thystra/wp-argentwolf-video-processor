<?php
/**
 * File: includes/PeerTube_Task_Worker.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Closure;
use Throwable;

/**
 * Consumer for the reviewed R45 PeerTube task types.
 *
 * run_once() preserves the qualified one-task execution boundary. run_drain()
 * is a bounded detached watcher: it advances durable safe boundaries, rechecks
 * the site-wide owned queue between tasks, and waits only in short unclaimed
 * slices for future work. Its process budget is observed only between remote
 * requests so a byte-bearing PUT is never interrupted by the worker.
 */
final class PeerTube_Task_Worker
{
    public const STATUS_IDLE = 'idle';
    public const STATUS_ADVANCED = 'advanced';
    public const STATUS_YIELDED = 'yielded';
    public const STATUS_INDETERMINATE = 'indeterminate';

    public const STALE_LOCK_SECONDS = 900;

    private const RECOVERY_LIMIT = 20;

    /** @var list<string> */
    private const ONCE_TASK_TYPES = array(
        PeerTube_Upload_Task_Coordinator::TASK_UPLOAD_ADVANCE,
        PeerTube_Upload_Task_Coordinator::TASK_REMOTE_RECONCILE,
    );

    /** @var list<string> */
    private const DRAIN_TASK_TYPES = array(
        PeerTube_Upload_Task_Coordinator::TASK_UPLOAD_ADVANCE,
        PeerTube_Upload_Task_Coordinator::TASK_REMOTE_RECONCILE,
        PeerTube_Upload_Task_Coordinator::TASK_FAILURE_NOTIFY,
        'peertube_publication_sync',
        'peertube_publication_finalize',
        'peertube_local_retention_cleanup',
    );

    /** @var Closure(string):array<string,mixed>|null */
    private Closure $operation_reader;
    /** @var Closure(array<string,mixed>,int):array<string,mixed>|null */
    private ?Closure $publication_advance;
    /** @var Closure(array<string,mixed>,int):array<string,mixed>|null */
    private ?Closure $retention_advance;

    public function __construct(
        private readonly Task_Repository $tasks,
        private readonly PeerTube_Upload_Task_Coordinator $coordinator,
        callable $operation_reader,
        ?callable $publication_advance = null,
        ?callable $retention_advance = null
    ) {
        $this->operation_reader = Closure::fromCallable($operation_reader);
        $this->publication_advance = null === $publication_advance ? null : Closure::fromCallable($publication_advance);
        $this->retention_advance = null === $retention_advance ? null : Closure::fromCallable($retention_advance);
    }

    /**
     * Recover stale locks owned by this worker and advance at most one task.
     *
     * @return array{status:string,recovered:int,task_id:int,task_type:string,coordinator_status:string}
     */
    public function run_once(int $now): array
    {
        if ($now < 1) {
            return self::result(self::STATUS_INDETERMINATE);
        }

        $recovered = $this->recover($now, self::ONCE_TASK_TYPES);
        $task = $this->tasks->claim_next_of_types(self::ONCE_TASK_TYPES, $now);
        if (! is_array($task)) {
            return self::result(self::STATUS_IDLE, $recovered);
        }

        return $this->advance_one($task, $now, $recovered);
    }

    /**
     * Drain/watch the site-wide PeerTube-owned queue within one bounded process.
     *
     * Immediate same-task continuations still get first preference, but every
     * durable boundary then returns to the global owned queue. If only future
     * work remains, the detached process sleeps in at most five-second slices
     * and rechecks the queue, allowing a newly-published video to run promptly
     * while an older upload/reconciliation is waiting on run_after.
     *
     * No claimed task is held while sleeping. Indeterminate byte-bearing work
     * is never replayed here; it must become an explicitly queued safe boundary
     * before this worker can claim it again.
     *
     * @param callable():int|null $clock
     * @param callable(int):void|null $sleeper
     * @return array{
     *   status:string,recovered:int,task_id:int,task_type:string,
     *   coordinator_status:string,steps:int,budget_seconds:int,elapsed_seconds:int
     * }
     */
    public function run_drain(int $started_at, ?callable $clock = null, ?callable $sleeper = null): array
    {
        if ($started_at < 1) {
            return self::drain_result(self::STATUS_INDETERMINATE);
        }

        $clock = null === $clock ? static fn(): int => time() : Closure::fromCallable($clock);
        $sleeper = null === $sleeper
            ? static function (int $seconds): void { if ($seconds > 0) { sleep($seconds); } }
            : Closure::fromCallable($sleeper);
        $now = self::clock_now($clock);
        if ($now < 1) {
            return self::drain_result(self::STATUS_INDETERMINATE);
        }

        $recovered = $this->recover($now, self::DRAIN_TASK_TYPES);
        $task = $this->tasks->claim_next_of_types(self::DRAIN_TASK_TYPES, $now);
        if (! is_array($task)) {
            return self::drain_result(self::STATUS_IDLE, $recovered);
        }

        $context = $this->task_context($task);
        $source_bytes = is_array($context) ? $context['source_bytes'] : 0;
        $budget_seconds = PeerTube_Upload_Runtime_Budget::process_seconds($source_bytes);
        $deadline = $started_at > PHP_INT_MAX - $budget_seconds
            ? PHP_INT_MAX
            : $started_at + $budget_seconds;
        $steps = 0;
        $last_task_id = 0;
        $last_task_type = '';
        $last_coordinator_status = '';

        while (true) {
            $now = self::clock_now($clock);
            if ($now < 1) {
                return self::drain_result(self::STATUS_INDETERMINATE, $recovered, $last_task_id, $last_task_type, $last_coordinator_status, $steps, $budget_seconds, 0);
            }
            if ($now >= $deadline) {
                return self::drain_result(self::STATUS_YIELDED, $recovered, $last_task_id, $last_task_type, $last_coordinator_status, $steps, $budget_seconds, max(0, $now - $started_at));
            }

            if (! is_array($task)) {
                $task = $this->tasks->claim_next_of_types(self::DRAIN_TASK_TYPES, $now);
                if (is_array($task)) {
                    continue;
                }

                $next_run_after = $this->tasks->next_run_after_of_types(self::DRAIN_TASK_TYPES, $now);
                if ($next_run_after < 1) {
                    return self::drain_result(self::STATUS_ADVANCED, $recovered, $last_task_id, $last_task_type, $last_coordinator_status, $steps, $budget_seconds, max(0, $now - $started_at));
                }
                if ($next_run_after >= $deadline) {
                    return self::drain_result(self::STATUS_YIELDED, $recovered, $last_task_id, $last_task_type, $last_coordinator_status, $steps, $budget_seconds, max(0, $now - $started_at));
                }

                $sleep_seconds = min(
                    5,
                    max(1, $next_run_after - $now),
                    max(1, $deadline - $now)
                );
                try {
                    $sleeper($sleep_seconds);
                } catch (Throwable) {
                    return self::drain_result(self::STATUS_INDETERMINATE, $recovered, $last_task_id, $last_task_type, $last_coordinator_status, $steps, $budget_seconds, max(0, $now - $started_at));
                }
                continue;
            }

            $advanced = $this->advance_one($task, $now, $recovered);
            ++$steps;
            $last_task_id = $advanced['task_id'];
            $last_task_type = $advanced['task_type'];
            $last_coordinator_status = $advanced['coordinator_status'];
            if (self::STATUS_INDETERMINATE === $advanced['status']) {
                return self::drain_result(self::STATUS_INDETERMINATE, $recovered, $last_task_id, $last_task_type, $last_coordinator_status, $steps, $budget_seconds, max(0, $now - $started_at));
            }

            $coordinator_result = $this->last_coordinator_result;
            if (! is_array($coordinator_result)) {
                return self::drain_result(self::STATUS_INDETERMINATE, $recovered, $last_task_id, $last_task_type, $last_coordinator_status, $steps, $budget_seconds, max(0, $now - $started_at));
            }

            $boundary_now = self::clock_now($clock);
            if ($boundary_now < 1) {
                return self::drain_result(self::STATUS_INDETERMINATE, $recovered, $last_task_id, $last_task_type, $last_coordinator_status, $steps, $budget_seconds, 0);
            }
            if ($boundary_now >= $deadline) {
                return self::drain_result(self::STATUS_YIELDED, $recovered, $last_task_id, $last_task_type, $last_coordinator_status, $steps, $budget_seconds, max(0, $boundary_now - $started_at));
            }

            $task = null;
            $run_after = self::positive_int($coordinator_result['run_after'] ?? null);
            if (
                PeerTube_Upload_Task_Coordinator::STATUS_REQUEUED === $last_coordinator_status
                && Task_Repository::APPLIED === ($coordinator_result['repository_status'] ?? null)
                && $run_after > 0
                && $run_after <= $boundary_now
            ) {
                $task = $this->tasks->claim_task_of_types($last_task_id, self::DRAIN_TASK_TYPES, $boundary_now);
            }
            // If the immediate reclaim lost a race, the next loop re-enters the
            // global queue rather than replaying any previously claimed task.
        }
    }

    /** @var array<string,mixed>|null */
    private ?array $last_coordinator_result = null;

    /** @param array<string,mixed> $task */
    private function advance_one(array $task, int $now, int $recovered): array
    {
        $this->last_coordinator_result = null;
        $task_id = self::positive_int($task['id'] ?? null);
        $task_type = is_string($task['task_type'] ?? null) ? $task['task_type'] : '';
        if ($task_id < 1 || ! in_array($task_type, self::DRAIN_TASK_TYPES, true)) {
            return self::result(self::STATUS_INDETERMINATE, $recovered, $task_id, $task_type);
        }

        try {
            if (in_array($task_type, array('peertube_publication_sync','peertube_publication_finalize'), true)) {
                if (null === $this->publication_advance) {
                    return self::result(self::STATUS_INDETERMINATE, $recovered, $task_id, $task_type);
                }
                $advanced = ($this->publication_advance)($task, $now);
            } elseif ('peertube_local_retention_cleanup' === $task_type) {
                if (null === $this->retention_advance) {
                    return self::result(self::STATUS_INDETERMINATE, $recovered, $task_id, $task_type);
                }
                $advanced = ($this->retention_advance)($task, $now);
            } else {
                $advanced = $this->coordinator->advance_claimed($task, $now);
            }
        } catch (Throwable) {
            return self::result(self::STATUS_INDETERMINATE, $recovered, $task_id, $task_type);
        }
        $this->last_coordinator_result = $advanced;

        $coordinator_status = is_string($advanced['status'] ?? null) ? $advanced['status'] : '';
        if (
            $task_id !== self::positive_int($advanced['task_id'] ?? null)
            || $task_type !== ($advanced['task_type'] ?? null)
            || '' === $coordinator_status
        ) {
            return self::result(self::STATUS_INDETERMINATE, $recovered, $task_id, $task_type);
        }

        return self::result(self::STATUS_ADVANCED, $recovered, $task_id, $task_type, $coordinator_status);
    }

    /** @param list<string> $task_types */
    private function recover(int $now, array $task_types): int
    {
        return $this->tasks->recover_stale_of_types(
            $task_types,
            max(1, $now - self::STALE_LOCK_SECONDS),
            $now,
            self::RECOVERY_LIMIT
        );
    }

    /** @param array<string,mixed> $task @return array{operation_id:string,source_bytes:int}|null */
    private function task_context(array $task): ?array
    {
        $payload_json = $task['payload_json'] ?? null;
        if (! is_string($payload_json) || '' === $payload_json || strlen($payload_json) > 16384) {
            return null;
        }
        try {
            $payload = json_decode($payload_json, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (
            ! is_array($payload)
            || array('version', 'operation_id') !== array_keys($payload)
            || PeerTube_Upload_Task_Coordinator::PAYLOAD_VERSION !== ($payload['version'] ?? null)
            || ! is_string($payload['operation_id'] ?? null)
            || 1 !== preg_match('/\Aupload_[a-f0-9]{32}\z/D', $payload['operation_id'])
        ) {
            return null;
        }

        try {
            $operation = ($this->operation_reader)($payload['operation_id']);
        } catch (Throwable) {
            return null;
        }
        if (
            ! is_array($operation)
            || ! PeerTube_Staged_Upload_State_Machine::valid($operation)
            || ! hash_equals($payload['operation_id'], (string) ($operation['operation_id'] ?? ''))
            || self::positive_int($task['video_post_id'] ?? null) !== ($operation['video_post_id'] ?? null)
            || ! is_string($task['backend_id'] ?? null)
            || $task['backend_id'] !== ($operation['backend_id'] ?? null)
            || ! is_int($operation['source']['bytes'] ?? null)
            || $operation['source']['bytes'] < 1
        ) {
            return null;
        }

        return array(
            'operation_id' => $payload['operation_id'],
            'source_bytes' => $operation['source']['bytes'],
        );
    }

    /** @param callable():int $clock */
    private static function clock_now(callable $clock): int
    {
        try {
            $now = $clock();
        } catch (Throwable) {
            return 0;
        }
        return is_int($now) && $now > 0 ? $now : 0;
    }

    private static function positive_int(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        if (! is_string($value) || 1 !== preg_match('/\A[1-9][0-9]*\z/D', $value)) {
            return 0;
        }

        $int = (int) $value;
        return $int > 0 && (string) $int === $value ? $int : 0;
    }

    /** @return array{status:string,recovered:int,task_id:int,task_type:string,coordinator_status:string} */
    private static function result(
        string $status,
        int $recovered = 0,
        int $task_id = 0,
        string $task_type = '',
        string $coordinator_status = ''
    ): array {
        return array(
            'status' => $status,
            'recovered' => max(0, $recovered),
            'task_id' => max(0, $task_id),
            'task_type' => $task_type,
            'coordinator_status' => $coordinator_status,
        );
    }

    /**
     * @return array{status:string,recovered:int,task_id:int,task_type:string,coordinator_status:string,steps:int,budget_seconds:int,elapsed_seconds:int}
     */
    private static function drain_result(
        string $status,
        int $recovered = 0,
        int $task_id = 0,
        string $task_type = '',
        string $coordinator_status = '',
        int $steps = 0,
        int $budget_seconds = 0,
        int $elapsed_seconds = 0
    ): array {
        return array(
            'status' => $status,
            'recovered' => max(0, $recovered),
            'task_id' => max(0, $task_id),
            'task_type' => $task_type,
            'coordinator_status' => $coordinator_status,
            'steps' => max(0, $steps),
            'budget_seconds' => max(0, $budget_seconds),
            'elapsed_seconds' => max(0, $elapsed_seconds),
        );
    }
}

// EOF: includes/PeerTube_Task_Worker.php
