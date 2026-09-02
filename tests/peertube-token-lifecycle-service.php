<?php
/** Focused dependency-free tests for R41 PeerTube token lifecycle. */

declare(strict_types=1);

require_once __DIR__ . '/peertube-backend-activation-service.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Token_Lifecycle_Api.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Token_Lifecycle_Store.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Token_Lifecycle_Service.php';

use ArgentVideo\Backend_Registry;
use ArgentVideo\Managed_Backend_Secret_Store;
use ArgentVideo\PeerTube_Token_Lifecycle_Api;
use ArgentVideo\PeerTube_Token_Lifecycle_Service;
use ArgentVideo\PeerTube_Token_Lifecycle_Store;

final class Awvp_R41_Fake_Api implements PeerTube_Token_Lifecycle_Api
{
    public int $oauth_reads = 0;
    public int $refresh_posts = 0;
    public int $revoke_posts = 0;
    public string $mode = 'success';
    public string $expected_refresh = 'refresh-token-canary-r39';
    public string $expected_revoke = 'access-token-v2';

    public function __construct(private string $origin) {}
    public function origin(): string { return $this->origin; }
    public function local_oauth_client(): array
    {
        ++$this->oauth_reads;
        if ('oauth_rate_limited' === $this->mode) {
            return array(
                'ok' => false,
                'data' => null,
                'error' => array('status' => 'rate_limited', 'http_status' => 429, 'retry_after' => 30),
            );
        }
        return array('ok' => true, 'data' => array('client_id' => 'client', 'client_secret' => 'client-secret'), 'error' => null);
    }
    public function refresh_token(array $oauth_client, string $refresh_token, int $received_at): array
    {
        ++$this->refresh_posts;
        awvp_coordinator_assert('client' === $oauth_client['client_id'], 'R41 fake received wrong OAuth client.');
        awvp_coordinator_assert($this->expected_refresh === $refresh_token, 'R41 fake received wrong refresh token.');
        if ('refresh_throw' === $this->mode) {
            throw new RuntimeException('Synthetic uncertain refresh transport.');
        }
        return array(
            'ok' => true,
            'data' => array(
                'access_token' => 'access-token-v2',
                'refresh_token' => 'refresh-token-v2',
                'access_expires_at' => $received_at + 3600,
                'refresh_expires_at' => $received_at + 1209600,
            ),
            'error' => null,
        );
    }
    public function revoke_token(string $access_token): array
    {
        ++$this->revoke_posts;
        awvp_coordinator_assert($this->expected_revoke === $access_token, 'R41 revoke used the wrong access token.');
        if ('revoke_throw' === $this->mode) {
            throw new RuntimeException('Synthetic uncertain revoke transport.');
        }
        return array('ok' => true, 'data' => array('revoked' => true), 'error' => null);
    }
}

$fixture = awvp_activation_ready_fixture(10000);
$operation_id = $fixture['operation_id'];
$activation = awvp_activation_service(4000);
$activation['service']->advance($operation_id, 4000);
$activation['service']->advance($operation_id, 4001);
$activation['service']->advance($operation_id, 4002);
$activation['service']->advance($operation_id, 4003);
$record = awvp_coordinator_record($operation_id);
$backend_id = $record['backend_id'];
$descriptor = (new Backend_Registry())->get($backend_id);
awvp_coordinator_assert('active' === ($descriptor['state'] ?? null), 'R41 fixture backend did not activate.');

$api = new Awvp_R41_Fake_Api($record['origin']);
$service = new PeerTube_Token_Lifecycle_Service(
    new PeerTube_Token_Lifecycle_Store(),
    new Managed_Backend_Secret_Store(),
    new Backend_Registry(),
    static fn (string $origin): PeerTube_Token_Lifecycle_Api => $api
);

$init = $service->refresh($backend_id, 5000);
awvp_coordinator_assert('refresh_ready' === $init['phase'], 'R41 refresh did not journal ready state.');
$sent = $service->refresh($backend_id, 5001);
awvp_coordinator_assert('refresh_in_flight' === $sent['phase'], 'R41 refresh did not retain in-flight claim after token replacement.');
awvp_coordinator_assert(1 === $api->oauth_reads && 1 === $api->refresh_posts, 'R41 refresh did not make exactly one reviewed OAuth/refresh request.');
$secret = (new Managed_Backend_Secret_Store())->read($descriptor['secret_ref'], $backend_id);
awvp_coordinator_assert(2 === ($secret['generation'] ?? 0), 'R41 refresh did not replace exact secret generation.');
awvp_coordinator_assert('access-token-v2' === ($secret['access_token'] ?? ''), 'R41 refreshed access token not stored.');
$done = $service->refresh($backend_id, 5002);
awvp_coordinator_assert(PeerTube_Token_Lifecycle_Service::STATUS_COMPLETE === $done['status'], 'R41 refresh did not independently close.');
awvp_coordinator_assert(1 === $api->refresh_posts, 'R41 refresh close replayed remote mutation.');

