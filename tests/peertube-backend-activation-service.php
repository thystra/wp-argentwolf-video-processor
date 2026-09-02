<?php
/**
 * Focused dependency-free tests for R40 PeerTube backend activation.
 *
 * The R39 identity/destination fixture establishes the exact activation-ready
 * prerequisite with a real atomic operation store, managed encrypted secret,
 * and backend registry. R40 then proves its local-only restart-safe sequence.
 */

declare(strict_types=1);

require_once __DIR__ . '/peertube-identity-destination-service.php';
require_once dirname(__DIR__) . '/includes/Backend_Capabilities.php';
require_once dirname(__DIR__) . '/includes/Backend_Health.php';
require_once dirname(__DIR__) . '/includes/Backend_Adapter.php';
require_once dirname(__DIR__) . '/includes/Backend_Adapter_Factory.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Backend_Adapter.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Backend_Activation_Service.php';

use ArgentVideo\Atomic_Option_Result;
use ArgentVideo\Backend_Adapter_Factory;
use ArgentVideo\Backend_Capabilities;
use ArgentVideo\Backend_Health;
use ArgentVideo\Backend_Registry;
use ArgentVideo\Managed_Backend_Secret_Store;
use ArgentVideo\PeerTube_Backend_Activation_Service as Activation_Service;
use ArgentVideo\PeerTube_Backend_Adapter;
use ArgentVideo\PeerTube_Connection_Operation_Store as Operation_Store;
use ArgentVideo\PeerTube_Connection_State_Machine as Machine;

/**
 * @return array{database:Awvp_Coordinator_Fake_Wpdb,operation_id:string,record:array<string,mixed>,api:Awvp_Identity_Fake_Api}
 */
function awvp_activation_ready_fixture(int $access_expires_at = 10000): array
{
    $fixture = awvp_identity_secret_fixture($access_expires_at);
    $operation_id = $fixture['operation_id'];
    $api_bundle = awvp_identity_service(
        array(
            awvp_identity_success(),
            awvp_identity_success(),
            awvp_identity_success(),
            awvp_identity_success(),
        )
    );

    $api_bundle['service']->advance($operation_id, 3000);
    $api_bundle['service']->advance($operation_id, 3001);
    $api_bundle['service']->discover($operation_id, 3002);
    $api_bundle['service']->select($operation_id, '42', 7, 3003);
    $ready = $api_bundle['service']->advance($operation_id, 3004);

    awvp_coordinator_assert(
        \ArgentVideo\PeerTube_Identity_Destination_Service::STATUS_ACTIVATION_READY === $ready['status'],
        'R40 fixture did not reach activation-ready status.'
    );
    $record = awvp_coordinator_record($operation_id);
    awvp_coordinator_assert(
        Machine::PHASE_ACTIVATION_READY === $record['phase']
            && '42' === $record['selected_destination']
            && 1 === $record['secret_generation'],
        'R40 fixture activation-ready journal is incomplete.'
    );
    $api_bundle['api']->assert_consumed('R40 activation-ready fixture');
    awvp_coordinator_clear_activity();

    return array(
        'database' => $fixture['database'],
        'operation_id' => $operation_id,
        'record' => $record,
        'api' => $api_bundle['api'],
    );
}

/** @return array{service:Activation_Service,factory:Backend_Adapter_Factory,registry:Backend_Registry,secrets:Managed_Backend_Secret_Store} */
function awvp_activation_service(int $clock = 4000, bool $with_adapter = true): array
{
    $secrets = new Managed_Backend_Secret_Store();
    $adapter = new PeerTube_Backend_Adapter($secrets, static fn (): int => $clock);
    $factory = $with_adapter
        ? new Backend_Adapter_Factory($adapter)
        : new Backend_Adapter_Factory();
    $registry = new Backend_Registry();

    return array(
        'service' => new Activation_Service(
            new Operation_Store(),
            $secrets,
            $registry,
            $factory
        ),
        'factory' => $factory,
        'registry' => $registry,
        'secrets' => $secrets,
    );
}

/** @param array<string,mixed> $projection */
function awvp_activation_assert_projection(
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
    awvp_coordinator_assert($status === $projection['status'], $message . ': wrong status.');
    awvp_coordinator_assert($mutation === $projection['mutation'], $message . ': wrong mutation classification.');
    awvp_coordinator_assert($phase === $projection['phase'], $message . ': wrong phase.');
    awvp_coordinator_assert($revision === $projection['record_revision'], $message . ': wrong revision.');
    awvp_coordinator_assert(0 === $projection['retry_after'], $message . ': activation leaked a retry delay.');
    awvp_coordinator_assert(strlen(serialize($projection)) < 1024, $message . ': projection exceeded its bound.');
}

