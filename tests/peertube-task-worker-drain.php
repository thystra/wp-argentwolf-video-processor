<?php
/** Focused dependency-free tests for R45.4b3 bounded PeerTube task draining. */
declare(strict_types=1);

namespace ArgentVideo {
    final class Task_Repository
    {
        public const APPLIED = 'applied';
        public const STATUS_QUEUED = 'queued';

        /** @var list<array{at:int,task:array<string,mixed>}> */
        public array $scheduled = array();
        /** @var array<int,list<array<string,mixed>>> */
        public array $exact_claims = array();
        /** @var list<int> */
        public array $exact_claim_ids = array();
        public int $claim_next_calls = 0;

        public function recover_stale_of_types(array $types, int $stale_before, int $now, int $limit = 100): int
        {
            unset($types, $stale_before, $now, $limit);
            return 0;
        }

        public function claim_next_of_types(array $types, int $now): ?array
        {
            unset($types);
            $this->claim_next_calls++;
            foreach ($this->scheduled as $index => $entry) {
                if ($entry['at'] <= $now) {
                    $task = $entry['task'];
                    array_splice($this->scheduled, $index, 1);
                    return $task;
                }
            }
            return null;
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

        public function next_run_after_of_types(array $types, int $now): int
        {
            unset($types);
            $next = 0;
            foreach ($this->scheduled as $entry) {
                if ($entry['at'] > $now && (0 === $next || $entry['at'] < $next)) {
                    $next = $entry['at'];
                }
            }
            return $next;
        }
    }

    final class PeerTube_Upload_Task_Coordinator
    {
        public const TASK_UPLOAD_ADVANCE = 'peertube_upload_advance';
        public const TASK_REMOTE_RECONCILE = 'peertube_remote_reconcile';
        public const TASK_FAILURE_NOTIFY = 'peertube_upload_failure_notify';
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
        public const PHASE_FAILED='failed';
        public const PHASE_UPLOAD_INDETERMINATE='upload_indeterminate';
        public const PHASE_PROCESSING='processing';
        public const PHASE_READY_VERIFIED='ready_verified';
        public static function valid(array $operation): bool
        {
            return isset($operation['operation_id'], $operation['video_post_id'], $operation['backend_id'], $operation['source']['bytes']);
        }
    }

    final class PeerTube_Event_Repository
    {
        public array $records=array();
        public function record(int $video_id,int $pipeline_step,string $event_code,string $severity,string $message,int $now,int $task_id=0,string $operation_id='',int $remote_asset_id=0,string $backend_id='',int $http_status=0,string $automatic_action='',string $operator_action='',array $context=array()):bool
        {
            $this->records[]=compact('video_id','pipeline_step','event_code','severity','message','now','task_id','operation_id','remote_asset_id','backend_id','http_status','automatic_action','operator_action','context');
            return true;
        }
    }

    final class PeerTube_Upload_Runtime_Budget
    {
        public static function watcher_seconds(): int
        {
            return 120;
        }

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

