<?php
/** Authoritative postconditions for R44 relational commit/read reconciliation. */
declare(strict_types=1);

use ArgentVideo\Backend_Capabilities;
use ArgentVideo\Backend_Adapter_Factory;
use ArgentVideo\Backend_Registry;
use ArgentVideo\Managed_Backend_Secret_Store;
use ArgentVideo\Model_Activator;
use ArgentVideo\PeerTube_Api_Client;
use ArgentVideo\PeerTube_Backend_Adapter;
use ArgentVideo\PeerTube_Http_Client;
use ArgentVideo\PeerTube_Remote_Asset_Reconciliation_Service;
use ArgentVideo\PeerTube_Remote_Reconciliation_Api;
use ArgentVideo\PeerTube_Staged_Upload_State_Machine;
use ArgentVideo\Remote_Asset_Repository;

// Establish the exact R43 remote_created checkpoint first.
require dirname(__DIR__) . '/peertube-staged-upload-smoke/assert-state.php';

$asset_repository = new Remote_Asset_Repository();
$reconciliation = new PeerTube_Remote_Asset_Reconciliation_Service(
    $store,
    $asset_repository,
    $registry,
    $secrets,
    static fn (string $origin): PeerTube_Remote_Reconciliation_Api => new PeerTube_Api_Client(new PeerTube_Http_Client($origin))
);

$committed = $reconciliation->advance($operation_id, $now + 3);
if (
    PeerTube_Remote_Asset_Reconciliation_Service::STATUS_REMOTE_COMMITTED !== ($committed['status'] ?? null)
    || PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_COMMITTED !== ($committed['phase'] ?? null)
    || 6 !== ($committed['record_revision'] ?? null)
    || ($committed['remote_asset_id'] ?? 0) < 1
) {
    throw new RuntimeException('R44 did not durably attach the exact relational remote-asset row.');
}
$remote_asset_id = (int) $committed['remote_asset_id'];

$processing = $reconciliation->advance($operation_id, $now + 4);
if (
    PeerTube_Remote_Asset_Reconciliation_Service::STATUS_PROCESSING !== ($processing['status'] ?? null)
    || PeerTube_Staged_Upload_State_Machine::PHASE_PROCESSING !== ($processing['phase'] ?? null)
    || 7 !== ($processing['record_revision'] ?? null)
    || 30 !== ($processing['retry_after'] ?? null)
) {
    throw new RuntimeException('R44 did not persist the positive PeerTube processing observation.');
}
$early = $reconciliation->advance($operation_id, $now + 20);
if (PeerTube_Remote_Asset_Reconciliation_Service::STATUS_WAIT !== ($early['status'] ?? null)) {
    throw new RuntimeException('R44 ignored its durable processing recheck wait.');
}
$ready = $reconciliation->advance($operation_id, $now + 35);
if (
    PeerTube_Remote_Asset_Reconciliation_Service::STATUS_READY_VERIFIED !== ($ready['status'] ?? null)
    || PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED !== ($ready['phase'] ?? null)
    || 8 !== ($ready['record_revision'] ?? null)
) {
    throw new RuntimeException('R44 did not independently verify remote readiness.');
}

$row = $asset_repository->find($remote_asset_id);
if (! is_array($row)
    || 101 !== (int) ($row['video_post_id'] ?? 0)
    || 'r38-admin' !== ($row['backend_id'] ?? null)
    || '101' !== ($row['channel_id'] ?? null)
    || '12345678-1234-4abc-9def-1234567890ab' !== ($row['remote_id'] ?? null)
    || 'secondary' !== ($row['role'] ?? null)
    || 'ready' !== ($row['state'] ?? null)
    || 'private' !== ($row['desired_privacy'] ?? null)
    || 'private' !== ($row['actual_privacy'] ?? null)
    || '1:published' !== ($row['remote_processing_state'] ?? null)
    || 'http://peertube.test:9000/videos/embed/12345678-1234-4abc-9def-1234567890ab' !== ($row['embed_url'] ?? null)
    || empty($row['last_verified_at'])
) {
    throw new RuntimeException('R44 relational remote-asset authority differed from the exact verified private PeerTube identity.');
}

/** @var wpdb $wpdb */
global $wpdb;
$table = $wpdb->prefix . Model_Activator::REMOTE_ASSETS_TABLE;
if (1 !== (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}")) {
    throw new RuntimeException('R44 relational commit was not exactly-once.');
}
if (! is_file($source) || file_get_contents($source) !== $source_bytes) {
    throw new RuntimeException('R44 remote reconciliation changed or deleted staged source bytes.');
}

$factory = new Backend_Adapter_Factory(new PeerTube_Backend_Adapter(new Managed_Backend_Secret_Store()));
foreach (array(Backend_Capabilities::INGEST_AWVP_STAGING, Backend_Capabilities::INGEST_SERVER_PUSH, Backend_Capabilities::PROCESSING_VIDEO) as $capability) {
    if ((new Backend_Registry())->eligible('r38-admin', $capability, $factory)) {
        throw new RuntimeException('R44 prematurely enabled a production PeerTube ingest/processing capability.');
    }
}

$raw = serialize(get_option(\ArgentVideo\PeerTube_Staged_Upload_Operation_Store::OPTION, null));
foreach (array('r37-success-access-token-canary', 'r37-success-refresh-token-canary', 'must-not-be-persisted') as $canary) {
    if (str_contains($raw, $canary)) {
        throw new RuntimeException('R44 operation journal retained a credential/raw remote-response canary.');
    }
}

echo "PEERTUBE_REMOTE_ASSET_RECONCILIATION_STATE_ASSERTIONS=PASS\n";
