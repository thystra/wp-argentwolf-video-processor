<?php
/** Focused dependency-free tests for the R45 PeerTube task coordinator. */
declare(strict_types=1);

namespace ArgentVideo {
    final class Task_Repository
    {
        public const STATUS_QUEUED = 'queued';
        public const STATUS_PROCESSING = 'processing';
        public const STATUS_COMPLETE = 'complete';
        public const STATUS_FAILED = 'failed';
        public const APPLIED = 'applied';
        public const PRESENT = 'present';
        public const CONFLICT = 'conflict';
        public const INDETERMINATE = 'indeterminate';
        public const EXHAUSTED = 'exhausted';
        public const MAX_ATTEMPTS = 65535;

        /** @var array<int,array<string,mixed>> */
        public array $rows = array();
        /** @var list<string> */
        public array $log = array();
        public ?string $next_enqueue_status = null;
        private int $next_id = 1;

        /** @return array{status:string,task_id:int} */
        public function enqueue(
            string $task_type,
            ?int $video_post_id,
            ?int $remote_asset_id,
            ?string $backend_id,
            string $idempotency_key,
            array $payload,
            int $run_after,
            int $now,
            int $priority = 100,
            int $max_attempts = 5
        ): array {
            unset($now);
            $this->log[] = 'enqueue:' . $task_type;
            if (null !== $this->next_enqueue_status) {
                $status = $this->next_enqueue_status;
                $this->next_enqueue_status = null;
                return array('status'=>$status,'task_id'=>0);
            }
            foreach ($this->rows as $row) {
                if (($row['idempotency_key'] ?? '') === $idempotency_key) {
                    $exact = ($row['task_type'] ?? null) === $task_type
                        && ($row['video_post_id'] ?? null) === $video_post_id
                        && ($row['remote_asset_id'] ?? null) === $remote_asset_id
                        && ($row['backend_id'] ?? null) === $backend_id
                        && ($row['payload_json'] ?? null) === json_encode($payload, JSON_UNESCAPED_SLASHES)
                        && (int) ($row['priority'] ?? -1) === $priority
                        && (int) ($row['max_attempts'] ?? -1) === $max_attempts;
                    return array('status'=>$exact?self::PRESENT:self::CONFLICT,'task_id'=>$exact?(int)$row['id']:0);
                }
            }
            $id = $this->next_id++;
            $this->rows[$id] = array(
                'id'=>$id,
                'task_type'=>$task_type,
                'video_post_id'=>$video_post_id,
                'remote_asset_id'=>$remote_asset_id,
                'backend_id'=>$backend_id,
                'idempotency_key'=>$idempotency_key,
                'status'=>self::STATUS_QUEUED,
                'priority'=>$priority,
                'run_after'=>$run_after,
                'attempts'=>0,
                'max_attempts'=>$max_attempts,
                'lock_token'=>null,
                'payload_json'=>json_encode($payload, JSON_UNESCAPED_SLASHES),
                'error_message'=>null,
            );
            return array('status'=>self::APPLIED,'task_id'=>$id);
        }

        /** @return array<string,mixed>|null */
        public function claim_next(int $now): ?array
        {
            foreach ($this->rows as $id => $row) {
                if (self::STATUS_QUEUED !== ($row['status'] ?? null)
                    || (int) ($row['run_after'] ?? PHP_INT_MAX) > $now
                    || (int) ($row['attempts'] ?? 0) >= (int) ($row['max_attempts'] ?? 0)) {
                    continue;
                }
                $token = sprintf('00000000-0000-4000-8000-%012x', $id);
                $this->rows[$id]['status'] = self::STATUS_PROCESSING;
                $this->rows[$id]['lock_token'] = $token;
                $this->rows[$id]['attempts'] = ((int) $row['attempts']) + 1;
                $this->log[] = 'claim:' . $id;
                return $this->rows[$id];
            }
            return null;
        }

        public function complete(int $task_id, string $lock_token, int $now): string
        {
            unset($now);
            if (! $this->owns($task_id, $lock_token)) return self::CONFLICT;
            $this->log[] = 'complete:' . $task_id;
            $this->rows[$task_id]['status'] = self::STATUS_COMPLETE;
            $this->rows[$task_id]['lock_token'] = null;
            $this->rows[$task_id]['error_message'] = null;
            return self::APPLIED;
        }

