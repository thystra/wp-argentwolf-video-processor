<?php
/**
 * File: includes/PeerTube_Upload_Task_Coordinator.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Closure;
use Throwable;

/**
 * Bounded task-to-service coordination for staged PeerTube upload work.
 *
 * This class owns no scheduler, worker loop, administrator action, HTTP client,
 * token lifecycle, upload implementation, offset reconciliation, cleanup, or
 * publication policy. A later worker may pass one already-claimed task here;
 * one call advances at most one existing R43 or R44 service boundary.
 */
final class PeerTube_Upload_Task_Coordinator
{
    public const TASK_UPLOAD_ADVANCE = 'peertube_upload_advance';
    public const TASK_REMOTE_RECONCILE = 'peertube_remote_reconcile';
    public const PAYLOAD_VERSION = 1;

    public const STATUS_REQUEUED = 'requeued';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_INDETERMINATE = 'indeterminate';

    private const PRIORITY = 100;
    private const LOCAL_RETRY_SECONDS = 30;

    /** @var Closure(string):array<string,mixed>|null */
    private Closure $operation_reader;

    /** @var Closure(string,int):array<string,mixed> */
    private Closure $upload_advance;

    /** @var Closure(string,int):array<string,mixed> */
    private Closure $reconciliation_advance;

    public function __construct(
        private readonly Task_Repository $tasks,
        callable $operation_reader,
        callable $upload_advance,
        callable $reconciliation_advance
    ) {
        $this->operation_reader = Closure::fromCallable($operation_reader);
        $this->upload_advance = Closure::fromCallable($upload_advance);
        $this->reconciliation_advance = Closure::fromCallable($reconciliation_advance);
    }

    /** @return array{status:string,task_id:int} */
    public function enqueue_upload(string $operation_id, int $now): array
    {
        $operation = $this->read_operation($operation_id);
        if (null === $operation || $now < 1 || ! self::upload_phase($operation['phase'])) {
            return self::enqueue_result(Task_Repository::CONFLICT);
        }

        return $this->enqueue_operation(self::TASK_UPLOAD_ADVANCE, $operation, $now);
    }

    /** @return array{status:string,task_id:int} */
    public function enqueue_reconciliation(string $operation_id, int $now): array
    {
        $operation = $this->read_operation($operation_id);
        if (null === $operation || $now < 1 || ! self::reconciliation_phase($operation['phase'])) {
            return self::enqueue_result(Task_Repository::CONFLICT);
        }

        return $this->enqueue_operation(self::TASK_REMOTE_RECONCILE, $operation, $now);
    }

    /**
     * Advance exactly one already-claimed task.
     *
     * @param array<string,mixed> $task
     * @return array{status:string,task_id:int,task_type:string,service_status:string,repository_status:string,run_after:int}
     */
    public function advance_claimed(array $task, int $now): array
    {
        $identity = self::claimed_identity($task);
        if (null === $identity || $now < 1) {
            return self::result(self::STATUS_CONFLICT);
        }

        $task_id = $identity['task_id'];
        $task_type = $identity['task_type'];
        $lock_token = $identity['lock_token'];

        if (! in_array($task_type, array(self::TASK_UPLOAD_ADVANCE, self::TASK_REMOTE_RECONCILE), true)) {
            return $this->fail_claimed(
                $task_id,
                $task_type,
                $lock_token,
                'Task type is outside the R45 PeerTube coordinator contract.',
                $now
            );
        }

        $payload = self::payload($task['payload_json'] ?? null);
        if (null === $payload) {
            return $this->fail_claimed(
                $task_id,
                $task_type,
                $lock_token,
                'PeerTube task payload contract validation failed.',
                $now
            );
        }

        $operation = $this->read_operation($payload['operation_id']);
        if (null === $operation || ! self::task_matches_operation($task, $operation, $task_type)) {
            return $this->fail_claimed(
                $task_id,
                $task_type,
                $lock_token,
                'PeerTube task no longer matches its authoritative upload operation.',
                $now
            );
        }

        if (self::TASK_UPLOAD_ADVANCE === $task_type) {
            return $this->advance_upload_task($task_id, $lock_token, $operation, $now);
        }

        return $this->advance_reconciliation_task($task_id, $lock_token, $operation, $now);
    }

