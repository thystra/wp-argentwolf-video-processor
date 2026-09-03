<?php
/** Focused dependency-free tests for R43 explicit resumable staged upload execution. */

declare(strict_types=1);

$GLOBALS['awvp_r43_upload_basedir'] = sys_get_temp_dir() . '/awvp-r43-service-' . getmypid();

function wp_upload_dir(): array
{
    return array(
        'basedir' => $GLOBALS['awvp_r43_upload_basedir'],
        'baseurl' => 'https://example.test/wp-content/uploads',
        'error'   => false,
    );
}

function wp_normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

require_once __DIR__ . '/peertube-backend-activation-service.php';
require_once dirname(__DIR__) . '/includes/Storage.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Source_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Upload_Guard.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Upload_State_Machine.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Upload_Operation_Store.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Upload_Api.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Upload_Service.php';

use ArgentVideo\Backend_Registry;
use ArgentVideo\Managed_Backend_Secret_Store;
use ArgentVideo\PeerTube_Staged_Upload_Api;
use ArgentVideo\PeerTube_Staged_Upload_Operation_Store;
use ArgentVideo\PeerTube_Staged_Upload_Service;
use ArgentVideo\PeerTube_Staged_Upload_State_Machine as Machine;
use ArgentVideo\Storage;

final class Awvp_R43_Fake_Api implements PeerTube_Staged_Upload_Api
{
    public int $init_posts = 0;
    public int $chunk_puts = 0;
    public int $probes = 0;
    public string $mode = 'success';
    public int $probe_confirmed = 0;
    /** @var list<array<string,mixed>> */
    public array $calls = array();

    public function __construct(private readonly string $api_origin) {}

    public function origin(): string
    {
        return $this->api_origin;
    }

    public function begin_resumable_upload(
        string $access_token,
        string $destination_id,
        string $name,
        string $filename,
        string $content_type,
        int $total_bytes
    ): array {
        ++$this->init_posts;
        $this->calls[] = array(
            'kind' => 'init',
            'token_sha256' => hash('sha256', $access_token),
            'destination_id' => $destination_id,
            'name' => $name,
            'filename' => $filename,
            'content_type' => $content_type,
            'total_bytes' => $total_bytes,
        );
        if ('init_rate_limited' === $this->mode) {
            return array(
                'ok' => false,
                'data' => null,
                'error' => array(
                    'status' => 'rate_limited',
                    'http_status' => 429,
                    'retry_after' => 30,
                ),
            );
        }
        if ('init_throw' === $this->mode) {
            throw new RuntimeException('Synthetic uncertain upload initialization.');
        }
        return array(
            'ok' => true,
            'data' => array('session_id' => 'r43-session-0001'),
            'error' => null,
        );
    }

    public function upload_resumable_chunk(
        string $access_token,
        string $session_id,
        int $start,
        int $total_bytes,
        string $content_type,
        string $chunk
    ): array {
        ++$this->chunk_puts;
        $this->calls[] = array(
            'kind' => 'chunk',
            'token_sha256' => hash('sha256', $access_token),
            'session_id' => $session_id,
            'start' => $start,
            'total_bytes' => $total_bytes,
            'content_type' => $content_type,
            'chunk_bytes' => strlen($chunk),
            'chunk_sha256' => hash('sha256', $chunk),
        );
        if ('chunk_throw' === $this->mode) {
            throw new RuntimeException('Synthetic uncertain byte-bearing upload PUT.');
        }
        $confirmed = $start + strlen($chunk);
        if ($confirmed < $total_bytes) {
            return array(
                'ok' => true,
                'data' => array('state' => 'incomplete', 'confirmed_bytes' => $confirmed),
                'error' => null,
            );
        }
        return array(
            'ok' => true,
            'data' => array(
                'state' => 'created',
                'remote_identity' => array(
                    'id' => '901',
                    'uuid' => '12345678-1234-4abc-9def-1234567890ab',
                ),
            ),
            'error' => null,
        );
    }