        public function fail(int $task_id, string $lock_token, string $message, int $now): string
        {
            unset($now);
            if (! $this->owns($task_id, $lock_token)) return self::CONFLICT;
            $this->log[] = 'fail:' . $task_id;
            $this->rows[$task_id]['status'] = self::STATUS_FAILED;
            $this->rows[$task_id]['lock_token'] = null;
            $this->rows[$task_id]['error_message'] = $message;
            return self::APPLIED;
        }

        public function reschedule(int $task_id, string $lock_token, int $run_after, string $message, int $now): string
        {
            unset($now);
            if (! $this->owns($task_id, $lock_token)) return self::CONFLICT;
            if ((int) $this->rows[$task_id]['attempts'] >= (int) $this->rows[$task_id]['max_attempts']) {
                $this->rows[$task_id]['status'] = self::STATUS_FAILED;
                $this->rows[$task_id]['lock_token'] = null;
                return self::EXHAUSTED;
            }
            $this->log[] = 'reschedule:' . $task_id;
            $this->rows[$task_id]['status'] = self::STATUS_QUEUED;
            $this->rows[$task_id]['lock_token'] = null;
            $this->rows[$task_id]['run_after'] = $run_after;
            $this->rows[$task_id]['error_message'] = $message;
            return self::APPLIED;
        }

        /** @return array<string,mixed>|null */
        public function find(int $task_id): ?array { return $this->rows[$task_id] ?? null; }

        private function owns(int $task_id, string $lock_token): bool
        {
            return isset($this->rows[$task_id])
                && self::STATUS_PROCESSING === ($this->rows[$task_id]['status'] ?? null)
                && $lock_token === ($this->rows[$task_id]['lock_token'] ?? null);
        }
    }

    final class PeerTube_Staged_Upload_State_Machine
    {
        public const MAX_UPLOAD_ATTEMPTS = 65535;
        public const PHASE_READY = 'ready';
        public const PHASE_UPLOAD_IN_FLIGHT = 'upload_in_flight';
        public const PHASE_RETRY_WAIT = 'retry_wait';
        public const PHASE_UPLOAD_INDETERMINATE = 'upload_indeterminate';
        public const PHASE_REMOTE_CREATED = 'remote_created';
        public const PHASE_REMOTE_COMMITTED = 'remote_committed';
        public const PHASE_PROCESSING = 'processing';
        public const PHASE_READY_VERIFIED = 'ready_verified';
        public const PHASE_CLEANUP_PENDING = 'cleanup_pending';
        public const PHASE_COMPLETE = 'complete';
        public const PHASE_FAILED = 'failed';

        /** @param array<string,mixed> $record */
        public static function valid(array $record): bool
        {
            return is_string($record['operation_id'] ?? null)
                && 1 === preg_match('/^upload_[a-f0-9]{32}$/D', $record['operation_id'])
                && is_int($record['video_post_id'] ?? null) && $record['video_post_id'] > 0
                && is_string($record['backend_id'] ?? null) && '' !== $record['backend_id']
                && is_string($record['phase'] ?? null)
                && is_int($record['updated_at'] ?? null) && $record['updated_at'] > 0
                && is_array($record['last_error'] ?? null)
                && array('code','http_status','retry_after') === array_keys($record['last_error']);
        }
    }

    final class PeerTube_Staged_Upload_Service
    {
        public const STATUS_ADVANCED = 'advanced';
        public const STATUS_SESSION_CREATED = 'session_created';
        public const STATUS_CHUNK_ACCEPTED = 'chunk_accepted';
        public const STATUS_REMOTE_CREATED = 'remote_created';
        public const STATUS_WAIT = 'wait';
        public const STATUS_REFRESH_REQUIRED = 'refresh_required';
        public const STATUS_INDETERMINATE = 'indeterminate';
        public const STATUS_CONFLICT = 'conflict';
        public const STATUS_REFUSED = 'refused';
    }

