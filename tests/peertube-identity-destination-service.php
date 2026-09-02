<?php
/**
 * Focused dependency-free tests for identity/destination orchestration.
 *
 * Run once without AWVP_ATOMIC_MODERN_AUTOLOAD and once with it set to 1.
 */

declare(strict_types=1);

require_once __DIR__ . '/peertube-connection-coordinator.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Identity_Destination_Api.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Identity_Destination_Service.php';

use ArgentVideo\Atomic_Option_Plan_Result;
use ArgentVideo\Atomic_Option_Result;
use ArgentVideo\Managed_Backend_Secret_Store;
use ArgentVideo\PeerTube_Connection_Operation_Store as Operation_Store;
use ArgentVideo\PeerTube_Connection_State_Machine as Machine;
use ArgentVideo\PeerTube_Identity_Destination_Api;
use ArgentVideo\PeerTube_Identity_Destination_Service as Identity_Service;

final class Awvp_Identity_Fake_Api implements PeerTube_Identity_Destination_Api
{
    /** @var list<array<string, mixed>|Throwable> */
    private array $results;

    /** @var list<string> */
    public array $received_tokens = array();

    private ?Closure $observer;

    /** @param list<array<string, mixed>|Throwable> $results */
    public function __construct(
        private readonly string $api_origin,
        array $results,
        ?callable $observer = null
    ) {
        $this->results = $results;
        $this->observer = null === $observer ? null : Closure::fromCallable($observer);
    }

    public function origin(): string
    {
        return $this->api_origin;
    }

    public function owned_channels(string $access_token): array
    {
        $this->received_tokens[] = $access_token;
        if (null !== $this->observer) {
            ($this->observer)();
        }

        $result = array_shift($this->results);
        awvp_coordinator_assert(null !== $result, 'Identity API result queue was exhausted.');
        if ($result instanceof Throwable) {
            throw $result;
        }
        return $result;
    }

    public function assert_consumed(string $message): void
    {
        awvp_coordinator_assert(array() === $this->results, $message . ': API result queue was not consumed.');
    }
}

final class Awvp_Identity_Fake_Factory
{
    public int $calls = 0;

    public function __construct(
        private readonly PeerTube_Identity_Destination_Api $api,
        private readonly string $expected_origin = 'https://video.example.com'
    ) {
    }

    public function __invoke(string $origin): PeerTube_Identity_Destination_Api
    {
        $this->calls++;
        awvp_coordinator_assert($this->expected_origin === $origin, 'Identity service requested the wrong origin.');
        return $this->api;
    }
}

/** @return array<string, string> */
function awvp_identity_projection(): array
{
    return array(
        'user_id'      => '17',
        'username'     => 'awvp_service',
        'account_id'   => '23',
        'account_name' => 'awvp_service',
    );
}

/** @return list<array<string, string>> */
function awvp_identity_channels(): array
{
    return array(
        array('id' => '41', 'name' => 'primary', 'display_name' => 'Primary Channel', 'authority' => 'owned'),
        array('id' => '42', 'name' => 'secondary', 'display_name' => 'Secondary Channel', 'authority' => 'owned'),
    );
}

/** @param list<array<string, string>>|null $channels */
function awvp_identity_success(?array $channels = null, ?array $identity = null): array
{
    return array(
        'ok'    => true,
        'data'  => array(
            'identity' => $identity ?? awvp_identity_projection(),
            'channels' => $channels ?? awvp_identity_channels(),
        ),
        'error' => null,
    );
}

function awvp_identity_error(string $status, int $http_status, int $retry_after = 0): array
{
    return array(
        'ok'    => false,
        'data'  => null,
        'error' => array(
            'status'      => $status,
            'http_status' => $http_status,
            'retry_after' => $retry_after,
            'detail'      => 'remote-detail-canary-r39',
        ),
    );
}

/** @param array<string, mixed> $record @param array<string, mixed> $payload */
function awvp_identity_apply(array $record, string $event, array $payload, int $now): array
{
    $result = (new Operation_Store())->apply_event(
        $record['operation_id'],
        $record['record_revision'],
        $event,
        $payload,
        $now
    );
    awvp_coordinator_assert(
        Atomic_Option_Result::APPLIED === $result->status()
            && Atomic_Option_Result::MUTATION_APPLIED === $result->mutation(),
        'Identity fixture event did not apply exactly: ' . $event
    );
    return awvp_coordinator_record($record['operation_id']);
}

