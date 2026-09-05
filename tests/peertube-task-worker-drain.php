<?php
/** Focused dependency-free tests for R45.4b3 bounded PeerTube task draining. */
declare(strict_types=1);

namespace ArgentVideo {
    final class Task_Repository
    {
        public const APPLIED = 'applied';
        public const STATUS_QUEUED = 'queued';

        /** @var list<array<string,mixed>> */
        public array $initial_claims = array();
        /** @var array<int,list<array<string,mixed>>> */
        public array $exact_claims = array();
        /** @var list<int> */
        public array $exact_claim_ids = array();
        /** @var list<array<string,mixed>> */
        public array $find_by_key_results = array();
        public int $claim_next_calls = 0;

        public function recover_stale_of_types(array $types, int $stale_before, int $now, int $limit = 100): int
        {
            unset($types, $stale_before, $now, $limit);
            return 0;
        }

        public function claim_next_of_types(array $types, int $now): ?array
        {
            unset($types, $now);
            $this->claim_next_calls++;
            return array_shift($this->initial_claims);
        }

        public function claim_task_of_types(int $task_id, array $types, int $now): ?array
        {
            unset($types, $now);
            $this->exact_claim_ids[] = $task_id;
            if (! isset($this->exact_claims[$task_id])) {
                return null;
            }
            return array_shift($this->exact_claims[$task_id]);
        }

        public function find_by_idempotency_key(string $key): ?array
        {
            unset($key);
            return array_shift($this->find_by_key_results);
        }
    }

    final class PeerTube_Upload_Task_Coordinator
    {
        public const TASK_UPLOAD_ADVANCE = 'peertube_upload_advance';
        public const TASK_REMOTE_RECONCILE = 'peertube_remote_reconcile';
        public const PAYLOAD_VERSION = 1;
        public const STATUS_REQUEUED = 'requeued';
        public const STATUS_COMPLETE = 'complete';

        /** @var list<array<string,mixed>> */
        public array $results = array();
        /** @var list<int> */
        public array $task_ids = array();

        public function advance_claimed(array $task, int $now): array
        {
            unset($now);
            $this->task_ids[] = (int) ($task['id'] ?? 0);
            return array_shift($this->results) ?? array();
        }
    }

    final class PeerTube_Staged_Upload_State_Machine
    {
        public static function valid(array $operation): bool
        {
            return isset($operation['operation_id'], $operation['video_post_id'], $operation['backend_id'], $operation['source']['bytes']);
        }
    }

