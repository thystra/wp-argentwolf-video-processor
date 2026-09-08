<?php
/** Focused local-player regression tests for RC7 native HLS ownership/fallback. */
declare(strict_types=1);

namespace {
    define('ARGENT_VIDEO_DIR', dirname(__DIR__) . '/');
    define('ARGENT_VIDEO_URL', 'https://example.test/wp-content/plugins/argentwolf-video-processor/');
    define('ARGENT_VIDEO_VERSION', 'test');

    $GLOBALS['awvp_renderer_meta'] = array();
    $GLOBALS['awvp_renderer_urls'] = array();
    $GLOBALS['awvp_renderer_mimes'] = array();
    $GLOBALS['awvp_renderer_enqueued'] = array();

    function get_post_meta(int $id, string $key, bool $single = true): mixed
    {
        unset($single);
        return $GLOBALS['awvp_renderer_meta'][$id][$key] ?? '';
    }
    function wp_get_attachment_url(int $id): string|false { return $GLOBALS['awvp_renderer_urls'][$id] ?? false; }
    function get_post_mime_type(int $id): string|false { return $GLOBALS['awvp_renderer_mimes'][$id] ?? false; }
    function attachment_url_to_postid(string $url): int
    {
        foreach ($GLOBALS['awvp_renderer_urls'] as $id => $candidate) {
            if ($candidate === $url) return (int) $id;
        }
        return 0;
    }
    function esc_url(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
    function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
    function wp_register_script(string $handle, string $src, array $deps = array(), string|bool|null $ver = false, array|bool $args = array()): bool
    {
        unset($handle, $src, $deps, $ver, $args);
        return true;
    }
    function wp_enqueue_script(string $handle): void { $GLOBALS['awvp_renderer_enqueued'][] = $handle; }

    require_once dirname(__DIR__) . '/includes/Player.php';
    require_once dirname(__DIR__) . '/includes/Renderer.php';

    $assert = static function (bool $ok, string $message): void {
        if (! $ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    };

    $id = 20;
    $GLOBALS['awvp_renderer_urls'][$id] = 'https://example.test/uploads/original.mp4';
    $GLOBALS['awvp_renderer_mimes'][$id] = 'video/mp4';
    $GLOBALS['awvp_renderer_meta'][$id]['_argent_video_outputs'] = array(
        'webm' => array('url'=>'https://example.test/awvp/video.webm','mime'=>'video/webm'),
        'mp4'  => array('url'=>'https://example.test/awvp/video.mp4','mime'=>'video/mp4'),
        'hls'  => array('url'=>'https://example.test/awvp/hls/master.m3u8','mime'=>'application/vnd.apple.mpegurl'),
    );

    $renderer = new \ArgentVideo\Renderer(new \ArgentVideo\Player());
    $html = $renderer->render_attachment_player($id);
    $assert(str_contains($html, '<video controls playsinline'), 'AWVP-owned native player lost controls/playsinline.');
    $assert(str_contains($html, 'data-argent-hls="https://example.test/awvp/hls/master.m3u8"'), 'Native player lost HLS authority.');
    $assert(str_contains($html, 'data-argent-fallback="https://example.test/awvp/video.mp4"'), 'Native player does not reserve the generated MP4 only as an HLS failure fallback.');
    $assert(str_contains($html, '<source src="https://example.test/awvp/video.webm" type="video/webm">'), 'Native player lost WebM progressive source.');
    $assert(str_contains($html, '<source src="https://example.test/awvp/video.mp4" type="video/mp4">'), 'Native player lost MP4 progressive source.');
    $assert(! str_contains(strtolower($html), 'autoplay'), 'Native player unexpectedly enables autoplay.');
    $assert(array('argent-video-player') === $GLOBALS['awvp_renderer_enqueued'], 'HLS player assets were not enqueued exactly once.');

    $shortcode = $renderer->render_shortcode(
        '<video autoplay="autoplay" controls src="https://example.test/uploads/original.mp4"></video>',
        array('src'=>'https://example.test/uploads/original.mp4')
    );
    $assert(! str_contains(strtolower($shortcode), 'autoplay'), 'Renderer did not strip inherited autoplay from compatibility content.');
    $assert(str_contains($shortcode, 'data-argent-hls='), 'Compatibility renderer lost HLS authority.');

    fwrite(STDOUT, "AWVP native renderer/player regression tests passed.\n");
}
