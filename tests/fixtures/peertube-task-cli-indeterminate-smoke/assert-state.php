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
    $assert($registry->eligible('r38-admin', $capability, $factory), 'R45 indeterminate smoke lost an R45.6-qualified PeerTube capability.');
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
$assert(is_array($tasks) && 2 === count($tasks), 'R45 indeterminate smoke did not retain exactly the upload task plus one failure-notification task.');
$upload_task = null;
$notification_task = null;
foreach ($tasks as $candidate) {
    if (PeerTube_Upload_Task_Coordinator::TASK_UPLOAD_ADVANCE === ($candidate['task_type'] ?? null)) {
        $upload_task = $candidate;
    } elseif (PeerTube_Upload_Task_Coordinator::TASK_FAILURE_NOTIFY === ($candidate['task_type'] ?? null)) {
        $notification_task = $candidate;
    }
}
$assert(is_array($upload_task), 'R45 indeterminate upload task is missing.');
$assert(
    Task_Repository::STATUS_FAILED === ($upload_task['status'] ?? null)
        && 2 === (int) ($upload_task['attempts'] ?? 0)
        && null === ($upload_task['lock_token'] ?? null)
        && null === ($upload_task['locked_at'] ?? null)
        && str_contains((string) ($upload_task['error_message'] ?? ''), 'explicit intervention'),
    'R45 indeterminate upload task was not terminally held after exactly two one-shot claims.'
);
$assert(is_array($notification_task), 'R45 indeterminate failure did not enqueue its durable notification task.');
$assert(
    Task_Repository::STATUS_COMPLETE === ($notification_task['status'] ?? null)
        && 1 === (int) ($notification_task['attempts'] ?? 0)
        && null === ($notification_task['lock_token'] ?? null)
        && null === ($notification_task['locked_at'] ?? null)
        && null !== ($notification_task['completed_at'] ?? null)
        && null === ($notification_task['error_message'] ?? null),
    'R45 indeterminate failure notification was not durably delivered exactly once by detached drain execution.'
);
$notification_payload = json_decode((string) ($notification_task['payload_json'] ?? ''), true);
$assert(
    is_array($notification_payload)
        && 1 === ($notification_payload['version'] ?? null)
        && ($record['operation_id'] ?? null) === ($notification_payload['operation_id'] ?? null)
        && 'upload_indeterminate' === ($notification_payload['failure']['state'] ?? null)
        && 'peertube.upload.indeterminate' === ($notification_payload['failure']['awvp_error_code'] ?? null),
    'R45 indeterminate notification payload did not preserve the bounded failed-state snapshot.'
);
$captured_mail = get_option('awvp_r45_failure_mail_capture', null);
$assert(is_array($captured_mail), 'R45 indeterminate smoke did not capture the durable failure email.');
$assert('awvp@example.invalid' === ($captured_mail['to'] ?? null), 'R45 failure email did not target the initiating WordPress user.');
$assert(
    is_string($captured_mail['subject'] ?? null)
        && str_contains($captured_mail['subject'], 'PeerTube upload requires attention')
        && str_contains($captured_mail['subject'], 'R45 one-shot CLI smoke'),
    'R45 failure email subject did not identify the failed video.'
);
$mail_body = is_string($captured_mail['message'] ?? null) ? $captured_mail['message'] : '';
foreach (array(
    'Video: R45 one-shot CLI smoke (#' . (int) $record['video_post_id'] . ')',
    'Upload operation: ' . (string) $record['operation_id'],
    'PeerTube backend: r38-admin (http://peertube.test:9000)',
    'State: upload_indeterminate',
    'AWVP error code: peertube.upload.indeterminate',
    'Last upload request: chunk at byte 0 for 16 bytes',
    'Service status: indeterminate',
    'Error classification:',
    'Transport/API code:',
    'AWVP PeerTube settings:',
) as $expected_mail_fragment) {
    $assert(str_contains($mail_body, $expected_mail_fragment), 'R45 failure email omitted bounded diagnostic context: ' . $expected_mail_fragment);
}
$assert(
    str_contains($mail_body, 'network transport boundary') || str_contains($mail_body, 'definitive response'),
    'R45 failure email did not include its controlled network/transport diagnostic detail.'
);

$serialized = serialize(array(
    $upload_task['payload_json'] ?? null,
    $upload_task['error_message'] ?? null,
    $notification_task['payload_json'] ?? null,
    $notification_task['error_message'] ?? null,
    $captured_mail,
));
foreach (array(
    'r37-success-access-token-canary',
    'r37-success-refresh-token-canary',
    '/var/www/html',
    '/wp-content/uploads/',
) as $canary) {
    $assert(! str_contains($serialized, $canary), 'R45 indeterminate notification persistence/mail retained forbidden credential or filesystem material.');
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