    public function probe_resumable_upload(string $access_token, string $session_id, int $total_bytes): array
    {
        ++$this->probes;
        $this->calls[] = array(
            'kind' => 'probe',
            'token_sha256' => hash('sha256', $access_token),
            'session_id' => $session_id,
            'total_bytes' => $total_bytes,
        );
        if ('probe_created' === $this->mode) {
            return array(
                'ok' => true,
                'data' => array(
                    'state' => 'created',
                    'remote_identity' => array(
                        'id' => '902',
                        'uuid' => '22345678-1234-4abc-9def-1234567890ab',
                    ),
                ),
                'error' => null,
            );
        }
        return array(
            'ok' => true,
            'data' => array('state' => 'incomplete', 'confirmed_bytes' => $this->probe_confirmed),
            'error' => null,
        );
    }
}

/** @return array{backend_id:string,descriptor:array<string,mixed>} */
function awvp_r43_active_backend(int $access_expires_at = 10000): array
{
    $fixture = awvp_activation_ready_fixture($access_expires_at);
    $operation_id = $fixture['operation_id'];
    $activation = awvp_activation_service(4000);
    foreach (array(4000, 4001, 4002, 4003) as $clock) {
        $activation['service']->advance($operation_id, $clock);
    }
    $record = awvp_coordinator_record($operation_id);
    $backend_id = $record['backend_id'];
    $descriptor = (new Backend_Registry())->get($backend_id);
    awvp_coordinator_assert(is_array($descriptor) && 'active' === ($descriptor['state'] ?? null), 'R43 fixture backend did not activate.');
    return array('backend_id' => $backend_id, 'descriptor' => $descriptor);
}

function awvp_r43_source(string $name, string $contents): string
{
    $directory = Storage::root() . '/77/staging';
    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException('Could not create R43 staged source directory.');
    }
    $path = $directory . '/' . $name;
    if (strlen($contents) !== file_put_contents($path, $contents)) {
        throw new RuntimeException('Could not create R43 staged source.');
    }
    return $path;
}

/** @return array{service:PeerTube_Staged_Upload_Service,store:PeerTube_Staged_Upload_Operation_Store} */
function awvp_r43_service(Awvp_R43_Fake_Api $api): array
{
    $store = new PeerTube_Staged_Upload_Operation_Store();
    return array(
        'store' => $store,
        'service' => new PeerTube_Staged_Upload_Service(
            $store,
            new Backend_Registry(),
            new Managed_Backend_Secret_Store(),
            static function (string $origin) use ($api): PeerTube_Staged_Upload_Api {
                awvp_coordinator_assert($origin === $api->origin(), 'R43 API factory received the wrong origin.');
                return $api;
            }
        ),
    );
}

// Happy path: init is separately claimed, each byte-bearing chunk is claimed,
// and final remote identity is journaled without deleting the source.
$backend = awvp_r43_active_backend();
$source_bytes = str_repeat('A', Machine::MAX_CHUNK_BYTES) . 'final';
$source_path = awvp_r43_source('happy.mp4', $source_bytes);
$api = new Awvp_R43_Fake_Api($backend['descriptor']['config']['origin']);
$bundle = awvp_r43_service($api);
$begun = $bundle['service']->begin(77, $backend['backend_id'], $source_path, 'R43 happy upload', 7, 5000);
awvp_coordinator_assert(PeerTube_Staged_Upload_Service::STATUS_ADVANCED === $begun['status'], 'R43 happy upload did not begin.');
awvp_coordinator_assert(Machine::PHASE_READY === $begun['phase'] && 1 === $begun['record_revision'], 'R43 happy upload begin state drifted.');
$operation_id = $begun['operation_id'];

