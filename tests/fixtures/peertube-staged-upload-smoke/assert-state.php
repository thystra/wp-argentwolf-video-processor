<?php
/** Authoritative postconditions for the R43 first executable staged-upload checkpoint. */

declare(strict_types=1);

use ArgentVideo\Backend_Capabilities;
use ArgentVideo\Backend_Adapter_Factory;
use ArgentVideo\Backend_Registry;
use ArgentVideo\Managed_Backend_Secret_Store;
use ArgentVideo\Model_Activator;
use ArgentVideo\PeerTube_Api_Client;
use ArgentVideo\PeerTube_Backend_Adapter;
use ArgentVideo\PeerTube_Http_Client;
use ArgentVideo\PeerTube_Staged_Upload_Api;
use ArgentVideo\PeerTube_Staged_Upload_Operation_Store;
use ArgentVideo\PeerTube_Staged_Upload_Service;
use ArgentVideo\PeerTube_Staged_Upload_State_Machine;
use ArgentVideo\PeerTube_Connection_State_Machine;
use ArgentVideo\Storage;

define('AWVP_ADMIN_SMOKE_EXPECTED_PHASE', PeerTube_Connection_State_Machine::PHASE_COMPLETE);
define('AWVP_ADMIN_SMOKE_EXPECTED_REVISION', 16);
define('AWVP_ADMIN_SMOKE_EXPECTED_DESTINATION', '101');
define('AWVP_ADMIN_SMOKE_EXPECT_COMPLETE', true);
define('AWVP_ADMIN_SMOKE_EXPECT_DESCRIPTOR_STATE', 'active');
define('AWVP_ADMIN_SMOKE_EXPECT_DESCRIPTOR_DESTINATION', '101');

require dirname(__DIR__) . '/peertube-admin-authorization-smoke/assert-state.php';

$descriptor = (new Backend_Registry())->get('r38-admin');
if (! is_array($descriptor) || 'active' !== ($descriptor['state'] ?? null)) {
    throw new RuntimeException('The R43 upload fixture did not retain the exact active PeerTube backend.');
}

$registry = new Backend_Registry();
$secrets = new Managed_Backend_Secret_Store();
$factory = new Backend_Adapter_Factory(new PeerTube_Backend_Adapter($secrets));
if ($registry->eligible('r38-admin', Backend_Capabilities::INGEST_AWVP_STAGING, $factory)) {
    throw new RuntimeException('R43 prematurely advertised AWVP-staged PeerTube ingest capability.');
}
if ($registry->eligible('r38-admin', Backend_Capabilities::PROCESSING_VIDEO, $factory)) {
    throw new RuntimeException('R43 prematurely advertised PeerTube processing authority.');
}

$directory = Storage::root() . '/101/staging';
if (! is_dir($directory) && ! wp_mkdir_p($directory)) {
    throw new RuntimeException('The R43 fixture could not create its managed staged-source directory.');
}
$source = Storage::assert_managed_path($directory . '/r43-source.mp4');
$source_bytes = 'R43-STAGED-BYTES';
if (strlen($source_bytes) !== file_put_contents($source, $source_bytes, LOCK_EX)) {
    throw new RuntimeException('The R43 fixture could not create its exact staged source.');
}

/** @var wpdb $wpdb */
global $wpdb;
$remote_assets_table = $wpdb->prefix . Model_Activator::REMOTE_ASSETS_TABLE;
$remote_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$remote_assets_table}");

$store = new PeerTube_Staged_Upload_Operation_Store();
$service = new PeerTube_Staged_Upload_Service(
    $store,
    $registry,
    $secrets,
    static fn (string $origin): PeerTube_Staged_Upload_Api => new PeerTube_Api_Client(
        new PeerTube_Http_Client($origin)
    )
);
$now = time();
$begin = $service->begin(101, 'r38-admin', $source, 'R43 staged source', 1, $now);
if (
    PeerTube_Staged_Upload_Service::STATUS_ADVANCED !== ($begin['status'] ?? null)
    || PeerTube_Staged_Upload_State_Machine::PHASE_READY !== ($begin['phase'] ?? null)
    || 1 !== ($begin['record_revision'] ?? null)
) {
    throw new RuntimeException('R43 did not create the exact durable staged-upload intent.');
}
$operation_id = (string) $begin['operation_id'];
$session = $service->advance($operation_id, $now + 1);
if (
    PeerTube_Staged_Upload_Service::STATUS_SESSION_CREATED !== ($session['status'] ?? null)
    || 3 !== ($session['record_revision'] ?? null)
) {
    throw new RuntimeException('R43 did not durably establish the reviewed resumable-upload session.');
}
$created = $service->advance($operation_id, $now + 2);
if (
    PeerTube_Staged_Upload_Service::STATUS_REMOTE_CREATED !== ($created['status'] ?? null)
    || PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_CREATED !== ($created['phase'] ?? null)
    || 5 !== ($created['record_revision'] ?? null)
    || 16 !== ($created['confirmed_bytes'] ?? null)
    || '901' !== ($created['remote_identity']['id'] ?? null)
    || '12345678-1234-4abc-9def-1234567890ab' !== ($created['remote_identity']['uuid'] ?? null)
) {
    throw new RuntimeException('R43 did not durably record the exact created PeerTube identity.');
}
$record = $store->get($operation_id);
if (! is_array($record) || $record['source']['sha256'] !== hash('sha256', $source_bytes)) {
    throw new RuntimeException('R43 upload journal lost the immutable staged-source commitment.');
}
if (! is_file($source) || file_get_contents($source) !== $source_bytes) {
    throw new RuntimeException('R43 upload mutation changed or deleted the local staged source.');
}
$remote_after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$remote_assets_table}");
if ($remote_before !== $remote_after) {
    throw new RuntimeException('R43 first executable upload checkpoint prematurely committed a remote-asset row.');
}

$raw = get_option(PeerTube_Staged_Upload_Operation_Store::OPTION, null);
$serialized = serialize($raw);
foreach (array('r37-success-access-token-canary', 'r37-success-refresh-token-canary') as $canary) {
    if (str_contains($serialized, $canary)) {
        throw new RuntimeException('R43 staged-upload journal retained plaintext managed credential material.');
    }
}

if ($registry->eligible('r38-admin', Backend_Capabilities::INGEST_AWVP_STAGING, $factory)) {
    throw new RuntimeException('R43 upload execution changed advertised ingest capability.');
}

echo "PEERTUBE_STAGED_UPLOAD_STATE_ASSERTIONS=PASS\n";

// EOF: tests/fixtures/peertube-staged-upload-smoke/assert-state.php
