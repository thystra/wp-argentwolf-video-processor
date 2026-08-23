<?php
/**
 * File: includes/Worker_Launcher.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use RuntimeException;

final class Worker_Launcher
{
    private const LAUNCH_LOCK = 'argent_video_processor_launch_lock';

    public function __construct(
        private readonly Job_Repository $jobs,
        private readonly Worker_Log_Repository $worker_logs
    ) {
    }

    public function dispatch(): void
    {
        $worker_active = Worker::lock_is_active();
        $this->worker_logs->reconcile_incomplete($worker_active);
        if (! Settings::get('auto_dispatch', true) || $this->jobs->count('queued') < 1 || $worker_active) {
            return;
        }
        if (get_transient(self::LAUNCH_LOCK)) {
            return;
        }
        set_transient(self::LAUNCH_LOCK, '1', 2 * MINUTE_IN_SECONDS);

        $result = $this->launch('automatic');
        update_option('argent_video_processor_last_launch', $result, false);
    }

    /** @return array<string, mixed> */
    public function launch(string $trigger_source = 'manual'): array
    {
        $worker_active = Worker::lock_is_active();
        $this->worker_logs->reconcile_incomplete($worker_active);
        if ($worker_active) {
            return $this->failure('Another Argent Video worker is already active.');
        }

        $run_id = 0;
        try {
            $run_id = $this->worker_logs->create($trigger_source);

            $security = FFmpeg_Security::assess((string) Settings::get('ffmpeg_path', ''));
            if (empty($security['processing_allowed'])) {
                return $this->recorded_failure(
                    $run_id,
                    FFmpeg_Security::blocking_message($security)
                );
            }

            $wp = (string) Settings::get('wp_cli_path', '/usr/local/bin/wp');
            if (! Shell_Probe::path_executable($wp)) {
                return $this->recorded_failure($run_id, 'WP-CLI is missing or not executable: ' . $wp);
            }

            if (! function_exists('exec') || $this->function_disabled('exec')) {
                return $this->recorded_failure(
                    $run_id,
                    'PHP exec() is unavailable or disabled; run the WP-CLI worker from the system scheduler.'
                );
            }

            $capture = $this->worker_logs->allocate_capture($run_id);
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
            // ABSPATH is intentional here: WP-CLI --path requires the WordPress installation root, not the plugin directory.
            $parts[] = '--path=' . escapeshellarg(untrailingslashit(ABSPATH));
            $parts[] = 'argent-video';
            $parts[] = 'worker';
            $parts[] = '--once';
            $parts[] = '--worker-log-id=' . (string) $run_id;
            $parts[] = '--quiet';

            $command = implode(' ', $parts)
                . ' > ' . escapeshellarg($capture)
                . ' 2>&1 < /dev/null & echo $!';
            $output = array();
            $exit_code = 1;
            // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- Detached WP-CLI launch is the plugin's documented worker boundary; all arguments and redirections are fixed or escaped above.
            exec($command, $output, $exit_code);
            $pid = isset($output[0]) ? (int) trim((string) $output[0]) : 0;

            if (0 !== $exit_code || $pid < 1) {
                return $this->recorded_failure($run_id, 'Detached worker launch failed.', $exit_code);
            }

            $this->worker_logs->mark_launched($run_id, $pid);
            return array(
                'ok'                => true,
                'time'              => current_time('mysql', true),
                'pid'               => $pid,
                'diagnostic_run_id' => $run_id,
                'exit_code'         => $exit_code,
                'message'           => 'Detached worker launched.',
            );
        } catch (RuntimeException $error) {
            if ($run_id > 0) {
                $this->worker_logs->fail($run_id, $error->getMessage(), 1);
            }
            return $this->failure($error->getMessage(), 1, $run_id);
        }
    }

    /** @return array<string, mixed> */
    private function recorded_failure(int $run_id, string $message, int $exit_code = 1): array
    {
        $this->worker_logs->fail($run_id, $message, $exit_code);
        return $this->failure($message, $exit_code, $run_id);
    }

    /** @return array<string, mixed> */
    private function failure(string $message, int $exit_code = 1, int $run_id = 0): array
    {
        return array(
            'ok'                => false,
            'time'              => current_time('mysql', true),
            'pid'               => 0,
            'diagnostic_run_id' => $run_id,
            'exit_code'         => $exit_code,
            'message'           => $message,
        );
    }

    private function function_disabled(string $function): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return in_array($function, $disabled, true);
    }
}

// EOF: includes/Worker_Launcher.php
