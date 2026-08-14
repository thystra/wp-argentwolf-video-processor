<?php
/**
 * File: includes/Diagnostics.php
 */

declare(strict_types=1);

namespace ArgentVideo;


final class Diagnostics
{
    /** @return list<array{check:string,status:string,detail:string}> */
    public function checks(): array
    {
        $settings = Settings::all();
        $checks = array();

        $checks[] = array(
            'check'  => 'PHP execution context',
            'status' => 'ok',
            'detail' => PHP_SAPI . '; open_basedir=' . ('' !== trim((string) ini_get('open_basedir')) ? (string) ini_get('open_basedir') : '(none)'),
        );

        foreach (array('ffmpeg_path' => 'FFmpeg', 'ffprobe_path' => 'FFprobe', 'wp_cli_path' => 'WP-CLI') as $key => $label) {
            $path = (string) $settings[$key];
            $available = Shell_Probe::path_executable($path);
            $detail = $path;
            if ($available && Shell_Probe::stat_restricted($path)) {
                $detail .= ' (executable; PHP filesystem stat restricted by open_basedir)';
            }
            $checks[] = array(
                'check'  => $label,
                'status' => $available ? 'ok' : 'error',
                'detail' => $detail,
            );
        }

        $checks[] = $this->ffmpeg_version($settings);
        $checks = array_merge($checks, $this->ffmpeg_security_checks($settings));
        $checks[] = array(
            'check'  => 'proc_open()',
            'status' => function_exists('proc_open') ? 'ok' : 'error',
            'detail' => function_exists('proc_open') ? 'Available' : 'Unavailable',
        );
        $checks[] = array(
            'check'  => 'exec()',
            'status' => function_exists('exec') ? 'ok' : 'warning',
            'detail' => function_exists('exec') ? 'Available for detached dispatch' : 'Use a system-scheduled worker',
        );
        $checks[] = array(
            'check'  => 'Uploads writable',
            'status' => wp_is_writable((string) wp_get_upload_dir()['basedir']) ? 'ok' : 'error',
            'detail' => (string) wp_get_upload_dir()['basedir'],
        );

        $checks = array_merge($checks, $this->encoder_checks($settings));
        if (! empty($settings['adaptive_hls'])) {
            $checks = array_merge($checks, $this->hls_checks($settings));
        }
        $checks[] = $this->hls_js_check();
        return $checks;
    }

    /** @return array<string, mixed> */
    public function ffmpeg_security(): array
    {
        return FFmpeg_Security::assess((string) Settings::get('ffmpeg_path', ''));
    }

    /** @param array<string, mixed> $tests
     *  @return array<string, mixed>
     */
    public function site_health_tests(array $tests): array
    {
        $tests['direct']['argentwolf_video_ffmpeg_security'] = array(
            'label' => __('ArgentWolf Video Processor FFmpeg security', 'argentwolf-video-processor'),
            'test' => array($this, 'site_health_ffmpeg_security'),
        );
        return $tests;
    }

    /** @return array<string, mixed> */
    public function site_health_ffmpeg_security(): array
    {
        $assessment = $this->ffmpeg_security();
        $allowed = ! empty($assessment['processing_allowed']);
        $items = array();
        $actions = array();
        foreach ((array) ($assessment['advisories'] ?? array()) as $advisory) {
            if (! is_array($advisory)) {
                continue;
            }
            $items[] = '<li><strong>' . esc_html((string) $advisory['id']) . '</strong>: ' . esc_html((string) $advisory['reason']) . '</li>';
            $actions[] = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url((string) $advisory['url']),
                esc_html(sprintf(__('%s details at NVD', 'argentwolf-video-processor'), (string) $advisory['id']))
            );
        }

        $description = $allowed
            ? __('The configured FFmpeg binary passed the plugin security advisory gate for new transcoding.', 'argentwolf-video-processor')
            : __('New video transcoding is disabled because the configured FFmpeg binary did not pass the security advisory gate.', 'argentwolf-video-processor');

