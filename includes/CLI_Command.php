<?php
/**
 * File: includes/CLI_Command.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use RuntimeException;
use Throwable;
use WP_CLI;
use WP_CLI\Utils;

final class CLI_Command
{
    public function __construct(
        private readonly Job_Repository $jobs,
        private readonly Queue $queue,
        private readonly Bulk_Queue $bulk,
        private readonly Worker $worker,
        private readonly Diagnostics $diagnostics,
        private readonly Worker_Log_Repository $worker_logs,
        private readonly PeerTube_Task_Worker $peertube_task_worker
    ) {
    }

    /**
     * Process queued videos.
     *
     * ## OPTIONS
     *
     * [--once]
     * : Process one queued job.
     *
     * [--limit=<count>]
     * : Process up to this many jobs. Default 1.
     *
     * [--worker-log-id=<id>]
     * : Internal detached-launch diagnostic record ID.
     */
    public function worker(array $args, array $assoc_args): void
    {
        unset($args);
        $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 1;
        if (isset($assoc_args['once'])) {
            $limit = 1;
        }

        $run_id = 0;
        $logging_started = false;
        try {
            $run_id = isset($assoc_args['worker-log-id'])
                ? max(0, (int) $assoc_args['worker-log-id'])
                : $this->worker_logs->create('cli');
            if ($run_id < 1) {
                throw new RuntimeException('Worker diagnostic record ID must be a positive integer.');
            }

            $this->worker_logs->mark_running($run_id, getmypid() ?: 0);
            $logging_started = true;
            $result = $this->worker->run($limit);
            foreach ($result['errors'] as $failure) {
                WP_CLI::warning(sprintf(
                    'Job %d for attachment %d failed: %s',
                    $failure['job_id'],
                    $failure['attachment_id'],
                    $this->error_summary($failure['message'])
                ));
            }
            $this->flush_output();
            $this->worker_logs->complete($run_id, $result);
            WP_CLI::success(sprintf(
                'Worker complete: %d processed, %d failed, %d stale recovered.',
                $result['processed'],
                $result['failed'],
                $result['recovered']
            ));
        } catch (Throwable $error) {
            if ($logging_started && $run_id > 0) {
                $this->flush_output();
                $this->worker_logs->fail($run_id, $error->getMessage(), 1);
            }
            WP_CLI::error($error->getMessage());
        }
    }

    /**
     * Advance exactly one queued PeerTube asynchronous task.
     *
     * This command is intentionally one-shot. R45 does not grant this command
     * a polling loop, detached-launch policy, or scheduler ownership.
     *
     * ## OPTIONS
     *
     * --once
     * : Required safety flag. Advance at most one eligible PeerTube task.
     */
    public function peertube_task_worker(array $args, array $assoc_args): void
    {
        unset($args);

        if (! isset($assoc_args['once'])) {
            WP_CLI::error('PeerTube task worker requires --once.');
            return;
        }

        $unexpected = array_diff(array_keys($assoc_args), array('once'));
        if ([] !== $unexpected) {
            WP_CLI::error('PeerTube task worker only supports --once.');
            return;
        }

        try {
            $result = $this->peertube_task_worker->run_once(time());
        } catch (Throwable $error) {
            WP_CLI::error('PeerTube task worker failed before a bounded result: ' . $this->error_summary($error->getMessage()));
            return;
        }

        $status = is_string($result['status'] ?? null) ? $result['status'] : '';
        $recovered = max(0, (int) ($result['recovered'] ?? 0));

        if (PeerTube_Task_Worker::STATUS_IDLE === $status) {
            WP_CLI::success(sprintf(
                'PeerTube task worker idle; %d stale recovered.',
                $recovered
            ));
            return;
        }

        if (PeerTube_Task_Worker::STATUS_ADVANCED === $status) {
            WP_CLI::success(sprintf(
                'PeerTube task worker advanced task %d (%s): %s; %d stale recovered.',
                max(0, (int) ($result['task_id'] ?? 0)),
                (string) ($result['task_type'] ?? ''),
                (string) ($result['coordinator_status'] ?? ''),
                $recovered
            ));
            return;
        }

        WP_CLI::error(sprintf(
            'PeerTube task worker ended indeterminate after task %d (%s); %d stale recovered.',
            max(0, (int) ($result['task_id'] ?? 0)),
            (string) ($result['task_type'] ?? ''),
            $recovered
        ));
    }

    /** Display configuration and executable checks. */
    public function diagnose(): void
    {
        Utils\format_items('table', $this->diagnostics->checks(), array('check', 'status', 'detail'));
        $security = $this->diagnostics->ffmpeg_security();
        if (empty($security['processing_allowed'])) {
            WP_CLI::error(FFmpeg_Security::blocking_message($security));
        }
    }

    /**
     * Queue a video attachment.
     *
     * ## OPTIONS
     *
     * <attachment-id>
     * : WordPress media attachment ID.
     *
     * [--force]
     * : Reprocess even when the source signature and profile are unchanged.
     */
    public function enqueue(array $args, array $assoc_args): void
    {
        $attachment_id = (int) ($args[0] ?? 0);
        try {
            $job_id = $this->queue->enqueue($attachment_id, isset($assoc_args['force']));
            WP_CLI::success('Queued job ' . $job_id . '.');
        } catch (RuntimeException $error) {
            WP_CLI::error($error->getMessage());
        }
    }

    /**
     * List video jobs.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Filter by status.
     *
     * [--limit=<count>]
     * : Maximum jobs. Default 50.
     *
     * [--format=<format>]
     * : table, json, csv, yaml, or count.
     */
    public function jobs(array $args, array $assoc_args): void
    {
        unset($args);
        $status = sanitize_key((string) ($assoc_args['status'] ?? ''));
        $limit = (int) ($assoc_args['limit'] ?? 50);
        $format = (string) ($assoc_args['format'] ?? 'table');
        $rows = $this->jobs->list($limit, $status);
        Utils\format_items($format, $rows, array('id', 'attachment_id', 'profile', 'status', 'attempts', 'updated_at', 'error_message'));
    }

    /**
     * Queue existing video attachments.
     *
     * ## OPTIONS
     *
     * [--mode=<mode>]
     * : `smart`, `adaptive`, or `all`. Default: `smart`.
     *
     * [--after=<YYYY-MM-DD>]
     * : Include videos uploaded on or after this date.
     *
     * [--through=<YYYY-MM-DD>]
     * : Include videos uploaded through this date.
     *
     * [--limit=<count>]
     * : Maximum attachments to inspect. Zero means all, capped at 5000.
     */
    public function scan(array $args, array $assoc_args): void
    {
        unset($args);
        $mode = sanitize_key((string) ($assoc_args['mode'] ?? 'smart'));
        $after = (string) ($assoc_args['after'] ?? '');
        $through = (string) ($assoc_args['through'] ?? '');
        $limit = max(0, min(5000, (int) ($assoc_args['limit'] ?? 0)));
        try {
            $result = $this->bulk->queue($mode, $after, $through, $limit);
            foreach ($result['errors'] as $error) {
                WP_CLI::warning($error);
            }
            WP_CLI::success(sprintf(
                'Queued %d; skipped %d; failed %d.',
                $result['queued'],
                $result['skipped'],
                $result['failed']
            ));
        } catch (RuntimeException $error) {
            WP_CLI::error($error->getMessage());
        }
    }

    private function flush_output(): void
    {
        if (defined('STDOUT') && is_resource(STDOUT)) {
            fflush(STDOUT);
        }
        if (defined('STDERR') && is_resource(STDERR)) {
            fflush(STDERR);
        }
    }

    private function error_summary(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', trim($message)) ?? trim($message);
        if (strlen($message) <= 500) {
            return $message;
        }

        return substr($message, 0, 497) . '...';
    }
}

// EOF: includes/CLI_Command.php