    final class PeerTube_Remote_Asset_Reconciliation_Service
    {
        public const STATUS_REMOTE_COMMITTED = 'remote_committed';
        public const STATUS_PROCESSING = 'processing';
        public const STATUS_READY_VERIFIED = 'ready_verified';
        public const STATUS_WAIT = 'wait';
        public const STATUS_MISSING = 'missing';
        public const STATUS_FAILED = 'failed';
        public const STATUS_REFRESH_REQUIRED = 'refresh_required';
        public const STATUS_INDETERMINATE = 'indeterminate';
        public const STATUS_CONFLICT = 'conflict';
        public const STATUS_REFUSED = 'refused';
    }
}

namespace {
    require_once dirname(__DIR__) . '/includes/PeerTube_Upload_Task_Coordinator.php';

    use ArgentVideo\PeerTube_Remote_Asset_Reconciliation_Service as Reconcile_Service;
    use ArgentVideo\PeerTube_Staged_Upload_Service as Upload_Service;
    use ArgentVideo\PeerTube_Staged_Upload_State_Machine as Machine;
    use ArgentVideo\PeerTube_Upload_Task_Coordinator as Coordinator;
    use ArgentVideo\Task_Repository;

    $assert = static function (bool $ok, string $message): void {
        if (! $ok) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };

    $empty_error = static fn (): array => array('code'=>'','http_status'=>0,'retry_after'=>0);
    $operation = static function (string $operation_id, string $phase, int $updated_at) use ($empty_error): array {
        return array(
            'operation_id'=>$operation_id,
            'video_post_id'=>77,
            'backend_id'=>'peertube-primary',
            'phase'=>$phase,
            'updated_at'=>$updated_at,
            'last_error'=>$empty_error(),
        );
    };

    /** @return array{tasks:Task_Repository,state:object,coordinator:Coordinator} */
    $fixture = static function (string $phase = Machine::PHASE_READY, int $now = 1000) use ($operation, $empty_error): array {
        $id = 'upload_' . str_repeat('a', 32);
        $tasks = new Task_Repository();
        $state = (object) array(
            'operations'=>array($id=>$operation($id, $phase, $now)),
            'upload_mode'=>'progress',
            'reconcile_mode'=>'remote_committed',
            'upload_calls'=>0,
            'reconcile_calls'=>0,
        );
        $reader = static fn (string $operation_id): ?array => $state->operations[$operation_id] ?? null;
        $upload = static function (string $operation_id, int $at) use ($state, $empty_error): array {
            $state->upload_calls++;
            $record = $state->operations[$operation_id];
            if ('progress' === $state->upload_mode) {
                $record['phase'] = Machine::PHASE_READY;
                $record['updated_at'] = $at;
                $record['last_error'] = $empty_error();
                $status = Upload_Service::STATUS_CHUNK_ACCEPTED;
            } elseif ('wait' === $state->upload_mode) {
                $record['phase'] = Machine::PHASE_RETRY_WAIT;
                $record['updated_at'] = $at;
                $record['last_error'] = array('code'=>'peertube.upload.rate_limited','http_status'=>429,'retry_after'=>45);
                $status = Upload_Service::STATUS_WAIT;
            } elseif ('remote_created' === $state->upload_mode) {
                $record['phase'] = Machine::PHASE_REMOTE_CREATED;
                $record['updated_at'] = $at;
                $record['last_error'] = $empty_error();
                $status = Upload_Service::STATUS_REMOTE_CREATED;
            } elseif ('advanced_error' === $state->upload_mode) {
                $record['phase'] = Machine::PHASE_READY;
                $record['updated_at'] = $at;
                $record['last_error'] = array('code'=>'peertube.upload.backend_unavailable','http_status'=>0,'retry_after'=>0);
                $status = Upload_Service::STATUS_ADVANCED;
            } else {
                $status = Upload_Service::STATUS_REFUSED;
            }
            $state->operations[$operation_id] = $record;
            return array('status'=>$status);
        };
        $reconcile = static function (string $operation_id, int $at) use ($state, $empty_error): array {
            $state->reconcile_calls++;
            $record = $state->operations[$operation_id];
            if ('remote_committed' === $state->reconcile_mode) {
                $record['phase'] = Machine::PHASE_REMOTE_COMMITTED;
                $record['updated_at'] = $at;
                $record['last_error'] = $empty_error();
                $status = Reconcile_Service::STATUS_REMOTE_COMMITTED;
            } elseif ('processing' === $state->reconcile_mode) {
                $record['phase'] = Machine::PHASE_PROCESSING;
                $record['updated_at'] = $at;
                $record['last_error'] = array('code'=>'peertube.remote.processing_wait','http_status'=>0,'retry_after'=>30);
                $status = Reconcile_Service::STATUS_PROCESSING;
            } elseif ('wait' === $state->reconcile_mode) {
                $record['updated_at'] = $at;
                $record['last_error'] = array('code'=>'peertube.remote.reconcile_wait','http_status'=>503,'retry_after'=>60);
                $status = Reconcile_Service::STATUS_WAIT;
            } elseif ('ready' === $state->reconcile_mode) {
                $record['phase'] = Machine::PHASE_READY_VERIFIED;
                $record['updated_at'] = $at;
                $record['last_error'] = $empty_error();
                $status = Reconcile_Service::STATUS_READY_VERIFIED;
            } elseif ('refresh' === $state->reconcile_mode) {
                $status = Reconcile_Service::STATUS_REFRESH_REQUIRED;
            } else {
                $record['phase'] = Machine::PHASE_FAILED;
                $record['updated_at'] = $at;
                $record['last_error'] = array('code'=>'peertube.remote.processing_failed','http_status'=>0,'retry_after'=>0);
                $status = Reconcile_Service::STATUS_FAILED;
            }
            $state->operations[$operation_id] = $record;
            return array('status'=>$status);
        };
        return array(
            'tasks'=>$tasks,
            'state'=>$state,
            'coordinator'=>new Coordinator($tasks, $reader, $upload, $reconcile),
        );
    };

