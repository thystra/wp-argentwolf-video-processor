<?php
/** Focused dependency-free tests for the R45 PeerTube task worker. */
declare(strict_types=1);

namespace ArgentVideo {
    final class Task_Repository
    {
        /** @var list<array{types:array<int,string>,stale_before:int,now:int,limit:int}> */
        public array $recover_calls = array();
        /** @var list<array{types:array<int,string>,now:int}> */
        public array $claim_calls = array();
        /** @var list<array<string,mixed>> */
        public array $claims = array();
        public int $recover_result = 0;

        public function recover_stale_of_types(array $task_types, int $stale_before, int $now, int $limit = 100): int
        {
            $this->recover_calls[] = array(
                'types'=>$task_types,
                'stale_before'=>$stale_before,
                'now'=>$now,
                'limit'=>$limit,
            );
            return $this->recover_result;
        }

        /** @return array<string,mixed>|null */
        public function claim_next_of_types(array $task_types, int $now): ?array
        {
            $this->claim_calls[] = array('types'=>$task_types,'now'=>$now);
            return array_shift($this->claims);
        }
    }

    final class PeerTube_Upload_Task_Coordinator
    {
        public const TASK_UPLOAD_ADVANCE = 'peertube_upload_advance';
        public const TASK_REMOTE_RECONCILE = 'peertube_remote_reconcile';

        /** @var list<array{task:array<string,mixed>,now:int}> */
        public array $calls = array();
        /** @var array<string,mixed>|null */
        public ?array $next_result = null;
        public bool $throw = false;