// Happy path: plan -> exact registry CAS -> journal confirmation -> independent
// eligibility proof and operation close. No PeerTube API object is involved.
$fixture = awvp_activation_ready_fixture();
$operation_id = $fixture['operation_id'];
$bundle = awvp_activation_service();

$plan = $bundle['service']->advance($operation_id, 4000);
awvp_activation_assert_projection(
    $plan,
    Activation_Service::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_ACTIVATION_PLANNED,
    13,
    'Activation plan'
);
awvp_coordinator_assert(
    array(Operation_Store::OPTION) === awvp_coordinator_mutation_targets(),
    'Activation planning crossed more than the journal persistence boundary.'
);
$planned_record = awvp_coordinator_record($operation_id);
awvp_coordinator_assert(
    'registry_activate' === ($planned_record['last_mutation']['kind'] ?? null),
    'Activation plan did not journal exact registry-activation evidence.'
);
$registry = awvp_coordinator_decode(Backend_Registry::OPTION);
awvp_coordinator_assert(
    'disabled' === $registry['backends'][$planned_record['backend_id']]['state']
        && '' === $registry['backends'][$planned_record['backend_id']]['default_destination'],
    'Plan-only activation changed the backend registry.'
);

awvp_coordinator_clear_activity();
$write = $bundle['service']->advance($operation_id, 4001);
awvp_activation_assert_projection(
    $write,
    Activation_Service::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_ACTIVATION_PLANNED,
    13,
    'Activation registry write'
);
awvp_coordinator_assert(
    array(Backend_Registry::OPTION) === awvp_coordinator_mutation_targets(),
    'Registry activation crossed a second local persistence boundary.'
);
$registry = awvp_coordinator_decode(Backend_Registry::OPTION);
awvp_coordinator_assert(
    'active' === $registry['backends'][$planned_record['backend_id']]['state']
        && '42' === $registry['backends'][$planned_record['backend_id']]['default_destination'],
    'Registry activation did not establish the exact active target descriptor.'
);

awvp_coordinator_clear_activity();
$confirm = $bundle['service']->advance($operation_id, 4002);
awvp_activation_assert_projection(
    $confirm,
    Activation_Service::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_ACTIVE_PENDING_CLOSE,
    14,
    'Activation confirmation'
);
awvp_coordinator_assert(
    array(Operation_Store::OPTION) === awvp_coordinator_mutation_targets(),
    'Activation confirmation crossed more than the journal boundary.'
);

awvp_coordinator_clear_activity();
$complete = $bundle['service']->advance($operation_id, 4003);
awvp_activation_assert_projection(
    $complete,
    Activation_Service::STATUS_ACTIVE,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_COMPLETE,
    15,
    'Activation close'
);
awvp_coordinator_assert(
    array(Operation_Store::OPTION) === awvp_coordinator_mutation_targets(),
    'Activation close crossed more than the journal boundary.'
);
awvp_coordinator_assert(
    $bundle['registry']->eligible(
        $planned_record['backend_id'],
        Backend_Capabilities::DELIVERY_EMBED,
        $bundle['factory']
    ),
    'Confirmed active PeerTube descriptor was not eligible for the R40 non-mutating capability.'
);
awvp_coordinator_assert(
    ! $bundle['registry']->eligible(
        $planned_record['backend_id'],
        Backend_Capabilities::PROCESSING_VIDEO,
        $bundle['factory']
    ),
    'R40 PeerTube adapter prematurely claimed processing/upload capability.'
);
awvp_coordinator_assert(
    array() === (new Operation_Store())->open_operations(),
    'Completed activation remained in the open-operation set.'
);

$active_descriptor = $bundle['registry']->get($planned_record['backend_id']);
awvp_coordinator_assert(is_array($active_descriptor), 'Active descriptor disappeared after close.');
$health = $bundle['factory']->resolve(Backend_Registry::PEERTUBE_TYPE)?->health($active_descriptor);
awvp_coordinator_assert(
    $health instanceof Backend_Health
        && Backend_Health::OK === $health->status()
        && 'peertube.auth.operational' === ($health->checks()[0]['code'] ?? null),
    'PeerTube adapter did not surface the reviewed operational token health state.'
);

$invalid_destination_descriptor = $active_descriptor;
$invalid_destination_descriptor['default_destination'] = '';
$invalid_destination_health = $bundle['factory']->resolve(Backend_Registry::PEERTUBE_TYPE)?->health(
    $invalid_destination_descriptor
);
awvp_coordinator_assert(
    $invalid_destination_health instanceof Backend_Health
        && Backend_Health::BLOCKING === $invalid_destination_health->status()
        && 'peertube.descriptor.invalid' === ($invalid_destination_health->checks()[0]['code'] ?? null),
    'R40 adapter health accepted an active PeerTube descriptor without a destination.'
);