/**
 * @return array{database:Awvp_Coordinator_Fake_Wpdb,operation_id:string,record:array<string,mixed>,secret:array<string,mixed>}
 */
function awvp_identity_secret_fixture(int $access_expires_at = 10000): array
{
    $database = awvp_coordinator_reset();
    $path = awvp_coordinator_drive(7);
    $record = awvp_coordinator_record($path['operation_id']);
    $record = awvp_identity_apply(
        $record,
        Machine::EVENT_BEGIN_GRANT,
        array('attempt_capability' => str_repeat('a', 64)),
        2000
    );

    $secret = array(
        'access_token'       => 'access-token-canary-r39',
        'refresh_token'      => 'refresh-token-canary-r39',
        'access_expires_at'  => $access_expires_at,
        'refresh_expires_at' => 20000,
    );
    $secrets = new Managed_Backend_Secret_Store();
    $prepared = $secrets->prepare_commit_reserved(
        $record['secret_ref'],
        $record['backend_id'],
        $record['provisioning_id'],
        $secret,
        'mutation_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
    );
    $plan = $prepared->plan();
    awvp_coordinator_assert(
        Atomic_Option_Plan_Result::READY === $prepared->status() && null !== $plan,
        'Identity fixture could not prepare the encrypted token commit.'
    );
    $record = awvp_identity_apply(
        $record,
        Machine::EVENT_PLAN_SECRET_STORAGE,
        $plan->evidence(),
        2001
    );
    $commit = $secrets->apply_commit_plan(
        $record['secret_ref'],
        $record['backend_id'],
        $record['provisioning_id'],
        $secret,
        $plan
    );
    awvp_coordinator_assert(
        Atomic_Option_Result::APPLIED === $commit->status()
            && Atomic_Option_Result::MUTATION_APPLIED === $commit->mutation(),
        'Identity fixture could not commit the encrypted token.'
    );
    $record = awvp_identity_apply($record, Machine::EVENT_CONFIRM_SECRET_STORED, array(), 2002);
    awvp_coordinator_assert(
        Machine::PHASE_SECRET_STORED === $record['phase']
            && 1 === $record['secret_generation'],
        'Identity fixture did not reach the exact secret-stored phase.'
    );
    awvp_coordinator_clear_activity();

    return array(
        'database'     => $database,
        'operation_id' => $record['operation_id'],
        'record'       => $record,
        'secret'       => $secret,
    );
}

/** @param list<array<string, mixed>|Throwable> $results */
function awvp_identity_service(
    array $results,
    ?callable $observer = null,
    string $api_origin = 'https://video.example.com',
    ?callable $clock = null
): array {
    $api = new Awvp_Identity_Fake_Api($api_origin, $results, $observer);
    $factory = new Awvp_Identity_Fake_Factory($api);
    return array(
        'service' => new Identity_Service(
            null,
            null,
            null,
            $factory,
            $clock ?? static fn (int $minimum): int => $minimum
        ),
        'api'     => $api,
        'factory' => $factory,
    );
}

/** @param array<string, mixed> $projection */
function awvp_identity_assert_projection(
    array $projection,
    string $status,
    string $mutation,
    string $phase,
    int $revision,
    string $message
): void {
    awvp_coordinator_assert(
        array(
            'status',
            'mutation',
            'operation_id',
            'backend_id',
            'phase',
            'record_revision',
            'retry_after',
        ) === array_keys($projection),
        $message . ': bounded projection shape changed.'
    );
    awvp_coordinator_assert($status === $projection['status'], $message . ': unexpected status.');
    awvp_coordinator_assert($mutation === $projection['mutation'], $message . ': unexpected mutation.');
    awvp_coordinator_assert($phase === $projection['phase'], $message . ': unexpected phase.');
    awvp_coordinator_assert($revision === $projection['record_revision'], $message . ': unexpected revision.');
    awvp_coordinator_assert(strlen(serialize($projection)) < 1024, $message . ': projection exceeded its bound.');
}

/** @param array<string, mixed> $projection */
function awvp_identity_assert_no_canaries(array $projection, string $message): void
{
    $serialized = serialize($projection);
    foreach (array(
        'access-token-canary-r39',
        'refresh-token-canary-r39',
        'remote-detail-canary-r39',
    ) as $canary) {
        awvp_coordinator_assert(! str_contains($serialized, $canary), $message . ': exposed ' . $canary);
    }
}