    // Deterministic enqueue: exact payload, task metadata, max-attempt scale, and idempotent replay.
    $f = $fixture();
    $operation_id = array_key_first($f['state']->operations);
    $first = $f['coordinator']->enqueue_upload($operation_id, 1000);
    $assert(Task_Repository::APPLIED === $first['status'] && 1 === $first['task_id'], 'Upload task enqueue failed.');
    $row = $f['tasks']->find(1);
    $assert(is_array($row), 'Upload task row missing.');
    $assert(Coordinator::TASK_UPLOAD_ADVANCE === $row['task_type'], 'Wrong upload task type.');
    $assert(77 === $row['video_post_id'] && null === $row['remote_asset_id'] && 'peertube-primary' === $row['backend_id'], 'Upload task authority columns drifted.');
    $assert(Task_Repository::MAX_ATTEMPTS === $row['max_attempts'], 'Upload task did not use the bounded staged-upload attempt scale.');
    $assert('{"version":1,"operation_id":"'.$operation_id.'"}' === $row['payload_json'], 'Upload task payload is not the exact minimal pointer contract.');
    $second = $f['coordinator']->enqueue_upload($operation_id, 1001);
    $assert(Task_Repository::PRESENT === $second['status'] && 1 === count($f['tasks']->rows), 'Exact upload enqueue replay created a duplicate task.');

    // One normal R43 advancement requeues exactly once and does not call R44.
    $claimed = $f['tasks']->claim_next(1000);
    $assert(is_array($claimed), 'Upload task did not claim.');
    $advanced = $f['coordinator']->advance_claimed($claimed, 1000);
    $assert(Coordinator::STATUS_REQUEUED === $advanced['status'] && Upload_Service::STATUS_CHUNK_ACCEPTED === $advanced['service_status'], 'Bounded upload advancement did not requeue.');
    $assert(1 === $f['state']->upload_calls && 0 === $f['state']->reconcile_calls, 'One upload task call crossed multiple service boundaries.');

    // Durable R43 wait maps to the exact operation-journal retry boundary.
    $f = $fixture();
    $operation_id = array_key_first($f['state']->operations);
    $f['state']->upload_mode = 'wait';
    $f['coordinator']->enqueue_upload($operation_id, 1000);
    $claimed = $f['tasks']->claim_next(1000);
    $wait = $f['coordinator']->advance_claimed($claimed, 1000);
    $assert(Coordinator::STATUS_REQUEUED === $wait['status'] && 1045 === $wait['run_after'], 'Upload durable wait did not preserve its exact retry boundary.');
    $assert(null === $f['tasks']->claim_next(1044), 'Upload task became runnable before its durable wait elapsed.');