$d0 = $service->disconnect($backend_id, 6000);
awvp_coordinator_assert('disconnect_ready' === $d0['phase'], 'R41 disconnect did not initialize.');
$d1 = $service->disconnect($backend_id, 6001);
awvp_coordinator_assert('disconnect_revoked' === $d1['phase'], 'R41 disconnect did not record definite revoke.');
awvp_coordinator_assert(1 === $api->revoke_posts, 'R41 disconnect did not perform exactly one revoke.');
$d2 = $service->disconnect($backend_id, 6002);
awvp_coordinator_assert('disconnect_retire_planned' === $d2['phase'], 'R41 disconnect did not journal retirement plan.');
$d3 = $service->disconnect($backend_id, 6003);
awvp_coordinator_assert('disconnect_retire_planned' === $d3['phase'], 'R41 registry write should remain a distinct persistence boundary.');
$d4 = $service->disconnect($backend_id, 6004);
awvp_coordinator_assert('disconnect_retired' === $d4['phase'], 'R41 disconnect did not confirm retired descriptor.');
$d5 = $service->disconnect($backend_id, 6005);
awvp_coordinator_assert(PeerTube_Token_Lifecycle_Service::STATUS_COMPLETE === $d5['status'], 'R41 disconnect did not complete.');
awvp_coordinator_assert(1 === $api->revoke_posts, 'R41 disconnect replayed revoke.');
$descriptor = (new Backend_Registry())->get($backend_id);
awvp_coordinator_assert('retired' === ($descriptor['state'] ?? null), 'R41 backend descriptor was not retired.');
awvp_coordinator_assert(null === (new Managed_Backend_Secret_Store())->read($descriptor['secret_ref'], $backend_id), 'R41 managed secret remained after disconnect.');

$journal = (new PeerTube_Token_Lifecycle_Store())->read($backend_id);
awvp_coordinator_assert('disconnect_complete' === ($journal['phase'] ?? ''), 'R41 lifecycle journal did not close disconnect.');
awvp_coordinator_assert(9 === ($journal['revision'] ?? 0), 'R41 lifecycle crossed the reviewed happy-path revision count.');
$serialized = serialize($journal);
foreach (array('access-token-v2', 'refresh-token-v2', 'client-secret') as $canary) {
    awvp_coordinator_assert(! str_contains($serialized, $canary), 'R41 lifecycle journal leaked secret material.');
}



// A read-only OAuth-client 429 is durably bounded without claiming or invoking
// the token mutation. Expiry of the wait only returns to refresh_ready.
$fixture = awvp_activation_ready_fixture(10000);
$operation_id = $fixture['operation_id'];
$activation = awvp_activation_service(4000);
foreach (array(4000, 4001, 4002, 4003) as $clock) {
    $activation['service']->advance($operation_id, $clock);
}
$backend_id = awvp_coordinator_record($operation_id)['backend_id'];
$api = new Awvp_R41_Fake_Api('https://example.test');
$api->mode = 'oauth_rate_limited';
$service = new PeerTube_Token_Lifecycle_Service(
    new PeerTube_Token_Lifecycle_Store(),
    new Managed_Backend_Secret_Store(),
    new Backend_Registry(),
    static fn (string $origin): PeerTube_Token_Lifecycle_Api => $api
);
$service->refresh($backend_id, 7000);
$wait = $service->refresh($backend_id, 7001);
awvp_coordinator_assert('refresh_wait' === $wait['phase'], 'R41 did not journal the OAuth preflight rate limit.');
awvp_coordinator_assert(1 === $api->oauth_reads && 0 === $api->refresh_posts, 'R41 rate-limit preflight crossed the token mutation boundary.');
$still_waiting = $service->refresh($backend_id, 7020);
awvp_coordinator_assert(PeerTube_Token_Lifecycle_Service::STATUS_WAIT === $still_waiting['status'], 'R41 ignored a durable refresh wait.');
$resumed = $service->refresh($backend_id, 7031);
awvp_coordinator_assert('refresh_ready' === $resumed['phase'], 'R41 did not explicitly resume an elapsed refresh wait.');
awvp_coordinator_assert(1 === $api->oauth_reads && 0 === $api->refresh_posts, 'R41 combined wait expiry with fresh remote I/O.');