// Happy path: intent is journaled separately, one authenticated discovery is
// re-proved, the chooser is read-only, and selection requires a second remote
// discovery followed by another explicit verification.
$fixture = awvp_identity_secret_fixture();
$operation_id = $fixture['operation_id'];
$api_bundle = awvp_identity_service(
    array(
        awvp_identity_success(),
        awvp_identity_success(),
        awvp_identity_success(),
        awvp_identity_success(),
    )
);
$begin = $api_bundle['service']->advance($operation_id, 3000);
awvp_identity_assert_projection(
    $begin,
    Identity_Service::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_VERIFICATION_IN_FLIGHT,
    9,
    'Verification intent'
);
awvp_coordinator_assert(0 === $api_bundle['factory']->calls, 'Verification intent unexpectedly contacted PeerTube.');

awvp_coordinator_clear_activity();
$verified = $api_bundle['service']->advance($operation_id, 3001);
awvp_identity_assert_projection(
    $verified,
    Identity_Service::STATUS_AWAITING_DESTINATION,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_AWAITING_DESTINATION,
    10,
    'Initial identity verification'
);
awvp_coordinator_assert(
    array(Operation_Store::OPTION) === awvp_coordinator_mutation_targets(),
    'Identity verification crossed an unexpected persistence boundary.'
);
$verified_record = awvp_coordinator_record($operation_id);
awvp_coordinator_assert(
    awvp_identity_projection() === $verified_record['verified_identity']
        && '' === $verified_record['selected_destination']
        && 1 === $verified_record['verified_secret_generation'],
    'Initial verification did not retain only the exact bounded identity.'
);
awvp_coordinator_assert(
    ! str_contains(serialize($verified_record), 'Primary Channel')
        && ! str_contains(serialize($verified_record), 'Secondary Channel'),
    'Ephemeral destination observations entered the durable journal.'
);
awvp_identity_assert_no_canaries($verified, 'Initial identity verification');

awvp_coordinator_clear_activity();
$discovered = $api_bundle['service']->discover($operation_id, 3002);
awvp_coordinator_assert(
    array(
        'status',
        'mutation',
        'operation_id',
        'backend_id',
        'phase',
        'record_revision',
        'retry_after',
        'identity',
        'destinations',
    ) === array_keys($discovered),
    'Read-only destination projection shape changed.'
);
awvp_coordinator_assert(
    Identity_Service::STATUS_DESTINATIONS_READY === $discovered['status']
        && awvp_identity_projection() === $discovered['identity']
        && awvp_identity_channels() === $discovered['destinations']
        && Atomic_Option_Result::MUTATION_NONE === $discovered['mutation'],
    'Read-only destination discovery did not return the reviewed projection.'
);
awvp_coordinator_assert(array() === awvp_coordinator_mutation_targets(), 'Destination chooser mutated local state.');
awvp_identity_assert_no_canaries($discovered, 'Destination chooser');

$selected = $api_bundle['service']->select($operation_id, '42', 7, 3003);
awvp_identity_assert_projection(
    $selected,
    Identity_Service::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_VERIFICATION_IN_FLIGHT,
    11,
    'Destination selection'
);
$selected_record = awvp_coordinator_record($operation_id);
awvp_coordinator_assert(
    '42' === $selected_record['selected_destination']
        && '' === $selected_record['verified_identity']['user_id']
        && 7 === $selected_record['activation_requested_by'],
    'Selection did not invalidate prior identity and retain exact activation intent.'
);

$ready = $api_bundle['service']->advance($operation_id, 3004);
awvp_identity_assert_projection(
    $ready,
    Identity_Service::STATUS_ACTIVATION_READY,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_ACTIVATION_READY,
    12,
    'Selected-destination re-verification'
);
$ready_record = awvp_coordinator_record($operation_id);
awvp_coordinator_assert(
    '42' === $ready_record['selected_destination']
        && awvp_identity_projection() === $ready_record['verified_identity'],
    'Activation-ready state lost the exact selection or re-verified identity.'
);
$registry = awvp_coordinator_decode(\ArgentVideo\Backend_Registry::OPTION);
awvp_coordinator_assert(
    is_array($registry)
        && 'disabled' === $registry['backends'][$ready_record['backend_id']]['state']
        && '' === $registry['backends'][$ready_record['backend_id']]['default_destination'],
    'Identity/destination work activated or rewrote the disabled descriptor.'
);
$api_bundle['api']->assert_consumed('Happy identity/destination path');
awvp_coordinator_assert(
    array_fill(0, 4, 'access-token-canary-r39') === $api_bundle['api']->received_tokens,
    'The service did not use only the exact decrypted access token.'
);

