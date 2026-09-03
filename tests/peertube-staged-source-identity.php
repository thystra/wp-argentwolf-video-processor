<?php
/**
 * Focused tests for immutable managed staged-source identity capture.
 */

declare(strict_types=1);

$root = sys_get_temp_dir() . '/awvp-staged-source-' . bin2hex(random_bytes(6));
$GLOBALS['awvp_staged_source_uploads'] = array(
    'basedir' => $root . '/uploads',
    'baseurl' => 'https://example.test/uploads',
    'error'   => false,
);
mkdir((string) $GLOBALS['awvp_staged_source_uploads']['basedir'], 0700, true);

function wp_upload_dir(): array
{
    return $GLOBALS['awvp_staged_source_uploads'];
}

function wp_normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

require_once dirname(__DIR__) . '/includes/Storage.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Source_Identity.php';

use ArgentVideo\PeerTube_Staged_Source_Identity as Source_Identity;
use ArgentVideo\Storage;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$managed = Storage::root() . '/42/staging';
mkdir($managed, 0700, true);
$path = $managed . '/source.mp4';
file_put_contents($path, "staged-source-v1\n");

$identity = Source_Identity::capture($path);
$assert(is_array($identity), 'A regular managed staged source was not captured.');
$assert(
    array('kind', 'relative_path', 'sha256', 'bytes') === array_keys($identity),
    'Source identity projection keys drifted.'
);
$assert('wordpress_staging' === $identity['kind'], 'Source kind drifted.');
$assert('42/staging/source.mp4' === $identity['relative_path'], 'Absolute source path leaked into durable identity.');
$assert(hash_file('sha256', $path) === $identity['sha256'], 'Source SHA-256 commitment is incorrect.');
$assert(filesize($path) === $identity['bytes'], 'Source byte commitment is incorrect.');
$assert(Source_Identity::valid($identity), 'Captured source identity is not valid.');
$assert(Source_Identity::matches($identity), 'Unchanged source did not re-prove its identity.');
$assert($path === Source_Identity::absolute_path($identity['relative_path']), 'Relative source path did not resolve inside managed storage.');

file_put_contents($path, "staged-source-v2\n");
$assert(! Source_Identity::matches($identity), 'Changed staged source incorrectly matched the prior identity.');

$outside = $root . '/outside.mp4';
file_put_contents($outside, 'outside');
$assert(null === Source_Identity::capture($outside), 'Source identity accepted a file outside managed storage.');
$assert('' === Source_Identity::absolute_path('../outside.mp4'), 'Relative traversal was accepted.');
$assert('' === Source_Identity::absolute_path('/absolute.mp4'), 'Absolute source path was accepted.');

$link = $managed . '/source-link.mp4';
if (@symlink($outside, $link)) {
    $assert(null === Source_Identity::capture($link), 'Source identity accepted a symbolic link.');
    @unlink($link);
}

@unlink($path);
@unlink($outside);
@rmdir($managed);
@rmdir(dirname($managed));
@rmdir(Storage::root());
@rmdir((string) $GLOBALS['awvp_staged_source_uploads']['basedir']);
@rmdir($root);

fwrite(STDOUT, "PeerTube staged source identity tests passed.\n");
