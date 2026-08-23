<?php
/**
 * File: includes/Settings.php
 */

declare(strict_types=1);

namespace ArgentVideo;

final class Settings
{
    public const OPTION = 'argent_video_processor_settings';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return array(
            'auto_queue'              => true,
            'auto_dispatch'           => true,
            'profile'                 => 'dual',
            'adaptive_hls'            => true,
            'max_width'               => 1280,
            'max_height'              => 720,
            'mp4_crf'                 => 23,
            'mp4_maxrate_kbps'        => 2500,
            'webm_crf'                => 32,
            'webm_maxrate_kbps'       => 1800,
            'audio_bitrate_kbps'      => 128,
            'hls_segment_seconds'     => 6,
            'hls_360_video_kbps'      => 650,
            'hls_480_video_kbps'      => 1100,
            'hls_720_video_kbps'      => 2200,
            'hls_audio_bitrate_kbps'  => 96,
            'hls_preset'              => 'medium',
            'ffmpeg_path'             => '/usr/bin/ffmpeg',
            'ffprobe_path'            => '/usr/bin/ffprobe',
            'wp_cli_path'             => '/usr/local/bin/wp',
            'nice_level'              => 10,
            'ionice_class'            => 2,
            'ionice_level'            => 7,
            'strip_metadata'          => true,
            'stale_job_minutes'       => 240,
            'worker_log_success_limit' => 10,
            'worker_log_error_limit'   => 100,
        );
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        $saved = get_option(self::OPTION, array());
        if (! is_array($saved)) {
            $saved = array();
        }

        return array_replace(self::defaults(), $saved);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function current_job_profile(): string
    {
        $profile = (string) self::get('profile', 'dual');
        return self::get('adaptive_hls', true) ? $profile . '+hls' : $profile;
    }

    public static function progressive_profile(string $job_profile): string
    {
        if ('adaptive-only' === $job_profile) {
            return '';
        }

        return str_replace('+hls', '', $job_profile);
    }

    public static function job_has_hls(string $job_profile): bool
    {
        return 'adaptive-only' === $job_profile || str_contains($job_profile, '+hls');
    }

    /** @return array<string, mixed> */
    public static function sanitize(mixed $input): array
    {
        if (! is_array($input)) {
            $input = array();
        }

        $defaults = self::defaults();
        $profiles = array('compatibility', 'dual', 'open');
        $presets = array('veryfast', 'faster', 'fast', 'medium', 'slow');

        return array(
            'auto_queue'             => ! empty($input['auto_queue']),
            'auto_dispatch'          => ! empty($input['auto_dispatch']),
            'profile'                => in_array((string) ($input['profile'] ?? ''), $profiles, true)
                ? (string) $input['profile']
                : $defaults['profile'],
            'adaptive_hls'           => ! empty($input['adaptive_hls']),
            'max_width'              => self::bounded_int($input['max_width'] ?? null, 320, 3840, 1280),
            'max_height'             => self::bounded_int($input['max_height'] ?? null, 240, 2160, 720),
            'mp4_crf'                => self::bounded_int($input['mp4_crf'] ?? null, 16, 35, 23),
            'mp4_maxrate_kbps'       => self::bounded_int($input['mp4_maxrate_kbps'] ?? null, 300, 20000, 2500),
            'webm_crf'               => self::bounded_int($input['webm_crf'] ?? null, 15, 50, 32),
            'webm_maxrate_kbps'      => self::bounded_int($input['webm_maxrate_kbps'] ?? null, 300, 20000, 1800),
            'audio_bitrate_kbps'     => self::bounded_int($input['audio_bitrate_kbps'] ?? null, 48, 320, 128),
            'hls_segment_seconds'    => self::bounded_int($input['hls_segment_seconds'] ?? null, 2, 12, 6),
            'hls_360_video_kbps'     => self::bounded_int($input['hls_360_video_kbps'] ?? null, 250, 2500, 650),
            'hls_480_video_kbps'     => self::bounded_int($input['hls_480_video_kbps'] ?? null, 400, 5000, 1100),
            'hls_720_video_kbps'     => self::bounded_int($input['hls_720_video_kbps'] ?? null, 700, 10000, 2200),
            'hls_audio_bitrate_kbps' => self::bounded_int($input['hls_audio_bitrate_kbps'] ?? null, 48, 192, 96),
            'hls_preset'             => in_array((string) ($input['hls_preset'] ?? ''), $presets, true)
                ? (string) $input['hls_preset']
                : $defaults['hls_preset'],
            'ffmpeg_path'            => self::sanitize_path($input['ffmpeg_path'] ?? $defaults['ffmpeg_path']),
            'ffprobe_path'           => self::sanitize_path($input['ffprobe_path'] ?? $defaults['ffprobe_path']),
            'wp_cli_path'            => self::sanitize_path($input['wp_cli_path'] ?? $defaults['wp_cli_path']),
            'nice_level'             => self::bounded_int($input['nice_level'] ?? null, 0, 19, 10),
            'ionice_class'           => self::bounded_int($input['ionice_class'] ?? null, 1, 3, 2),
            'ionice_level'           => self::bounded_int($input['ionice_level'] ?? null, 0, 7, 7),
            'strip_metadata'         => ! empty($input['strip_metadata']),
            'stale_job_minutes'       => self::bounded_int($input['stale_job_minutes'] ?? null, 30, 1440, 240),
            'worker_log_success_limit' => self::bounded_int($input['worker_log_success_limit'] ?? null, 0, 500, 10),
            'worker_log_error_limit'   => self::bounded_int($input['worker_log_error_limit'] ?? null, 0, 1000, 100),
        );
    }

    private static function bounded_int(mixed $value, int $minimum, int $maximum, int $fallback): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (false === $integer) {
            return $fallback;
        }

        return max($minimum, min($maximum, (int) $integer));
    }

    private static function sanitize_path(mixed $value): string
    {
        $path = trim((string) $value);
        if ('' === $path || ! str_starts_with($path, '/')) {
            return '';
        }

        return preg_replace('/[^A-Za-z0-9_\.\/-]/', '', $path) ?? '';
    }
}

// EOF: includes/Settings.php