    /** @param array<string,mixed> $operation */
    private function advance_upload_task(
        int $task_id,
        string $lock_token,
        array $operation,
        int $now
    ): array {
        $phase = $operation['phase'];

        if (in_array(
            $phase,
            array(
                PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_CREATED,
                PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_COMMITTED,
                PeerTube_Staged_Upload_State_Machine::PHASE_PROCESSING,
                PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED,
            ),
            true
        )) {
            return $this->handoff_to_reconciliation($task_id, $lock_token, $operation, 'not_called', $now);
        }

        if (PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE === $phase) {
            return $this->fail_claimed(
                $task_id,
                self::TASK_UPLOAD_ADVANCE,
                $lock_token,
                'Upload outcome is indeterminate; explicit zero-byte reconciliation is required before any retry.',
                $now,
                PeerTube_Staged_Upload_Service::STATUS_INDETERMINATE
            );
        }

        if (PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_IN_FLIGHT === $phase) {
            return $this->fail_claimed(
                $task_id,
                self::TASK_UPLOAD_ADVANCE,
                $lock_token,
                'Upload journal remains in-flight after task recovery; automatic replay is forbidden.',
                $now,
                PeerTube_Staged_Upload_Service::STATUS_INDETERMINATE
            );
        }

        if (PeerTube_Staged_Upload_State_Machine::PHASE_FAILED === $phase) {
            return $this->fail_claimed(
                $task_id,
                self::TASK_UPLOAD_ADVANCE,
                $lock_token,
                'Upload operation is already terminally failed.',
                $now
            );
        }

        if (! in_array(
            $phase,
            array(
                PeerTube_Staged_Upload_State_Machine::PHASE_READY,
                PeerTube_Staged_Upload_State_Machine::PHASE_RETRY_WAIT,
            ),
            true
        )) {
            return $this->fail_claimed(
                $task_id,
                self::TASK_UPLOAD_ADVANCE,
                $lock_token,
                'Upload operation phase is outside the R45 execution boundary.',
                $now
            );
        }

        try {
            $service = ($this->upload_advance)($operation['operation_id'], $now);
        } catch (Throwable) {
            return $this->fail_claimed(
                $task_id,
                self::TASK_UPLOAD_ADVANCE,
                $lock_token,
                'Upload service raised an indeterminate coordinator boundary failure.',
                $now,
                PeerTube_Staged_Upload_Service::STATUS_INDETERMINATE
            );
        }

        $service_status = is_string($service['status'] ?? null) ? $service['status'] : '';
        $current = $this->read_operation($operation['operation_id']);
        if (null === $current) {
            return $this->fail_claimed(
                $task_id,
                self::TASK_UPLOAD_ADVANCE,
                $lock_token,
                'Upload operation could not be re-read after service advancement.',
                $now,
                $service_status
            );
        }

        if (PeerTube_Staged_Upload_Service::STATUS_REMOTE_CREATED === $service_status
            && PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_CREATED === $current['phase']) {
            return $this->handoff_to_reconciliation($task_id, $lock_token, $current, $service_status, $now);
        }

        if (PeerTube_Staged_Upload_Service::STATUS_WAIT === $service_status) {
            $run_after = self::wait_run_after($current, $now);
            if ($run_after < 1) {
                return $this->fail_claimed(
                    $task_id,
                    self::TASK_UPLOAD_ADVANCE,
                    $lock_token,
                    'Upload service returned a wait without a valid durable retry boundary.',
                    $now,
                    $service_status
                );
            }
            return $this->reschedule_claimed(
                $task_id,
                self::TASK_UPLOAD_ADVANCE,
                $lock_token,
                $run_after,
                'PeerTube upload is durably waiting before its next bounded advancement.',
                $now,
                $service_status
            );
        }

        if (in_array(
            $service_status,
            array(
                PeerTube_Staged_Upload_Service::STATUS_ADVANCED,
                PeerTube_Staged_Upload_Service::STATUS_SESSION_CREATED,
                PeerTube_Staged_Upload_Service::STATUS_CHUNK_ACCEPTED,
            ),
            true
        )) {
            if (PeerTube_Staged_Upload_State_Machine::PHASE_READY !== $current['phase']
                || ! self::empty_error($current['last_error'] ?? null)) {
                return $this->fail_claimed(
                    $task_id,
                    self::TASK_UPLOAD_ADVANCE,
                    $lock_token,
                    'Upload advancement requires explicit intervention before another automatic step.',
                    $now,
                    $service_status
                );
            }
            return $this->reschedule_claimed(
                $task_id,
                self::TASK_UPLOAD_ADVANCE,
                $lock_token,
                $now,
                'PeerTube upload advanced one bounded step.',
                $now,
                $service_status
            );
        }

        return $this->fail_claimed(
            $task_id,
            self::TASK_UPLOAD_ADVANCE,
            $lock_token,
            'Upload service stopped at an explicit intervention boundary.',
            $now,
            $service_status
        );
    }

