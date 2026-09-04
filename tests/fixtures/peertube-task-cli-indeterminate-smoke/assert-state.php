<?php
/** Final durable-state assertions for an uncertain byte-bearing PeerTube PUT. */
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
    'R45 indeterminate smoke changed the active backend prerequisite.'
);
$factory = new Backend_Adapter_Factory(new PeerTube_Backend_Adapter(new Managed_Backend_Secret_Store()));
foreach (array(Backend_Capabilities::INGEST_AWVP_STAGING, Backend_Capabilities::PROCESSING_VIDEO) as $capability) {
    $assert(! $registry->eligible('r38-admin', $capability, $factory), 'R45 indeterminate smoke prematurely advertised PeerTube execution capability.');
}

$operations = (new PeerTube_Staged_Upload_Operation_Store())->open_operations();
$assert(is_array($operations) && 1 === count($operations), 'R45 indeterminate smoke did not retain exactly one staged-upload journal record.');
$record = array_values($operations)[0];
$assert(
    PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE === ($record['phase'] ?? null)
        && 0 === ($record['confirmed_bytes'] ?? null)
        && PeerTube_Staged_Upload_State_Machine::REQUEST_CHUNK === ($record['request_kind'] ?? null)
        && 0 === (int) ($record['request_start'] ?? -1)
        && 16 === (int) ($record['request_bytes'] ?? -1)
        && '' !== (string) ($record['upload_session_id'] ?? '')
        && '' === (string) ($record['remote_identity']['id'] ?? '')
        && '' === (string) ($record['remote_identity']['uuid'] ?? '')
        && 0 === (int) ($record['remote_asset_id'] ?? 0)
        && 'peertube.upload.indeterminate' === ($record['last_error']['code'] ?? null),
    'R45 indeterminate smoke did not preserve the exact uncertain chunk journal state.'
);

$source = Storage::assert_managed_path(Storage::root() . '/' . (int) $record['video_post_id'] . '/staging/r43-source.mp4');
$assert(is_file($source) && 'R43-STAGED-BYTES' === file_get_contents($source), 'R45 indeterminate execution changed or deleted the staged source.');

/** @var wpdb $wpdb */
global $wpdb;
$task_table = $wpdb->prefix . Task_Repository::TABLE_SUFFIX;
$tasks = $wpdb->get_results(
    $wpdb->prepare("SELECT * FROM %i WHERE video_post_id = %d ORDER BY id ASC", $task_table, (int) $record['video_post_id']),
    ARRAY_A
);
$assert(is_array($tasks) && 1 === count($tasks), 'R45 indeterminate smoke created a task outside the original upload task.');
$task = $tasks[0];
$assert(
    PeerTube_Upload_Task_Coordinator::TASK_UPLOAD_ADVANCE === ($task['task_type'] ?? null)
        && Task_Repository::STATUS_FAILED === ($task['status'] ?? null)
        && 2 === (int) ($task['attempts'] ?? 0)
        && null === ($task['lock_token'] ?? null)
        && null === ($task['locked_at'] ?? null)
        && str_contains((string) ($task['error_message'] ?? ''), 'explicit intervention'),
    'R45 indeterminate upload task was not terminally held after exactly two one-shot claims.'
);
$serialized = serialize(array($task['payload_json'] ?? null, $task['error_message'] ?? null));
foreach (array('r37-success-access-token-canary', 'r37-success-refresh-token-canary') as $canary) {
    $assert(! str_contains($serialized, $canary), 'R45 indeterminate task persistence retained plaintext managed credential material.');
}

$remote_table = $wpdb->prefix . Model_Activator::REMOTE_ASSETS_TABLE;
$assert(0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM {$remote_table}"), 'R45 indeterminate smoke prematurely created a remote-asset row.');
$assert(
    0 === (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE task_type = %s",
            $task_table,
            PeerTube_Upload_Task_Coordinator::TASK_REMOTE_RECONCILE
        )
    ),
    'R45 indeterminate smoke prematurely created a reconciliation task.'
);

$owned_open = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM %i WHERE task_type IN (%s,%s) AND status IN ('queued','processing')",
        $task_table,
        PeerTube_Upload_Task_Coordinator::TASK_UPLOAD_ADVANCE,
        PeerTube_Upload_Task_Coordinator::TASK_REMOTE_RECONCILE
    )
);
$assert(0 === $owned_open, 'R45 indeterminate smoke left an owned task eligible for automatic replay.');

echo "PEERTUBE_TASK_CLI_INDETERMINATE_STATE_ASSERTIONS=PASS\n";
