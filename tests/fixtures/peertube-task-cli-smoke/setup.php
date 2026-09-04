<?php
/** Seed one local staged operation and queued R45 upload task without remote HTTP. */
declare(strict_types=1);

use ArgentVideo\Atomic_Option_Result;
use ArgentVideo\Backend_Registry;
use ArgentVideo\Managed_Backend_Secret_Store;
use ArgentVideo\Model_Activator;
use ArgentVideo\PeerTube_Api_Client;
use ArgentVideo\PeerTube_Http_Client;
use ArgentVideo\PeerTube_Remote_Asset_Reconciliation_Service;
use ArgentVideo\PeerTube_Staged_Upload_Api;
use ArgentVideo\PeerTube_Staged_Upload_Operation_Store;
use ArgentVideo\PeerTube_Staged_Upload_Service;
use ArgentVideo\PeerTube_Staged_Upload_State_Machine;
use ArgentVideo\PeerTube_Upload_Task_Coordinator;
use ArgentVideo\Remote_Asset_Repository;
use ArgentVideo\Storage;
use ArgentVideo\Task_Repository;
use ArgentVideo\Video_Post_Type;

$assert = static function (bool $ok, string $message): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
};

$registry = new Backend_Registry();
$descriptor = $registry->get('r38-admin');
$assert(
    is_array($descriptor)
        && 'active' === ($descriptor['state'] ?? null)
        && '101' === ($descriptor['default_destination'] ?? null)
        && 'http://peertube.test:9000' === ($descriptor['config']['origin'] ?? null),
    'R45 CLI setup did not inherit the exact active PeerTube backend prerequisite.'
);
$secrets = new Managed_Backend_Secret_Store();
$secret = $secrets->read((string) $descriptor['secret_ref'], 'r38-admin');
$assert(
    is_array($secret)
        && 'r37-success-access-token-canary' === ($secret['access_token'] ?? null)
        && 1 === ($secret['generation'] ?? null),
    'R45 CLI setup could not re-prove the managed credential prerequisite.'
);
unset($secret);

Video_Post_Type::register();
$video_post_id = wp_insert_post(array(
    'post_type' => Video_Post_Type::POST_TYPE,
    'post_status' => 'draft',
    'post_title' => 'R45 one-shot CLI smoke',
), true);
$assert(! is_wp_error($video_post_id) && (int) $video_post_id > 0, 'R45 CLI setup could not create its video asset.');
$video_post_id = (int) $video_post_id;

$directory = Storage::root() . '/' . $video_post_id . '/staging';
$assert(is_dir($directory) || wp_mkdir_p($directory), 'R45 CLI setup could not create its managed staging directory.');
$source = Storage::assert_managed_path($directory . '/r43-source.mp4');
$source_bytes = 'R43-STAGED-BYTES';
$assert(strlen($source_bytes) === file_put_contents($source, $source_bytes, LOCK_EX), 'R45 CLI setup could not create its exact staged source.');

$operations = new PeerTube_Staged_Upload_Operation_Store();
$tasks = new Task_Repository();
$api_factory = static fn (string $origin): PeerTube_Staged_Upload_Api => new PeerTube_Api_Client(new PeerTube_Http_Client($origin));
$upload = new PeerTube_Staged_Upload_Service($operations, $registry, $secrets, $api_factory);
$reconciliation = new PeerTube_Remote_Asset_Reconciliation_Service(
    $operations,
    new Remote_Asset_Repository(),
    $registry,
    $secrets,
    $api_factory
);
$coordinator = new PeerTube_Upload_Task_Coordinator(
    $tasks,
    array($operations, 'get'),
    array($upload, 'advance'),
    array($reconciliation, 'advance')
);

$now = time();
$begun = $upload->begin($video_post_id, 'r38-admin', $source, 'R43 staged source', 1, $now);
$assert(
    PeerTube_Staged_Upload_Service::STATUS_ADVANCED === ($begun['status'] ?? null)
        && PeerTube_Staged_Upload_State_Machine::PHASE_READY === ($begun['phase'] ?? null),
    'R45 CLI setup did not create the exact ready staged-upload operation.'
);
$operation_id = (string) ($begun['operation_id'] ?? '');
$queued = $coordinator->enqueue_upload($operation_id, $now);
$assert(
    Task_Repository::APPLIED === ($queued['status'] ?? null)
        && (int) ($queued['task_id'] ?? 0) > 0,
    'R45 CLI setup did not enqueue the exact upload-advance task.'
);

/** @var wpdb $wpdb */
global $wpdb;
$remote_table = $wpdb->prefix . Model_Activator::REMOTE_ASSETS_TABLE;
$assert(0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM {$remote_table}"), 'R45 CLI setup prematurely created a remote-asset row.');

echo "PEERTUBE_TASK_CLI_SETUP=PASS\n";
