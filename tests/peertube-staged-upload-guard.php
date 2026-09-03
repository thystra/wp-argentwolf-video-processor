<?php
/**
 * Focused tests for the R42 local source/backend upload fence.
 */

declare(strict_types=1);

$root = sys_get_temp_dir() . '/awvp-upload-guard-' . bin2hex(random_bytes(6));
$GLOBALS['awvp_upload_guard_uploads'] = array(
    'basedir' => $root . '/uploads',
    'baseurl' => 'https://example.test/uploads',
    'error'   => false,
);
mkdir((string) $GLOBALS['awvp_upload_guard_uploads']['basedir'], 0700, true);

function wp_upload_dir(): array
{
    return $GLOBALS['awvp_upload_guard_uploads'];
}

function wp_normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

function sanitize_text_field(string $value): string
{
    return trim($value);
}

require_once dirname(__DIR__) . '/includes/Storage.php';
require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_Input.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Source_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Upload_State_Machine.php';
require_once dirname(__DIR__) . '/includes/Backend_Registry.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Upload_Guard.php';

use ArgentVideo\PeerTube_Staged_Source_Identity as Source_Identity;
use ArgentVideo\PeerTube_Staged_Upload_Guard as Guard;
use ArgentVideo\PeerTube_Staged_Upload_State_Machine as Machine;
use ArgentVideo\Storage;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$directory = Storage::root() . '/77/staging';
mkdir($directory, 0700, true);
$path = $directory . '/source.mp4';
file_put_contents($path, 'guard-source-v1');
$source = Source_Identity::capture($path);
$assert(is_array($source), 'Guard source fixture could not be captured.');

$record = Machine::create(
    array(
        'operation_id'   => 'upload_11111111111111111111111111111111',
        'video_post_id'  => 77,
        'backend_id'     => 'peertube-primary',
        'origin'         => 'https://video.example.org',
        'destination_id' => '41',
        'source'         => $source,
    ),
    7,
    1000
);
$assert(is_array($record), 'Guard upload record fixture is invalid.');

$descriptor = array(
    'id'                  => 'peertube-primary',
    'type'                => 'peertube',
    'label'               => 'Primary PeerTube',
    'state'               => 'active',
    'default_destination' => '41',
    'secret_ref'          => 'managed_11111111111111111111111111111111',
    'config_version'      => 1,
    'config'              => array('origin' => 'https://video.example.org'),
);

$assert(Guard::READY === Guard::evaluate($record, $descriptor), 'Exact source/backend binding did not pass the guard.');

$changed_destination = $descriptor;
$changed_destination['default_destination'] = '42';
$assert(Guard::BACKEND_CHANGED === Guard::evaluate($record, $changed_destination), 'Destination drift did not block upload readiness.');

$changed_origin = $descriptor;
$changed_origin['config']['origin'] = 'https://other.example.org';
$assert(Guard::BACKEND_CHANGED === Guard::evaluate($record, $changed_origin), 'Origin drift did not block upload readiness.');

$retired = $descriptor;
$retired['state'] = 'retired';
$assert(Guard::BACKEND_CHANGED === Guard::evaluate($record, $retired), 'Retired backend remained upload-ready.');

file_put_contents($path, 'guard-source-v2');
$assert(Guard::SOURCE_CHANGED === Guard::evaluate($record, $descriptor), 'Source mutation did not block upload readiness.');

@unlink($path);
@rmdir($directory);
@rmdir(dirname($directory));
@rmdir(Storage::root());
@rmdir((string) $GLOBALS['awvp_upload_guard_uploads']['basedir']);
@rmdir($root);

fwrite(STDOUT, "PeerTube staged upload guard tests passed.\n");
