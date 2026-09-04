<?php
/** Focused tests for the R45.3b one-shot PeerTube WP-CLI boundary. */
declare(strict_types=1);

namespace {
    final class WP_CLI
    {
        /** @var list<string> */
        public static array $successes = array();
        /** @var list<string> */
        public static array $errors = array();
        /** @var list<string> */
        public static array $warnings = array();

        public static function success(string $message): void
        {
            self::$successes[] = $message;
        }

        public static function error(string $message): void
        {
            self::$errors[] = $message;
            throw new \RuntimeException('WP_CLI_ERROR');
        }

        public static function warning(string $message): void
        {
            self::$warnings[] = $message;
        }

        public static function reset(): void
        {
            self::$successes = array();
            self::$errors = array();
            self::$warnings = array();
        }
    }
}

namespace ArgentVideo {
    final class Job_Repository {}
    final class Queue {}
    final class Bulk_Queue {}
    final class Worker {}
    final class Diagnostics {}
    final class Worker_Log_Repository {}

    final class PeerTube_Task_Worker
    {
        public const STATUS_IDLE = 'idle';
        public const STATUS_ADVANCED = 'advanced';
        public const STATUS_INDETERMINATE = 'indeterminate';

        /** @var list<array<string,mixed>> */
        public array $results = array();
        /** @var list<int> */
        public array $calls = array();

        /** @return array<string,mixed> */
        public function run_once(int $now): array
        {
            $this->calls[] = $now;
            return array_shift($this->results) ?? array(
                'status'=>self::STATUS_IDLE,
                'recovered'=>0,
                'task_id'=>0,
                'task_type'=>'',
                'coordinator_status'=>'',
            );
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/includes/CLI_Command.php';

    use ArgentVideo\Bulk_Queue;
    use ArgentVideo\CLI_Command;
    use ArgentVideo\Diagnostics;
    use ArgentVideo\Job_Repository;
    use ArgentVideo\PeerTube_Task_Worker;
    use ArgentVideo\Queue;
    use ArgentVideo\Worker;
    use ArgentVideo\Worker_Log_Repository;

    $assert = static function (bool $ok, string $message): void {
        if (! $ok) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };

    $worker = new PeerTube_Task_Worker();
    $cli = new CLI_Command(
        new Job_Repository(),
        new Queue(),
        new Bulk_Queue(),
        new Worker(),
        new Diagnostics(),
        new Worker_Log_Repository(),
        $worker
    );

    // --once is an explicit safety fence, not an optional alias.
    WP_CLI::reset();
    $thrown = false;
    try {
        $cli->peertube_task_worker(array(), array());
    } catch (\RuntimeException $error) {
        $thrown = 'WP_CLI_ERROR' === $error->getMessage();
    }
    $assert($thrown, 'PeerTube CLI worker accepted execution without --once.');
    $assert(0 === count($worker->calls), 'PeerTube CLI worker ran before --once validation.');

    // Additional loop-ish/options surface is rejected at this checkpoint.
    WP_CLI::reset();
    $thrown = false;
    try {
        $cli->peertube_task_worker(array(), array('once'=>true,'limit'=>2));
    } catch (\RuntimeException $error) {
        $thrown = 'WP_CLI_ERROR' === $error->getMessage();
    }
    $assert($thrown, 'PeerTube CLI worker accepted an unreviewed option alongside --once.');
    $assert(0 === count($worker->calls), 'Rejected PeerTube CLI options still ran the worker.');

    // Idle is a successful bounded invocation.
    WP_CLI::reset();
    $worker->results[] = array(
        'status'=>PeerTube_Task_Worker::STATUS_IDLE,
        'recovered'=>2,
        'task_id'=>0,
        'task_type'=>'',
        'coordinator_status'=>'',
    );
    $cli->peertube_task_worker(array(), array('once'=>true));
    $assert(1 === count($worker->calls), 'Idle --once invocation did not call the worker exactly once.');
    $assert(
        1 === count(WP_CLI::$successes)
            && str_contains(WP_CLI::$successes[0], 'idle')
            && str_contains(WP_CLI::$successes[0], '2 stale recovered'),
        'Idle PeerTube CLI result was not reported as bounded success.'
    );

    // One claimed task is reported; CLI itself does not loop to a second task.
    WP_CLI::reset();
    $worker->results[] = array(
        'status'=>PeerTube_Task_Worker::STATUS_ADVANCED,
        'recovered'=>1,
        'task_id'=>41,
        'task_type'=>'peertube_upload_advance',
        'coordinator_status'=>'requeued',
    );
    $worker->results[] = array(
        'status'=>PeerTube_Task_Worker::STATUS_ADVANCED,
        'recovered'=>0,
        'task_id'=>42,
        'task_type'=>'peertube_remote_reconcile',
        'coordinator_status'=>'complete',
    );
    $before = count($worker->calls);
    $cli->peertube_task_worker(array(), array('once'=>true));
    $assert($before + 1 === count($worker->calls), 'One CLI invocation called PeerTube task worker more than once.');
    $assert(1 === count($worker->results), 'One CLI invocation consumed more than one synthetic worker result.');
    $assert(
        1 === count(WP_CLI::$successes)
            && str_contains(WP_CLI::$successes[0], 'task 41')
            && str_contains(WP_CLI::$successes[0], 'peertube_upload_advance')
            && str_contains(WP_CLI::$successes[0], 'requeued'),
        'Advanced PeerTube CLI result lost bounded task identity/status.'
    );

    // Process-level uncertainty returns a CLI error/non-zero boundary.
    WP_CLI::reset();
    $worker->results = array(
        array(
            'status'=>PeerTube_Task_Worker::STATUS_INDETERMINATE,
            'recovered'=>0,
            'task_id'=>60,
            'task_type'=>'peertube_remote_reconcile',
            'coordinator_status'=>'',
        ),
    );
    $thrown = false;
    try {
        $cli->peertube_task_worker(array(), array('once'=>true));
    } catch (\RuntimeException $error) {
        $thrown = 'WP_CLI_ERROR' === $error->getMessage();
    }
    $assert($thrown, 'Indeterminate PeerTube worker result did not produce a CLI error boundary.');
    $assert(
        1 === count(WP_CLI::$errors)
            && str_contains(WP_CLI::$errors[0], 'indeterminate')
            && str_contains(WP_CLI::$errors[0], 'task 60'),
        'Indeterminate PeerTube CLI error lost bounded task identity.'
    );

    $source = (string) file_get_contents(dirname(__DIR__) . '/includes/CLI_Command.php');
    $method_start = strpos($source, 'public function peertube_task_worker');
    $method_end = false === $method_start ? false : strpos($source, '/** Display configuration', $method_start);
    $method = false === $method_start || false === $method_end ? '' : substr($source, $method_start, $method_end - $method_start);
    $assert('' !== $method, 'Could not isolate PeerTube CLI worker method for boundary checks.');
    foreach (array('while (','for (','foreach (','sleep(','usleep(','wp_schedule','exec(','proc_open','shell_exec') as $needle) {
        $assert(! str_contains($method, $needle), 'PeerTube CLI method acquired loop/scheduler/process authority: '.$needle);
    }
    $assert(
        ! str_contains($method, 'PeerTube_Staged_Upload_Service')
            && ! str_contains($method, 'PeerTube_Remote_Asset_Reconciliation_Service')
            && ! str_contains($method, 'Task_Repository'),
        'PeerTube CLI method bypasses the one-shot worker abstraction.'
    );

    fwrite(STDOUT, "PeerTube task CLI boundary tests passed.\n");
}
