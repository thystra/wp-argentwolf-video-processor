<?php
/** Focused tests for inode-bound streamed PeerTube upload slices. */
declare(strict_types=1);

$GLOBALS['awvp_upload_slice_basedir'] = sys_get_temp_dir() . '/awvp-upload-slice-' . getmypid();

function wp_upload_dir(): array
{
    return array(
        'basedir' => $GLOBALS['awvp_upload_slice_basedir'],
        'baseurl' => 'https://example.test/wp-content/uploads',
        'error' => false,
    );
}

function wp_normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

require_once dirname(__DIR__) . '/includes/Storage.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Source_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Upload_Slice.php';

use ArgentVideo\PeerTube_Staged_Source_Identity;
use ArgentVideo\PeerTube_Upload_Slice;
use ArgentVideo\Storage;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$directory = Storage::root() . '/77/staging';
if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
    throw new RuntimeException('Could not create upload-slice fixture directory.');
}
$path = $directory . '/source.mp4';
$contents = str_repeat('A', 1024 * 1024) . str_repeat('B', 1024 * 1024) . 'tail';
$assert(strlen($contents) === file_put_contents($path, $contents), 'Could not create upload-slice fixture.');
$identity = PeerTube_Staged_Source_Identity::capture($path);
$assert(is_array($identity), 'Could not capture upload-slice source identity.');

$slice = PeerTube_Upload_Slice::open($identity, 1024 * 1024, 1024 * 1024);
$assert($slice instanceof PeerTube_Upload_Slice, 'Could not open verified upload slice.');
$assert(1024 * 1024 === $slice->start() && 1024 * 1024 === $slice->bytes(), 'Upload-slice range drifted.');
$received = hash_init('sha256');
$received_bytes = 0;
while (true) {
    $piece = $slice->read(65536);
    if ('' === $piece) {
        break;
    }
    $received_bytes += strlen($piece);
    hash_update($received, $piece);
}
$assert(1024 * 1024 === $received_bytes, 'Upload slice did not stream the exact requested byte count.');
$assert(hash('sha256', str_repeat('B', 1024 * 1024)) === hash_final($received), 'Upload slice streamed the wrong source range.');
$assert($slice->complete(), 'Upload slice did not confirm the streamed slice hash.');
$assert($slice->verify_unchanged(), 'Unchanged upload slice failed post-transfer identity verification.');
$slice->close();

// Whole-remaining semantics are represented by an ordinary exact slice whose
// length is the remaining source; the upload policy decides that length.
$whole = PeerTube_Upload_Slice::open($identity, 0, strlen($contents));
$assert($whole instanceof PeerTube_Upload_Slice, 'Whole-remaining slice could not open.');
$first = $whole->read(4 * 1024 * 1024);
$assert(1024 * 1024 === strlen($first), 'Upload slice honored an unbounded transport read request.');
$total = strlen($first);
while (true) {
    $piece = $whole->read(131072);
    if ('' === $piece) {
        break;
    }
    $total += strlen($piece);
}
$assert(strlen($contents) === $total && $whole->complete(), 'Whole-remaining slice did not stream exactly once.');
$assert($whole->verify_unchanged(true), 'Final whole-source verification failed for unchanged source.');
$whole->close();

// A source mutation after opening must prevent confirmation even if the already
// selected slice itself was fully read.
$mutating = PeerTube_Upload_Slice::open($identity, 0, 1024);
$assert($mutating instanceof PeerTube_Upload_Slice, 'Mutation fixture slice could not open.');
while ('' !== $mutating->read(256)) {
}
$assert($mutating->complete(), 'Mutation fixture did not finish its selected slice.');
$assert(false !== file_put_contents($path, $contents . 'changed'), 'Could not mutate staged source fixture.');
clearstatcache(true, $path);
$assert(! $mutating->verify_unchanged(), 'Post-open source mutation was not detected.');
$mutating->close();

// A changed source must also fail the pre-transfer full-source commitment.
$assert(null === PeerTube_Upload_Slice::open($identity, 0, 1), 'Changed source was accepted for a new upload slice.');

@unlink($path);
@rmdir($directory);
@rmdir(dirname($directory));
@rmdir(Storage::root());
@rmdir($GLOBALS['awvp_upload_slice_basedir']);

fwrite(STDOUT, "PeerTube upload slice tests passed.\n");