    // Retry-safe but zero-delay backend/source failure must not become a tight automatic loop.
    $f = $fixture();
    $operation_id = array_key_first($f['state']->operations);
    $f['state']->upload_mode = 'advanced_error';
    $f['coordinator']->enqueue_upload($operation_id, 1000);
    $claimed = $f['tasks']->claim_next(1000);
    $stopped = $f['coordinator']->advance_claimed($claimed, 1000);
    $assert(Coordinator::STATUS_FAILED === $stopped['status'] && Task_Repository::STATUS_FAILED === $f['tasks']->find(1)['status'], 'Zero-delay upload error was automatically looped.');

    // An uncertain byte-bearing upload is an intervention fence: no service call, no zero-byte probe.
    $f = $fixture(Machine::PHASE_UPLOAD_INDETERMINATE);
    $operation_id = array_key_first($f['state']->operations);
    $f['state']->operations[$operation_id]['last_error'] = array('code'=>'peertube.upload.indeterminate','http_status'=>0,'retry_after'=>0);
    $f['coordinator']->enqueue_upload($operation_id, 1000);
    $claimed = $f['tasks']->claim_next(1000);
    $uncertain = $f['coordinator']->advance_claimed($claimed, 1000);
    $assert(Coordinator::STATUS_FAILED === $uncertain['status'] && 0 === $f['state']->upload_calls, 'R45 automatically advanced/reconciled an indeterminate upload.');

    // Remote-created handoff enqueues the deterministic R44 task before completing the upload task.
    $f = $fixture();
    $operation_id = array_key_first($f['state']->operations);
    $f['state']->upload_mode = 'remote_created';
    $f['coordinator']->enqueue_upload($operation_id, 1000);
    $claimed = $f['tasks']->claim_next(1000);
    $handoff = $f['coordinator']->advance_claimed($claimed, 1000);
    $assert(Coordinator::STATUS_COMPLETE === $handoff['status'], 'Remote-created upload task did not complete after handoff.');
    $assert(2 === count($f['tasks']->rows) && Coordinator::TASK_REMOTE_RECONCILE === $f['tasks']->find(2)['task_type'], 'Remote-created handoff did not enqueue reconciliation.');
    $enqueue_index = array_search('enqueue:'.Coordinator::TASK_REMOTE_RECONCILE, $f['tasks']->log, true);
    $complete_index = array_search('complete:1', $f['tasks']->log, true);
    $assert(is_int($enqueue_index) && is_int($complete_index) && $enqueue_index < $complete_index, 'Upload task completed before durable reconciliation handoff.');

    // A local enqueue uncertainty keeps the upload task retryable without repeating remote upload work.
    $f = $fixture(Machine::PHASE_REMOTE_CREATED);
    $operation_id = array_key_first($f['state']->operations);
    $f['coordinator']->enqueue_upload($operation_id, 1000);
    $claimed = $f['tasks']->claim_next(1000);
    $f['tasks']->next_enqueue_status = Task_Repository::INDETERMINATE;
    $handoff_wait = $f['coordinator']->advance_claimed($claimed, 1000);
    $assert(Coordinator::STATUS_REQUEUED === $handoff_wait['status'] && 1030 === $handoff_wait['run_after'], 'Indeterminate local handoff did not become a bounded local retry.');
    $assert(0 === $f['state']->upload_calls, 'Local handoff retry repeated remote upload work.');

    // R44 coordination: commit is one task step, processing is durably delayed, ready completes.
    $f = $fixture(Machine::PHASE_REMOTE_CREATED);
    $operation_id = array_key_first($f['state']->operations);
    $f['coordinator']->enqueue_reconciliation($operation_id, 2000);
    $claimed = $f['tasks']->claim_next(2000);
    $committed = $f['coordinator']->advance_claimed($claimed, 2000);
    $assert(Coordinator::STATUS_REQUEUED === $committed['status'] && Reconcile_Service::STATUS_REMOTE_COMMITTED === $committed['service_status'] && 1 === $f['state']->reconcile_calls, 'Remote commit was not one bounded reconciliation task step.');