    // RC9 watcher behavior: immediate same-task work is preferred, deterministic
    // handoff remains durable/global, a newly-published task can run while an
    // older reconciliation waits, and the older work resumes afterward.
    $tasks = new Task_Repository();
    $coordinator = new Coordinator();
    $tasks->scheduled = array(
        array('at'=>1000,'task'=>$task(41, Coordinator::TASK_UPLOAD_ADVANCE)),
        array('at'=>1000,'task'=>$task(91, Coordinator::TASK_REMOTE_RECONCILE)),
        array('at'=>1005,'task'=>$task(120, Coordinator::TASK_FAILURE_NOTIFY)),
        array('at'=>1030,'task'=>$task(91, Coordinator::TASK_REMOTE_RECONCILE)),
    );
    $tasks->exact_claims[41] = array($task(41, Coordinator::TASK_UPLOAD_ADVANCE));
    $coordinator->results = array(
        array('status'=>'requeued','task_id'=>41,'task_type'=>Coordinator::TASK_UPLOAD_ADVANCE,'repository_status'=>'applied','run_after'=>1000),
        array('status'=>'complete','task_id'=>41,'task_type'=>Coordinator::TASK_UPLOAD_ADVANCE,'repository_status'=>'applied','run_after'=>0),
        array('status'=>'requeued','task_id'=>91,'task_type'=>Coordinator::TASK_REMOTE_RECONCILE,'repository_status'=>'applied','run_after'=>1030),
        array('status'=>'complete','task_id'=>120,'task_type'=>Coordinator::TASK_FAILURE_NOTIFY,'repository_status'=>'applied','run_after'=>0),
        array('status'=>'complete','task_id'=>91,'task_type'=>Coordinator::TASK_REMOTE_RECONCILE,'repository_status'=>'applied','run_after'=>0),
    );
    $time = 1000;
    $clock = static function () use (&$time): int { return $time; };
    $sleeps = array();
    $sleeper = static function (int $seconds) use (&$time, &$sleeps): void { $sleeps[]=$seconds; $time += $seconds; };
    $events = new \ArgentVideo\PeerTube_Event_Repository();
    $worker = new PeerTube_Task_Worker($tasks, $coordinator, static fn(string $id): ?array => $id === $operation_id ? $operation : null, null, null, $events);
    $drained = $worker->run_drain(1000, $clock, $sleeper);
    $assert(PeerTube_Task_Worker::STATUS_ADVANCED === $drained['status'], 'RC9 watcher did not drain the bounded owned queue cleanly.');
    $assert(5 === $drained['steps'], 'RC9 watcher did not advance the expected five durable boundaries.');
    $assert(array(41) === $tasks->exact_claim_ids, 'Only the immediate same-task continuation should use exact reclaim.');
    $assert(array(41,41,91,120,91) === $coordinator->task_ids, 'RC9 watcher did not service new work before resuming the future reconciliation.');
    $assert(5===count($events->records),'RC10 worker did not append one operator event per durable task boundary.');
    $assert(3===($events->records[0]['pipeline_step']??0)&&5===($events->records[2]['pipeline_step']??0),'RC10 worker pipeline-step mapping drifted for upload/reconciliation tasks.');
    $assert(array() !== $sleeps && max($sleeps) <= 5, 'RC9 watcher exceeded its five-second sleep slice.');
    $assert(3600 === $drained['budget_seconds'], 'Drain lost the size-derived one-hour process floor.');

    // A future boundary at or beyond the size-derived deadline yields without
    // sleeping or replaying the just-completed request.
    $large = $operation;
    $large['source']['bytes'] = 10 * 1024 * 1024 * 1024;
    $tasks = new Task_Repository();
    $coordinator = new Coordinator();
    $tasks->scheduled = array(
        array('at'=>2000,'task'=>$task(51, Coordinator::TASK_UPLOAD_ADVANCE)),
        array('at'=>7000,'task'=>$task(51, Coordinator::TASK_UPLOAD_ADVANCE)),
    );
    $coordinator->results[] = array(
        'status'=>'requeued','task_id'=>51,'task_type'=>Coordinator::TASK_UPLOAD_ADVANCE,
        'repository_status'=>'applied','run_after'=>7000,
    );
    $time = 2000; $sleeps = array();
    $clock = static function () use (&$time): int { return $time; };
    $sleeper = static function (int $seconds) use (&$time, &$sleeps): void { $sleeps[]=$seconds; $time += $seconds; };
    $worker = new PeerTube_Task_Worker($tasks, $coordinator, static fn(string $id): ?array => $id === $operation_id ? $large : null);
    $yielded = $worker->run_drain(2000, $clock, $sleeper);
    $assert(PeerTube_Task_Worker::STATUS_YIELDED === $yielded['status'], 'Drain did not yield at the future safe-boundary deadline.');
    $assert(1 === $yielded['steps'] && 4800 === $yielded['budget_seconds'], 'Yield result lost bounded progress/budget evidence.');
    $assert(array() === $sleeps, 'Drain slept even though the next durable boundary was outside its process budget.');

    // Publication-only/pre-upload work must not inherit the upload process
    // floor. It watches briefly and yields when its next durable boundary lies
    // beyond that short process lifetime.
    $publication_task = $task(57, 'peertube_publication_sync');
    $tasks = new Task_Repository();
    $coordinator = new Coordinator();
    $tasks->scheduled = array(
        array('at'=>2500,'task'=>$publication_task),
        array('at'=>2800,'task'=>$publication_task),
    );
    $publication_calls = array();
    $publication_advance = static function (array $claimed, int $now) use (&$publication_calls): array {
        $publication_calls[] = array((int)($claimed['id']??0), $now);
        return array(
            'status'=>'requeued','task_id'=>(int)($claimed['id']??0),
            'task_type'=>(string)($claimed['task_type']??''),
            'repository_status'=>'applied','run_after'=>2800,
        );
    };
    $time = 2500; $sleeps = array();
    $clock = static function () use (&$time): int { return $time; };
    $sleeper = static function (int $seconds) use (&$time, &$sleeps): void { $sleeps[]=$seconds; $time += $seconds; };
    $worker = new PeerTube_Task_Worker(
        $tasks, $coordinator,
        static fn(string $id): ?array => $id === $operation_id ? $operation : null,
        $publication_advance
    );
    $preupload = $worker->run_drain(2500, $clock, $sleeper);
    $assert(PeerTube_Task_Worker::STATUS_YIELDED === $preupload['status'], 'Pre-upload publication watcher did not yield at its short process boundary.');
    $assert(120 === $preupload['budget_seconds'], 'Pre-upload publication watcher inherited an hour-scale upload budget.');
    $assert(1 === $preupload['steps'] && array(array(57,2500)) === $publication_calls, 'Pre-upload publication watcher did not stop after its first durable boundary.');
    $assert(array() === $sleeps, 'Pre-upload publication watcher slept even though its next boundary was outside the short watcher budget.');