$session = $bundle['service']->advance($operation_id, 5001);
awvp_coordinator_assert(PeerTube_Staged_Upload_Service::STATUS_SESSION_CREATED === $session['status'], 'R43 resumable session was not established.');
awvp_coordinator_assert(Machine::PHASE_READY === $session['phase'] && 3 === $session['record_revision'], 'R43 session revision sequence drifted.');
$chunk1 = $bundle['service']->advance($operation_id, 5002);
awvp_coordinator_assert(PeerTube_Staged_Upload_Service::STATUS_CHUNK_ACCEPTED === $chunk1['status'], 'R43 first chunk was not durably accepted.');
awvp_coordinator_assert(Machine::MAX_CHUNK_BYTES === $chunk1['confirmed_bytes'] && 5 === $chunk1['record_revision'], 'R43 first chunk offset/revision drifted.');
$created = $bundle['service']->advance($operation_id, 5003);
awvp_coordinator_assert(PeerTube_Staged_Upload_Service::STATUS_REMOTE_CREATED === $created['status'], 'R43 final chunk did not establish remote identity.');
awvp_coordinator_assert(strlen($source_bytes) === $created['confirmed_bytes'] && 7 === $created['record_revision'], 'R43 final chunk offset/revision drifted.');
awvp_coordinator_assert(1 === $api->init_posts && 2 === $api->chunk_puts && 0 === $api->probes, 'R43 happy path crossed an unexpected remote-request count.');
awvp_coordinator_assert(is_file($source_path), 'R43 upload executor deleted staged source bytes.');

$stored = $bundle['store']->get($operation_id);
awvp_coordinator_assert(Machine::PHASE_REMOTE_CREATED === ($stored['phase'] ?? null), 'R43 remote-created state did not persist.');
$journal_raw = $GLOBALS['wpdb']->rows[PeerTube_Staged_Upload_Operation_Store::OPTION]['option_value'] ?? '';
foreach (array('access-token-canary-r39', 'refresh-token-canary-r39') as $canary) {
    awvp_coordinator_assert(! str_contains((string) $journal_raw, $canary), 'R43 upload journal leaked managed credential material.');
}
foreach ($api->calls as $call) {
    awvp_coordinator_assert(! str_contains(serialize($call), 'access-token-canary-r39'), 'R43 test call transcript retained raw bearer authority.');
}

// An uncertain byte-bearing PUT is fenced. Repeated advance calls never replay
// it; only a later explicit zero-byte reconciliation can make retry possible.
$backend = awvp_r43_active_backend();
$source_path = awvp_r43_source('uncertain.mp4', '0123456789');
$api = new Awvp_R43_Fake_Api($backend['descriptor']['config']['origin']);
$bundle = awvp_r43_service($api);
$begun = $bundle['service']->begin(77, $backend['backend_id'], $source_path, 'R43 uncertain upload', 7, 6000);
$operation_id = $begun['operation_id'];
$bundle['service']->advance($operation_id, 6001);
$api->mode = 'chunk_throw';
$uncertain = $bundle['service']->advance($operation_id, 6002);
awvp_coordinator_assert(PeerTube_Staged_Upload_Service::STATUS_INDETERMINATE === $uncertain['status'], 'R43 uncertain chunk did not fail closed.');
awvp_coordinator_assert(Machine::PHASE_UPLOAD_INDETERMINATE === $uncertain['phase'] && 1 === $api->chunk_puts, 'R43 uncertain chunk fence drifted.');
$again = $bundle['service']->advance($operation_id, 6003);
awvp_coordinator_assert(PeerTube_Staged_Upload_Service::STATUS_INDETERMINATE === $again['status'] && 1 === $api->chunk_puts, 'R43 silently replayed an uncertain byte-bearing PUT.');
$api->mode = 'success';
$api->probe_confirmed = 0;
$reconciled = $bundle['service']->reconcile($operation_id, 6004);
awvp_coordinator_assert(PeerTube_Staged_Upload_Service::STATUS_ADVANCED === $reconciled['status'], 'R43 zero-byte offset reconciliation did not restore explicit retryability.');
awvp_coordinator_assert(1 === $api->probes && 1 === $api->chunk_puts, 'R43 reconciliation transmitted source bytes.');
$retried = $bundle['service']->advance($operation_id, 6005);
awvp_coordinator_assert(PeerTube_Staged_Upload_Service::STATUS_REMOTE_CREATED === $retried['status'] && 2 === $api->chunk_puts, 'R43 explicit post-reconciliation retry did not finish exactly once.');