    /** @param array<string,mixed> $operation */
    private function advance_reconciliation_task(
        int $task_id,
        string $lock_token,
        array $operation,
        int $now
    ): array {
        if (PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED === $operation['phase']) {
            return $this->complete_claimed(
                $task_id,
                self::TASK_REMOTE_RECONCILE,
                $lock_token,
                $now,
                PeerTube_Remote_Asset_Reconciliation_Service::STATUS_READY_VERIFIED
            );
        }

        if (PeerTube_Staged_Upload_State_Machine::PHASE_FAILED === $operation['phase']) {
            return $this->fail_claimed(
                $task_id,
                self::TASK_REMOTE_RECONCILE,
                $lock_token,
                'Remote reconciliation operation is already terminally failed.',
                $now
            );
        }

        if (! in_array(
            $operation['phase'],
            array(
                PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_CREATED,
                PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_COMMITTED,
                PeerTube_Staged_Upload_State_Machine::PHASE_PROCESSING,
            ),
            true
        )) {
            return $this->fail_claimed(
                $task_id,
                self::TASK_REMOTE_RECONCILE,
                $lock_token,
                'Remote reconciliation phase is outside the R45 execution boundary.',
                $now
            );
        }

        try {
            $service = ($this->reconciliation_advance)($operation['operation_id'], $now);
        } catch (Throwable) {
            return $this->fail_claimed(
                $task_id,
                self::TASK_REMOTE_RECONCILE,
                $lock_token,
                'Remote reconciliation raised an indeterminate coordinator boundary failure.',
                $now,
                PeerTube_Remote_Asset_Reconciliation_Service::STATUS_INDETERMINATE
            );
        }

        $service_status = is_string($service['status'] ?? null) ? $service['status'] : '';
        $current = $this->read_operation($operation['operation_id']);
        if (null === $current) {
            return $this->fail_claimed(
                $task_id,
                self::TASK_REMOTE_RECONCILE,
                $lock_token,
                'Remote reconciliation operation could not be re-read after advancement.',
                $now,
                $service_status
            );
        }

        if (PeerTube_Remote_Asset_Reconciliation_Service::STATUS_READY_VERIFIED === $service_status
            && PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED === $current['phase']) {
            return $this->complete_claimed(
                $task_id,
                self::TASK_REMOTE_RECONCILE,
                $lock_token,
                $now,
                $service_status
            );
        }

        if (PeerTube_Remote_Asset_Reconciliation_Service::STATUS_REMOTE_COMMITTED === $service_status
            && PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_COMMITTED === $current['phase']) {
            return $this->reschedule_claimed(
                $task_id,
                self::TASK_REMOTE_RECONCILE,
                $lock_token,
                $now,
                'Remote identity was committed; readiness observation remains a later bounded step.',
                $now,
                $service_status
            );
        }

        if (in_array(
            $service_status,
            array(
                PeerTube_Remote_Asset_Reconciliation_Service::STATUS_PROCESSING,
                PeerTube_Remote_Asset_Reconciliation_Service::STATUS_WAIT,
            ),
            true
        )) {
            $run_after = self::wait_run_after($current, $now);
            if ($run_after < 1) {
                return $this->fail_claimed(
                    $task_id,
                    self::TASK_REMOTE_RECONCILE,
                    $lock_token,
                    'Remote reconciliation returned a wait without a valid durable retry boundary.',
                    $now,
                    $service_status
                );
            }
            return $this->reschedule_claimed(
                $task_id,
                self::TASK_REMOTE_RECONCILE,
                $lock_token,
                $run_after,
                'PeerTube remote readiness reconciliation is durably waiting.',
                $now,
                $service_status
            );
        }

        return $this->fail_claimed(
            $task_id,
            self::TASK_REMOTE_RECONCILE,
            $lock_token,
            'Remote reconciliation stopped at an explicit intervention or terminal boundary.',
            $now,
            $service_status
        );
    }

