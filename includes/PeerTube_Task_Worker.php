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
        ?callable $retention_advance = null,
        private readonly ?PeerTube_Event_Repository $events = null
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

        $budget_seconds = PeerTube_Upload_Runtime_Budget::watcher_seconds();
        $upload_budget = $this->upload_budget_for_task($task);
        if ($upload_budget > $budget_seconds) {
            $budget_seconds = $upload_budget;
        }
        $deadline = self::deadline($started_at, $budget_seconds);
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

            if (is_array($task)) {
                $upload_budget = $this->upload_budget_for_task($task);
                if ($upload_budget > $budget_seconds) {
                    $budget_seconds = $upload_budget;
                    $deadline = self::deadline($started_at, $budget_seconds);
                }
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

        $this->record_operator_event($task, $advanced, $now);
        return self::result(self::STATUS_ADVANCED, $recovered, $task_id, $task_type, $coordinator_status);
    }

    /** @param array<string,mixed> $task @param array<string,mixed> $advanced */
    private function record_operator_event(array $task, array $advanced, int $now): void
    {
        if (null === $this->events) {
            return;
        }
        $video_id = self::positive_int($task['video_post_id'] ?? null);
        $task_id = self::positive_int($task['id'] ?? null);
        if ($video_id < 1 || $task_id < 1) {
            return;
        }

        $task_type = is_string($task['task_type'] ?? null) ? $task['task_type'] : '';
        $service = is_string($advanced['service_status'] ?? null) ? $advanced['service_status'] : '';
        $status = is_string($advanced['status'] ?? null) ? $advanced['status'] : '';
        $task_context = $this->task_context($task);
        $failure_context = PeerTube_Upload_Task_Coordinator::TASK_FAILURE_NOTIFY === $task_type
            ? self::failure_notification_context($task)
            : null;
        $operation_id = is_array($task_context)
            ? $task_context['operation_id']
            : (is_array($failure_context) ? $failure_context['operation_id'] : '');
        $operation = null;
        if ('' !== $operation_id) {
            try {
                $candidate = ($this->operation_reader)($operation_id);
            } catch (Throwable) {
                $candidate = null;
            }
            if (is_array($candidate) && PeerTube_Staged_Upload_State_Machine::valid($candidate)) {
                $operation = $candidate;
            }
        }

        $failure = is_array($failure_context) ? $failure_context['failure'] : array();
        $phase = is_string($failure['state'] ?? null) && '' !== $failure['state']
            ? $failure['state']
            : (is_array($operation) && is_string($operation['phase'] ?? null) ? $operation['phase'] : '');
        $confirmed = is_int($failure['confirmed_bytes'] ?? null)
            ? max(0, $failure['confirmed_bytes'])
            : (is_array($operation) && is_int($operation['confirmed_bytes'] ?? null) ? max(0, $operation['confirmed_bytes']) : 0);
        $source_bytes = is_int($failure['source_bytes'] ?? null)
            ? max(0, $failure['source_bytes'])
            : (is_array($operation) && is_int($operation['source']['bytes'] ?? null) ? max(0, $operation['source']['bytes']) : 0);
        $last_error = is_array($operation['last_error'] ?? null) ? $operation['last_error'] : array();
        $http_status = self::event_http_status(
            $advanced['http_status'] ?? ($failure['http_status'] ?? ($last_error['http_status'] ?? null))
        );
        $error_status = self::event_token(
            $advanced['error_status'] ?? ($failure['error_status'] ?? ''),
            64
        );
        $error_code = self::event_token(
            $failure['service_error_code'] ?? ($last_error['code'] ?? ''),
            191
        );

        $step = match ($task_type) {
            'peertube_publication_sync' => 'waiting' === $service ? 2 : 1,
            PeerTube_Upload_Task_Coordinator::TASK_UPLOAD_ADVANCE => $confirmed > 0 || 'chunk' === ($operation['request_kind'] ?? null) ? 4 : 3,
            PeerTube_Upload_Task_Coordinator::TASK_REMOTE_RECONCILE => 5,
            'peertube_publication_finalize' => self::finalizer_is_serving_step($service) ? 7 : 6,
            'peertube_local_retention_cleanup' => 7,
            PeerTube_Upload_Task_Coordinator::TASK_FAILURE_NOTIFY => self::failure_step(
                $phase,
                $confirmed,
                is_string($failure['request_kind'] ?? null) ? $failure['request_kind'] : '',
                is_int($failure['request_bytes'] ?? null) ? max(0, $failure['request_bytes']) : 0
            ),
            default => 2,
        };

        $event_code = self::event_token(
            PeerTube_Upload_Task_Coordinator::TASK_FAILURE_NOTIFY === $task_type && '' !== $error_status
                ? $error_status
                : ('' !== $service ? $service : ('' !== $phase ? $phase : $status)),
            64
        );
        if ('' === $event_code) {
            $event_code = 'task_boundary';
        }
        $severity = PeerTube_Upload_Task_Coordinator::TASK_FAILURE_NOTIFY === $task_type
            || in_array($status, array('failed','indeterminate','conflict'), true)
            || in_array($phase, array(PeerTube_Staged_Upload_State_Machine::PHASE_FAILED,PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE), true)
            ? 'error'
            : ('requeued' === $status ? 'warning' : 'info');

        $message = is_string($advanced['operator_message'] ?? null) ? trim($advanced['operator_message']) : '';
        if ('' === $message && PeerTube_Upload_Task_Coordinator::TASK_FAILURE_NOTIFY === $task_type) {
            $message = self::failure_event_message($failure, $step);
        }
        if ('' === $message) {
            $message = self::event_message($task_type, $step, $status, $service, $phase, $http_status);
        }
        $automatic_action = match ($status) {
            'requeued' => 'AWVP queued the next bounded retry or continuation.',
            'failed' => 'Automatic processing stopped at this task boundary.',
            'indeterminate', 'conflict' => 'Automatic replay stopped until durable state can be reconciled.',
            default => 'AWVP committed this durable processing boundary.',
        };
        if ('mutation_indeterminate' === $service || PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE === $phase) {
            $automatic_action = 'Automatic replay is stopped by the no-blind-replay safety boundary.';
        }
        if (PeerTube_Upload_Task_Coordinator::TASK_FAILURE_NOTIFY === $task_type) {
            $automatic_action = PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE === $phase
                ? 'Automatic byte replay remains blocked; AWVP recorded and notified the operator about the preserved failure.'
                : 'AWVP recorded and notified the operator about the preserved upload failure.';
        }

        $operator_action = match ($service) {
            'mutation_not_sent' => 'Correct the reviewed publication values, then resume the publication.',
            'mutation_rejected' => 'Correct the reported PeerTube rejection, then resume the publication.',
            'mutation_indeterminate' => 'Review the provider state in Details & log before explicitly resuming.',
            'waiting' => 'No immediate action is required; AWVP will retry after its dependency becomes current.',
            default => $severity === 'error'
                ? 'Review Details & log and use Resume only when the reported condition is understood.'
                : '',
        };
        if (PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE === $phase) {
            $operator_action = 'Review the upload outcome before explicitly resuming or reconciling it.';
        }
        if (PeerTube_Upload_Task_Coordinator::TASK_FAILURE_NOTIFY === $task_type) {
            $operator_action = self::failure_operator_action($failure, $phase);
        }

        $this->events->record(
            $video_id,
            $step,
            $event_code,
            $severity,
            $message,
            $now,
            $task_id,
            $operation_id,
            is_array($operation) && is_int($operation['remote_asset_id'] ?? null) ? $operation['remote_asset_id'] : 0,
            is_string($task['backend_id'] ?? null) ? $task['backend_id'] : '',
            $http_status,
            $automatic_action,
            $operator_action,
            array(
                'remote_uuid' => is_array($operation) ? (string)($operation['remote_identity']['uuid'] ?? '') : '',
                'service_status' => '' !== $service ? $service : self::event_token($failure['service_status'] ?? '', 64),
                'error_status' => $error_status,
                'error_code' => $error_code,
                'confirmed_bytes' => $confirmed,
                'source_bytes' => $source_bytes,
                'request_start' => is_int($failure['request_start'] ?? null) ? max(0, $failure['request_start']) : 0,
                'request_bytes' => is_int($failure['request_bytes'] ?? null) ? max(0, $failure['request_bytes']) : 0,
                'phase' => $phase,
            )
        );
    }

    private static function event_message(string $task_type,int $step,string $status,string $service,string $phase,int $http_status): string
    {
        if ($http_status > 0 && in_array($status,array('failed','indeterminate'),true)) {
            return 'PeerTube processing stopped at Step '.$step.' of 7 with HTTP '.$http_status.'.';
        }
        if ('waiting' === $service) {
            return 'PeerTube publication is waiting for a current server, credential, or catalog dependency.';
        }
        if (PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE === $phase) {
            return 'The upload outcome is indeterminate and automatic byte replay is blocked.';
        }
        return match ($task_type) {
            'peertube_publication_sync' => 'WordPress publication authority and source preparation reached a durable boundary.',
            PeerTube_Upload_Task_Coordinator::TASK_UPLOAD_ADVANCE => 4 === $step
                ? 'Video transfer to PeerTube reached a durable boundary.'
                : 'PeerTube upload initialization reached a durable boundary.',
            PeerTube_Upload_Task_Coordinator::TASK_REMOTE_RECONCILE => 'PeerTube processing status reconciliation reached a durable boundary.',
            'peertube_publication_finalize' => 7 === $step
                ? 'PeerTube publication verification and serving cutover reached a durable boundary.'
                : 'PeerTube publication finalization reached a durable boundary.',
            'peertube_local_retention_cleanup' => 'Verified serving and local retention cleanup reached a durable boundary.',
            default => 'PeerTube processing reached a durable boundary.',
        };
    }

    private static function finalizer_is_serving_step(string $service): bool
    {
        return str_starts_with($service,'verified') || str_starts_with($service,'cutover_retry:');
    }

    private static function failure_step(string $phase,int $confirmed,string $request_kind='',int $request_bytes=0): int
    {
        if (PeerTube_Staged_Upload_State_Machine::PHASE_PROCESSING === $phase || PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED === $phase) {
            return 5;
        }
        return $confirmed > 0 || 'chunk' === $request_kind || $request_bytes > 0 ? 4 : 3;
    }

    /** @param array<string,mixed> $task @return array{operation_id:string,failure:array<string,mixed>}|null */
    private static function failure_notification_context(array $task): ?array
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
            || 1 !== ($payload['version'] ?? null)
            || ! is_string($payload['operation_id'] ?? null)
            || 1 !== preg_match('/\Aupload_[a-f0-9]{32}\z/D', $payload['operation_id'])
            || ! is_array($payload['failure'] ?? null)
        ) {
            return null;
        }

        $failure = $payload['failure'];
        $state = self::event_token($failure['state'] ?? '', 64);
        $source_bytes = is_int($failure['source_bytes'] ?? null) ? max(0, $failure['source_bytes']) : 0;
        if ('' === $state || $source_bytes < 1) {
            return null;
        }

        return array(
            'operation_id' => $payload['operation_id'],
            'failure' => array(
                'state' => $state,
                'confirmed_bytes' => is_int($failure['confirmed_bytes'] ?? null) ? max(0, $failure['confirmed_bytes']) : 0,
                'source_bytes' => $source_bytes,
                'request_kind' => self::event_token($failure['request_kind'] ?? '', 32),
                'request_start' => is_int($failure['request_start'] ?? null) ? max(0, $failure['request_start']) : 0,
                'request_bytes' => is_int($failure['request_bytes'] ?? null) ? max(0, $failure['request_bytes']) : 0,
                'http_status' => self::event_http_status($failure['http_status'] ?? null),
                'service_status' => self::event_token($failure['service_status'] ?? '', 64),
                'error_status' => self::event_token($failure['error_status'] ?? '', 64),
                'service_error_code' => self::event_token($failure['service_error_code'] ?? '', 191),
                'detail' => self::event_text($failure['detail'] ?? '', 512),
                'reason' => self::event_text($failure['reason'] ?? '', 512),
            ),
        );
    }

    /** @param array<string,mixed> $failure */
    private static function failure_event_message(array $failure,int $step): string
    {
        $detail = self::event_text($failure['detail'] ?? '', 512);
        $reason = self::event_text($failure['reason'] ?? '', 512);
        $error_status = self::event_token($failure['error_status'] ?? '', 64);
        $http_status = self::event_http_status($failure['http_status'] ?? null);
        $confirmed = is_int($failure['confirmed_bytes'] ?? null) ? max(0, $failure['confirmed_bytes']) : 0;
        $source_bytes = is_int($failure['source_bytes'] ?? null) ? max(0, $failure['source_bytes']) : 0;

        $prefix = 4 === $step
            ? 'Video transfer stopped after '.number_format($confirmed).' of '.number_format($source_bytes).' bytes.'
            : 'PeerTube processing stopped at Step '.$step.' of 7.';
        if ($http_status > 0) {
            $prefix .= ' HTTP '.$http_status.'.';
        }
        if ('' !== $error_status) {
            $prefix .= ' Classification: '.str_replace('_', ' ', $error_status).'.';
        }
        if ('' !== $detail) {
            return $prefix.' '.$detail;
        }
        if ('' !== $reason) {
            return $prefix.' '.$reason;
        }
        return $prefix;
    }

    /** @param array<string,mixed> $failure */
    private static function failure_operator_action(array $failure,string $phase): string
    {
        $status = self::event_token($failure['error_status'] ?? '', 64);
        $http_status = self::event_http_status($failure['http_status'] ?? null);
        $action = match (true) {
            'transport_dns' === $status => 'Check the PeerTube server hostname and DNS resolution.',
            'transport_connection_refused' === $status => 'Confirm the PeerTube server and reverse proxy are reachable.',
            'transport_timeout' === $status => 'Check network/server responsiveness.',
            'transport_tls' === $status => 'Correct the PeerTube server TLS certificate or trust problem.',
            401 === $http_status => 'Refresh or repair PeerTube credentials.',
            403 === $http_status => 'Check the PeerTube account permissions for this operation.',
            429 === $http_status => 'Wait for the PeerTube rate limit to clear according to the reported retry guidance.',
            $http_status >= 500 && $http_status <= 599 => 'Check PeerTube server health.',
            default => 'Review Details & log and confirm the reported failure is understood.',
        };
        if (PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE === $phase) {
            return $action.' The upload outcome is indeterminate; review provider state before explicitly resuming or reconciling it.';
        }
        return $action.' Resume only when the failed request is safe to retry.';
    }

    private static function event_text(mixed $value,int $maximum): string
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

    private static function event_http_status(mixed $value): int
    {
        return is_int($value) && $value >= 100 && $value <= 599 ? $value : 0;
    }

    private static function event_token(mixed $value,int $maximum): string
    {
        if (! is_string($value) || '' === $value || strlen($value) > $maximum) {
            return '';
        }
        return 1 === preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D',$value) ? $value : '';
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


    /** @param array<string,mixed> $task */
    private function upload_budget_for_task(array $task): int
    {
        if (PeerTube_Upload_Task_Coordinator::TASK_UPLOAD_ADVANCE !== ($task['task_type'] ?? null)) {
            return 0;
        }

        $context = $this->task_context($task);
        return is_array($context)
            ? PeerTube_Upload_Runtime_Budget::process_seconds($context['source_bytes'])
            : 0;
    }

    private static function deadline(int $started_at, int $budget_seconds): int
    {
        return $started_at > PHP_INT_MAX - $budget_seconds
            ? PHP_INT_MAX
            : $started_at + $budget_seconds;
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
