<?php
/**
 * File: includes/Process_Runner.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use RuntimeException;

final class Process_Runner
{
    /**
     * @param list<string> $command
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    public function run(array $command, bool $prioritize = false): array
    {
        if (! function_exists('proc_open') || $this->function_disabled('proc_open')) {
            throw new RuntimeException('PHP proc_open() is unavailable or disabled.');
        }
        if ([] === $command || ! is_executable($command[0])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception; escaped at every administrative display boundary.
            throw new RuntimeException('Executable is missing or not executable: ' . ($command[0] ?? '(empty command)'));
        }

        if ($prioritize) {
            $command = $this->with_priority($command);
        }

        $stdout_path = '';
        $stderr_path = '';
        try {
            $stdout_path = $this->allocate_temporary_file('argentwolf-video-processor-stdout.log');
            $stderr_path = $this->allocate_temporary_file('argentwolf-video-processor-stderr.log');

            $descriptors = array(
                0 => array('file', '/dev/null', 'r'),
                1 => array('file', $stdout_path, 'w'),
                2 => array('file', $stderr_path, 'w'),
            );
            // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- External FFmpeg/FFprobe execution is the plugin's documented core function; bypass_shell and argument arrays prevent shell parsing.
            $process = proc_open($command, $descriptors, $pipes, null, null, array('bypass_shell' => true));
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start external process.');
            }

            $exit_code = proc_close($process);
            return array(
                'exit_code' => (int) $exit_code,
                'stdout'    => $this->read_tail($stdout_path, 1048576),
                'stderr'    => $this->read_tail($stderr_path, 1048576),
            );
        } finally {
            if ('' !== $stdout_path && is_file($stdout_path)) {
                wp_delete_file($stdout_path);
            }
            if ('' !== $stderr_path && is_file($stderr_path)) {
                wp_delete_file($stderr_path);
            }
        }
    }

    private function allocate_temporary_file(string $filename): string
    {
        if (! function_exists('wp_tempnam')) {
            // ABSPATH is intentional: wp_tempnam() is a WordPress core file API loaded from wp-admin/includes/file.php.
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $path = wp_tempnam($filename);
        if ('' === $path || ! is_file($path)) {
            throw new RuntimeException('Could not allocate a temporary process log file.');
        }

        return $path;
    }

    /** @param list<string> $command
     *  @return list<string>
     */
    private function with_priority(array $command): array
    {
        $settings = Settings::all();
        $prefix = array();

        if (is_executable('/usr/bin/nice')) {
            array_push($prefix, '/usr/bin/nice', '-n', (string) (int) $settings['nice_level']);
        }
        if (is_executable('/usr/bin/ionice')) {
            array_push(
                $prefix,
                '/usr/bin/ionice',
                '-c',
                (string) (int) $settings['ionice_class'],
                '-n',
                (string) (int) $settings['ionice_level']
            );
        }

        return array_merge($prefix, $command);
    }

    private function function_disabled(string $function): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return in_array($function, $disabled, true);
    }

    private function read_tail(string $path, int $maximum_bytes): string
    {
        if (! is_file($path)) {
            return '';
        }

        $size = filesize($path);
        if (false === $size || 0 === $size) {
            return '';
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded tail reading avoids loading potentially large FFmpeg logs into memory.
        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return '';
        }

        if ($size > $maximum_bytes) {
            fseek($handle, -$maximum_bytes, SEEK_END);
        }
        $content = stream_get_contents($handle);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the bounded FFmpeg log stream opened above.
        fclose($handle);

        return false === $content ? '' : $content;
    }
}

// EOF: includes/Process_Runner.php