    /** @param array<string,mixed> $operation */
    private function handoff_to_reconciliation(
        int $task_id,
        string $lock_token,
        array $operation,
        string $service_status,
        int $now
    ): array {
        $queued = $this->enqueue_operation(self::TASK_REMOTE_RECONCILE, $operation, $now);
        if (in_array($queued['status'], array(Task_Repository::APPLIED, Task_Repository::PRESENT), true)) {
            return $this->complete_claimed(
                $task_id,
                self::TASK_UPLOAD_ADVANCE,
                $lock_token,
                $now,
                $service_status
            );
        }

        if (Task_Repository::INDETERMINATE === $queued['status']) {
            $run_after = $now <= PHP_INT_MAX - self::LOCAL_RETRY_SECONDS
                ? $now + self::LOCAL_RETRY_SECONDS
                : $now;
            return $this->reschedule_claimed(
                $task_id,
                self::TASK_UPLOAD_ADVANCE,
                $lock_token,
                $run_after,
                'Remote reconciliation task enqueue is indeterminate; retrying the local idempotent handoff later.',
                $now,
                $service_status
            );
        }

        return $this->fail_claimed(
            $task_id,
            self::TASK_UPLOAD_ADVANCE,
            $lock_token,
            'Remote reconciliation task handoff conflicted with its deterministic task identity.',
            $now,
            $service_status
        );
    }

    /** @param array<string,mixed> $operation @return array{status:string,task_id:int} */
    private function enqueue_operation(string $task_type, array $operation, int $now): array
    {
        if (! PeerTube_Staged_Upload_State_Machine::valid($operation)
            || ! in_array($task_type, array(self::TASK_UPLOAD_ADVANCE, self::TASK_REMOTE_RECONCILE), true)
            || $now < 1) {
            return self::enqueue_result(Task_Repository::CONFLICT);
        }

        $payload = array(
            'version' => self::PAYLOAD_VERSION,
            'operation_id' => $operation['operation_id'],
        );

        return $this->tasks->enqueue(
            $task_type,
            $operation['video_post_id'],
            null,
            $operation['backend_id'],
            self::idempotency_key($task_type, $operation['operation_id']),
            $payload,
            $now,
            $now,
            self::PRIORITY,
            PeerTube_Staged_Upload_State_Machine::MAX_UPLOAD_ATTEMPTS
        );
    }

    /** @return array<string,mixed>|null */
    private function read_operation(string $operation_id): ?array
    {
        if (1 !== preg_match('/\Aupload_[a-f0-9]{32}\z/D', $operation_id)) {
            return null;
        }

        try {
            $operation = ($this->operation_reader)($operation_id);
        } catch (Throwable) {
            return null;
        }

        return is_array($operation)
            && PeerTube_Staged_Upload_State_Machine::valid($operation)
            && hash_equals($operation_id, (string) ($operation['operation_id'] ?? ''))
            ? $operation
            : null;
    }

