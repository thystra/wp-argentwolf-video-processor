<?php
/**
 * Focused dependency-free tests for the PeerTube password-grant service.
 *
 * Run once without AWVP_ATOMIC_MODERN_AUTOLOAD to model the legacy `no`
 * value, and once with it set to 1 to model the modern `off` value.
 */

declare(strict_types=1);

require_once __DIR__ . '/peertube-connection-coordinator.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Password_Grant_Api.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Password_Grant_Service.php';

use ArgentVideo\Atomic_Option_Plan_Result;
use ArgentVideo\Atomic_Option_Result;
use ArgentVideo\Atomic_Option_Store;
use ArgentVideo\Managed_Backend_Secret_Store;
use ArgentVideo\PeerTube_Connection_Operation_Store as Operation_Store;
use ArgentVideo\PeerTube_Connection_State_Machine as Machine;
use ArgentVideo\PeerTube_Password_Grant_Api;
use ArgentVideo\PeerTube_Password_Grant_Service as Grant_Service;

final class Awvp_Grant_Fake_Api implements PeerTube_Password_Grant_Api
{
    /** @var list<string> */
    public array $requests = array();

    /** @var list<array{username:string,password:string,otp:string,received_at:int}> */
    private array $expected_grants;

    /** @var list<array<string, mixed>|Throwable> */
    private array $token_results;

    /** @var array<string, mixed> */
    private array $oauth_result;

    private ?Closure $token_observer;

    /**
     * @param array<string, mixed> $oauth_result
     * @param list<array<string, mixed>|Throwable> $token_results
     * @param list<array{username:string,password:string,otp:string,received_at:int}> $expected_grants
     */
    public function __construct(
        private readonly string $api_origin,
        array $oauth_result,
        array $token_results,
        array $expected_grants,
        ?callable $token_observer = null
    ) {
        $this->oauth_result = $oauth_result;
        $this->token_results = $token_results;
        $this->expected_grants = $expected_grants;
        $this->token_observer = null === $token_observer
            ? null
            : Closure::fromCallable($token_observer);
    }

    public function origin(): string
    {
        return $this->api_origin;
    }

    public function local_oauth_client(): array
    {
        $this->requests[] = 'oauth_client';
        return $this->oauth_result;
    }

    public function password_token(
        array $oauth_client,
        string $username,
        string $password,
        string $otp,
        int $received_at
    ): array {
        $this->requests[] = 'password_token';
        $expected = array_shift($this->expected_grants);
        awvp_coordinator_assert(is_array($expected), 'Unexpected password-token request.');
        awvp_coordinator_assert(
            $expected === compact('username', 'password', 'otp', 'received_at'),
            'Password-token request did not retain the exact ephemeral credential input.'
        );
        awvp_coordinator_assert(
            is_array($this->oauth_result['data'] ?? null)
                && $this->oauth_result['data'] === $oauth_client,
            'Password-token request did not use the exact ephemeral OAuth client.'
        );

        if (null !== $this->token_observer) {
            ($this->token_observer)();
        }

        $result = array_shift($this->token_results);
        awvp_coordinator_assert(null !== $result, 'Password-token result queue was exhausted.');
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    public function assert_consumed(string $message): void
    {
        awvp_coordinator_assert(
            array() === $this->expected_grants && array() === $this->token_results,
            $message . ': expected password-token calls were not consumed.'
        );
    }
}

final class Awvp_Grant_Fake_Factory
{
    public int $calls = 0;

    public function __construct(
        private readonly PeerTube_Password_Grant_Api $api,
        private readonly string $expected_origin = 'https://video.example.com'
    ) {
    }

    public function __invoke(string $origin): PeerTube_Password_Grant_Api
    {
        $this->calls++;
        awvp_coordinator_assert(
            $this->expected_origin === $origin,
            'Grant service requested an API for the wrong origin.'
        );
        return $this->api;
    }
}

/** @return array<string, mixed> */
function awvp_grant_oauth_success(
    string $client_id = 'awvp-client-id',
    string $client_secret = 'oauth-client-secret-canary'
): array {
    return array(
        'ok'    => true,
        'data'  => array('client_id' => $client_id, 'client_secret' => $client_secret),
        'error' => null,
    );
}

/** @return array<string, mixed> */
function awvp_grant_token_success(
    string $access_token,
    string $refresh_token,
    int $received_at
): array {
    return array(
        'ok'   => true,
        'data' => array(
            'access_token'       => $access_token,
            'refresh_token'      => $refresh_token,
            'access_expires_at'  => $received_at + 3600,
            'refresh_expires_at' => $received_at + 7200,
        ),
        'error' => null,
    );
}

/** @return array<string, mixed> */
function awvp_grant_api_error(
    string $status,
    int $http_status,
    string $code = '',
    int $retry_after = 0,
    string $detail = 'remote-detail-must-not-persist'
): array {
    return array(
        'ok'   => false,
        'data' => null,
        'error' => array(
            'status'      => $status,
            'http_status' => $http_status,
            'code'        => $code,
            'retry_after' => $retry_after,
            'detail'      => $detail,
        ),
    );
}

/**
 * @return array{
 *   database:Awvp_Coordinator_Fake_Wpdb,
 *   operation_id:string,
 *   record:array<string,mixed>,
 *   secret_option:string
 * }
 */
function awvp_grant_disabled_fixture(): array
{
    $database = awvp_coordinator_reset();
    $path = awvp_coordinator_drive(7);
    $operation_id = $path['operation_id'];
    $record = awvp_coordinator_record($operation_id);
    awvp_coordinator_assert(
        Machine::PHASE_DISABLED === $record['phase'] && 5 === $record['record_revision'],
        'Grant fixture did not reach the exact disabled pre-grant state.'
    );
    awvp_coordinator_clear_activity();

    return array(
        'database'      => $database,
        'operation_id'  => $operation_id,
        'record'        => $record,
        'secret_option' => Managed_Backend_Secret_Store::OPTION . '_' . $record['secret_ref'],
    );
}

/** @param array<string, mixed> $projection */
function awvp_grant_assert_projection(
    array $projection,
    string $status,
    string $mutation,
    string $phase,
    int $revision,
    int $retry_after,
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
        $message . ': projection shape changed.'
    );
    awvp_coordinator_assert($status === $projection['status'], $message . ': unexpected status.');
    awvp_coordinator_assert($mutation === $projection['mutation'], $message . ': unexpected mutation.');
    awvp_coordinator_assert($phase === $projection['phase'], $message . ': unexpected phase.');
    awvp_coordinator_assert($revision === $projection['record_revision'], $message . ': unexpected revision.');
    awvp_coordinator_assert($retry_after === $projection['retry_after'], $message . ': unexpected retry delay.');
    awvp_coordinator_assert(strlen(serialize($projection)) < 1024, $message . ': projection was not bounded.');
}

/** @param list<string> $canaries @param list<mixed> $values */
function awvp_grant_assert_canaries_absent(array $canaries, array $values, string $message): void
{
    $serialized = serialize($values);
    foreach ($canaries as $canary) {
        awvp_coordinator_assert(
            ! str_contains($serialized, $canary),
            $message . ': durable/bounded state retained canary ' . $canary
        );
    }
}

/** @param array<string, mixed> $record */
function awvp_grant_apply_event(
    array $record,
    string $event,
    array $payload,
    int $now
): array {
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
        'Manual grant fixture event did not apply exactly: ' . $event
    );
    return awvp_coordinator_record($record['operation_id']);
}

/**
 * @param list<array<string, mixed>|Throwable> $token_results
 * @param list<array{username:string,password:string,otp:string,received_at:int}> $expected_grants
 * @return array{service:Grant_Service,api:Awvp_Grant_Fake_Api,factory:Awvp_Grant_Fake_Factory}
 */