// If the zero-byte probe proves that the uncertain final PUT already created
// the video, reconciliation commits identity without replaying source bytes.
$backend = awvp_r43_active_backend();
$source_path = awvp_r43_source('reconcile-created.mp4', 'abcdefghij');
$api = new Awvp_R43_Fake_Api($backend['descriptor']['config']['origin']);
$bundle = awvp_r43_service($api);
$begun = $bundle['service']->begin(77, $backend['backend_id'], $source_path, 'R43 reconcile created', 7, 6500);
$operation_id = $begun['operation_id'];
$bundle['service']->advance($operation_id, 6501);
$api->mode = 'chunk_throw';
$bundle['service']->advance($operation_id, 6502);
$api->mode = 'probe_created';
$reconciled = $bundle['service']->reconcile($operation_id, 6503);
awvp_coordinator_assert(PeerTube_Staged_Upload_Service::STATUS_REMOTE_CREATED === $reconciled['status'], 'R43 remote-found reconciliation did not establish identity.');
awvp_coordinator_assert('902' === ($reconciled['remote_identity']['id'] ?? ''), 'R43 reconciliation persisted the wrong remote identity.');
awvp_coordinator_assert(1 === $api->chunk_puts && 1 === $api->probes, 'R43 remote-found reconciliation replayed source bytes.');

// A definite initialization 429 is the only current remote retry-safe response.
// It creates a durable wait; early/elapsed waits themselves perform no I/O.
$backend = awvp_r43_active_backend();
$source_path = awvp_r43_source('rate-limit.mp4', 'rate-limited');
$api = new Awvp_R43_Fake_Api($backend['descriptor']['config']['origin']);
$api->mode = 'init_rate_limited';
$bundle = awvp_r43_service($api);
$begun = $bundle['service']->begin(77, $backend['backend_id'], $source_path, 'R43 rate limit', 7, 7000);
$operation_id = $begun['operation_id'];
$wait = $bundle['service']->advance($operation_id, 7001);
awvp_coordinator_assert(PeerTube_Staged_Upload_Service::STATUS_WAIT === $wait['status'] && Machine::PHASE_RETRY_WAIT === $wait['phase'], 'R43 initialization 429 was not durably bounded.');
$early = $bundle['service']->advance($operation_id, 7020);
awvp_coordinator_assert(PeerTube_Staged_Upload_Service::STATUS_WAIT === $early['status'] && 1 === $api->init_posts, 'R43 ignored a durable upload wait.');
$resumed = $bundle['service']->advance($operation_id, 7031);
awvp_coordinator_assert(PeerTube_Staged_Upload_Service::STATUS_ADVANCED === $resumed['status'] && Machine::PHASE_READY === $resumed['phase'], 'R43 elapsed wait did not return to ready.');
awvp_coordinator_assert(1 === $api->init_posts, 'R43 combined wait expiry with fresh remote I/O.');
$api->mode = 'success';
$session = $bundle['service']->advance($operation_id, 7032);
awvp_coordinator_assert(PeerTube_Staged_Upload_Service::STATUS_SESSION_CREATED === $session['status'] && 2 === $api->init_posts, 'R43 explicit retry after elapsed wait did not establish a session.');

// Near-expiry credentials stop before the durable upload claim/remote boundary.
$backend = awvp_r43_active_backend(5050);
$source_path = awvp_r43_source('refresh-required.mp4', 'refresh-required');
$api = new Awvp_R43_Fake_Api($backend['descriptor']['config']['origin']);
$bundle = awvp_r43_service($api);
$begun = $bundle['service']->begin(77, $backend['backend_id'], $source_path, 'R43 refresh required', 7, 5000);
$operation_id = $begun['operation_id'];
$refresh = $bundle['service']->advance($operation_id, 5000);
awvp_coordinator_assert(PeerTube_Staged_Upload_Service::STATUS_REFRESH_REQUIRED === $refresh['status'], 'R43 near-expiry credential did not stop for refresh.');
awvp_coordinator_assert(1 === $refresh['record_revision'] && 0 === $api->init_posts && 0 === $api->chunk_puts, 'R43 refresh-required preflight crossed the mutation boundary.');

// Clean temporary staged sources without exercising plugin cleanup behavior.
$root = Storage::root();
if (is_dir($root)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($root);
    @rmdir(dirname($root));
}

echo "PeerTube staged upload service tests passed.\n";