        /** @param array<string,mixed> $task @return array<string,mixed> */
        public function advance_claimed(array $task, int $now): array
        {
            $this->calls[] = array('task'=>$task,'now'=>$now);
            if ($this->throw) {
                throw new \RuntimeException('synthetic worker boundary failure');
            }
            return $this->next_result ?? array(
                'status'=>'requeued',
                'task_id'=>(int)($task['id']??0),
                'task_type'=>(string)($task['task_type']??''),
            );
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

    $types = array(Coordinator::TASK_UPLOAD_ADVANCE, Coordinator::TASK_REMOTE_RECONCILE);

    // Idle one-shot execution recovers/claims only the reviewed PeerTube types.
    $tasks = new Task_Repository();
    $tasks->recover_result = 2;
    $coordinator = new Coordinator();
    $worker = new PeerTube_Task_Worker($tasks, $coordinator, static fn(string $operation_id): ?array => null);
    $idle = $worker->run_once(2000);
    $assert(PeerTube_Task_Worker::STATUS_IDLE === $idle['status'], 'Empty worker did not return idle.');
    $assert(2 === $idle['recovered'], 'Worker did not report bounded stale recovery.');
    $assert(1 === count($tasks->recover_calls) && $types === $tasks->recover_calls[0]['types'], 'Worker stale recovery was not type restricted.');
    $assert(1100 === $tasks->recover_calls[0]['stale_before'], 'Worker stale-lock boundary drifted.');
    $assert(1 === count($tasks->claim_calls) && $types === $tasks->claim_calls[0]['types'], 'Worker claim was not type restricted.');
    $assert(0 === count($coordinator->calls), 'Idle worker called the coordinator.');

    // Exactly one eligible task is advanced; a second queued task is untouched.
    $tasks = new Task_Repository();
    $coordinator = new Coordinator();
    $worker = new PeerTube_Task_Worker($tasks, $coordinator, static fn(string $operation_id): ?array => null);
    $tasks->claims[] = array(
        'id'=>41,
        'task_type'=>Coordinator::TASK_UPLOAD_ADVANCE,
        'status'=>'processing',
        'lock_token'=>'00000000-0000-4000-8000-000000000041',
    );
    $tasks->claims[] = array(
        'id'=>42,
        'task_type'=>Coordinator::TASK_REMOTE_RECONCILE,
        'status'=>'processing',
        'lock_token'=>'00000000-0000-4000-8000-000000000042',
    );
    $coordinator->next_result = array(
        'status'=>'requeued',
        'task_id'=>41,
        'task_type'=>Coordinator::TASK_UPLOAD_ADVANCE,
    );
    $advanced = $worker->run_once(3000);
    $assert(PeerTube_Task_Worker::STATUS_ADVANCED === $advanced['status'], 'One-shot worker did not advance its claimed task.');
    $assert(41 === $advanced['task_id'] && 'requeued' === $advanced['coordinator_status'], 'Worker result lost coordinator identity/status.');
    $assert(1 === count($coordinator->calls), 'One-shot worker crossed the coordinator boundary more than once.');
    $assert(1 === count($tasks->claims), 'One-shot worker consumed more than one claimed task.');

    // Repository ownership mismatch fails closed before coordinator invocation.
    $tasks = new Task_Repository();
    $coordinator = new Coordinator();
    $worker = new PeerTube_Task_Worker($tasks, $coordinator, static fn(string $operation_id): ?array => null);
    $tasks->claims[] = array(
        'id'=>50,
        'task_type'=>'future_cleanup',
        'status'=>'processing',
        'lock_token'=>'00000000-0000-4000-8000-000000000050',
    );
    $wrong = $worker->run_once(4000);
    $assert(PeerTube_Task_Worker::STATUS_INDETERMINATE === $wrong['status'], 'Unexpected task ownership did not fail closed.');
    $assert(0 === count($coordinator->calls), 'Unexpected task type reached the coordinator.');

    // Unexpected process/coordinator failure preserves uncertainty rather than
    // silently claiming success or attempting a second task.
    $tasks = new Task_Repository();
    $coordinator = new Coordinator();
    $coordinator->throw = true;
    $worker = new PeerTube_Task_Worker($tasks, $coordinator, static fn(string $operation_id): ?array => null);
    $tasks->claims[] = array(
        'id'=>60,
        'task_type'=>Coordinator::TASK_REMOTE_RECONCILE,
        'status'=>'processing',
        'lock_token'=>'00000000-0000-4000-8000-000000000060',
    );
    $uncertain = $worker->run_once(5000);
    $assert(PeerTube_Task_Worker::STATUS_INDETERMINATE === $uncertain['status'], 'Unexpected coordinator failure was not preserved as indeterminate.');
    $assert(1 === count($coordinator->calls), 'Coordinator failure caused an automatic replay.');

    // R45.3b exposes this worker through one explicit WP-CLI command only. The
    // worker itself still owns no scheduler, browser surface, detached process,
    // source cleanup, network implementation, or offset-reconciliation grant.
    $root = dirname(__DIR__);
    $source = (string) file_get_contents($root.'/includes/PeerTube_Task_Worker.php');
    foreach (array(
        'wp_schedule','wp_cron','register_rest_route','wp_ajax','admin_post',
        'exec(','proc_open','shell_exec','unlink(','wp_delete',
        'PeerTube_Api_Client','PeerTube_Http_Client','reconcile_offset('
    ) as $needle) {
        $assert(! str_contains($source, $needle), 'PeerTube task worker acquired forbidden authority: '.$needle);
    }
    foreach (array('includes/Admin.php','includes/Worker.php','includes/Worker_Launcher.php') as $relative) {
        $surface = (string) file_get_contents($root.'/'.$relative);
        $assert(! str_contains($surface, 'PeerTube_Task_Worker'), 'PeerTube task worker leaked into unreviewed production surface '.$relative);
    }
    $plugin = (string) file_get_contents($root.'/includes/Plugin.php');
    $cli = (string) file_get_contents($root.'/includes/CLI_Command.php');
    $wp_cli_guard = strpos($plugin, "if (defined('WP_CLI') && WP_CLI)");
    $worker_build = strpos($plugin, '$peertube_task_worker = new PeerTube_Task_Worker(');
    $assert(
        false !== $wp_cli_guard && false !== $worker_build && $worker_build > $wp_cli_guard,
        'PeerTube task worker is not composed strictly behind the WP_CLI runtime guard.'
    );
    $assert(
        str_contains($cli, 'private readonly PeerTube_Task_Worker $peertube_task_worker')
            && str_contains($cli, 'public function peertube_task_worker('),
        'R45.3b CLI does not expose the reviewed one-shot PeerTube worker boundary.'
    );

    fwrite(STDOUT, "PeerTube task worker tests passed.\n");
}