$noncanonical_descriptor = $active_descriptor;
$noncanonical_descriptor['id'] = strtoupper($noncanonical_descriptor['id']);
$noncanonical_health = $bundle['factory']->resolve(Backend_Registry::PEERTUBE_TYPE)?->health(
    $noncanonical_descriptor
);
awvp_coordinator_assert(
    $noncanonical_health instanceof Backend_Health
        && Backend_Health::BLOCKING === $noncanonical_health->status()
        && 'peertube.descriptor.invalid' === ($noncanonical_health->checks()[0]['code'] ?? null),
    'R40 adapter health accepted a non-canonical backend identifier.'
);

// Adapter absence fails closed before any activation journal mutation.
$fixture = awvp_activation_ready_fixture();
$operation_id = $fixture['operation_id'];
$without_adapter = awvp_activation_service(4000, false);
awvp_coordinator_clear_activity();
$missing_adapter = $without_adapter['service']->advance($operation_id, 4000);
awvp_activation_assert_projection(
    $missing_adapter,
    Activation_Service::STATUS_REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_ACTIVATION_READY,
    12,
    'Missing adapter'
);
awvp_coordinator_assert(array() === awvp_coordinator_mutation_targets(), 'Missing adapter path mutated local state.');

// Shared registry races are replanned explicitly rather than overwriting the
// unrelated winner or silently substituting new evidence.
$fixture = awvp_activation_ready_fixture();
$operation_id = $fixture['operation_id'];
$bundle = awvp_activation_service();
$bundle['service']->advance($operation_id, 4000);
$before_replan = awvp_coordinator_record($operation_id);
$registry_value = awvp_coordinator_decode(Backend_Registry::OPTION);
$registry_value['backends']['unrelated-r40'] = array(
    'id' => 'unrelated-r40',
    'type' => 'future-kind',
    'label' => 'Unrelated R40 Winner',
    'state' => 'active',
    'default_destination' => 'opaque',
    'secret_ref' => 'managed:unrelated-r40',
    'config_version' => 5,
    'config' => array('future' => true),
);
$GLOBALS['wpdb']->rows[Backend_Registry::OPTION] = array(
    'option_value' => serialize($registry_value),
    'autoload' => function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no',
);
awvp_coordinator_clear_activity();
$replanned = $bundle['service']->advance($operation_id, 4001);
awvp_activation_assert_projection(
    $replanned,
    Activation_Service::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_ACTIVATION_PLANNED,
    14,
    'Activation replan'
);
$after_replan = awvp_coordinator_record($operation_id);
awvp_coordinator_assert(
    $before_replan['last_mutation'] !== $after_replan['last_mutation'],
    'Explicit activation replan reused stale mutation evidence.'
);
awvp_coordinator_assert(
    array(Operation_Store::OPTION) === awvp_coordinator_mutation_targets(),
    'Conflict replan crossed a registry mutation boundary.'
);
$current_registry = awvp_coordinator_decode(Backend_Registry::OPTION);
awvp_coordinator_assert(
    isset($current_registry['backends']['unrelated-r40'])
        && 'disabled' === $current_registry['backends'][$after_replan['backend_id']]['state'],
    'Conflict replan overwrote unrelated state or activated prematurely.'
);

// R41 permits an active backend to remain eligible when only the access
// token needs refresh and the refresh credential remains usable.
$fixture = awvp_activation_ready_fixture(4050);
$operation_id = $fixture['operation_id'];
$early = awvp_activation_service(3000);
$early['service']->advance($operation_id, 4000);
$early['service']->advance($operation_id, 4001);
$early['service']->advance($operation_id, 4002);
$before_close = awvp_coordinator_record($operation_id);
awvp_coordinator_assert(Machine::PHASE_ACTIVE_PENDING_CLOSE === $before_close['phase'], 'Refresh-required fixture did not reach pending-close.');
$late = awvp_activation_service(4000);
awvp_coordinator_clear_activity();
$closed = $late['service']->advance($operation_id, 4003);
awvp_activation_assert_projection(
    $closed,
    Activation_Service::STATUS_ACTIVE,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_COMPLETE,
    15,
    'Refresh-required access-token close'
);
$warning_descriptor = $late['registry']->get($before_close['backend_id']);
$warning_health = is_array($warning_descriptor)
    ? $late['factory']->resolve(Backend_Registry::PEERTUBE_TYPE)?->health($warning_descriptor)
    : null;
awvp_coordinator_assert(
    $warning_health instanceof Backend_Health
        && Backend_Health::WARNING === $warning_health->status()
        && 'peertube.auth.refresh_required' === ($warning_health->checks()[0]['code'] ?? null),
    'Near-expiry access token did not produce the reviewed refresh-required warning.'
);

fwrite(STDOUT, "AWVP PeerTube backend activation tests passed.\n");

// EOF: tests/peertube-backend-activation-service.php
