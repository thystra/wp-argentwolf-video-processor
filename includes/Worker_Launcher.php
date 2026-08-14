<?php
/**
 * File: includes/Worker_Launcher.php
 */

declare(strict_types=1);

namespace ArgentVideo;

final class Worker_Launcher
{
    private const LAUNCH_LOCK = 'argent_video_processor_launch_lock';

    public function __construct(private readonly Job_Repository $jobs)
    {
    }

    public function dispatch(): void
    {
        if (! Settings::get('auto_dispatch', true) || $this->jobs->count('queued') < 1 || Worker::lock_is_active()) {
            return;
        }

        if (get_transient(self::LAUNCH_LOCK)) {
            return;
        }
        set_transient(self::LAUNCH_LOCK, '1', 2 * MINUTE_IN_SECONDS);

        $result = $this->launch();
        update_option('argent_video_processor_last_launch', $result, false);
    }

    /** @return array<string, mixed> */
    public function launch(): array
    {
        $security = FFmpeg_Security::assess((string) Settings::get('ffmpeg_path', ''));
        if (empty($security['processing_allowed'])) {
            return $this->failure(FFmpeg_Security::blocking_message($security));
        }

        $wp = (string) Settings::get('wp_cli_path', '/usr/local/bin/wp');
        if (! Shell_Probe::path_executable($wp)) {
            return $this->failure('WP-CLI is missing or not executable: ' . $wp);
        }

        if (! function_exists('exec') || $this->function_disabled('exec')) {
            return $this->failure('PHP exec() is unavailable or disabled; run the WP-CLI worker from the system scheduler.');
        }

        $log = trailingslashit(sys_get_temp_dir()) . 'argentwolf-video-processor-' . md5(ABSPATH) . '.log';
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
        $parts[] = '--path=' . escapeshellarg(untrailingslashit(ABSPATH));
        $parts[] = 'argent-video';
        $parts[] = 'worker';
        $parts[] = '--once';
        $parts[] = '--quiet';

        $command = implode(' ', $parts)
            . ' >> ' . escapeshellarg($log)
            . ' 2>&1 < /dev/null & echo $!';

        $output = array();
        $exit_code = 1;
        exec($command, $output, $exit_code);
        $pid = isset($output[0]) ? (int) trim((string) $output[0]) : 0;

        if (0 !== $exit_code || $pid < 1) {
            return $this->failure('Detached worker launch failed.', $exit_code, $log);
        }

        return array(
            'ok'        => true,
            'time'      => current_time('mysql', true),
            'pid'       => $pid,
            'log'       => $log,
            'exit_code' => $exit_code,
            'message'   => 'Detached worker launched.',
        );
    }

    /** @return array<string, mixed> */
    private function failure(string $message, int $exit_code = 1, string $log = ''): array
    {
        return array(
            'ok'        => false,
            'time'      => current_time('mysql', true),
            'pid'       => 0,
            'log'       => $log,
            'exit_code' => $exit_code,
            'message'   => $message,
        );
    }

    private function function_disabled(string $function): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return in_array($function, $disabled, true);
    }
}

// EOF: includes/Worker_Launcher.php