    // Failure notification remains a normal owned durable task. After it
    // completes, an empty queue ends the watcher rather than inventing work.
    $notification_payload = json_encode(array(
        'version'=>1,
        'operation_id'=>$operation_id,
        'failure_revision'=>7,
        'failure'=>array(
            'state'=>'upload_indeterminate','failed_at'=>3000,'confirmed_bytes'=>0,
            'source_bytes'=>1024 * 1024 * 1024,'request_kind'=>'chunk','request_start'=>0,
            'request_bytes'=>128 * 1024 * 1024,'awvp_error_code'=>'peertube.upload.indeterminate',
            'http_status'=>0,'retry_after'=>0,'service_status'=>'indeterminate',
            'error_status'=>'transport_timeout','service_error_code'=>'curl_28',
            'detail'=>'Synthetic timeout.','reason'=>'Explicit intervention boundary.',
        ),
    ), JSON_UNESCAPED_SLASHES);
    $notification_task = $task(61, Coordinator::TASK_FAILURE_NOTIFY);
    $notification_task['payload_json'] = $notification_payload;
    $tasks = new Task_Repository();
    $coordinator = new Coordinator();
    $tasks->scheduled[] = array('at'=>3000,'task'=>$notification_task);
    $coordinator->results[] = array(
        'status'=>'complete','task_id'=>61,'task_type'=>Coordinator::TASK_FAILURE_NOTIFY,
        'repository_status'=>'applied','run_after'=>0,
    );
    $events = new \ArgentVideo\PeerTube_Event_Repository();
    $worker = new PeerTube_Task_Worker($tasks, $coordinator, static fn(string $id): ?array => $id === $operation_id ? $operation : null, null, null, $events);
    $notified = $worker->run_drain(3000, static fn(): int => 3000, static function(int $seconds):void{unset($seconds);});
    $assert(PeerTube_Task_Worker::STATUS_ADVANCED === $notified['status'], 'Drain did not complete the durable notification branch.');
    $assert(1 === $notified['steps'] && array(61) === $coordinator->task_ids, 'Failure notification did not consume exactly one durable boundary.');
    $assert(1 === count($events->records), 'Failure notification did not write one operator diagnostic event.');
    $failure_event = $events->records[0];
    $assert('transport_timeout' === $failure_event['event_code'] && 'error' === $failure_event['severity'], 'Failure event lost its sanitized transport classification.');
    $assert(4 === $failure_event['pipeline_step'] && 0 === $failure_event['http_status'], 'Failure event did not map byte-bearing timeout to transfer Step 4.');
    $assert($operation_id === $failure_event['operation_id'], 'Failure event lost the durable upload operation ID.');
    $assert('transport_timeout' === ($failure_event['context']['error_status'] ?? '') && 'curl_28' === ($failure_event['context']['error_code'] ?? ''), 'Failure event lost transport/API diagnostic evidence.');
    $assert(0 === ($failure_event['context']['confirmed_bytes'] ?? -1) && 1024 * 1024 * 1024 === ($failure_event['context']['source_bytes'] ?? 0), 'Failure event lost byte-progress evidence from the immutable failure snapshot.');
    $assert(str_contains($failure_event['message'], 'Synthetic timeout.') && str_contains($failure_event['operator_action'], 'network/server responsiveness'), 'Failure event did not surface useful timeout detail and operator guidance.');

    $source = (string) file_get_contents(dirname(__DIR__) . '/includes/PeerTube_Task_Worker.php');
    foreach (array('usleep(', 'wp_schedule', 'exec(', 'proc_open', 'shell_exec') as $needle) {
        $assert(! str_contains($source, $needle), 'Drain worker acquired forbidden scheduler/process authority: '.$needle);
    }
    $assert(str_contains($source, "min(\n                    5,"), 'RC9 watcher lost the bounded five-second wait slice.');
    $assert(str_contains($source, 'next_run_after_of_types'), 'RC9 watcher lost durable future-work observation.');

    fwrite(STDOUT, "PeerTube task worker RC9 drain/watcher tests passed.\n");
}