    final class PeerTube_Upload_Runtime_Budget
    {
        public static function process_seconds(int $source_bytes): int
        {
            return $source_bytes >= 10 * 1024 * 1024 * 1024 ? 4800 : 3600;
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/includes/PeerTube_Task_Worker.php';

    use ArgentVideo\PeerTube_Task_Worker;
    use ArgentVideo\PeerTube_Upload_Task_Coordinator as Coordinator;
    use ArgentVideo\Task_Repository;

    $assert = static function (bool $ok, string $message): void {
        if (! $ok) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };

    $operation_id = 'upload_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    $payload = json_encode(array('version'=>1,'operation_id'=>$operation_id), JSON_UNESCAPED_SLASHES);
    $operation = array(
        'operation_id'=>$operation_id,
        'video_post_id'=>77,
        'backend_id'=>'peertube-primary',
        'source'=>array('bytes'=>1024 * 1024 * 1024),
    );
    $task = static fn(int $id, string $type): array => array(
        'id'=>$id,
        'task_type'=>$type,
        'video_post_id'=>77,
        'backend_id'=>'peertube-primary',
        'payload_json'=>$payload,
        'status'=>'processing',
        'lock_token'=>sprintf('00000000-0000-4000-8000-%012d', $id),
    );

    // Drain reclaims only the same task between immediate upload boundaries,
    // then follows the deterministic reconciliation handoff for that operation.
    $tasks = new Task_Repository();
    $coordinator = new Coordinator();
    $tasks->initial_claims[] = $task(41, Coordinator::TASK_UPLOAD_ADVANCE);
    $tasks->exact_claims[41] = array($task(41, Coordinator::TASK_UPLOAD_ADVANCE));
    $tasks->find_by_key_results[] = array('id'=>91,'task_type'=>Coordinator::TASK_REMOTE_RECONCILE,'status'=>'queued');
    $tasks->exact_claims[91] = array(
        $task(91, Coordinator::TASK_REMOTE_RECONCILE),
        $task(91, Coordinator::TASK_REMOTE_RECONCILE),
    );
    $coordinator->results = array(
        array('status'=>'requeued','task_id'=>41,'task_type'=>Coordinator::TASK_UPLOAD_ADVANCE,'repository_status'=>'applied','run_after'=>1000),
        array('status'=>'complete','task_id'=>41,'task_type'=>Coordinator::TASK_UPLOAD_ADVANCE,'repository_status'=>'applied','run_after'=>0),
        array('status'=>'requeued','task_id'=>91,'task_type'=>Coordinator::TASK_REMOTE_RECONCILE,'repository_status'=>'applied','run_after'=>1000),
        array('status'=>'requeued','task_id'=>91,'task_type'=>Coordinator::TASK_REMOTE_RECONCILE,'repository_status'=>'applied','run_after'=>1100),
    );
    $worker = new PeerTube_Task_Worker($tasks, $coordinator, static fn(string $id): ?array => $id === $operation_id ? $operation : null);
    $drained = $worker->run_drain(1000, static fn(): int => 1000);
    $assert(PeerTube_Task_Worker::STATUS_ADVANCED === $drained['status'], 'Drain did not stop cleanly at the future durable boundary.');
    $assert(4 === $drained['steps'], 'Drain did not advance the expected upload/handoff sequence.');
    $assert(array(41,91,91) === $tasks->exact_claim_ids, 'Drain claimed anything other than the same task and deterministic handoff.');
    $assert(array(41,41,91,91) === $coordinator->task_ids, 'Coordinator sequence drifted during drain.');
    $assert(1 === $tasks->claim_next_calls, 'Drain returned to global queue selection after the first claim.');
    $assert(3600 === $drained['budget_seconds'], 'Drain lost the size-derived one-hour process floor.');

    // A size-derived deadline yields only after a durable immediate boundary;
    // it does not claim/send the next segment once the boundary budget expires.
    $large = $operation;
    $large['source']['bytes'] = 10 * 1024 * 1024 * 1024;
    $tasks = new Task_Repository();
    $coordinator = new Coordinator();
    $tasks->initial_claims[] = $task(51, Coordinator::TASK_UPLOAD_ADVANCE);
    $coordinator->results[] = array(
        'status'=>'requeued','task_id'=>51,'task_type'=>Coordinator::TASK_UPLOAD_ADVANCE,
        'repository_status'=>'applied','run_after'=>2000,
    );
    $times = array(2000, 2000, 6800);
    $clock = static function () use (&$times): int { return array_shift($times) ?? 6800; };
    $worker = new PeerTube_Task_Worker($tasks, $coordinator, static fn(string $id): ?array => $id === $operation_id ? $large : null);
    $yielded = $worker->run_drain(2000, $clock);
    $assert(PeerTube_Task_Worker::STATUS_YIELDED === $yielded['status'], 'Drain did not yield at the size-derived safe-boundary deadline.');
    $assert(1 === $yielded['steps'] && 4800 === $yielded['budget_seconds'], 'Yield result lost bounded progress/budget evidence.');
    $assert(array() === $tasks->exact_claim_ids, 'Drain claimed another segment after the safe-boundary deadline.');

    $source = (string) file_get_contents(dirname(__DIR__) . '/includes/PeerTube_Task_Worker.php');
    foreach (array('sleep(', 'usleep(', 'wp_schedule', 'exec(', 'proc_open', 'shell_exec') as $needle) {
        $assert(! str_contains($source, $needle), 'Drain worker acquired forbidden wait/scheduler/process authority: '.$needle);
    }

    fwrite(STDOUT, "PeerTube task worker drain tests passed.\n");
}
