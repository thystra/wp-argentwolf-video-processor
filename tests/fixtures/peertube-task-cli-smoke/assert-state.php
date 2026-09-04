<?php
/** Final durable-state assertions for the R45 one-shot CLI happy/wait path. */
declare(strict_types=1);

use ArgentVideo\Backend_Adapter_Factory;
use ArgentVideo\Backend_Capabilities;
use ArgentVideo\Backend_Registry;
use ArgentVideo\Managed_Backend_Secret_Store;
use ArgentVideo\Model_Activator;
use ArgentVideo\PeerTube_Backend_Adapter;
use ArgentVideo\PeerTube_Staged_Upload_Operation_Store;
use ArgentVideo\PeerTube_Staged_Upload_State_Machine;
use ArgentVideo\PeerTube_Upload_Task_Coordinator;
use ArgentVideo\Storage;
use ArgentVideo\Task_Repository;

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
        && '101' === ($descriptor['default_destination'] ?? null),
    'R45 CLI execution changed the active backend prerequisite.'
);
$factory = new Backend_Adapter_Factory(new PeerTube_Backend_Adapter(new Managed_Backend_Secret_Store()));
foreach (array(Backend_Capabilities::INGEST_AWVP_STAGING, Backend_Capabilities::PROCESSING_VIDEO) as $capability) {
    $assert(! $registry->eligible('r38-admin', $capability, $factory), 'R45 CLI smoke prematurely advertised PeerTube execution capability.');
}

$operations = (new PeerTube_Staged_Upload_Operation_Store())->open_operations();
$assert(is_array($operations) && 1 === count($operations), 'R45 CLI smoke did not retain exactly one staged-upload journal record.');
$record = array_values($operations)[0];
$assert(
    PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED === ($record['phase'] ?? null)
        && 16 === ($record['confirmed_bytes'] ?? null)
        && '901' === ($record['remote_identity']['id'] ?? null)
        && '12345678-1234-4abc-9def-1234567890ab' === ($record['remote_identity']['uuid'] ?? null)
        && (int) ($record['remote_asset_id'] ?? 0) > 0,
    'R45 CLI smoke did not reach the exact ready-verified durable operation state.'
);

$source = Storage::assert_managed_path(Storage::root() . '/' . (int) $record['video_post_id'] . '/staging/r43-source.mp4');
$assert(is_file($source) && 'R43-STAGED-BYTES' === file_get_contents($source), 'R45 CLI execution changed or deleted the staged source.');

/** @var wpdb $wpdb */
global $wpdb;
$task_table = $wpdb->prefix . Task_Repository::TABLE_SUFFIX;
$tasks = $wpdb->get_results(
    $wpdb->prepare("SELECT * FROM %i WHERE video_post_id = %d ORDER BY id ASC", $task_table, (int) $record['video_post_id']),
    ARRAY_A
);
$assert(is_array($tasks) && 2 === count($tasks), 'R45 CLI smoke did not retain exactly the upload and reconciliation tasks.');
$by_type = array();
foreach ($tasks as $task) {
    $by_type[(string) $task['task_type']] = $task;
    $serialized = serialize(array($task['payload_json'] ?? null, $task['error_message'] ?? null));
    foreach (array('r37-success-access-token-canary', 'r37-success-refresh-token-canary') as $canary) {
        $assert(! str_contains($serialized, $canary), 'R45 task persistence retained plaintext managed credential material.');
    }
}
$upload = $by_type[PeerTube_Upload_Task_Coordinator::TASK_UPLOAD_ADVANCE] ?? null;
$reconcile = $by_type[PeerTube_Upload_Task_Coordinator::TASK_REMOTE_RECONCILE] ?? null;
$assert(
    is_array($upload)
        && Task_Repository::STATUS_COMPLETE === ($upload['status'] ?? null)
        && 2 === (int) ($upload['attempts'] ?? 0),
    'R45 upload task did not complete in exactly two one-shot claims.'
);
$assert(
    is_array($reconcile)
        && Task_Repository::STATUS_COMPLETE === ($reconcile['status'] ?? null)
        && 3 === (int) ($reconcile['attempts'] ?? 0),
    'R45 reconciliation task did not complete in exactly three one-shot claims.'
);

$remote_table = $wpdb->prefix . Model_Activator::REMOTE_ASSETS_TABLE;
$remote = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM %i WHERE id = %d", $remote_table, (int) $record['remote_asset_id']),
    ARRAY_A
);
$assert(
    is_array($remote)
        && 'r38-admin' === ($remote['backend_id'] ?? null)
        && '101' === ($remote['channel_id'] ?? null)
        && '12345678-1234-4abc-9def-1234567890ab' === ($remote['remote_id'] ?? null)
        && 'ready' === ($remote['state'] ?? null)
        && 'private' === ($remote['desired_privacy'] ?? null)
        && 'private' === ($remote['actual_privacy'] ?? null)
        && '1:published' === ($remote['remote_processing_state'] ?? null)
        && '' !== (string) ($remote['last_verified_at'] ?? ''),
    'R45 CLI smoke did not persist the exact ready remote-asset observation.'
);

$queued_owned = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM %i WHERE task_type IN (%s,%s) AND status IN ('queued','processing')",
        $task_table,
        PeerTube_Upload_Task_Coordinator::TASK_UPLOAD_ADVANCE,
        PeerTube_Upload_Task_Coordinator::TASK_REMOTE_RECONCILE
    )
);
$assert(0 === $queued_owned, 'R45 CLI smoke left an owned task queued or processing after ready verification.');

echo "PEERTUBE_TASK_CLI_STATE_ASSERTIONS=PASS\n";
