<?php
/**
 * File: tests/storage-boundary.php
 */

declare(strict_types=1);

$test_root = sys_get_temp_dir() . '/argent-video-storage-boundary-' . bin2hex(random_bytes(6));
$uploads = $test_root . '/custom-uploads';
$outside = $test_root . '/outside';

mkdir($uploads, 0700, true);
mkdir($outside, 0700, true);

$GLOBALS['argent_video_storage_test_uploads'] = array(
    'basedir' => $uploads,
    'baseurl' => 'https://example.test/media',
    'error'   => false,
);

function wp_upload_dir(): array
{
    return $GLOBALS['argent_video_storage_test_uploads'];
}

function wp_normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

function trailingslashit(string $value): string
{
    return rtrim($value, "/\\") . '/';
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
require_once dirname(__DIR__) . '/includes/Output_Namer.php';
require_once dirname(__DIR__) . '/includes/Adaptive_HLS.php';

use ArgentVideo\Adaptive_HLS;
use ArgentVideo\Output_Namer;
use ArgentVideo\Storage;

$failures = array();

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$throws = static function (callable $callback, string $message) use (&$failures): void {
    try {
        $callback();
        $failures[] = $message;
    } catch (RuntimeException) {
        // Expected.
    }
};

$cleanup = static function (string $path) use (&$cleanup): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }

    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: array() as $item) {
        if ('.' !== $item && '..' !== $item) {
            $cleanup($path . '/' . $item);
        }
    }

    @rmdir($path);
};

register_shutdown_function(static function () use ($test_root, $cleanup): void {
    $cleanup($test_root);
});

$expected_root = wp_normalize_path($uploads) . '/argentwolf-video-processor';
$assert($expected_root === Storage::root(), 'Storage root must use wp_upload_dir()[basedir] plus the plugin slug.');

$attachment = Storage::ensure_attachment_directory(42);
$assert(
    $expected_root . '/42' === $attachment,
    'Attachment storage must be isolated beneath the plugin root by attachment ID.'
);

$mp4 = Output_Namer::derivative($attachment, '720p', 'mp4');
$webm = Output_Namer::derivative($attachment, '720p-vp9', 'webm');
$hls = Output_Namer::adaptive_directory($attachment);

$assert($attachment . '/video-720p.mp4' === $mp4, 'MP4 derivative naming must be attachment-local.');
$assert($attachment . '/video-720p-vp9.webm' === $webm, 'WebM derivative naming must be attachment-local.');
$assert($attachment . '/hls' === $hls, 'HLS output must use the attachment-local hls directory.');

$assert(Storage::is_managed_path($mp4), 'Expected managed derivative was rejected.');
$assert(
    ! Storage::is_managed_path($uploads . '/argentwolf-video-processor-evil/42/video.mp4'),
    'Sibling-prefix path must not be accepted as managed storage.'
);

$throws(
    static fn() => Storage::assert_managed_path($attachment . '/../43/video.mp4'),
    'Traversal path was accepted.'
);
$throws(
    static fn() => Storage::assert_managed_path($outside . '/video.mp4'),
    'Outside path was accepted.'
);
$throws(
    static fn() => Storage::assert_managed_path(Storage::root()),
    'The plugin storage root itself must not be accepted as a mutable managed child path.'
);

Storage::write_file($mp4, 'test-media');
$assert(is_file($mp4), 'Managed file write failed.');

$temp = Output_Namer::temporary($webm);
Storage::write_file($temp, 'temporary-media');
Storage::rename_path($temp, $webm);
$assert(is_file($webm) && ! is_file($temp), 'Managed atomic rename failed.');

$assert(
    'https://example.test/media/argentwolf-video-processor/42/video-720p.mp4' === Storage::url_for_path($mp4),
    'Managed path-to-URL conversion failed.'
);

Storage::make_directory($hls . '/360p');
$master = $hls . '/master.m3u8';
Adaptive_HLS::write_master(
    $master,
    array(
        array(
            'label' => '360p',
            'width' => 640,
            'height' => 360,
            'video_kbps' => 650,
            'audio_kbps' => 96,
        ),
    )
);
$assert(is_file($master), 'HLS master write did not use managed storage successfully.');

$throws(
    static fn() => Adaptive_HLS::write_master(
        $outside . '/master.m3u8',
        array(
            array(
                'label' => '360p',
                'width' => 640,
                'height' => 360,
                'video_kbps' => 650,
                'audio_kbps' => 96,
            ),
        )
    ),
    'HLS master write accepted a path outside managed storage.'
);

$symlink_supported = @symlink($outside, $attachment . '/escape');
if ($symlink_supported) {
    $throws(
        static fn() => Storage::assert_managed_path($attachment . '/escape/escaped.mp4'),
        'Managed path validation followed a symlink outside the plugin root.'
    );
    @unlink($attachment . '/escape');
}

Storage::delete_file($mp4);
$assert(! is_file($mp4), 'Managed file deletion failed.');

Storage::remove_tree($hls);
$assert(! is_dir($hls), 'Managed recursive directory deletion failed.');

Storage::remove_tree($attachment);
$assert(! is_dir($attachment), 'Attachment storage directory deletion failed.');

if ([] !== $failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Storage-boundary regression tests passed.\n");

// EOF: tests/storage-boundary.php