    /** @param array<string,mixed> $task @param array<string,mixed> $operation */
    private static function task_matches_operation(array $task, array $operation, string $task_type): bool
    {
        $video_post_id = self::db_nonnegative_int($task['video_post_id'] ?? null);
        $priority = self::db_nonnegative_int($task['priority'] ?? null);
        $max_attempts = self::db_nonnegative_int($task['max_attempts'] ?? null);
        $attempts = self::db_nonnegative_int($task['attempts'] ?? null);

        return $video_post_id === $operation['video_post_id']
            && self::unbound_remote_asset($task['remote_asset_id'] ?? null)
            && is_string($task['backend_id'] ?? null) && $task['backend_id'] === $operation['backend_id']
            && is_string($task['idempotency_key'] ?? null)
            && $task['idempotency_key'] === self::idempotency_key($task_type, $operation['operation_id'])
            && self::PRIORITY === $priority
            && PeerTube_Staged_Upload_State_Machine::MAX_UPLOAD_ATTEMPTS === $max_attempts
            && is_int($attempts) && $attempts >= 1
            && is_int($max_attempts) && $attempts <= $max_attempts;
    }

    /** @param mixed $payload_json @return array{version:int,operation_id:string}|null */
    private static function payload(mixed $payload_json): ?array
    {
        if (! is_string($payload_json) || '' === $payload_json || strlen($payload_json) > 16384) {
            return null;
        }

        try {
            $payload = json_decode($payload_json, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload)
            || array('version','operation_id') !== array_keys($payload)
            || self::PAYLOAD_VERSION !== ($payload['version'] ?? null)
            || ! is_string($payload['operation_id'] ?? null)
            || 1 !== preg_match('/\Aupload_[a-f0-9]{32}\z/D', $payload['operation_id'])) {
            return null;
        }

        return array(
            'version' => self::PAYLOAD_VERSION,
            'operation_id' => $payload['operation_id'],
        );
    }

    /** @param array<string,mixed> $task @return array{task_id:int,task_type:string,lock_token:string}|null */
    private static function claimed_identity(array $task): ?array
    {
        $task_id = self::db_nonnegative_int($task['id'] ?? null) ?? 0;
        $task_type = is_string($task['task_type'] ?? null) ? $task['task_type'] : '';
        $lock_token = is_string($task['lock_token'] ?? null) ? $task['lock_token'] : '';
        if ($task_id < 1
            || Task_Repository::STATUS_PROCESSING !== ($task['status'] ?? null)
            || 1 !== preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $lock_token)
            || '' === $task_type) {
            return null;
        }