// A submitted identifier must be an exact current owned channel. Rejection is
// mutation-free and leaves the earlier identity/default state untouched.
$fixture = awvp_identity_secret_fixture();
$operation_id = $fixture['operation_id'];
$api_bundle = awvp_identity_service(array(awvp_identity_success(), awvp_identity_success()));
$api_bundle['service']->advance($operation_id, 3000);
$api_bundle['service']->advance($operation_id, 3001);
awvp_coordinator_clear_activity();
$missing = $api_bundle['service']->select($operation_id, '99', 7, 3002);
awvp_identity_assert_projection(
    $missing,
    Identity_Service::STATUS_DESTINATION_UNAVAILABLE,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_AWAITING_DESTINATION,
    10,
    'Missing destination selection'
);
awvp_coordinator_assert(array() === awvp_coordinator_mutation_targets(), 'Missing destination changed durable state.');
$noncanonical = $api_bundle['service']->select($operation_id, '042', 7, 3003);
awvp_identity_assert_projection(
    $noncanonical,
    Identity_Service::STATUS_REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    '',
    0,
    'Non-canonical destination selection'
);
awvp_coordinator_assert(2 === $api_bundle['factory']->calls, 'Invalid destination input reached PeerTube.');

// A failed fresh authority read during selection is intentionally read-only.
// The previously verified identity and destination gate remain authoritative;
// no remote detail or transient destination observation becomes durable state.
$fixture = awvp_identity_secret_fixture();
$operation_id = $fixture['operation_id'];
$api_bundle = awvp_identity_service(
    array(
        awvp_identity_success(),
        awvp_identity_error('rate_limited', 429, 19),
    )
);
$api_bundle['service']->advance($operation_id, 3000);
$api_bundle['service']->advance($operation_id, 3001);
$before_selection_failure = awvp_coordinator_record($operation_id);
awvp_coordinator_clear_activity();
$selection_failure = $api_bundle['service']->select($operation_id, '42', 7, 3002);
awvp_identity_assert_projection(
    $selection_failure,
    Identity_Service::STATUS_VERIFICATION_FAILED,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_AWAITING_DESTINATION,
    10,
    'Read-only selection authority failure'
);
awvp_coordinator_assert(
    19 === $selection_failure['retry_after'],
    'Read-only selection authority failure lost its bounded Retry-After projection.'
);
awvp_coordinator_assert(
    $before_selection_failure === awvp_coordinator_record($operation_id)
        && array() === awvp_coordinator_mutation_targets(),
    'Read-only selection authority failure changed durable state.'
);
awvp_identity_assert_no_canaries($selection_failure, 'Read-only selection authority failure');

// No channels and a selected channel that later disappears both fail closed;
// the latter preserves the exact selected destination instead of rewriting it.
$fixture = awvp_identity_secret_fixture();
$operation_id = $fixture['operation_id'];
$api_bundle = awvp_identity_service(array(awvp_identity_success(array())));
$api_bundle['service']->advance($operation_id, 3000);
$none = $api_bundle['service']->advance($operation_id, 3001);
awvp_identity_assert_projection(
    $none,
    Identity_Service::STATUS_VERIFICATION_FAILED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_VERIFICATION_FAILED,
    10,
    'No-channel verification'
);
awvp_coordinator_assert(
    'peertube.channels.none' === awvp_coordinator_record($operation_id)['last_error']['code'],
    'No-channel verification did not retain its bounded classification.'
);

$fixture = awvp_identity_secret_fixture();
$operation_id = $fixture['operation_id'];
$api_bundle = awvp_identity_service(
    array(awvp_identity_success(), awvp_identity_success(), awvp_identity_success(array(
        array('id' => '41', 'name' => 'primary', 'display_name' => 'Primary Channel', 'authority' => 'owned'),
    )))
);
$api_bundle['service']->advance($operation_id, 3000);
$api_bundle['service']->advance($operation_id, 3001);
$api_bundle['service']->select($operation_id, '42', 7, 3002);
$disappeared = $api_bundle['service']->advance($operation_id, 3003);
awvp_identity_assert_projection(
    $disappeared,
    Identity_Service::STATUS_VERIFICATION_FAILED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_VERIFICATION_FAILED,
    12,
    'Disappeared selected destination'
);
$disappeared_record = awvp_coordinator_record($operation_id);
awvp_coordinator_assert(
    '42' === $disappeared_record['selected_destination']
        && 'peertube.channels.unauthorized' === $disappeared_record['last_error']['code'],
    'A disappeared destination was silently rewritten or misclassified.'
);

