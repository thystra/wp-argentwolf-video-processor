<?php
/**
 * File: includes/FFmpeg_Security.php
 */

declare(strict_types=1);

namespace ArgentVideo;

final class FFmpeg_Security
{
    public const CVE_2026_8461 = 'CVE-2026-8461';
    public const CVE_2026_8461_URL = 'https://nvd.nist.gov/vuln/detail/CVE-2026-8461';

    /**
     * Security advisories enforced before new transcoding begins.
     *
     * Add future FFmpeg CVEs here with their own NVD URL, affected capability,
     * and upstream/backport release floors. Capability absence is evaluated
     * before version status so builds compiled without vulnerable code can pass.
     *
     * @return list<array<string, mixed>>
     */
    public static function advisories(): array
    {
        return array(
            array(
                'id' => self::CVE_2026_8461,
                'url' => self::CVE_2026_8461_URL,
                'title' => 'MagicYUV decoder out-of-bounds write',
                'impact' => 'crafted media may cause denial of service or remote code execution',
                'capability_type' => 'decoder',
                'capability' => 'magicyuv',
                // Upstream/backported release lines known to contain the fix.
                'fixed_families' => array(
                    '5.1' => '5.1.10',
                    '7.1' => '7.1.5',
                    '8.0' => '8.0.3',
                    '8.1' => '8.1.2',
                ),
                // Later release lines are also fixed.
                'global_fixed_floor' => '8.1.2',
            ),
        );
    }

    /**
     * Inspect an actual configured FFmpeg binary.
     *
     * @return array{processing_allowed:bool,version:string,version_raw:string,advisories:list<array<string,mixed>>}
     */
    public static function assess(string $ffmpeg): array
    {
        $version = self::probe(array($ffmpeg, '-version'));
        $decoders = self::probe(array($ffmpeg, '-hide_banner', '-decoders'));
        $encoders = self::probe(array($ffmpeg, '-hide_banner', '-encoders'));

        return self::evaluate(
            $version['output'],
            $decoders['output'],
            $version['ok'],
            $decoders['ok'],
            $encoders['output'],
            $encoders['ok']
        );
    }

    /**
     * Pure evaluator used by unit and cross-version matrix tests.
     *
     * @return array{processing_allowed:bool,version:string,version_raw:string,advisories:list<array<string,mixed>>}
     */
    public static function evaluate(
        string $version_output,
        string $decoders_output,
        bool $version_ok = true,
        bool $decoders_ok = true,
        string $encoders_output = '',
        bool $encoders_ok = true
    ): array {
        $version_raw = self::version_token($version_output);
        $version = self::numeric_version($version_raw);
        $results = array();
        $processing_allowed = true;

        foreach (self::advisories() as $advisory) {
            $capability = (string) $advisory['capability'];
            $capability_type = (string) $advisory['capability_type'];
            $capability_enabled = self::capability_enabled(
                $capability_type,
                $capability,
                $decoders_output,
                $decoders_ok,
                $encoders_output,
                $encoders_ok
            );

            if (false === $capability_enabled) {
                $status = 'not_affected';
                $blocking = false;
                $reason = sprintf(
                    '%s %s is not enabled in this FFmpeg build; the vulnerable code path is unavailable.',
                    $capability,
                    $capability_type
                );
            } elseif (true === $capability_enabled && $version_ok && '' !== $version && self::version_is_fixed($version, $advisory)) {
                $status = 'patched';
                $blocking = false;
                $reason = sprintf(
                    '%s %s is enabled, but FFmpeg %s is on a release line containing the fix.',
                    $capability,
                    $capability_type,
                    $version
                );
            } elseif (true === $capability_enabled && $version_ok && '' !== $version) {
                $status = 'vulnerable';
                $blocking = true;
                $reason = sprintf(
                    '%s %s is enabled and FFmpeg %s is below the known fixed release for this branch.',
                    $capability,
                    $capability_type,
                    $version
                );
            } else {
                $status = 'unverified';
                $blocking = true;
                $reason = sprintf(
                    'Unable to verify whether the configured FFmpeg build safely addresses %s.',
                    (string) $advisory['id']
                );
            }

            if ($blocking) {
                $processing_allowed = false;
            }

            $results[] = array(
                'id' => (string) $advisory['id'],
                'url' => (string) $advisory['url'],
                'title' => (string) $advisory['title'],
                'impact' => (string) $advisory['impact'],
                'capability_type' => (string) $advisory['capability_type'],
                'capability' => $capability,
                'capability_enabled' => $capability_enabled,
                'status' => $status,
                'blocking' => $blocking,
                'reason' => $reason,
            );
        }

        return array(
            'processing_allowed' => $processing_allowed,
            'version' => $version,
            'version_raw' => $version_raw,
            'advisories' => $results,
        );
    }

