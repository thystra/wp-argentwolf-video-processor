<?php
/**
 * File: includes/PeerTube_Task_Worker_Launcher.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/**
 * Detached-process boundary for the reviewed R45 PeerTube task worker.
 *
 * This class is deliberately not registered with WP-Cron or any administrator,
 * REST, AJAX, or browser surface in R45.4a. It performs no PeerTube HTTP work
 * itself: after an advisory type-owned queue probe it can only launch the
 * reviewed bounded-drain WP-CLI consumer in a detached process.
 */
final class PeerTube_Task_Worker_Launcher
{
    public const STATUS_LAUNCHED = 'launched';
    public const STATUS_IDLE = 'idle';
    public const STATUS_LOCKED = 'locked';
    public const STATUS_FAILED = 'failed';

    public const LAUNCH_LOCK_SECONDS = 120;

    private const LAUNCH_LOCK = 'argent_video_processor_peertube_task_launch_lock';

    /** @var list<string> */
    private const TASK_TYPES = array(
        PeerTube_Upload_Task_Coordinator::TASK_UPLOAD_ADVANCE,
        PeerTube_Upload_Task_Coordinator::TASK_REMOTE_RECONCILE,
    );

    public function __construct(private readonly Task_Repository $tasks)
    {
    }

    /**
     * Launch at most one detached bounded-drain worker process when owned work is due.
     *
     * The pre-launch work probe is advisory. The detached worker's atomic claim
     * remains authoritative and may legitimately return idle after a race.
     *
     * @return array{ok:bool,status:string,time:string,pid:int,exit_code:int,message:string}
     */
    public function launch(): array
    {
        $now = time();
        if ($now < 1) {
            return $this->failure('Current time is unavailable.');
        }

        $stale_before = max(1, $now - PeerTube_Task_Worker::STALE_LOCK_SECONDS);
        if (! $this->tasks->has_work_of_types(self::TASK_TYPES, $now, $stale_before)) {
            return $this->result(
                true,
                self::STATUS_IDLE,
                0,
                0,
                'No eligible PeerTube task work is due.'
            );
        }

        if (get_transient(self::LAUNCH_LOCK)) {
            return $this->result(
                true,
                self::STATUS_LOCKED,
                0,
                0,
                'A PeerTube task worker launch is already in progress.'
            );
        }

        $wp = (string) Settings::get('wp_cli_path', '/usr/local/bin/wp');
        if (! Shell_Probe::path_executable($wp)) {
            return $this->failure('WP-CLI is missing or not executable: ' . $wp);
        }
        if (! Shell_Probe::exec_available()) {
            return $this->failure(
                'PHP exec() is unavailable or disabled; run the PeerTube task worker from the system scheduler.'
            );
        }

        set_transient(self::LAUNCH_LOCK, '1', self::LAUNCH_LOCK_SECONDS);
        try {
            $parts = array();
            if (Shell_Probe::path_executable('/usr/bin/nohup')) {
                $parts[] = escapeshellarg('/usr/bin/nohup');
            }
            if (Shell_Probe::path_executable('/usr/bin/nice')) {
                $parts[] = escapeshellarg('/usr/bin/nice');
                $parts[] = '-n';
                $parts[] = (string) (int) Settings::get('nice_level', 10);
            }
            if (Shell_Probe::path_executable('/usr/bin/ionice')) {
                $parts[] = escapeshellarg('/usr/bin/ionice');
                $parts[] = '-c';
                $parts[] = (string) (int) Settings::get('ionice_class', 2);
                $parts[] = '-n';
                $parts[] = (string) (int) Settings::get('ionice_level', 7);
            }
            $parts[] = escapeshellarg($wp);
            // ABSPATH is intentional: WP-CLI --path requires the WordPress installation root.
            $parts[] = '--path=' . escapeshellarg(untrailingslashit(ABSPATH));
            $parts[] = 'argent-video';
            $parts[] = 'peertube-task-worker';
            $parts[] = '--drain';
            $parts[] = '--quiet';

            $command = implode(' ', $parts) . ' > /dev/null 2>&1 < /dev/null & echo $!';
            $output = array();
            $exit_code = 1;
            // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- Detached WP-CLI launch uses only fixed/escaped arguments after explicit executable checks.
            exec($command, $output, $exit_code);
            $pid = isset($output[0]) ? (int) trim((string) $output[0]) : 0;

            if (0 !== $exit_code || $pid < 1) {
                delete_transient(self::LAUNCH_LOCK);
                return $this->failure('Detached PeerTube task worker launch failed.', $exit_code);
            }

            return $this->result(
                true,
                self::STATUS_LAUNCHED,
                $pid,
                $exit_code,
                'Detached PeerTube task worker launched.'
            );
        } catch (Throwable $error) {
            delete_transient(self::LAUNCH_LOCK);
            return $this->failure($error->getMessage());
        }
    }

    /** @return array{ok:bool,status:string,time:string,pid:int,exit_code:int,message:string} */
    private function failure(string $message, int $exit_code = 1): array
    {
        return $this->result(false, self::STATUS_FAILED, 0, $exit_code, $message);
    }

    /** @return array{ok:bool,status:string,time:string,pid:int,exit_code:int,message:string} */
    private function result(
        bool $ok,
        string $status,
        int $pid,
        int $exit_code,
        string $message
    ): array {
        return array(
            'ok' => $ok,
            'status' => $status,
            'time' => current_time('mysql', true),
            'pid' => max(0, $pid),
            'exit_code' => $exit_code,
            'message' => $message,
        );
    }
}

// EOF: includes/PeerTube_Task_Worker_Launcher.php