        return array(
            'task_id' => $task_id,
            'task_type' => $task_type,
            'lock_token' => $lock_token,
        );
    }

    /** @param mixed $error */
    private static function empty_error(mixed $error): bool
    {
        return is_array($error)
            && array('code','http_status','retry_after') === array_keys($error)
            && '' === ($error['code'] ?? null)
            && 0 === ($error['http_status'] ?? null)
            && 0 === ($error['retry_after'] ?? null);
    }

    /** @param array<string,mixed> $operation */
    private static function wait_run_after(array $operation, int $now): int
    {
        if (! PeerTube_Staged_Upload_State_Machine::valid($operation)) {
            return 0;
        }
        $updated_at = is_int($operation['updated_at'] ?? null) ? $operation['updated_at'] : 0;
        $retry_after = is_int($operation['last_error']['retry_after'] ?? null)
            ? $operation['last_error']['retry_after']
            : 0;
        if ($updated_at < 1 || $retry_after < 1 || $updated_at > PHP_INT_MAX - $retry_after) {
            return 0;
        }
        $run_after = $updated_at + $retry_after;
        return $run_after > $now ? $run_after : 0;
    }

    private static function upload_phase(string $phase): bool
    {
        return in_array(
            $phase,
            array(
                PeerTube_Staged_Upload_State_Machine::PHASE_READY,
                PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_IN_FLIGHT,
                PeerTube_Staged_Upload_State_Machine::PHASE_RETRY_WAIT,
                PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE,
                PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_CREATED,
                PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_COMMITTED,
                PeerTube_Staged_Upload_State_Machine::PHASE_PROCESSING,
                PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED,
                PeerTube_Staged_Upload_State_Machine::PHASE_FAILED,
            ),
            true
        );
    }

    private static function reconciliation_phase(string $phase): bool
    {
        return in_array(
            $phase,
            array(
                PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_CREATED,
                PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_COMMITTED,
                PeerTube_Staged_Upload_State_Machine::PHASE_PROCESSING,
                PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED,
                PeerTube_Staged_Upload_State_Machine::PHASE_FAILED,
            ),
            true
        );
    }

    private static function idempotency_key(string $task_type, string $operation_id): string
    {
        return hash('sha256', 'awvp-task:v1:' . $task_type . ':' . $operation_id);
    }

    private static function unbound_remote_asset(mixed $value): bool
    {
        return null === $value || '' === $value;
    }

    private static function db_nonnegative_int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (! is_string($value) || 1 !== preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value)) {
            return null;
        }
        $int = (int) $value;
        return $int >= 0 && (string) $int === $value ? $int : null;
    }

    /** @return array{status:string,task_id:int} */
    private static function enqueue_result(string $status, int $task_id = 0): array
    {
        return array('status' => $status, 'task_id' => max(0, $task_id));
    }

    /** @return array{status:string,task_id:int,task_type:string,service_status:string,repository_status:string,run_after:int} */
    private function complete_claimed(
        int $task_id,
        string $task_type,
        string $lock_token,
        int $now,
        string $service_status = ''
    ): array {
        $repository_status = $this->tasks->complete($task_id, $lock_token, $now);
        return self::transition_result(
            $repository_status,
            self::STATUS_COMPLETE,
            $task_id,
            $task_type,
            $service_status,
            0
        );
    }

    /** @return array{status:string,task_id:int,task_type:string,service_status:string,repository_status:string,run_after:int} */
    private function fail_claimed(
        int $task_id,
        string $task_type,
        string $lock_token,
        string $message,
        int $now,
        string $service_status = ''
    ): array {
        $repository_status = $this->tasks->fail($task_id, $lock_token, $message, $now);
        return self::transition_result(
            $repository_status,
            self::STATUS_FAILED,
            $task_id,
            $task_type,
            $service_status,
            0
        );
    }

    /** @return array{status:string,task_id:int,task_type:string,service_status:string,repository_status:string,run_after:int} */
    private function reschedule_claimed(
        int $task_id,
        string $task_type,
        string $lock_token,
        int $run_after,
        string $message,
        int $now,
        string $service_status = ''
    ): array {
        $repository_status = $this->tasks->reschedule($task_id, $lock_token, $run_after, $message, $now);
        $success = Task_Repository::EXHAUSTED === $repository_status ? self::STATUS_FAILED : self::STATUS_REQUEUED;
        return self::transition_result(
            $repository_status,
            $success,
            $task_id,
            $task_type,
            $service_status,
            $run_after
        );
    }

    /** @return array{status:string,task_id:int,task_type:string,service_status:string,repository_status:string,run_after:int} */
    private static function transition_result(
        string $repository_status,
        string $success_status,
        int $task_id,
        string $task_type,
        string $service_status,
        int $run_after
    ): array {
        $status = match ($repository_status) {
            Task_Repository::APPLIED, Task_Repository::EXHAUSTED => $success_status,
            Task_Repository::CONFLICT => self::STATUS_CONFLICT,
            default => self::STATUS_INDETERMINATE,
        };
        return self::result($status, $task_id, $task_type, $service_status, $repository_status, $run_after);
    }

    /** @return array{status:string,task_id:int,task_type:string,service_status:string,repository_status:string,run_after:int} */
    private static function result(
        string $status,
        int $task_id = 0,
        string $task_type = '',
        string $service_status = '',
        string $repository_status = '',
        int $run_after = 0
    ): array {
        return array(
            'status' => $status,
            'task_id' => max(0, $task_id),
            'task_type' => $task_type,
            'service_status' => $service_status,
            'repository_status' => $repository_status,
            'run_after' => max(0, $run_after),
        );
    }
}

// EOF: includes/PeerTube_Upload_Task_Coordinator.php
