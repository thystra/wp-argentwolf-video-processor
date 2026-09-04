<?php
/**
 * File: includes/PeerTube_Task_Worker.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/**
 * One-shot consumer for the two R45 PeerTube task types.
 *
 * This worker owns no scheduler, detached launcher, administrator action,
 * credential refresh, upload/reconciliation implementation, cleanup,
 * publication, retention, or remote-delete policy. One invocation may recover
 * stale locks for its own task types and advance at most one claimed task.
 */
final class PeerTube_Task_Worker
{
    public const STATUS_IDLE = 'idle';
    public const STATUS_ADVANCED = 'advanced';
    public const STATUS_INDETERMINATE = 'indeterminate';

    public const STALE_LOCK_SECONDS = 900;

    private const RECOVERY_LIMIT = 20;

    /** @var list<string> */
    private const TASK_TYPES = array(
        PeerTube_Upload_Task_Coordinator::TASK_UPLOAD_ADVANCE,
        PeerTube_Upload_Task_Coordinator::TASK_REMOTE_RECONCILE,
    );

    public function __construct(
        private readonly Task_Repository $tasks,
        private readonly PeerTube_Upload_Task_Coordinator $coordinator
    ) {
    }

    /**
     * Recover stale locks owned by this worker and advance at most one task.
     *
     * @return array{
     *   status:string,
     *   recovered:int,
     *   task_id:int,
     *   task_type:string,
     *   coordinator_status:string
     * }
     */
    public function run_once(int $now): array
    {
        if ($now < 1) {
            return self::result(self::STATUS_INDETERMINATE);
        }

        $stale_before = max(1, $now - self::STALE_LOCK_SECONDS);
        $recovered = $this->tasks->recover_stale_of_types(
            self::TASK_TYPES,
            $stale_before,
            $now,
            self::RECOVERY_LIMIT
        );

        $task = $this->tasks->claim_next_of_types(self::TASK_TYPES, $now);
        if (! is_array($task)) {
            return self::result(self::STATUS_IDLE, $recovered);
        }

        $task_id = self::positive_int($task['id'] ?? null);
        $task_type = is_string($task['task_type'] ?? null) ? $task['task_type'] : '';
        if ($task_id < 1 || ! in_array($task_type, self::TASK_TYPES, true)) {
            // Do not mutate a row whose ownership cannot be re-proved. Its
            // lock remains durable evidence and can be reviewed/recovered.
            return self::result(self::STATUS_INDETERMINATE, $recovered, $task_id, $task_type);
        }

        try {
            $advanced = $this->coordinator->advance_claimed($task, $now);
        } catch (Throwable) {
            // A process-level or unexpected coordinator failure must not guess
            // whether remote work happened. Preserve the processing lock for
            // the reviewed stale-recovery path.
            return self::result(self::STATUS_INDETERMINATE, $recovered, $task_id, $task_type);
        }

        $coordinator_status = is_string($advanced['status'] ?? null) ? $advanced['status'] : '';
        if (
            $task_id !== self::positive_int($advanced['task_id'] ?? null)
            || $task_type !== ($advanced['task_type'] ?? null)
            || '' === $coordinator_status
        ) {
            return self::result(self::STATUS_INDETERMINATE, $recovered, $task_id, $task_type);
        }

        return self::result(
            self::STATUS_ADVANCED,
            $recovered,
            $task_id,
            $task_type,
            $coordinator_status
        );
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

    /**
     * @return array{
     *   status:string,
     *   recovered:int,
     *   task_id:int,
     *   task_type:string,
     *   coordinator_status:string
     * }
     */
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
}

// EOF: includes/PeerTube_Task_Worker.php