// Credential and transport failures become only allowlisted journal evidence;
// Retry-After blocks a new verification intent until its exact deadline.
foreach (array(
    array('authentication_required', 401, 0, 'peertube.auth.reauthentication_required'),
    array('permission_denied', 403, 0, 'peertube.auth.permission_denied'),
    array('transport_error', 0, 0, 'peertube.connection.failed'),
    array('remote_error', 503, 0, 'peertube.connection.failed'),
) as $case) {
    [$remote_status, $http_status, $retry_after, $record_code] = $case;
    $fixture = awvp_identity_secret_fixture();
    $operation_id = $fixture['operation_id'];
    $api_bundle = awvp_identity_service(array(awvp_identity_error($remote_status, $http_status, $retry_after)));
    $api_bundle['service']->advance($operation_id, 3000);
    $failure = $api_bundle['service']->advance($operation_id, 3001);
    awvp_identity_assert_projection(
        $failure,
        Identity_Service::STATUS_VERIFICATION_FAILED,
        Atomic_Option_Result::MUTATION_APPLIED,
        Machine::PHASE_VERIFICATION_FAILED,
        10,
        'Remote verification failure ' . $remote_status
    );
    awvp_coordinator_assert(
        $record_code === awvp_coordinator_record($operation_id)['last_error']['code'],
        'Remote verification failure was not minimally classified: ' . $remote_status
    );
    awvp_identity_assert_no_canaries($failure, 'Remote verification failure ' . $remote_status);
}

$fixture = awvp_identity_secret_fixture();
$operation_id = $fixture['operation_id'];
$api_bundle = awvp_identity_service(array(awvp_identity_error('rate_limited', 429, 17)));
$api_bundle['service']->advance($operation_id, 3000);
$limited = $api_bundle['service']->advance($operation_id, 3001);
awvp_coordinator_assert(17 === $limited['retry_after'], 'Verification Retry-After was not retained.');
awvp_coordinator_clear_activity();
$too_soon = $api_bundle['service']->advance($operation_id, 3017);
awvp_identity_assert_projection(
    $too_soon,
    Identity_Service::STATUS_VERIFICATION_FAILED,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_VERIFICATION_FAILED,
    10,
    'Premature verification retry'
);
awvp_coordinator_assert(array() === awvp_coordinator_mutation_targets(), 'Premature retry changed durable state.');
$retry = $api_bundle['service']->advance($operation_id, 3018);
awvp_identity_assert_projection(
    $retry,
    Identity_Service::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_VERIFICATION_IN_FLIGHT,
    11,
    'Allowed verification retry'
);

// An expired/near-expiry access token sends no bearer and becomes a bounded
// reauthentication requirement. A malformed normalized projection also fails.
$fixture = awvp_identity_secret_fixture(3060);
$operation_id = $fixture['operation_id'];
$api_bundle = awvp_identity_service(array());
$api_bundle['service']->advance($operation_id, 3000);
$expired = $api_bundle['service']->advance($operation_id, 3001);
awvp_identity_assert_projection(
    $expired,
    Identity_Service::STATUS_VERIFICATION_FAILED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_VERIFICATION_FAILED,
    10,
    'Near-expiry token verification'
);
awvp_coordinator_assert(0 === $api_bundle['factory']->calls, 'Near-expiry token reached the API factory.');

$fixture = awvp_identity_secret_fixture();
$operation_id = $fixture['operation_id'];
$bad_channels = awvp_identity_channels();
$bad_channels[1]['id'] = '41';
$api_bundle = awvp_identity_service(array(awvp_identity_success($bad_channels)));
$api_bundle['service']->advance($operation_id, 3000);
$malformed = $api_bundle['service']->advance($operation_id, 3001);
awvp_identity_assert_projection(
    $malformed,
    Identity_Service::STATUS_VERIFICATION_FAILED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_VERIFICATION_FAILED,
    10,
    'Malformed destination projection'
);
awvp_coordinator_assert(
    'peertube.response.invalid' === awvp_coordinator_record($operation_id)['last_error']['code'],
    'Malformed destination projection was not rejected as an invalid response.'
);