function awvp_grant_service(
    array $token_results,
    array $expected_grants,
    ?callable $token_observer = null,
    ?array $oauth_result = null,
    string $api_origin = 'https://video.example.com',
    ?callable $clock = null
): array {
    $api = new Awvp_Grant_Fake_Api(
        $api_origin,
        $oauth_result ?? awvp_grant_oauth_success(),
        $token_results,
        $expected_grants,
        $token_observer
    );
    $factory = new Awvp_Grant_Fake_Factory($api);

    return array(
        'service' => new Grant_Service(
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

$expected_autoload = function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no';
$username_canary = 'upload-bot@example.org';
$password_canary = 'password canary value';
$otp_canary = '654321';
$oauth_secret_canary = 'oauth-client-secret-canary';
$access_canary = 'access-token-canary-r37';
$refresh_canary = 'refresh-token-canary-r37';
$credential_canaries = array(
    $username_canary,
    $password_canary,
    $otp_canary,
    'awvp-client-id',
    $oauth_secret_canary,
    $access_canary,
    $refresh_canary,
    'remote-detail-must-not-persist',
);

// A successful submit performs one OAuth-client GET and one password-token
// POST. It journals the claim before the POST, journals commit evidence before
// the target write, and leaves confirmation to a fresh request.
$fixture = awvp_grant_disabled_fixture();
$operation_id = $fixture['operation_id'];
$token_observer = static function () use ($operation_id, $fixture): void {
    $claimed = awvp_coordinator_record($operation_id);
    awvp_coordinator_assert(
        Machine::PHASE_GRANT_IN_FLIGHT === $claimed['phase']
            && 7 === $claimed['record_revision'],
        'Password-token POST began before the durable request-start mark.'
    );
    awvp_coordinator_assert(
        array(Operation_Store::OPTION, Operation_Store::OPTION)
            === awvp_coordinator_mutation_targets(),
        'Password-token POST began after an unexpected local write boundary.'
    );
    $state = (new Managed_Backend_Secret_Store())->provisioning_state(
        $claimed['secret_ref'],
        $claimed['backend_id'],
        $claimed['provisioning_id']
    );
    awvp_coordinator_assert(
        Managed_Backend_Secret_Store::PROVISION_PENDING === $state['state']
            && 0 === $state['generation']
            && isset($fixture['database']->rows[$fixture['secret_option']]),
        'Password-token POST began without the exact pending reservation.'
    );
};
$success = awvp_grant_service(
    array(awvp_grant_token_success($access_canary, $refresh_canary, 2000)),
    array(array(
        'username'    => $username_canary,
        'password'    => $password_canary,
        'otp'         => $otp_canary,
        'received_at' => 2000,
    )),
    $token_observer,
    awvp_grant_oauth_success('awvp-client-id', $oauth_secret_canary)
);
$submitted = $success['service']->submit(
    $operation_id,
    $username_canary,
    $password_canary,
    $otp_canary,
    2000
);
awvp_grant_assert_projection(
    $submitted,
    Grant_Service::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_SECRET_WRITE_PLANNED,
    8,
    0,
    'Successful password grant'
);
awvp_coordinator_assert(
    array('oauth_client', 'password_token') === $success['api']->requests
        && 1 === $success['factory']->calls,
    'Successful password grant did not perform the exact two-request sequence.'
);
$success['api']->assert_consumed('Successful password grant');
$planned_record = awvp_coordinator_record($operation_id);
awvp_coordinator_assert(
    'secret_commit' === $planned_record['last_mutation']['kind']
        && Machine::PHASE_SECRET_WRITE_PLANNED === $planned_record['phase'],
    'Successful grant did not retain bounded secret-commit evidence.'
);
awvp_coordinator_assert(
    array(
        Operation_Store::OPTION,
        Operation_Store::OPTION,
        Operation_Store::OPTION,
        $fixture['secret_option'],
    )
        === awvp_coordinator_mutation_targets(),
    'Successful grant crossed an unexpected persistence boundary or reordered writes.'
);
$ready_state = (new Managed_Backend_Secret_Store())->provisioning_state(
    $planned_record['secret_ref'],
    $planned_record['backend_id'],
    $planned_record['provisioning_id']
);
awvp_coordinator_assert(
    Managed_Backend_Secret_Store::PROVISION_READY === $ready_state['state']
        && 1 === $ready_state['generation'],
    'Successful grant did not install the exact encrypted generation-one secret.'
);

$fresh_api = new Awvp_Grant_Fake_Api(
    'https://video.example.com',
    awvp_grant_oauth_success(),
    array(),
    array()
);
$fresh_factory = new Awvp_Grant_Fake_Factory($fresh_api);
$fresh_service = new Grant_Service(
    null,
    null,
    null,
    $fresh_factory,
    static fn (int $minimum): int => $minimum
);
awvp_coordinator_clear_activity();
$confirmed = $fresh_service->reconcile($operation_id, 2001);
awvp_grant_assert_projection(
    $confirmed,
    Grant_Service::STATUS_READY_FOR_VERIFICATION,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_SECRET_STORED,
    9,
    0,
    'Fresh secret confirmation'
);
awvp_coordinator_assert(
    array() === $fresh_api->requests && 0 === $fresh_factory->calls,
    'Fresh secret confirmation performed outbound HTTP.'
);
awvp_coordinator_assert(
    array(Operation_Store::OPTION) === awvp_coordinator_mutation_targets(),
    'Fresh secret confirmation crossed more than the journal boundary.'
);
$stored_record = awvp_coordinator_record($operation_id);
$decrypted = (new Managed_Backend_Secret_Store())->read(
    $stored_record['secret_ref'],
    $stored_record['backend_id']
);
awvp_coordinator_assert(
    array(
        'access_token'       => $access_canary,
        'refresh_token'      => $refresh_canary,
        'access_expires_at'  => 5600,
        'refresh_expires_at' => 9200,
        'generation'         => 1,
    ) === $decrypted,
    'Fresh confirmation did not preserve the exact encrypted token record.'
);
awvp_grant_assert_canaries_absent(
    $credential_canaries,
    array(
        $fixture['database']->rows,
        $fixture['database']->mutations,
        $GLOBALS['awvp_coordinator_actions'],
        $submitted,
        $confirmed,
        $stored_record,
    ),
    'Successful grant persistence'
);

// A journal confirmation hook can race the separate ready-secret option. The
// confirming request must re-prove the exact commit after its hook and cannot
// project readiness from the journal alone.
$fixture = awvp_grant_disabled_fixture();
$deleted_ready = awvp_grant_service(
    array(awvp_grant_token_success($access_canary, $refresh_canary, 2000)),
    array(array(
        'username' => $username_canary,
        'password' => $password_canary,
        'otp' => '',
        'received_at' => 2000,
    ))
);
$deleted_ready_submit = $deleted_ready['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2000
);
awvp_grant_assert_projection(
    $deleted_ready_submit,
    Grant_Service::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_SECRET_WRITE_PLANNED,
    8,
    0,
    'Ready-target deletion setup'
);
$ready_delete_result = null;
$GLOBALS['awvp_coordinator_action_callbacks']['updated_option'] = static function (
    string $option,
    mixed $old_value,
    mixed $new_value
) use ($fixture, &$ready_delete_result): void {
    unset($old_value);
    $candidate = is_array($new_value)
        ? ($new_value['operations'][$fixture['operation_id']] ?? null)
        : null;
    if (
        Operation_Store::OPTION === $option
        && is_array($candidate)
        && Machine::PHASE_SECRET_STORED === ($candidate['phase'] ?? null)
        && 9 === ($candidate['record_revision'] ?? null)
    ) {
        $store = new Atomic_Option_Store($fixture['secret_option']);
        $ready_delete_result = $store->compare_delete($store->snapshot());
    }
};
$requests_before_ready_delete = $deleted_ready['api']->requests;
$ready_deleted = $deleted_ready['service']->reconcile($fixture['operation_id'], 2001);
awvp_grant_assert_projection(
    $ready_deleted,
    Grant_Service::STATUS_CONFLICT,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_SECRET_STORED,
    9,
    0,
    'Post-confirm ready-target reproof'
);
$ready_deleted_again = $deleted_ready['service']->reconcile($fixture['operation_id'], 2002);
awvp_grant_assert_projection(
    $ready_deleted_again,
    Grant_Service::STATUS_CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_SECRET_STORED,
    9,
    0,
    'Missing ready target reconciliation'
);
awvp_coordinator_assert(
    $ready_delete_result instanceof Atomic_Option_Result
        && Atomic_Option_Result::APPLIED === $ready_delete_result->status()
        && ! isset($fixture['database']->rows[$fixture['secret_option']])
        && $requests_before_ready_delete === $deleted_ready['api']->requests,
    'Secret deletion during journal confirmation was projected as ready or performed HTTP.'
);
awvp_grant_assert_canaries_absent(
    $credential_canaries,
    array(
        $fixture['database']->rows,
        $fixture['database']->mutations,
        $GLOBALS['awvp_coordinator_actions'],
        $ready_deleted,
        $ready_deleted_again,
    ),
    'Post-confirm ready-target reproof'
);

// The durable request-start mark, rather than the earlier claim, owns the
// stale timer. Simulate a mark hook consuming 40 seconds, then prove the
// service refreshes and re-proves a second mark before the POST begins.
$fixture = awvp_grant_disabled_fixture();
$request_clock_now = 2000;
$request_clock = static function (int $minimum) use (&$request_clock_now): int {
    return max($minimum, $request_clock_now);
};
$marked_record = null;
$early_reconcile = null;
$timed_service = null;
$GLOBALS['awvp_coordinator_action_callbacks']['updated_option'] = static function (
    string $option,
    mixed $old_value,
    mixed $new_value
) use ($fixture, &$request_clock_now): void {
    unset($old_value);
    $candidate = is_array($new_value)
        ? ($new_value['operations'][$fixture['operation_id']] ?? null)
        : null;
    if (
        Operation_Store::OPTION === $option
        && is_array($candidate)
        && Machine::PHASE_GRANT_IN_FLIGHT === ($candidate['phase'] ?? null)
        && 7 === ($candidate['record_revision'] ?? null)
    ) {
        $request_clock_now = 2040;
    }
};
$request_observer = static function () use (
    $fixture,
    &$timed_service,
    &$marked_record,
    &$early_reconcile
): void {
    $marked_record = awvp_coordinator_record($fixture['operation_id']);
    awvp_coordinator_assert(is_array($timed_service), 'Timed grant service was unavailable at POST.');
    $early_reconcile = $timed_service['service']->reconcile($fixture['operation_id'], 2031);
};
$timed_service = awvp_grant_service(
    array(awvp_grant_api_error('authentication_required', 400, 'invalid_grant')),
    array(array(
        'username' => $username_canary,
        'password' => $password_canary,
        'otp' => '',
        'received_at' => 2040,
    )),
    $request_observer,
    null,
    'https://video.example.com',
    $request_clock
);
$timed_result = $timed_service['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2000
);
awvp_grant_assert_projection(
    $timed_result,
    Grant_Service::STATUS_AWAITING_CREDENTIALS,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_AWAITING_CREDENTIALS,
    10,
    0,
    'Request-start timing refresh'
);
awvp_coordinator_assert(
    is_array($marked_record)
        && 2040 === $marked_record['grant_started_at']
        && 2040 === $marked_record['updated_at']
        && 8 === $marked_record['record_revision']
        && is_array($early_reconcile)
        && Grant_Service::STATUS_REFUSED === $early_reconcile['status']
        && Machine::PHASE_GRANT_IN_FLIGHT === $early_reconcile['phase']
        && 8 === $early_reconcile['record_revision']
        && array('oauth_client', 'password_token') === $timed_service['api']->requests,
    'The final request mark did not prevent claim-time stale reconciliation.'
);
$timed_service['api']->assert_consumed('Request-start timing refresh');
awvp_grant_assert_canaries_absent(
    $credential_canaries,
    array(
        $fixture['database']->rows,
        $fixture['database']->mutations,
        $GLOBALS['awvp_coordinator_actions'],
        $timed_result,
        $marked_record,
        $early_reconcile,
    ),
    'Request-start timing refresh'
);
unset($timed_service, $marked_record, $early_reconcile);

// If every request mark consumes the entire stale window, bounded refresh
// exhausts into a capability-proved grant_not_sent state. Credentials never
// reach the token endpoint.
$fixture = awvp_grant_disabled_fixture();
$exhausted_clock_now = 2000;
$exhausted_clock = static function (int $minimum) use (&$exhausted_clock_now): int {
    return max($minimum, $exhausted_clock_now);
};
$GLOBALS['awvp_coordinator_action_callbacks']['updated_option'] = static function (
    string $option,
    mixed $old_value,
    mixed $new_value
) use ($fixture, &$exhausted_clock_now): void {
    unset($old_value);
    $candidate = is_array($new_value)
        ? ($new_value['operations'][$fixture['operation_id']] ?? null)
        : null;
    if (
        Operation_Store::OPTION === $option
        && is_array($candidate)
        && Machine::PHASE_GRANT_IN_FLIGHT === ($candidate['phase'] ?? null)
        && ($candidate['record_revision'] ?? 0) >= 7
    ) {
        $exhausted_clock_now = $candidate['updated_at'] + 40;
    }
};
$exhausted = awvp_grant_service(
    array(awvp_grant_api_error('authentication_required', 400, 'invalid_grant')),
    array(),
    null,
    null,
    'https://video.example.com',
    $exhausted_clock
);
$exhausted_result = $exhausted['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2000
);
awvp_grant_assert_projection(
    $exhausted_result,
    Grant_Service::STATUS_AWAITING_CREDENTIALS,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_AWAITING_CREDENTIALS,
    10,
    0,
    'Request-mark bounded exhaustion'
);
awvp_coordinator_assert(
    array('oauth_client') === $exhausted['api']->requests
        && 1 === $exhausted['factory']->calls
        && 'peertube.auth.grant_not_sent'
            === awvp_coordinator_record($fixture['operation_id'])['last_error']['code'],
    'Request-mark exhaustion reached the credential-bearing endpoint.'
);
awvp_grant_assert_canaries_absent(
    $credential_canaries,
    array(
        $fixture['database']->rows,
        $fixture['database']->mutations,
        $GLOBALS['awvp_coordinator_actions'],
        $exhausted_result,
    ),
    'Request-mark bounded exhaustion'
);
unset($exhausted);

// Invalid credential shapes and time fail before journal/target reads or API
// construction. The disabled operation remains byte-for-byte unchanged.
$fixture = awvp_grant_disabled_fixture();
$rows_before_invalid = $fixture['database']->rows;
$invalid = awvp_grant_service(array(), array());
$invalid_inputs = array(
    array('', $password_canary, '', 2000),
    array('user name', $password_canary, '', 2000),
    array(str_repeat('u', 1025), $password_canary, '', 2000),
    array("invalid\xB1username", $password_canary, '', 2000),
    array($username_canary, '', '', 2000),
    array($username_canary, "bad\npassword", '', 2000),
    array($username_canary, str_repeat('p', 16385), '', 2000),
    array($username_canary, $password_canary, '12345', 2000),
    array($username_canary, $password_canary, "12345\n", 2000),
    array($username_canary, $password_canary, '', 0),
);
foreach ($invalid_inputs as [$username, $password, $otp, $now]) {
    $refused = $invalid['service']->submit(
        $fixture['operation_id'],
        $username,
        $password,
        $otp,
        $now
    );
    awvp_coordinator_assert(
        Grant_Service::STATUS_REFUSED === $refused['status'],
        'Invalid grant input did not fail closed.'
    );
}
awvp_coordinator_assert(
    0 === $invalid['factory']->calls
        && array() === $invalid['api']->requests
        && array() === $fixture['database']->mutations
        && $rows_before_invalid === $fixture['database']->rows,
    'Invalid grant input reached HTTP or mutated durable state.'
);

// Missing local prerequisites and an out-of-scope journal phase perform no
// OAuth-client read or password-token POST.
foreach (array('secret', 'descriptor', 'phase') as $missing) {
    $fixture = awvp_grant_disabled_fixture();
    if ('secret' === $missing) {
        unset($fixture['database']->rows[$fixture['secret_option']]);
    } elseif ('descriptor' === $missing) {
        $registry = awvp_coordinator_decode(ArgentVideo\Backend_Registry::OPTION);
        unset($registry['backends'][$fixture['record']['backend_id']]);
        awvp_coordinator_seed(ArgentVideo\Backend_Registry::OPTION, $registry);
    } else {
        $fixture = array_merge($fixture, array('database' => awvp_coordinator_reset()));
        $path = awvp_coordinator_drive(0);
        $fixture['operation_id'] = $path['operation_id'];
    }
    $rows_before = $fixture['database']->rows;
    awvp_coordinator_clear_activity();
    $blocked = awvp_grant_service(array(), array());
    $result = $blocked['service']->submit(
        $fixture['operation_id'],
        $username_canary,
        $password_canary,
        '',
        2000
    );
    awvp_coordinator_assert(
        in_array($result['status'], array(Grant_Service::STATUS_CONFLICT, Grant_Service::STATUS_REFUSED), true),
        'Missing grant prerequisite did not fail closed: ' . $missing
    );
    awvp_coordinator_assert(
        0 === $blocked['factory']->calls
            && array() === $blocked['api']->requests
            && array() === $fixture['database']->mutations
            && $rows_before === $fixture['database']->rows,
        'Missing grant prerequisite reached HTTP or mutated state: ' . $missing
    );
}

// OTP-required is retryable only with a supplied six-digit OTP. PeerTube's
// authoritative HTTP 400 invalid_two_factor result becomes invalid_otp rather
// than another OTP challenge or an indeterminate grant.
$fixture = awvp_grant_disabled_fixture();
$otp_flow = awvp_grant_service(
    array(
        awvp_grant_api_error('otp_required', 401, 'invalid_grant'),
        awvp_grant_api_error('otp_required', 400, 'invalid_two_factor'),
    ),
    array(
        array(
            'username' => $username_canary,
            'password' => $password_canary,
            'otp' => '',
            'received_at' => 2000,
        ),
        array(
            'username' => $username_canary,
            'password' => $password_canary,
            'otp' => $otp_canary,
            'received_at' => 2001,
        ),
    )
);
$otp_required = $otp_flow['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2000
);
awvp_grant_assert_projection(
    $otp_required,
    Grant_Service::STATUS_AWAITING_OTP,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_AWAITING_OTP,
    9,
    0,
    'OTP-required grant'
);
$calls_before_missing_otp = $otp_flow['api']->requests;
$factory_before_missing_otp = $otp_flow['factory']->calls;
$missing_otp = $otp_flow['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2001
);
awvp_coordinator_assert(
    Grant_Service::STATUS_REFUSED === $missing_otp['status']
        && $calls_before_missing_otp === $otp_flow['api']->requests
        && $factory_before_missing_otp === $otp_flow['factory']->calls,
    'Awaiting-OTP state retried without a supplied OTP.'
);
$invalid_otp = $otp_flow['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    $otp_canary,
    2001
);
awvp_grant_assert_projection(
    $invalid_otp,
    Grant_Service::STATUS_AWAITING_CREDENTIALS,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_AWAITING_CREDENTIALS,
    13,
    0,
    'Invalid OTP grant'
);
awvp_coordinator_assert(
    array('oauth_client', 'password_token', 'oauth_client', 'password_token')
        === $otp_flow['api']->requests
        && 2 === $otp_flow['factory']->calls,
    'OTP retry did not perform exactly one fresh OAuth GET and token POST.'
);
$otp_flow['api']->assert_consumed('OTP retry');
$invalid_otp_record = awvp_coordinator_record($fixture['operation_id']);
awvp_coordinator_assert(
    'peertube.auth.invalid' === $invalid_otp_record['last_error']['code']
        && 400 === $invalid_otp_record['last_error']['http_status'],
    'Invalid OTP did not retain its bounded authoritative classification.'
);
awvp_grant_assert_canaries_absent(
    $credential_canaries,
    array(
        $fixture['database']->rows,
        $fixture['database']->mutations,
        $GLOBALS['awvp_coordinator_actions'],
        $otp_required,
        $missing_otp,
        $invalid_otp,
    ),
    'OTP error persistence'
);

// Definite credential, invalid-client, and permission failures remain retryable
// without writing a secret or reflecting remote detail.
$definite_errors = array(
    'credentials' => array(
        awvp_grant_api_error('authentication_required', 400, 'invalid_grant'),
        400,
        'peertube.auth.invalid',
    ),
    'client' => array(
        awvp_grant_api_error('authentication_required', 401, 'invalid_client'),
        401,
        'peertube.auth.invalid',
    ),
    'permission-400' => array(
        awvp_grant_api_error('permission_denied', 400, 'account_blocked'),
        400,
        'peertube.auth.permission_denied',
    ),
    'permission-403' => array(
        awvp_grant_api_error('permission_denied', 403, ''),
        403,
        'peertube.auth.permission_denied',
    ),
);
foreach ($definite_errors as $case => [$api_error, $http_status, $journal_code]) {
    $fixture = awvp_grant_disabled_fixture();
    $failure = awvp_grant_service(
        array($api_error),
        array(array(
            'username' => $username_canary,
            'password' => $password_canary,
            'otp' => '',
            'received_at' => 2000,
        ))
    );
    $result = $failure['service']->submit(
        $fixture['operation_id'],
        $username_canary,
        $password_canary,
        '',
        2000
    );
    awvp_grant_assert_projection(
        $result,
        Grant_Service::STATUS_AWAITING_CREDENTIALS,
        Atomic_Option_Result::MUTATION_APPLIED,
        Machine::PHASE_AWAITING_CREDENTIALS,
        9,
        0,
        'Definite grant failure ' . $case
    );
    $failure['api']->assert_consumed('Definite grant failure ' . $case);
    awvp_coordinator_assert(
        array('oauth_client', 'password_token') === $failure['api']->requests
            && 1 === $failure['factory']->calls,
        'Definite grant failure request sequence changed: ' . $case
    );
    $record = awvp_coordinator_record($fixture['operation_id']);
    awvp_coordinator_assert(
        $journal_code === $record['last_error']['code']
            && $http_status === $record['last_error']['http_status'],
        'Definite grant failure journal classification changed: ' . $case
    );
    $provision = (new Managed_Backend_Secret_Store())->provisioning_state(
        $record['secret_ref'],
        $record['backend_id'],
        $record['provisioning_id']
    );
    awvp_coordinator_assert(
        Managed_Backend_Secret_Store::PROVISION_PENDING === $provision['state'],
        'Definite grant failure changed the pending secret: ' . $case
    );
    awvp_grant_assert_canaries_absent(
        $credential_canaries,
        array(
            $fixture['database']->rows,
            $fixture['database']->mutations,
            $GLOBALS['awvp_coordinator_actions'],
            $result,
        ),
        'Definite grant failure ' . $case
    );
}

// A bounded 429 delay begins at response receipt, not request start. The fake
// token request advances the injected clock by 15 seconds; API construction is
// suppressed until the full Retry-After interval has then elapsed.
$fixture = awvp_grant_disabled_fixture();
$rate_clock_now = 2000;
$rate_clock = static function (int $minimum) use (&$rate_clock_now): int {
    return max($minimum, $rate_clock_now);
};
$rate_observer = static function () use (&$rate_clock_now): void {
    if (2000 === $rate_clock_now) {
        $rate_clock_now = 2015;
    }
};
$rate_limited = awvp_grant_service(
    array(
        awvp_grant_api_error('rate_limited', 429, 'rate_limit', 17),
        awvp_grant_api_error('authentication_required', 400, 'invalid_grant'),
    ),
    array(
        array(
            'username' => $username_canary,
            'password' => $password_canary,
            'otp' => '',
            'received_at' => 2000,
        ),
        array(
            'username' => $username_canary,
            'password' => $password_canary,
            'otp' => '',
            'received_at' => 2032,
        ),
    ),
    $rate_observer,
    null,
    'https://video.example.com',
    $rate_clock
);
$limited = $rate_limited['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2000
);
awvp_grant_assert_projection(
    $limited,
    Grant_Service::STATUS_AWAITING_CREDENTIALS,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_AWAITING_CREDENTIALS,
    9,
    17,
    'Rate-limited grant'
);
$requests_at_limit = $rate_limited['api']->requests;
$factories_at_limit = $rate_limited['factory']->calls;
$too_early = $rate_limited['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2031
);
awvp_grant_assert_projection(
    $too_early,
    Grant_Service::STATUS_REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_AWAITING_CREDENTIALS,
    9,
    17,
    'Early rate-limit retry'
);
awvp_coordinator_assert(
    $requests_at_limit === $rate_limited['api']->requests
        && $factories_at_limit === $rate_limited['factory']->calls,
    'Early rate-limit retry reached the API.'
);
$at_boundary = $rate_limited['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2032
);
awvp_grant_assert_projection(
    $at_boundary,
    Grant_Service::STATUS_AWAITING_CREDENTIALS,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_AWAITING_CREDENTIALS,
    13,
    0,
    'Rate-limit boundary retry'
);
awvp_coordinator_assert(
    array('oauth_client', 'password_token', 'oauth_client', 'password_token')
        === $rate_limited['api']->requests
        && 2 === $rate_limited['factory']->calls,
    'Rate-limit boundary did not permit exactly one explicit fresh attempt.'
);
$rate_limited['api']->assert_consumed('Rate-limit boundary retry');
awvp_grant_assert_canaries_absent(
    $credential_canaries,
    array(
        $fixture['database']->rows,
        $fixture['database']->mutations,
        $GLOBALS['awvp_coordinator_actions'],
        $limited,
        $too_early,
        $at_boundary,
    ),
    'Rate-limit persistence'
);

// The bounded eight-attempt ceiling is local journal authority. A ninth
// submission must be rejected before API construction or even the read-only
// OAuth-client request.
$fixture = awvp_grant_disabled_fixture();
$attempt_ceiling = $fixture['record'];
$attempt_time = 2000;
for ($attempt = 1; $attempt <= 8; $attempt++) {
    $attempt_capability = str_pad(dechex($attempt), 64, '0', STR_PAD_LEFT);
    $attempt_ceiling = awvp_grant_apply_event(
        $attempt_ceiling,
        Machine::EVENT_BEGIN_GRANT,
        array(
            'attempt_capability' => $attempt_capability,
        ),
        $attempt_time++
    );
    $attempt_ceiling = awvp_grant_apply_event(
        $attempt_ceiling,
        Machine::EVENT_CREDENTIALS_REJECTED,
        array(
            'reason' => 'invalid_credentials',
            'http_status' => 400,
            'retry_after' => 0,
        ),
        $attempt_time++
    );
    $attempt_ceiling = awvp_grant_apply_event(
        $attempt_ceiling,
        Machine::EVENT_CONFIRM_GRANT_RESULT,
        array('attempt_capability' => $attempt_capability),
        $attempt_time++
    );
}
awvp_coordinator_assert(
    8 === $attempt_ceiling['grant_attempt_no']
        && Machine::PHASE_AWAITING_CREDENTIALS === $attempt_ceiling['phase']
        && 29 === $attempt_ceiling['record_revision'],
    'Grant-attempt ceiling fixture did not reach the exact eighth rejection.'
);
awvp_coordinator_clear_activity();
$ceiling_service = awvp_grant_service(array(), array());
$ninth_attempt = $ceiling_service['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    3000
);
awvp_grant_assert_projection(
    $ninth_attempt,
    Grant_Service::STATUS_REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_AWAITING_CREDENTIALS,
    29,
    0,
    'Ninth grant attempt'
);
awvp_coordinator_assert(
    0 === $ceiling_service['factory']->calls
        && array() === $ceiling_service['api']->requests
        && array() === $fixture['database']->mutations
        && $attempt_ceiling === awvp_coordinator_record($fixture['operation_id']),
    'Grant-attempt ceiling was checked after API construction or changed the journal.'
);

// A malformed OAuth-client read is pre-token and consumes no durable attempt.
// It is reported as uncertain local preflight, while the operation remains the
// exact disabled retryable record.
$fixture = awvp_grant_disabled_fixture();
$disabled_before_oauth_failure = $fixture['database']->rows[Operation_Store::OPTION];
$bad_oauth = awvp_grant_service(
    array(),
    array(),
    null,
    array(
        'ok' => true,
        'data' => array('client_id' => 'client-without-secret'),
        'error' => null,
    )
);
$bad_oauth_result = $bad_oauth['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2000
);
awvp_grant_assert_projection(
    $bad_oauth_result,
    Grant_Service::STATUS_INDETERMINATE,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_DISABLED,
    5,
    0,
    'Malformed OAuth-client preflight'
);
awvp_coordinator_assert(
    array('oauth_client') === $bad_oauth['api']->requests
        && 1 === $bad_oauth['factory']->calls
        && $disabled_before_oauth_failure === $fixture['database']->rows[Operation_Store::OPTION]
        && array() === $fixture['database']->mutations,
    'Malformed OAuth-client preflight claimed a grant or reached the token endpoint.'
);

// API implementations are origin-bound authority. A factory result for any
// other origin is refused before OAuth, claim, or token activity.
$fixture = awvp_grant_disabled_fixture();
$journal_before_origin_mismatch = $fixture['database']->rows[Operation_Store::OPTION];
$origin_mismatch = awvp_grant_service(
    array(),
    array(),
    null,
    null,
    'https://other.example.net'
);
$origin_mismatch_result = $origin_mismatch['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2000
);
awvp_grant_assert_projection(
    $origin_mismatch_result,
    Grant_Service::STATUS_REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_DISABLED,
    5,
    0,
    'API-origin mismatch'
);
awvp_coordinator_assert(
    1 === $origin_mismatch['factory']->calls
        && array() === $origin_mismatch['api']->requests
        && array() === $fixture['database']->mutations
        && $journal_before_origin_mismatch === $fixture['database']->rows[Operation_Store::OPTION],
    'API-origin mismatch reached OAuth/claim activity or changed the journal.'
);

// Reentrant submission after the winning request's durable claim observes
// grant_in_flight and cannot construct a second API or alter that claim.
$fixture = awvp_grant_disabled_fixture();
$nested = awvp_grant_service(array(), array());
$nested_result = null;
$winner_claim = null;
$nested_started = false;
$GLOBALS['awvp_coordinator_action_callbacks']['updated_option'] = static function (
    string $option,
    mixed $old_value,
    mixed $new_value
) use (
    $fixture,
    $nested,
    $username_canary,
    $password_canary,
    &$nested_result,
    &$winner_claim,
    &$nested_started
): void {
    unset($old_value);
    $candidate = is_array($new_value)
        ? ($new_value['operations'][$fixture['operation_id']] ?? null)
        : null;
    if (
        $nested_started
        || Operation_Store::OPTION !== $option
        || ! is_array($candidate)
        || Machine::PHASE_GRANT_IN_FLIGHT !== ($candidate['phase'] ?? null)
    ) {
        return;
    }

    $nested_started = true;
    $winner_claim = $candidate;
    $nested_result = $nested['service']->submit(
        $fixture['operation_id'],
        $username_canary,
        $password_canary,
        '',
        2000
    );
};
$winner = awvp_grant_service(
    array(awvp_grant_api_error('authentication_required', 400, 'invalid_grant')),
    array(array(
        'username' => $username_canary,
        'password' => $password_canary,
        'otp' => '',
        'received_at' => 2000,
    ))
);
$winner_result = $winner['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2000
);
awvp_grant_assert_projection(
    $winner_result,
    Grant_Service::STATUS_AWAITING_CREDENTIALS,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_AWAITING_CREDENTIALS,
    9,
    0,
    'Winning concurrent grant'
);
awvp_coordinator_assert(is_array($nested_result), 'Nested grant submission did not run after the claim.');
awvp_grant_assert_projection(
    $nested_result,
    Grant_Service::STATUS_REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_GRANT_IN_FLIGHT,
    6,
    0,
    'Nested concurrent grant'
);
awvp_coordinator_assert(
    array('oauth_client', 'password_token') === $winner['api']->requests
        && 1 === $winner['factory']->calls
        && array() === $nested['api']->requests
        && 0 === $nested['factory']->calls,
    'Nested concurrent submit issued a second OAuth/token request.'
);
$winner['api']->assert_consumed('Winning concurrent grant');
$concurrent_record = awvp_coordinator_record($fixture['operation_id']);
awvp_coordinator_assert(
    is_array($winner_claim)
        && $winner_claim['grant_attempt_id'] === $concurrent_record['grant_attempt_id']
        && $winner_claim['grant_attempt_no'] === $concurrent_record['grant_attempt_no']
        && $winner_claim['grant_started_at'] === $concurrent_record['grant_started_at']
        && array(
            Operation_Store::OPTION,
            Operation_Store::OPTION,
            Operation_Store::OPTION,
            Operation_Store::OPTION,
        )
            === awvp_coordinator_mutation_targets(),
    'Nested concurrent submit altered the winning durable claim.'
);

// A hook cannot replace the winner's in-flight state with a retryable outcome
// while the credential-bearing request is running. This competing record is
// deliberately timestamped one second after submit()'s input to prove that
// terminalization uses the fresh authoritative timestamp rather than regressing
// to the original request time.
$fixture = awvp_grant_disabled_fixture();
$outcome_race_observer = static function () use ($fixture): void {
    $claimed = awvp_coordinator_record($fixture['operation_id']);
    $raced = (new Operation_Store())->apply_event(
        $claimed['operation_id'],
        $claimed['record_revision'],
        Machine::EVENT_OTP_REQUIRED,
        array('http_status' => 401, 'retry_after' => 0),
        2001
    );
    awvp_coordinator_assert(
        Atomic_Option_Result::APPLIED === $raced->status()
            && Atomic_Option_Result::MUTATION_APPLIED === $raced->mutation(),
        'Post-token outcome race fixture did not install its competing state.'
    );
};
$outcome_race = awvp_grant_service(
    array(awvp_grant_token_success($access_canary, $refresh_canary, 2000)),
    array(array(
        'username' => $username_canary,
        'password' => $password_canary,
        'otp' => '',
        'received_at' => 2000,
    )),
    $outcome_race_observer
);
$outcome_race_result = $outcome_race['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2000
);
awvp_grant_assert_projection(
    $outcome_race_result,
    Grant_Service::STATUS_GRANT_INDETERMINATE,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_GRANT_INDETERMINATE,
    9,
    0,
    'Post-token outcome race'
);
$outcome_race_record = awvp_coordinator_record($fixture['operation_id']);
$outcome_race_state = (new Managed_Backend_Secret_Store())->provisioning_state(
    $outcome_race_record['secret_ref'],
    $outcome_race_record['backend_id'],
    $outcome_race_record['provisioning_id']
);
awvp_coordinator_assert(
    array('oauth_client', 'password_token') === $outcome_race['api']->requests
        && 1 === $outcome_race['factory']->calls
        && 'peertube.auth.grant_indeterminate' === $outcome_race_record['last_error']['code']
        && Managed_Backend_Secret_Store::PROVISION_PENDING === $outcome_race_state['state']
        && 'registry_link' === $outcome_race_record['last_mutation']['kind'],
    'Post-token outcome race remained retryable or persisted untracked token material.'
);
$outcome_race_repeat = $outcome_race['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2002
);
awvp_coordinator_assert(
    Grant_Service::STATUS_REFUSED === $outcome_race_repeat['status']
        && array('oauth_client', 'password_token') === $outcome_race['api']->requests,
    'Post-token outcome race permitted another remote attempt.'
);
awvp_grant_assert_canaries_absent(
    $credential_canaries,
    array(
        $fixture['database']->rows,
        $fixture['database']->mutations,
        $GLOBALS['awvp_coordinator_actions'],
        $outcome_race_result,
        $outcome_race_repeat,
        $outcome_race_record,
    ),
    'Post-token outcome race'
);

// The same retry-safety invariant applies before any successful-response path:
// a transport throw, 5xx normalization, or malformed success must re-probe and
// terminalize a retryable same-attempt race instead of using the stale claim.
$uncertain_race_cases = array(
    'transport' => new RuntimeException('raced transport detail must not persist'),
    'remote-5xx' => awvp_grant_api_error('remote_error', 503),
    'malformed-success' => array(
        'ok' => true,
        'data' => array('access_token' => $access_canary),
        'error' => null,
    ),
);
foreach ($uncertain_race_cases as $case => $token_result) {
    $fixture = awvp_grant_disabled_fixture();
    $race_observer = static function () use ($fixture, $case): void {
        $claimed = awvp_coordinator_record($fixture['operation_id']);
        $raced = (new Operation_Store())->apply_event(
            $claimed['operation_id'],
            $claimed['record_revision'],
            Machine::EVENT_OTP_REQUIRED,
            array('http_status' => 401, 'retry_after' => 0),
            2000
        );
        awvp_coordinator_assert(
            Atomic_Option_Result::APPLIED === $raced->status()
                && Atomic_Option_Result::MUTATION_APPLIED === $raced->mutation(),
            'Uncertain post race did not install retryable state: ' . $case
        );
        $fixture['database']->failed_reads[Operation_Store::OPTION] = 1;
    };
    $raced = awvp_grant_service(
        array($token_result),
        array(array(
            'username' => $username_canary,
            'password' => $password_canary,
            'otp' => '',
            'received_at' => 2000,
        )),
        $race_observer
    );
    $result = $raced['service']->submit(
        $fixture['operation_id'],
        $username_canary,
        $password_canary,
        '',
        2000
    );
    awvp_grant_assert_projection(
        $result,
        Grant_Service::STATUS_GRANT_INDETERMINATE,
        Atomic_Option_Result::MUTATION_APPLIED,
        Machine::PHASE_GRANT_INDETERMINATE,
        9,
        0,
        'Uncertain post race ' . $case
    );
    $raced['api']->assert_consumed('Uncertain post race ' . $case);
    $request_count = count($raced['api']->requests);
    $factory_count = $raced['factory']->calls;
    $repeat = $raced['service']->submit(
        $fixture['operation_id'],
        $username_canary,
        $password_canary,
        '',
        2001
    );
    awvp_coordinator_assert(
        Grant_Service::STATUS_REFUSED === $repeat['status']
            && $request_count === count($raced['api']->requests)
            && $factory_count === $raced['factory']->calls,
        'Uncertain post race allowed another credential POST: ' . $case
    );
    awvp_grant_assert_canaries_absent(
        array_merge(
            $credential_canaries,
            array('raced transport detail must not persist')
        ),
        array(
            $fixture['database']->rows,
            $fixture['database']->mutations,
            $GLOBALS['awvp_coordinator_actions'],
            $result,
            $repeat,
        ),
        'Uncertain post race ' . $case
    );
}

// Retry eligibility is capability-bound to the request that received a
// definite remote rejection. Even if every read after the authentic confirm
// write fails, the durable awaiting state is safe for a later explicit retry;
// the capability itself never crosses the journal/action/result boundary.
$fixture = awvp_grant_disabled_fixture();
$confirmation_hook_fired = false;
$GLOBALS['awvp_coordinator_action_callbacks']['updated_option'] = static function (
    string $option,
    mixed $old_value,
    mixed $new_value
) use ($fixture, &$confirmation_hook_fired): void {
    unset($old_value);
    $candidate = is_array($new_value)
        ? ($new_value['operations'][$fixture['operation_id']] ?? null)
        : null;
    if (
        ! $confirmation_hook_fired
        && Operation_Store::OPTION === $option
        && is_array($candidate)
        && Machine::PHASE_AWAITING_CREDENTIALS === ($candidate['phase'] ?? null)
        && 9 === ($candidate['record_revision'] ?? null)
    ) {
        $confirmation_hook_fired = true;
        $fixture['database']->failed_reads[Operation_Store::OPTION] = 16;
    }
};
$confirmed_retry = awvp_grant_service(
    array(
        awvp_grant_api_error('authentication_required', 400, 'invalid_grant'),
        awvp_grant_api_error('authentication_required', 400, 'invalid_grant'),
    ),
    array(
        array(
            'username' => $username_canary,
            'password' => $password_canary,
            'otp' => '',
            'received_at' => 2000,
        ),
        array(
            'username' => $username_canary,
            'password' => $password_canary,
            'otp' => '',
            'received_at' => 2001,
        ),
    )
);
$unobserved_confirm = $confirmed_retry['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2000
);
awvp_grant_assert_projection(
    $unobserved_confirm,
    Grant_Service::STATUS_INDETERMINATE,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_GRANT_IN_FLIGHT,
    7,
    0,
    'Unobserved authentic result confirmation'
);
$fixture['database']->failed_reads[Operation_Store::OPTION] = 0;
$confirmed_record = awvp_coordinator_record($fixture['operation_id']);
awvp_coordinator_assert(
    $confirmation_hook_fired
        && Machine::PHASE_AWAITING_CREDENTIALS === $confirmed_record['phase']
        && 9 === $confirmed_record['record_revision']
        && false === strpos(
            serialize(array(
                $fixture['database']->rows,
                $fixture['database']->mutations,
                $GLOBALS['awvp_coordinator_actions'],
                $unobserved_confirm,
            )),
            'attempt_capability'
        ),
    'Authentic result confirmation was not durable or exposed its capability.'
);
$confirmed_retry_result = $confirmed_retry['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2001
);
awvp_grant_assert_projection(
    $confirmed_retry_result,
    Grant_Service::STATUS_AWAITING_CREDENTIALS,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_AWAITING_CREDENTIALS,
    13,
    0,
    'Capability-proved explicit retry'
);
awvp_coordinator_assert(
    array('oauth_client', 'password_token', 'oauth_client', 'password_token')
        === $confirmed_retry['api']->requests,
    'Capability-proved definite rejection did not permit one explicit retry.'
);
$confirmed_retry['api']->assert_consumed('Capability-proved explicit retry');
awvp_grant_assert_canaries_absent(
    $credential_canaries,
    array(
        $fixture['database']->rows,
        $fixture['database']->mutations,
        $GLOBALS['awvp_coordinator_actions'],
        $unobserved_confirm,
        $confirmed_retry_result,
    ),
    'Capability-proved result confirmation'
);

// Once the grant claim is confirmed, a later local read/write failure cannot
// erase that known mutation from the bounded result. The observer makes every
// post-claim journal read indeterminate; the durable claim remains in-flight
// and therefore cannot be submitted again while recovery is unresolved.
$fixture = awvp_grant_disabled_fixture();
$partial_mutation_observer = static function () use ($fixture): void {
    $claimed = awvp_coordinator_record($fixture['operation_id']);
    awvp_coordinator_assert(
        Machine::PHASE_GRANT_IN_FLIGHT === $claimed['phase'],
        'Partial-mutation fixture did not observe the confirmed claim.'
    );
    $fixture['database']->failed_reads[Operation_Store::OPTION] = 16;
};
$partial_mutation = awvp_grant_service(
    array(awvp_grant_api_error('authentication_required', 400, 'invalid_grant')),
    array(array(
        'username' => $username_canary,
        'password' => $password_canary,
        'otp' => '',
        'received_at' => 2000,
    )),
    $partial_mutation_observer
);
$partial_result = $partial_mutation['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2000
);
awvp_grant_assert_projection(
    $partial_result,
    Grant_Service::STATUS_INDETERMINATE,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_GRANT_IN_FLIGHT,
    7,
    0,
    'Known partial grant mutation'
);
$fixture['database']->failed_reads[Operation_Store::OPTION] = 0;
$partial_record = awvp_coordinator_record($fixture['operation_id']);
awvp_coordinator_assert(
    Machine::PHASE_GRANT_IN_FLIGHT === $partial_record['phase']
        && array('oauth_client', 'password_token') === $partial_mutation['api']->requests,
    'Known partial grant mutation was lost or repeated.'
);

// If a WordPress hook changes a prerequisite after the read-only OAuth GET but
// after the durable claim, the service journals grant_not_sent and performs no
// credential-bearing request.
$fixture = awvp_grant_disabled_fixture();
$GLOBALS['awvp_coordinator_action_callbacks']['updated_option'] = static function (
    string $option,
    mixed $old_value,
    mixed $new_value
) use ($fixture): void {
    unset($old_value);
    if (
        Operation_Store::OPTION === $option
        && is_array($new_value)
        && Machine::PHASE_GRANT_IN_FLIGHT
            === ($new_value['operations'][$fixture['operation_id']]['phase'] ?? null)
    ) {
        unset($fixture['database']->rows[$fixture['secret_option']]);
    }
};
$not_sent = awvp_grant_service(array(), array());
$not_sent_result = $not_sent['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2000
);
awvp_grant_assert_projection(
    $not_sent_result,
    Grant_Service::STATUS_AWAITING_CREDENTIALS,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_AWAITING_CREDENTIALS,
    7,
    0,
    'Post-claim prerequisite change'
);
awvp_coordinator_assert(
    array('oauth_client') === $not_sent['api']->requests
        && 1 === $not_sent['factory']->calls,
    'Post-claim prerequisite change reached the password-token endpoint.'
);
$not_sent_record = awvp_coordinator_record($fixture['operation_id']);
awvp_coordinator_assert(
    'peertube.auth.grant_not_sent' === $not_sent_record['last_error']['code']
        && 0 === $not_sent_record['last_error']['http_status']
        && ! isset($fixture['database']->rows[$fixture['secret_option']]),
    'Post-claim prerequisite change was not durably classified as grant-not-sent.'
);
awvp_grant_assert_canaries_absent(
    $credential_canaries,
    array(
        $fixture['database']->rows,
        $fixture['database']->mutations,
        $GLOBALS['awvp_coordinator_actions'],
        $not_sent_result,
        $not_sent_record,
    ),
    'Post-claim prerequisite change'
);

// The request-start mark itself invokes WordPress option hooks. A prerequisite
// changed specifically by that second journal write must be re-proved before
// credentials leave the process.
$fixture = awvp_grant_disabled_fixture();
$mark_hook_fired = false;
$GLOBALS['awvp_coordinator_action_callbacks']['updated_option'] = static function (
    string $option,
    mixed $old_value,
    mixed $new_value
) use ($fixture, &$mark_hook_fired): void {
    unset($old_value);
    $candidate = is_array($new_value)
        ? ($new_value['operations'][$fixture['operation_id']] ?? null)
        : null;
    if (
        Operation_Store::OPTION === $option
        && is_array($candidate)
        && Machine::PHASE_GRANT_IN_FLIGHT === ($candidate['phase'] ?? null)
        && 7 === ($candidate['record_revision'] ?? null)
    ) {
        $mark_hook_fired = true;
        unset($fixture['database']->rows[$fixture['secret_option']]);
    }
};
$mark_not_sent = awvp_grant_service(array(), array());
$mark_not_sent_result = $mark_not_sent['service']->submit(
    $fixture['operation_id'],
    $username_canary,
    $password_canary,
    '',
    2000
);
awvp_grant_assert_projection(
    $mark_not_sent_result,
    Grant_Service::STATUS_AWAITING_CREDENTIALS,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_AWAITING_CREDENTIALS,
    8,
    0,
    'Request-mark prerequisite change'
);
awvp_coordinator_assert(
    $mark_hook_fired
        && array('oauth_client') === $mark_not_sent['api']->requests
        && 1 === $mark_not_sent['factory']->calls
        && array(
            Operation_Store::OPTION,
            Operation_Store::OPTION,
            Operation_Store::OPTION,
        ) === awvp_coordinator_mutation_targets(),
    'A request-mark hook changed authority without suppressing the token POST.'
);
$mark_not_sent_record = awvp_coordinator_record($fixture['operation_id']);
awvp_coordinator_assert(
    'peertube.auth.grant_not_sent' === $mark_not_sent_record['last_error']['code']
        && ! isset($fixture['database']->rows[$fixture['secret_option']]),
    'The request-mark prerequisite race was not durably classified as not sent.'
);
awvp_grant_assert_canaries_absent(
    $credential_canaries,
    array(
        $fixture['database']->rows,
        $fixture['database']->mutations,
        $GLOBALS['awvp_coordinator_actions'],
        $mark_not_sent_result,
        $mark_not_sent_record,
    ),
    'Request-mark prerequisite change'
);

// Once the token POST has been invoked, transport failure and malformed token
// success are terminal. Neither submit nor reconcile may issue another API
// request or create a ready secret.
$terminal_cases = array(
    'transport' => array(
        new RuntimeException('transport exception detail must not persist'),
        array_merge($credential_canaries, array('transport exception detail must not persist')),
    ),
    'malformed-success' => array(
        array(
            'ok' => true,
            'data' => array(
                'access_token' => $access_canary,
                'access_expires_at' => 5600,
                'refresh_expires_at' => 9200,
            ),
            'error' => null,
        ),
        $credential_canaries,
    ),
    'malformed-definite-shape' => array(
        array(
            'ok' => true,
            'data' => null,
            'error' => array(
                'status' => 'authentication_required',
                'http_status' => 400,
                'code' => 'invalid_grant',
                'retry_after' => 0,
            ),
        ),
        $credential_canaries,
    ),
    'oversized-token-lifetime' => array(
        array(
            'ok' => true,
            'data' => array(
                'access_token' => $access_canary,
                'refresh_token' => $refresh_canary,
                'access_expires_at' => 2000 + 315576001,
                'refresh_expires_at' => 2000 + 315576001,
            ),
            'error' => null,
        ),
        $credential_canaries,
    ),
);
foreach ($terminal_cases as $case => [$token_result, $case_canaries]) {
    $fixture = awvp_grant_disabled_fixture();
    $terminal = awvp_grant_service(
        array($token_result),
        array(array(
            'username' => $username_canary,
            'password' => $password_canary,
            'otp' => '',
            'received_at' => 2000,
        ))
    );
    $result = $terminal['service']->submit(
        $fixture['operation_id'],
        $username_canary,
        $password_canary,
        '',
        2000
    );
    awvp_grant_assert_projection(
        $result,
        Grant_Service::STATUS_GRANT_INDETERMINATE,
        Atomic_Option_Result::MUTATION_APPLIED,
        Machine::PHASE_GRANT_INDETERMINATE,
        8,
        0,
        'Terminal token outcome ' . $case
    );
    awvp_coordinator_assert(
        array('oauth_client', 'password_token') === $terminal['api']->requests
            && 1 === $terminal['factory']->calls,
        'Terminal token outcome request sequence changed: ' . $case
    );
    $terminal['api']->assert_consumed('Terminal token outcome ' . $case);
    $request_count = count($terminal['api']->requests);
    $factory_count = $terminal['factory']->calls;
    $repeat = $terminal['service']->submit(
        $fixture['operation_id'],
        $username_canary,
        $password_canary,
        '',
        2001
    );
    awvp_coordinator_assert(
        Grant_Service::STATUS_REFUSED === $repeat['status'],
        'Terminal token outcome accepted an explicit resubmit: ' . $case
    );
    $reconciled = $terminal['service']->reconcile($fixture['operation_id'], 2100);
    awvp_grant_assert_projection(
        $reconciled,
        Grant_Service::STATUS_GRANT_INDETERMINATE,
        Atomic_Option_Result::MUTATION_NONE,
        Machine::PHASE_GRANT_INDETERMINATE,
        8,
        0,
        'Terminal token reconciliation ' . $case
    );
    awvp_coordinator_assert(
        $request_count === count($terminal['api']->requests)
            && $factory_count === $terminal['factory']->calls,
        'Terminal token outcome was retried automatically: ' . $case
    );
    $record = awvp_coordinator_record($fixture['operation_id']);
    $provision = (new Managed_Backend_Secret_Store())->provisioning_state(
        $record['secret_ref'],
        $record['backend_id'],
        $record['provisioning_id']
    );
    awvp_coordinator_assert(
        Managed_Backend_Secret_Store::PROVISION_PENDING === $provision['state']
            && 'registry_link' === $record['last_mutation']['kind'],
        'Terminal token outcome created a ready secret or false commit evidence: ' . $case
    );
    awvp_grant_assert_canaries_absent(
        $case_canaries,
        array(
            $fixture['database']->rows,
            $fixture['database']->mutations,
            $GLOBALS['awvp_coordinator_actions'],
            $result,
            $repeat,
            $reconciled,
            $record,
        ),
        'Terminal token outcome ' . $case
    );
}

// A stranded in-flight claim is not classified until it is strictly older
// than the 30-second HTTP bound. Once stale it becomes terminal without HTTP.
$fixture = awvp_grant_disabled_fixture();
$in_flight = awvp_grant_apply_event(
    $fixture['record'],
    Machine::EVENT_BEGIN_GRANT,
    array('attempt_capability' => str_repeat('a', 64)),
    2000
);
awvp_coordinator_clear_activity();
$stale_api = awvp_grant_service(array(), array());
$in_flight_boundary = $stale_api['service']->reconcile($fixture['operation_id'], 2030);
awvp_grant_assert_projection(
    $in_flight_boundary,
    Grant_Service::STATUS_INDETERMINATE,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_GRANT_IN_FLIGHT,
    6,
    0,
    'In-flight stale boundary'
);
awvp_coordinator_assert(
    array() === $fixture['database']->mutations,
    'Non-stale in-flight reconciliation mutated the journal.'
);
$stale_in_flight = $stale_api['service']->reconcile($fixture['operation_id'], 2031);
awvp_grant_assert_projection(
    $stale_in_flight,
    Grant_Service::STATUS_GRANT_INDETERMINATE,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_GRANT_INDETERMINATE,
    7,
    0,
    'Stale in-flight recovery'
);
awvp_coordinator_assert(
    array() === $stale_api['api']->requests
        && 0 === $stale_api['factory']->calls
        && array(Operation_Store::OPTION) === awvp_coordinator_mutation_targets(),
    'Stale in-flight recovery performed HTTP or crossed another write boundary.'
);
$stale_in_flight_record = awvp_coordinator_record($fixture['operation_id']);
awvp_coordinator_assert(
    'peertube.auth.grant_indeterminate' === $stale_in_flight_record['last_error']['code']
        && 'registry_link' === $stale_in_flight_record['last_mutation']['kind'],
    'Stale in-flight recovery did not retain exact pre-commit evidence.'
);

// A crash after commit evidence was journaled but before the target write has
// no replay authority: request-local plaintext/token authority is gone. Before
// stale it waits. Once stale, reconciliation installs an exact persistent fence
// before terminalizing. That marker blocks both a still-live pending-to-ready
// plan and an older absent-to-pending plan from recreating its expected bytes.
$fixture = awvp_grant_disabled_fixture();
$stale_plan_store = new Managed_Backend_Secret_Store();
$removed_for_aba = $stale_plan_store->delete_reserved_if_pending(
    $fixture['record']['secret_ref'],
    $fixture['record']['backend_id'],
    $fixture['record']['provisioning_id']
);
$old_reservation = $stale_plan_store->prepare_reservation(
    $fixture['record']['secret_ref'],
    $fixture['record']['backend_id'],
    $fixture['record']['provisioning_id'],
    'mutation_c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1'
);
$old_reservation_plan = $old_reservation->plan();
$restore_reservation = $stale_plan_store->prepare_reservation(
    $fixture['record']['secret_ref'],
    $fixture['record']['backend_id'],
    $fixture['record']['provisioning_id'],
    'mutation_d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2'
);
$restore_reservation_plan = $restore_reservation->plan();
awvp_coordinator_assert(
    Atomic_Option_Result::APPLIED === $removed_for_aba->status()
        && Atomic_Option_Result::MUTATION_APPLIED === $removed_for_aba->mutation()
        && Atomic_Option_Plan_Result::READY === $old_reservation->status()
        && null !== $old_reservation_plan
        && Atomic_Option_Plan_Result::READY === $restore_reservation->status()
        && null !== $restore_reservation_plan,
    'Stale planned-write ABA fixture did not prepare two absent reservation plans.'
);
$restored_for_aba = $stale_plan_store->apply_reservation_plan(
    $fixture['record']['secret_ref'],
    $fixture['record']['backend_id'],
    $fixture['record']['provisioning_id'],
    $restore_reservation_plan
);
awvp_coordinator_assert(
    Atomic_Option_Result::APPLIED === $restored_for_aba->status()
        && Atomic_Option_Result::MUTATION_APPLIED === $restored_for_aba->mutation(),
    'Stale planned-write ABA fixture did not restore the exact pending target.'
);
$planned = awvp_grant_apply_event(
    $fixture['record'],
    Machine::EVENT_BEGIN_GRANT,
    array('attempt_capability' => str_repeat('b', 64)),
    2000
);
$planned_secret = array(
    'access_token'       => $access_canary,
    'refresh_token'      => $refresh_canary,
    'access_expires_at'  => 5600,
    'refresh_expires_at' => 9200,
);
$prepared = $stale_plan_store->prepare_commit_reserved(
    $planned['secret_ref'],
    $planned['backend_id'],
    $planned['provisioning_id'],
    $planned_secret,
    'mutation_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
);
$plan = $prepared->plan();
awvp_coordinator_assert(
    Atomic_Option_Plan_Result::READY === $prepared->status() && null !== $plan,
    'Stale planned-write fixture did not create an exact request-local plan.'
);
$planned = awvp_grant_apply_event(
    $planned,
    Machine::EVENT_PLAN_SECRET_STORAGE,
    $plan->evidence(),
    2001
);
$planned_evidence = $planned['last_mutation'];
unset($prepared);
awvp_coordinator_clear_activity();
$planned_api = awvp_grant_service(array(), array());
$planned_boundary = $planned_api['service']->reconcile($fixture['operation_id'], 2031);
awvp_grant_assert_projection(
    $planned_boundary,
    Grant_Service::STATUS_INDETERMINATE,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_SECRET_WRITE_PLANNED,
    7,
    0,
    'Planned-write stale boundary'
);
awvp_coordinator_assert(
    array() === $fixture['database']->mutations,
    'Non-stale planned-write reconciliation mutated the journal or secret.'
);
$stale_planned = $planned_api['service']->reconcile($fixture['operation_id'], 2032);
awvp_grant_assert_projection(
    $stale_planned,
    Grant_Service::STATUS_GRANT_INDETERMINATE,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_GRANT_INDETERMINATE,
    8,
    0,
    'Stale planned-write recovery'
);
$stale_planned_record = awvp_coordinator_record($fixture['operation_id']);
$stale_planned_state = (new Managed_Backend_Secret_Store())->provisioning_state(
    $stale_planned_record['secret_ref'],
    $stale_planned_record['backend_id'],
    $stale_planned_record['provisioning_id']
);
awvp_coordinator_assert(
    $planned_evidence === $stale_planned_record['last_mutation']
        && 'secret_commit' === $stale_planned_record['last_mutation']['kind']
        && Managed_Backend_Secret_Store::PROVISION_FENCED === $stale_planned_state['state'],
    'Stale planned-write recovery lost evidence or failed to fence the empty target.'
);
awvp_coordinator_assert(
    array() === $planned_api['api']->requests
        && 0 === $planned_api['factory']->calls
        && array($fixture['secret_option'], Operation_Store::OPTION)
            === awvp_coordinator_mutation_targets(),
    'Stale planned-write recovery performed HTTP or crossed another write boundary.'
);
$late_apply = $stale_plan_store->apply_commit_plan(
    $stale_planned_record['secret_ref'],
    $stale_planned_record['backend_id'],
    $stale_planned_record['provisioning_id'],
    $planned_secret,
    $plan
);
$late_reservation = $stale_plan_store->apply_reservation_plan(
    $stale_planned_record['secret_ref'],
    $stale_planned_record['backend_id'],
    $stale_planned_record['provisioning_id'],
    $old_reservation_plan
);
$direct_reservation = $stale_plan_store->reserve(
    $stale_planned_record['secret_ref'],
    $stale_planned_record['backend_id'],
    $stale_planned_record['provisioning_id']
);
$after_late_apply = $stale_plan_store->provisioning_state(
    $stale_planned_record['secret_ref'],
    $stale_planned_record['backend_id'],
    $stale_planned_record['provisioning_id']
);
awvp_coordinator_assert(
    Atomic_Option_Result::CONFLICT === $late_apply->status()
        && Atomic_Option_Result::MUTATION_NONE === $late_apply->mutation()
        && Atomic_Option_Result::CONFLICT === $late_reservation->status()
        && Atomic_Option_Result::MUTATION_NONE === $late_reservation->mutation()
        && Atomic_Option_Result::CONFLICT === $direct_reservation->status()
        && Atomic_Option_Result::MUTATION_NONE === $direct_reservation->mutation()
        && Managed_Backend_Secret_Store::PROVISION_FENCED === $after_late_apply['state'],
    'A stalled reservation/commit path regained authority after stale recovery won: '
        . implode(
            '/',
            array(
                $late_apply->status(),
                $late_apply->mutation(),
                $late_reservation->status(),
                $late_reservation->mutation(),
                $direct_reservation->status(),
                $direct_reservation->mutation(),
                $after_late_apply['state'],
            )
        )
);
unset(
    $planned_secret,
    $plan,
    $late_apply,
    $late_reservation,
    $direct_reservation,
    $after_late_apply,
    $old_reservation,
    $old_reservation_plan,
    $restore_reservation,
    $restore_reservation_plan,
    $removed_for_aba,
    $restored_for_aba,
    $stale_plan_store
);
awvp_grant_assert_canaries_absent(
    $credential_canaries,
    array(
        $fixture['database']->rows,
        $fixture['database']->mutations,
        $GLOBALS['awvp_coordinator_actions'],
        $planned_boundary,
        $stale_planned,
        $stale_planned_record,
    ),
    'Stale planned-write recovery'
);

// The terminal journal write has its own hook seam. If that hook removes the
// fence and lets the still-live absent reservation plus exact token commit win,
// the service must re-prove the target and recover the journal to secret_stored
// instead of returning a contradictory terminal projection.
$fixture = awvp_grant_disabled_fixture();
$terminal_race_store = new Managed_Backend_Secret_Store();
$terminal_race_removed = $terminal_race_store->delete_reserved_if_pending(
    $fixture['record']['secret_ref'],
    $fixture['record']['backend_id'],
    $fixture['record']['provisioning_id']
);
$terminal_race_old_reservation = $terminal_race_store->prepare_reservation(
    $fixture['record']['secret_ref'],
    $fixture['record']['backend_id'],
    $fixture['record']['provisioning_id'],
    'mutation_e3e3e3e3e3e3e3e3e3e3e3e3e3e3e3e3'
);
$terminal_race_old_plan = $terminal_race_old_reservation->plan();
$terminal_race_restore = $terminal_race_store->prepare_reservation(
    $fixture['record']['secret_ref'],
    $fixture['record']['backend_id'],
    $fixture['record']['provisioning_id'],
    'mutation_f4f4f4f4f4f4f4f4f4f4f4f4f4f4f4f4'
);
$terminal_race_restore_plan = $terminal_race_restore->plan();
awvp_coordinator_assert(
    Atomic_Option_Result::APPLIED === $terminal_race_removed->status()
        && null !== $terminal_race_old_plan
        && null !== $terminal_race_restore_plan,
    'Terminal fence race did not prepare its absent reservation plans.'
);
$terminal_race_restored = $terminal_race_store->apply_reservation_plan(
    $fixture['record']['secret_ref'],
    $fixture['record']['backend_id'],
    $fixture['record']['provisioning_id'],
    $terminal_race_restore_plan
);
$terminal_race_record = awvp_grant_apply_event(
    $fixture['record'],
    Machine::EVENT_BEGIN_GRANT,
    array('attempt_capability' => str_repeat('c', 64)),
    2000
);
$terminal_race_secret = array(
    'access_token' => $access_canary,
    'refresh_token' => $refresh_canary,
    'access_expires_at' => 5600,
    'refresh_expires_at' => 9200,
);
$terminal_race_prepared = $terminal_race_store->prepare_commit_reserved(
    $terminal_race_record['secret_ref'],
    $terminal_race_record['backend_id'],
    $terminal_race_record['provisioning_id'],
    $terminal_race_secret,
    'mutation_abcddcbaabcddcbaabcddcbaabcddcba'
);
$terminal_race_commit_plan = $terminal_race_prepared->plan();
awvp_coordinator_assert(
    Atomic_Option_Result::APPLIED === $terminal_race_restored->status()
        && Atomic_Option_Plan_Result::READY === $terminal_race_prepared->status()
        && null !== $terminal_race_commit_plan,
    'Terminal fence race did not restore pending and prepare its commit.'
);
$terminal_race_record = awvp_grant_apply_event(
    $terminal_race_record,
    Machine::EVENT_PLAN_SECRET_STORAGE,
    $terminal_race_commit_plan->evidence(),
    2001
);
$terminal_race_delete = null;
$terminal_race_reserve_apply = null;
$terminal_race_commit_apply = null;
$GLOBALS['awvp_coordinator_action_callbacks']['updated_option'] = static function (
    string $option,
    mixed $old_value,
    mixed $new_value
) use (
    $fixture,
    $terminal_race_store,
    $terminal_race_old_plan,
    $terminal_race_commit_plan,
    $terminal_race_secret,
    &$terminal_race_delete,
    &$terminal_race_reserve_apply,
    &$terminal_race_commit_apply
): void {
    unset($old_value);
    $candidate = is_array($new_value)
        ? ($new_value['operations'][$fixture['operation_id']] ?? null)
        : null;
    if (
        Operation_Store::OPTION === $option
        && is_array($candidate)
        && Machine::PHASE_GRANT_INDETERMINATE === ($candidate['phase'] ?? null)
        && 8 === ($candidate['record_revision'] ?? null)
    ) {
        $atomic = new Atomic_Option_Store($fixture['secret_option']);
        $terminal_race_delete = $atomic->compare_delete($atomic->snapshot());
        $terminal_race_reserve_apply = $terminal_race_store->apply_reservation_plan(
            $candidate['secret_ref'],
            $candidate['backend_id'],
            $candidate['provisioning_id'],
            $terminal_race_old_plan
        );
        $terminal_race_commit_apply = $terminal_race_store->apply_commit_plan(
            $candidate['secret_ref'],
            $candidate['backend_id'],
            $candidate['provisioning_id'],
            $terminal_race_secret,
            $terminal_race_commit_plan
        );
    }
};
awvp_coordinator_clear_activity();
$terminal_race_service = awvp_grant_service(array(), array());
$terminal_race_result = $terminal_race_service['service']->reconcile(
    $fixture['operation_id'],
    2032
);
awvp_grant_assert_projection(
    $terminal_race_result,
    Grant_Service::STATUS_READY_FOR_VERIFICATION,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_SECRET_STORED,
    9,
    0,
    'Terminal fence hook recovery'
);
$terminal_race_final_record = awvp_coordinator_record($fixture['operation_id']);
$terminal_race_final_state = $terminal_race_store->provisioning_state(
    $terminal_race_final_record['secret_ref'],
    $terminal_race_final_record['backend_id'],
    $terminal_race_final_record['provisioning_id']
);
awvp_coordinator_assert(
    $terminal_race_delete instanceof Atomic_Option_Result
        && Atomic_Option_Result::APPLIED === $terminal_race_delete->status()
        && $terminal_race_reserve_apply instanceof Atomic_Option_Result
        && Atomic_Option_Result::APPLIED === $terminal_race_reserve_apply->status()
        && $terminal_race_commit_apply instanceof Atomic_Option_Result
        && Atomic_Option_Result::APPLIED === $terminal_race_commit_apply->status()
        && Managed_Backend_Secret_Store::PROVISION_READY === $terminal_race_final_state['state']
        && 1 === $terminal_race_final_state['generation']
        && array() === $terminal_race_service['api']->requests
        && 0 === $terminal_race_service['factory']->calls,
    'Terminal fence hook winner was not freshly recovered without HTTP.'
);
awvp_grant_assert_canaries_absent(
    $credential_canaries,
    array(
        $fixture['database']->rows,
        $fixture['database']->mutations,
        $GLOBALS['awvp_coordinator_actions'],
        $terminal_race_result,
        $terminal_race_final_record,
    ),
    'Terminal fence hook recovery'
);
unset(
    $terminal_race_store,
    $terminal_race_removed,
    $terminal_race_old_reservation,
    $terminal_race_old_plan,
    $terminal_race_restore,
    $terminal_race_restore_plan,
    $terminal_race_restored,
    $terminal_race_secret,
    $terminal_race_prepared,
    $terminal_race_commit_plan,
    $terminal_race_delete,
    $terminal_race_reserve_apply,
    $terminal_race_commit_apply
);

// Missing operation IDs are bounded conflicts and never construct an API.
$fixture = awvp_grant_disabled_fixture();
$missing_operation = awvp_grant_service(array(), array());
$missing_operation_result = $missing_operation['service']->submit(
    'connection_ffffffffffffffffffffffffffffffff',
    $username_canary,
    $password_canary,
    '',
    2000
);
awvp_coordinator_assert(
    Grant_Service::STATUS_CONFLICT === $missing_operation_result['status']
        && 0 === $missing_operation['factory']->calls
        && array() === $missing_operation['api']->requests,
    'Missing operation reached the API or was not a bounded conflict.'
);

fwrite(
    STDOUT,
    'PeerTube password-grant service tests passed (' . $expected_autoload . " autoload mode).\n"
);

// EOF: tests/peertube-password-grant-service.php
