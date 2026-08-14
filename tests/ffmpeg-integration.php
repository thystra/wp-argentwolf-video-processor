<?php
/**
 * File: tests/ffmpeg-integration.php
 */

declare(strict_types=1);

$GLOBALS['argent_video_integration_uploads'] = array(
    'basedir' => '',
    'baseurl' => 'https://example.test/media',
    'error'   => false,
);

function wp_upload_dir(): array
{
    return $GLOBALS['argent_video_integration_uploads'];
}

function wp_normalize_path(string $path): string
{
    return str_replace('\\\\', '/', $path);
}

function trailingslashit(string $value): string
{
    return rtrim($value, "/\\\\") . '/';
}

function wp_mkdir_p(string $target): bool
{
    return is_dir($target) || mkdir($target, 0755, true);
}

function wp_delete_file(string $file): void
{
    @unlink($file);
}

require_once dirname(__DIR__) . '/includes/Storage.php';
require_once dirname(__DIR__) . '/includes/Command_Builder.php';
require_once dirname(__DIR__) . '/includes/Probe.php';
require_once dirname(__DIR__) . '/includes/Adaptive_HLS.php';
require_once dirname(__DIR__) . '/includes/Shell_Probe.php';
require_once dirname(__DIR__) . '/includes/FFmpeg_Security.php';

use ArgentVideo\Adaptive_HLS;
use ArgentVideo\Command_Builder;
use ArgentVideo\Storage;

$ffmpeg = getenv('ARGENT_VIDEO_TEST_FFMPEG') ?: '/usr/bin/ffmpeg';
$ffprobe = getenv('ARGENT_VIDEO_TEST_FFPROBE') ?: '/usr/bin/ffprobe';
if (! is_executable($ffmpeg) || ! is_executable($ffprobe)) {
    fwrite(STDOUT, "FFmpeg integration test skipped: ffmpeg or ffprobe is unavailable.\n");
    exit(0);
}
$security = \ArgentVideo\FFmpeg_Security::assess($ffmpeg);
if (empty($security['processing_allowed'])) {
    fwrite(STDERR, 'FAIL: ' . \ArgentVideo\FFmpeg_Security::blocking_message($security) . "\n");
    exit(1);
}

$test_root = sys_get_temp_dir() . '/argent-video-test-' . bin2hex(random_bytes(6));
mkdir($test_root, 0700, true);
$GLOBALS['argent_video_integration_uploads']['basedir'] = $test_root . '/uploads';
mkdir((string) $GLOBALS['argent_video_integration_uploads']['basedir'], 0700, true);
$directory = Storage::ensure_attachment_directory(9001);
$remove_tree = static function (string $path) use (&$remove_tree): void {
    if (is_dir($path)) {
        foreach (scandir($path) ?: array() as $item) {
            if ('.' !== $item && '..' !== $item) {
                $remove_tree($path . '/' . $item);
            }
        }
        @rmdir($path);
    } elseif (is_file($path)) {
        @unlink($path);
    }
};
register_shutdown_function(static function () use ($test_root, $remove_tree): void { $remove_tree($test_root); });