// WordPress HTTP hooks may mutate the journal during a read. The stale remote
// response never overwrites that newer authority.
$fixture = awvp_identity_secret_fixture();
$operation_id = $fixture['operation_id'];
$begin_service = awvp_identity_service(array());
$begin_service['service']->advance($operation_id, 3000);
$observer = static function () use ($operation_id): void {
    $record = awvp_coordinator_record($operation_id);
    awvp_identity_apply(
        $record,
        Machine::EVENT_VERIFICATION_FAILED,
        array('reason' => 'transport_error', 'http_status' => 0, 'retry_after' => 0),
        3001
    );
};
$api_bundle = awvp_identity_service(array(awvp_identity_success()), $observer);
$raced = $api_bundle['service']->advance($operation_id, 3001);
awvp_identity_assert_projection(
    $raced,
    Identity_Service::STATUS_CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_VERIFICATION_FAILED,
    10,
    'Concurrent verification race'
);
awvp_coordinator_assert(
    '' === awvp_coordinator_record($operation_id)['verified_identity']['user_id'],
    'A stale remote response overwrote concurrent journal authority.'
);

// The journal write that records a verified identity has its own WordPress
// option-hook seam. If that hook changes a separate prerequisite, the applied
// journal transition is reported as an indeterminate partial mutation and no
// later destination read may reuse it as complete authority.
$fixture = awvp_identity_secret_fixture();
$operation_id = $fixture['operation_id'];
$secret_option = Managed_Backend_Secret_Store::OPTION . '_' . $fixture['record']['secret_ref'];
$api_bundle = awvp_identity_service(array(awvp_identity_success()));
$api_bundle['service']->advance($operation_id, 3000);
$journal_hook_fired = false;
$GLOBALS['awvp_coordinator_action_callbacks']['updated_option'] = static function (
    string $option,
    mixed $old_value,
    mixed $new_value
) use ($fixture, $operation_id, $secret_option, &$journal_hook_fired): void {
    unset($old_value);
    $candidate = is_array($new_value)
        ? ($new_value['operations'][$operation_id] ?? null)
        : null;
    if (
        ! $journal_hook_fired
        && Operation_Store::OPTION === $option
        && is_array($candidate)
        && Machine::PHASE_AWAITING_DESTINATION === ($candidate['phase'] ?? null)
    ) {
        $journal_hook_fired = true;
        unset($fixture['database']->rows[$secret_option]);
    }
};
$hook_changed = $api_bundle['service']->advance($operation_id, 3001);
awvp_identity_assert_projection(
    $hook_changed,
    Identity_Service::STATUS_INDETERMINATE,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_AWAITING_DESTINATION,
    10,
    'Post-verification journal-hook prerequisite change'
);
awvp_coordinator_assert(
    $journal_hook_fired && ! isset($fixture['database']->rows[$secret_option]),
    'The post-verification journal-hook prerequisite fixture did not run.'
);
$blocked_discovery = $api_bundle['service']->discover($operation_id, 3002);
awvp_coordinator_assert(
    Identity_Service::STATUS_CONFLICT === $blocked_discovery['status']
        && 1 === $api_bundle['factory']->calls,
    'A partially confirmed identity was reused after its prerequisite changed.'
);
awvp_identity_assert_no_canaries($hook_changed, 'Post-verification journal-hook prerequisite change');

// The API contract remains read-only even when the implementation throws.
$fixture = awvp_identity_secret_fixture();
$operation_id = $fixture['operation_id'];
$api_bundle = awvp_identity_service(array(new RuntimeException('transport canary')));
$api_bundle['service']->advance($operation_id, 3000);
$thrown = $api_bundle['service']->advance($operation_id, 3001);
awvp_identity_assert_projection(
    $thrown,
    Identity_Service::STATUS_VERIFICATION_FAILED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_VERIFICATION_FAILED,
    10,
    'Thrown read-only transport failure'
);
awvp_coordinator_assert(
    'peertube.connection.failed' === awvp_coordinator_record($operation_id)['last_error']['code'],
    'Thrown read-only transport failure was not bounded.'
);

echo "AWVP PeerTube identity/destination service tests passed.\n";

// EOF: tests/peertube-identity-destination-service.php