// If transport becomes uncertain after the durable refresh claim, a subsequent
// explicit request never replays the token POST with the old generation.
$fixture = awvp_activation_ready_fixture(10000);
$operation_id = $fixture['operation_id'];
$activation = awvp_activation_service(4000);
foreach (array(4000, 4001, 4002, 4003) as $clock) {
    $activation['service']->advance($operation_id, $clock);
}
$backend_id = awvp_coordinator_record($operation_id)['backend_id'];
$api = new Awvp_R41_Fake_Api('https://example.test');
$api->mode = 'refresh_throw';
$service = new PeerTube_Token_Lifecycle_Service(
    new PeerTube_Token_Lifecycle_Store(),
    new Managed_Backend_Secret_Store(),
    new Backend_Registry(),
    static fn (string $origin): PeerTube_Token_Lifecycle_Api => $api
);
$service->refresh($backend_id, 8000);
$uncertain = $service->refresh($backend_id, 8001);
awvp_coordinator_assert(PeerTube_Token_Lifecycle_Service::STATUS_INDETERMINATE === $uncertain['status'], 'R41 did not fail closed after uncertain refresh transport.');
awvp_coordinator_assert(1 === $api->refresh_posts, 'R41 uncertain refresh fixture did not cross exactly one remote mutation.');
$uncertain = $service->refresh($backend_id, 8002);
awvp_coordinator_assert('refresh_indeterminate' === $uncertain['phase'], 'R41 did not durably classify the unresolved refresh claim.');
awvp_coordinator_assert(1 === $api->refresh_posts, 'R41 replayed an uncertain refresh token POST.');

// An uncertain revoke is likewise never replayed. A later explicit disconnect
// can still retire local authority and remove the credential without claiming
// that remote revocation was proven.
$fixture = awvp_activation_ready_fixture(10000);
$operation_id = $fixture['operation_id'];
$activation = awvp_activation_service(4000);
foreach (array(4000, 4001, 4002, 4003) as $clock) {
    $activation['service']->advance($operation_id, $clock);
}
$record = awvp_coordinator_record($operation_id);
$backend_id = $record['backend_id'];
$api = new Awvp_R41_Fake_Api($record['origin']);
$api->mode = 'revoke_throw';
$api->expected_revoke = 'access-token-canary-r39';
$service = new PeerTube_Token_Lifecycle_Service(
    new PeerTube_Token_Lifecycle_Store(),
    new Managed_Backend_Secret_Store(),
    new Backend_Registry(),
    static fn (string $origin): PeerTube_Token_Lifecycle_Api => $api
);
$service->disconnect($backend_id, 9000);
$uncertain = $service->disconnect($backend_id, 9001);
awvp_coordinator_assert(PeerTube_Token_Lifecycle_Service::STATUS_INDETERMINATE === $uncertain['status'], 'R41 did not fail closed after uncertain revoke transport.');
awvp_coordinator_assert(1 === $api->revoke_posts, 'R41 uncertain revoke fixture did not cross exactly one remote mutation.');
$uncertain = $service->disconnect($backend_id, 9002);
awvp_coordinator_assert('disconnect_indeterminate' === $uncertain['phase'], 'R41 did not durably classify the unresolved revoke claim.');
awvp_coordinator_assert(1 === $api->revoke_posts, 'R41 replayed an uncertain revoke POST.');
$service->disconnect($backend_id, 9003); // plan retirement
$service->disconnect($backend_id, 9004); // apply registry CAS
$service->disconnect($backend_id, 9005); // confirm retirement
$done = $service->disconnect($backend_id, 9006); // delete secret and complete
awvp_coordinator_assert(PeerTube_Token_Lifecycle_Service::STATUS_COMPLETE === $done['status'], 'R41 could not explicitly retire local authority after uncertain revoke.');
awvp_coordinator_assert(1 === $api->revoke_posts, 'R41 replayed revoke while retiring local authority.');

echo "PeerTube token lifecycle tests passed.\n";