$run = static function (array $command): array {
    $descriptors = array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $process = proc_open($command, $descriptors, $pipes, null, null, array('bypass_shell' => true));
    if (! is_resource($process)) {
        return array('exit_code' => 255, 'stdout' => '', 'stderr' => 'proc_open failed');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return array('exit_code' => proc_close($process), 'stdout' => $stdout ?: '', 'stderr' => $stderr ?: '');
};

$base = $test_root . '/base.mp4';
$source = $test_root . '/source.mp4';
$mp4 = $directory . '/output.mp4';
$webm = $directory . '/output.webm';
$hls_directory = $directory . '/hls';
mkdir($hls_directory . '/360p', 0700, true);

$result = $run(array(
    $ffmpeg, '-hide_banner', '-loglevel', 'error', '-y',
    '-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=10',
    '-f', 'lavfi', '-i', 'sine=frequency=1000:sample_rate=48000',
    '-t', '2', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $base,
));
if (0 !== $result['exit_code']) {
    fwrite(STDERR, "FAIL: synthetic source encode failed: {$result['stderr']}\n"); exit(1);
}
$help = $run(array($ffmpeg, '-hide_banner', '-h', 'full'));
$help_text = $help['stdout'] . "\n" . $help['stderr'];
if (str_contains($help_text, '-display_rotation')) {
    $rotation_fixture_mode = 'display_rotation';
    $metadata_command = array(
        $ffmpeg, '-hide_banner', '-loglevel', 'error', '-y',
        '-display_rotation:v:0', '90', '-i', $base, '-map', '0', '-c', 'copy',
        '-metadata', 'location=+30.1161-081.8837/', $source,
    );
} else {
    $rotation_fixture_mode = 'legacy_rotate_metadata';
    $metadata_command = array(
        $ffmpeg, '-hide_banner', '-loglevel', 'error', '-y',
        '-i', $base, '-map', '0', '-c', 'copy',
        '-metadata:s:v:0', 'rotate=90',
        '-metadata', 'location=+30.1161-081.8837/', $source,
    );
}
$result = $run($metadata_command);
if (0 !== $result['exit_code']) {
    fwrite(STDERR, "FAIL: synthetic metadata remux failed ({$rotation_fixture_mode}): {$result['stderr']}\n"); exit(1);
}

$result = $run(array(
    $ffprobe, '-v', 'error',
    '-show_entries', 'stream_tags=rotate:stream_side_data=rotation',
    '-of', 'json', $source,
));
if (0 !== $result['exit_code']) {
    fwrite(STDERR, "FAIL: synthetic source rotation probe failed: {$result['stderr']}\n"); exit(1);
}
$rotation_probe = json_decode($result['stdout'], true);
$rotation_value = null;
foreach ((array) ($rotation_probe['streams'] ?? array()) as $stream) {
    if (! is_array($stream)) { continue; }
    if (isset($stream['tags']['rotate']) && is_numeric($stream['tags']['rotate'])) {
        $rotation_value = (float) $stream['tags']['rotate'];
        break;
    }
    foreach ((array) ($stream['side_data_list'] ?? array()) as $side_data) {
        if (is_array($side_data) && isset($side_data['rotation']) && is_numeric($side_data['rotation'])) {
            $rotation_value = (float) $side_data['rotation'];
            break 2;
        }
    }
}
if (null === $rotation_value || abs(abs($rotation_value) - 90.0) > 0.01) {
    fwrite(STDERR, "FAIL: synthetic source did not contain 90-degree rotation metadata ({$rotation_fixture_mode}).\n");
    exit(1);
}
fwrite(STDOUT, "FFmpeg rotation fixture mode: {$rotation_fixture_mode}.\n");

$settings = array(
    'ffmpeg_path' => $ffmpeg, 'max_width' => 1280, 'max_height' => 720,
    'mp4_crf' => 23, 'mp4_maxrate_kbps' => 1000, 'webm_crf' => 32,
    'webm_maxrate_kbps' => 800, 'audio_bitrate_kbps' => 96,
    'strip_metadata' => true, 'hls_segment_seconds' => 2,
    'hls_360_video_kbps' => 500, 'hls_480_video_kbps' => 800,
    'hls_720_video_kbps' => 1200, 'hls_audio_bitrate_kbps' => 64,
    'hls_preset' => 'veryfast',
);
$source_probe = array('streams' => array(array(
    'codec_type' => 'video', 'width' => 320, 'height' => 240,
    'side_data_list' => array(array('rotation' => 90)),
)));
$rendition = Adaptive_HLS::renditions($settings, $source_probe)[0];
$commands = array(
    'mp4' => Command_Builder::mp4($source, $mp4, $settings),
    'webm' => Command_Builder::webm($source, $webm, $settings),
    'hls' => Command_Builder::hls(
        $source,
        $hls_directory . '/360p/index.m3u8',
        $hls_directory . '/360p/segment-%05d.m4s',
        $settings,
        $rendition,
        true
    ),
);
foreach ($commands as $type => $command) {
    $result = $run($command);
    if (0 !== $result['exit_code']) {
        fwrite(STDERR, "FAIL: {$type} command failed: {$result['stderr']}\n"); exit(1);
    }
}
Adaptive_HLS::validate_media_playlist($hls_directory . '/360p/index.m3u8');
Adaptive_HLS::write_master($hls_directory . '/master.m3u8', array(array_merge($rendition, array('audio_kbps' => 64))));

$probe = static function (string $path) use ($ffprobe, $run): array {
    $result = $run(array(
        $ffprobe, '-v', 'error', '-show_entries',
        'format=duration:format_tags:stream=codec_type,codec_name,width,height:stream_tags=rotate:stream_side_data=rotation',
        '-of', 'json', $path,
    ));
    if (0 !== $result['exit_code']) {
        throw new RuntimeException('FFprobe failed: ' . $result['stderr']);
    }
    $decoded = json_decode($result['stdout'], true);
    if (! is_array($decoded)) {
        throw new RuntimeException('FFprobe returned invalid JSON.');
    }
    return $decoded;
};

try {
    $outputs = array('mp4' => $probe($mp4), 'webm' => $probe($webm), 'hls' => $probe($hls_directory . '/360p/index.m3u8'));
} catch (RuntimeException $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n"); exit(1);
}
$video_stream = static function (array $data): array {
    foreach ((array) ($data['streams'] ?? array()) as $stream) {
        if (is_array($stream) && 'video' === ($stream['codec_type'] ?? '')) { return $stream; }
    }
    return array();
};
$failures = array();
foreach ($outputs as $type => $data) {
    $stream = $video_stream($data);
    $expected_codec = 'webm' === $type ? 'vp9' : 'h264';
    if ($expected_codec !== ($stream['codec_name'] ?? '')) { $failures[] = "{$type} codec was not {$expected_codec}."; }
    if (240 !== (int) ($stream['width'] ?? 0) || 320 !== (int) ($stream['height'] ?? 0)) { $failures[] = "{$type} did not normalize rotation into 240x320 pixels."; }
    if (isset($stream['tags']['rotate']) || isset($stream['side_data_list'][0]['rotation'])) { $failures[] = "{$type} retained rotation metadata."; }
    foreach (array_keys((array) ($data['format']['tags'] ?? array())) as $tag) {
        if (str_contains(strtolower((string) $tag), 'location')) { $failures[] = "{$type} retained location metadata."; }
    }
}
$master = is_file($hls_directory . '/master.m3u8') ? (string) file_get_contents($hls_directory . '/master.m3u8') : '';
if ('' === $master || ! is_file($hls_directory . '/360p/init.mp4') || [] === glob($hls_directory . '/360p/*.m4s')) {
    $failures[] = 'HLS master, initialization segment, or media segments are missing.';
}
if (! str_contains($master, 'CODECS="avc1.640028,mp4a.40.2"')) {
    $failures[] = 'HLS master playlist does not declare the expected codecs.';
}
if ([] !== $failures) {
    foreach ($failures as $failure) { fwrite(STDERR, "FAIL: {$failure}\n"); }
    exit(1);
}
fwrite(STDOUT, "FFmpeg integration test passed, including adaptive HLS.\n");

// EOF: tests/ffmpeg-integration.php