        return array(
            'label' => $allowed
                ? __('FFmpeg security advisory gate passed', 'argentwolf-video-processor')
                : __('FFmpeg security advisory gate is blocking transcoding', 'argentwolf-video-processor'),
            'status' => $allowed ? 'good' : 'critical',
            'badge' => array(
                'label' => __('ArgentWolf Video Processor', 'argentwolf-video-processor'),
                'color' => 'blue',
            ),
            'description' => '<p>' . esc_html($description) . '</p><ul>' . implode('', $items) . '</ul>',
            'actions' => '<p>' . implode(' | ', $actions) . '</p>',
            'test' => 'argentwolf_video_ffmpeg_security',
        );
    }

    /** @param array<string, mixed> $settings
     *  @return list<array<string, mixed>>
     */
    private function ffmpeg_security_checks(array $settings): array
    {
        $assessment = FFmpeg_Security::assess((string) ($settings['ffmpeg_path'] ?? ''));
        $checks = array();
        foreach ((array) ($assessment['advisories'] ?? array()) as $advisory) {
            if (! is_array($advisory)) {
                continue;
            }
            $status = empty($advisory['blocking']) ? 'ok' : 'error';
            $checks[] = array(
                'check' => 'FFmpeg security ' . (string) $advisory['id'],
                'status' => $status,
                'detail' => (string) $advisory['reason'],
                'url' => (string) $advisory['url'],
                'url_label' => (string) $advisory['id'] . ' — NVD',
            );
        }
        return $checks;
    }

    /** @param array<string, mixed> $settings
     *  @return array{check:string,status:string,detail:string}
     */
    private function ffmpeg_version(array $settings): array
    {
        $ffmpeg = (string) ($settings['ffmpeg_path'] ?? '');
        $result = Shell_Probe::run(array($ffmpeg, '-version'));
        $line = trim(strtok($result['output'], "\n") ?: 'Unknown version');
        return array('check' => 'FFmpeg version', 'status' => $result['ok'] ? 'ok' : 'error', 'detail' => $line);
    }

    /** @param array<string, mixed> $settings
     *  @return list<array{check:string,status:string,detail:string}>
     */
    private function encoder_checks(array $settings): array
    {
        $profile = (string) ($settings['profile'] ?? 'dual');
        $required = array();
        if (in_array($profile, array('compatibility', 'dual'), true) || ! empty($settings['adaptive_hls'])) {
            $required['libx264'] = 'H.264 video';
            $required['aac'] = 'AAC audio';
        }
        if (in_array($profile, array('open', 'dual'), true)) {
            $required['libvpx-vp9'] = 'VP9 video';
            $required['libopus'] = 'Opus audio';
        }

        $ffmpeg = (string) ($settings['ffmpeg_path'] ?? '');
        if ([] === $required || ! Shell_Probe::path_executable($ffmpeg)) {
            return array();
        }

        $result = Shell_Probe::run(array($ffmpeg, '-hide_banner', '-encoders'));
        if (! $result['ok']) {
            return array(array('check' => 'FFmpeg encoders', 'status' => 'error', 'detail' => $result['output']));
        }
        $output = $result['output'];
        $checks = array();
        foreach ($required as $encoder => $description) {
            $available = 1 === preg_match('/^\s*[A-Z\.]{6}\s+' . preg_quote($encoder, '/') . '\s/m', $output);
            $checks[] = array(
                'check'  => 'Encoder ' . $encoder,
                'status' => $available ? 'ok' : 'error',
                'detail' => $available ? $description . ' available' : $description . ' unavailable',
            );
        }
        return $checks;
    }

    /** @param array<string, mixed> $settings
     *  @return list<array{check:string,status:string,detail:string}>
     */
    private function hls_checks(array $settings): array
    {
        $ffmpeg = (string) ($settings['ffmpeg_path'] ?? '');
        if (! Shell_Probe::path_executable($ffmpeg)) {
            return array();
        }
        $muxers = Shell_Probe::run(array($ffmpeg, '-hide_banner', '-muxers'));
        $help = Shell_Probe::run(array($ffmpeg, '-hide_banner', '-h', 'muxer=hls'));
        if (! $muxers['ok'] || ! $help['ok']) {
            return array(array('check' => 'Adaptive HLS', 'status' => 'error', 'detail' => trim($muxers['output'] . "\n" . $help['output'])));
        }
        $muxer_available = 1 === preg_match('/^\s*E\s+hls\s/m', $muxers['output']);
        $help_output = $help['output'];
        $fmp4_available = str_contains($help_output, 'hls_segment_type') && str_contains($help_output, 'fmp4');
        return array(
            array('check' => 'HLS muxer', 'status' => $muxer_available ? 'ok' : 'error', 'detail' => $muxer_available ? 'Available' : 'Unavailable'),
            array('check' => 'HLS fragmented MP4', 'status' => $fmp4_available ? 'ok' : 'error', 'detail' => $fmp4_available ? 'fMP4 segments available' : 'Required fMP4 options unavailable'),
        );
    }

    /** @return array{check:string,status:string,detail:string} */
    private function hls_js_check(): array
    {
        if (Player::has_local_hls_js()) {
            return array('check' => 'HLS.js player', 'status' => 'ok', 'detail' => 'Local hls.js ' . Player::HLS_JS_VERSION);
        }
        return array('check' => 'HLS.js player', 'status' => 'warning', 'detail' => 'Local player absent; native HLS or progressive sources will be used');
    }
}

// EOF: includes/Diagnostics.php