    $f['state']->reconcile_mode = 'processing';
    $claimed = $f['tasks']->claim_next(2000);
    $processing = $f['coordinator']->advance_claimed($claimed, 2000);
    $assert(Coordinator::STATUS_REQUEUED === $processing['status'] && 2030 === $processing['run_after'], 'Processing observation did not establish durable task wait.');
    $assert(null === $f['tasks']->claim_next(2029), 'Remote task ignored its processing wait.');

    $f['state']->reconcile_mode = 'ready';
    $claimed = $f['tasks']->claim_next(2030);
    $ready = $f['coordinator']->advance_claimed($claimed, 2030);
    $assert(Coordinator::STATUS_COMPLETE === $ready['status'] && Reconcile_Service::STATUS_READY_VERIFIED === $ready['service_status'], 'Ready verification did not complete reconciliation task.');
    $assert(3 === $f['state']->reconcile_calls, 'R44 task sequence performed an unexpected number of service advancements.');

    // Refresh-required is explicit intervention, not an automatic token lifecycle transition.
    $f = $fixture(Machine::PHASE_REMOTE_COMMITTED);
    $operation_id = array_key_first($f['state']->operations);
    $f['state']->reconcile_mode = 'refresh';
    $f['coordinator']->enqueue_reconciliation($operation_id, 3000);
    $claimed = $f['tasks']->claim_next(3000);
    $refresh = $f['coordinator']->advance_claimed($claimed, 3000);
    $assert(Coordinator::STATUS_FAILED === $refresh['status'] && 1 === $f['state']->reconcile_calls, 'Refresh-required reconciliation was automatically retried/refreshed.');

    // Task-row tampering fails closed before either service is called.
    $f = $fixture();
    $operation_id = array_key_first($f['state']->operations);
    $f['coordinator']->enqueue_upload($operation_id, 4000);
    $f['tasks']->rows[1]['payload_json'] = '{"version":1,"operation_id":"'.$operation_id.'","extra":true}';
    $claimed = $f['tasks']->claim_next(4000);
    $tampered = $f['coordinator']->advance_claimed($claimed, 4000);
    $assert(Coordinator::STATUS_FAILED === $tampered['status'] && 0 === $f['state']->upload_calls && 0 === $f['state']->reconcile_calls, 'Tampered task payload reached a service boundary.');

    // R45.3b permits CLI-only composition, but the coordinator itself remains a
    // bounded orchestration object with no scheduler/browser/process authority.
    $root = dirname(__DIR__);
    $source = (string) file_get_contents($root.'/includes/PeerTube_Upload_Task_Coordinator.php');
    foreach (array('wp_schedule','wp_cron','register_rest_route','wp_ajax','unlink(','wp_delete','PeerTube_Api_Client','PeerTube_Http_Client','claim_next(') as $needle) {
        $assert(! str_contains($source, $needle), 'R45 coordinator acquired forbidden runtime/scheduler/API authority: '.$needle);
    }
    $assert(! str_contains($source, '->reconcile_offset('), 'R45 coordinator automatically invokes zero-byte upload reconciliation.');
    foreach (array('includes/Admin.php','includes/CLI_Command.php','includes/Worker.php','includes/Worker_Launcher.php') as $relative) {
        $assert(is_file($root.'/'.$relative), 'Expected production surface is missing: '.$relative);
        $surface = (string) file_get_contents($root.'/'.$relative);
        $assert(! str_contains($surface, 'PeerTube_Upload_Task_Coordinator'), 'R45 coordinator leaked past Plugin CLI composition into '.$relative);
    }
    $plugin = (string) file_get_contents($root.'/includes/Plugin.php');
    $assert(
        str_contains($plugin, '$peertube_task_coordinator = new PeerTube_Upload_Task_Coordinator('),
        'R45.3b Plugin CLI composition is missing the reviewed PeerTube coordinator.'
    );

    fwrite(STDOUT, "PeerTube upload task coordinator tests passed.\n");
}