    /** @param array<string, mixed> $assessment */
    public static function blocking_message(array $assessment): string
    {
        $blocked = array();
        foreach ((array) ($assessment['advisories'] ?? array()) as $advisory) {
            if (! is_array($advisory) || empty($advisory['blocking'])) {
                continue;
            }
            $blocked[] = sprintf(
                '%s: %s %s',
                (string) ($advisory['id'] ?? 'FFmpeg advisory'),
                (string) ($advisory['reason'] ?? 'security verification failed.'),
                (string) ($advisory['url'] ?? '')
            );
        }

        if ([] === $blocked) {
            return 'FFmpeg security gate passed.';
        }

        return 'FFmpeg security gate blocked new transcoding. ' . implode(' ', $blocked);
    }

    /** @param array<string, mixed> $advisory */
    private static function version_is_fixed(string $version, array $advisory): bool
    {
        $global_floor = (string) ($advisory['global_fixed_floor'] ?? '');
        if ('' !== $global_floor && version_compare($version, $global_floor, '>=')) {
            return true;
        }

        foreach ((array) ($advisory['fixed_families'] ?? array()) as $family => $floor) {
            $family = (string) $family;
            $floor = (string) $floor;
            if ($version === $family || str_starts_with($version, $family . '.')) {
                return version_compare($version, $floor, '>=');
            }
        }

        return false;
    }

    private static function capability_enabled(
        string $type,
        string $capability,
        string $decoders_output,
        bool $decoders_ok,
        string $encoders_output,
        bool $encoders_ok
    ): ?bool {
        if ('decoder' === $type) {
            return $decoders_ok ? self::inventory_contains($decoders_output, $capability) : null;
        }
        if ('encoder' === $type) {
            return $encoders_ok ? self::inventory_contains($encoders_output, $capability) : null;
        }
        return null;
    }

    private static function inventory_contains(string $output, string $capability): bool
    {
        return 1 === preg_match(
            '/^\\s*[A-Z\\.]{6}\\s+' . preg_quote($capability, '/') . '(?:\\s|$)/m',
            $output
        );
    }

    private static function version_token(string $output): string
    {
        if (1 !== preg_match('/^ffmpeg version\\s+([^\\s]+)/mi', $output, $match)) {
            return '';
        }
        return trim((string) $match[1]);
    }

    private static function numeric_version(string $token): string
    {
        if (1 !== preg_match('/(?:^|[^0-9])([0-9]+\\.[0-9]+(?:\\.[0-9]+)?)/', $token, $match)) {
            return '';
        }
        return (string) $match[1];
    }

    /** @param list<string> $command
     *  @return array{ok:bool,exit_code:int,output:string}
     */
    private static function probe(array $command): array
    {
        if ([] === $command || ! self::valid_absolute_path((string) $command[0])) {
            return array('ok' => false, 'exit_code' => 1, 'output' => 'Invalid executable path.');
        }

        if (Shell_Probe::exec_available()) {
            return Shell_Probe::run($command);
        }

        if (! self::proc_open_available()) {
            return array(
                'ok' => false,
                'exit_code' => 1,
                'output' => 'Neither PHP exec() nor proc_open() is available for FFmpeg security inspection.',
            );
        }

        $descriptors = array(
            0 => array('file', '/dev/null', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );

        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- Security inspection must execute the configured FFmpeg binary; argument arrays and bypass_shell prevent shell parsing.
        $process = proc_open($command, $descriptors, $pipes, null, null, array('bypass_shell' => true));
        if (! is_resource($process)) {
            return array('ok' => false, 'exit_code' => 1, 'output' => 'Could not start FFmpeg security inspection.');
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);
        $output = trim(
            (false === $stdout ? '' : $stdout)
            . (false === $stderr || '' === $stderr ? '' : "\n" . $stderr)
        );

        return array(
            'ok' => 0 === $exit_code,
            'exit_code' => (int) $exit_code,
            'output' => $output,
        );
    }

    private static function proc_open_available(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return ! in_array('proc_open', $disabled, true);
    }

    private static function valid_absolute_path(string $path): bool
    {
        return '' !== $path
            && str_starts_with($path, '/')
            && ! str_contains($path, "\0")
            && 1 === preg_match('#^/[A-Za-z0-9_./+-]+$#', $path);
    }

    /** @return array{processing_allowed:bool,version:string,version_raw:string,advisories:list<array<string,mixed>>} */
    private static function unverified_assessment(string $reason): array
    {
        $results = array();
        foreach (self::advisories() as $advisory) {
            $results[] = array(
                'id' => (string) $advisory['id'],
                'url' => (string) $advisory['url'],
                'title' => (string) $advisory['title'],
                'impact' => (string) $advisory['impact'],
                'capability_type' => (string) $advisory['capability_type'],
                'capability' => (string) $advisory['capability'],
                'capability_enabled' => null,
                'status' => 'unverified',
                'blocking' => true,
                'reason' => $reason,
            );
        }
        return array(
            'processing_allowed' => false,
            'version' => '',
            'version_raw' => '',
            'advisories' => $results,
        );
    }
}

// EOF: includes/FFmpeg_Security.php
