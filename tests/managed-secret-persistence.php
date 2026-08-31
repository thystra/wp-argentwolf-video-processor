<?php
/**
 * Focused dependency-free tests for managed secret reservation and CAS.
 *
 * Run once without AWVP_ATOMIC_MODERN_AUTOLOAD to model WordPress 6.4/6.5
 * (`no`), and once with it set to 1 to model WordPress 6.6+ (`off`).
 */

declare(strict_types=1);

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (! defined('AUTH_KEY')) {
    define(
        'AUTH_KEY',
        'awvp-managed-secret-persistence-test-key-with-no-production-value'
    );
}

if (
    '1' === getenv('AWVP_ATOMIC_MODERN_AUTOLOAD')
    && ! function_exists('wp_autoload_values_to_autoload')
) {
    function wp_autoload_values_to_autoload(): array
    {
        return array('yes', 'on', 'auto-on', 'auto');
    }
}

$GLOBALS['awvp_managed_secret_actions'] = array();
$GLOBALS['awvp_managed_secret_action_callbacks'] = array();
$GLOBALS['awvp_managed_secret_cache'] = array();
$GLOBALS['awvp_managed_secret_cache_deletes'] = array();

function do_action(string $hook, mixed ...$arguments): void
{
    $GLOBALS['awvp_managed_secret_actions'][] = array($hook, $arguments);
    $callback = $GLOBALS['awvp_managed_secret_action_callbacks'][$hook] ?? null;
    if (is_callable($callback)) {
        $callback(...$arguments);
    }
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    $GLOBALS['awvp_managed_secret_cache_deletes'][] = array($key, $group);
    $cache_key = $group . ':' . $key;
    $existed = array_key_exists($cache_key, $GLOBALS['awvp_managed_secret_cache']);
    unset($GLOBALS['awvp_managed_secret_cache'][$cache_key]);
    return $existed;
}

final class Awvp_Managed_Secret_Fake_Wpdb
{
    public string $options = 'wp_options';
    public string $last_error = '';

    /** @var array<string, array{option_value:string,autoload:string}> */
    public array $rows = array();

    /** @var array<string, array{template:string,args:list<mixed>}> */
    public array $prepared = array();

    /** @var list<array{template:string,args:list<mixed>}> */
    public array $mutations = array();

    /** @var array<string, int> */
    public array $failed_reads = array();

    private int $query_id = 0;

    public function prepare(string $query, mixed ...$arguments): string
    {
        $token = 'awvp-managed-secret-prepared-' . (++$this->query_id);
        $this->prepared[$token] = array(
            'template' => $query,
            'args'     => $arguments,
        );
        return $token;
    }

    /** @return array{option_value:?string,autoload:string,byte_length:string}|null */
    public function get_row(string $query, string $output): ?array
    {
        unset($output);

        $prepared = $this->prepared[$query] ?? null;
        if (! is_array($prepared) || ! str_starts_with(ltrim($prepared['template']), 'SELECT ')) {
            throw new RuntimeException('Unexpected fake get_row query.');
        }

        $maximum_bytes = (int) ($prepared['args'][0] ?? 0);
        $option = (string) ($prepared['args'][2] ?? '');
        if (($this->failed_reads[$option] ?? 0) > 0) {
            $this->failed_reads[$option]--;
            $this->last_error = 'synthetic targeted read failure';
            return null;
        }

        $this->last_error = '';
        $row = $this->rows[$option] ?? null;
        if (null === $row) {
            return null;
        }

        return array(
            'option_value' => strlen($row['option_value']) <= $maximum_bytes
                ? $row['option_value']
                : null,
            'autoload'     => $row['autoload'],
            'byte_length'  => (string) strlen($row['option_value']),
        );
    }

    public function query(string $query): int|false
    {
        $prepared = $this->prepared[$query] ?? null;
        if (! is_array($prepared)) {
            throw new RuntimeException('Unexpected fake mutation query.');
        }

        $this->mutations[] = $prepared;
        $this->last_error = '';
        return $this->apply($prepared['template'], $prepared['args']);
    }

    /** @param list<mixed> $arguments */
    private function apply(string $template, array $arguments): int
    {
        $normalized = ltrim($template);

        if (str_starts_with($normalized, 'INSERT INTO ')) {
            $option = (string) ($arguments[1] ?? '');
            if (array_key_exists($option, $this->rows)) {
                return 0;
            }

            $this->rows[$option] = array(
                'option_value' => (string) ($arguments[2] ?? ''),
                'autoload'     => (string) ($arguments[3] ?? ''),
            );
            return 1;
        }

        if (str_starts_with($normalized, 'UPDATE ')) {
            $option = (string) ($arguments[3] ?? '');
            $current = $this->rows[$option] ?? null;
            if (
                null === $current
                || strlen($current['option_value']) !== (int) ($arguments[4] ?? -1)
                || $current['option_value'] !== (string) ($arguments[5] ?? '')
                || $current['autoload'] !== (string) ($arguments[6] ?? '')
            ) {
                return 0;
            }

            $this->rows[$option] = array(
                'option_value' => (string) ($arguments[1] ?? ''),
                'autoload'     => (string) ($arguments[2] ?? ''),
            );
            return 1;
        }

        if (str_starts_with($normalized, 'DELETE FROM ')) {
            $option = (string) ($arguments[1] ?? '');
            $current = $this->rows[$option] ?? null;
            if (
                null === $current
                || strlen($current['option_value']) !== (int) ($arguments[2] ?? -1)
                || $current['option_value'] !== (string) ($arguments[3] ?? '')
                || $current['autoload'] !== (string) ($arguments[4] ?? '')
            ) {
                return 0;
            }

            unset($this->rows[$option]);
            return 1;
        }

        throw new RuntimeException('Unsupported fake mutation query.');
    }
}

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Snapshot.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Result.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Mutation_Plan.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Plan_Result.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Store.php';
require_once dirname(__DIR__) . '/includes/Backend_Secret_Store.php';
require_once dirname(__DIR__) . '/includes/Backend_Secret_Crypto.php';
require_once dirname(__DIR__) . '/includes/Managed_Backend_Secret_Store.php';

use ArgentVideo\Atomic_Option_Result;
use ArgentVideo\Atomic_Option_Plan_Result;
use ArgentVideo\Atomic_Option_Store;
use ArgentVideo\Backend_Secret_Crypto;
use ArgentVideo\Managed_Backend_Secret_Store;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$assert_result = static function (
    Atomic_Option_Result $result,
    string $status,
    string $mutation,
    string $message
) use ($assert): void {
    $assert($status === $result->status(), $message . ' status mismatch.');
    $assert($mutation === $result->mutation(), $message . ' mutation mismatch.');
};

$decode_row = static function (string $option): array {
    $row = $GLOBALS['wpdb']->rows[$option] ?? null;
    if (! is_array($row)) {
        throw new RuntimeException('Expected raw option row was absent: ' . $option);
    }

    $decoded = @unserialize(
        $row['option_value'],
        array(
            'allowed_classes' => false,
            'max_depth'       => 16,
        )
    );
    if (! is_array($decoded)) {
        throw new RuntimeException('Expected canonical array row was unreadable: ' . $option);
    }

    return $decoded;
};

$record_option = static fn (string $secret_ref): string =>
    Managed_Backend_Secret_Store::OPTION . '_' . $secret_ref;

$expected_autoload = function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no';
$GLOBALS['wpdb'] = new Awvp_Managed_Secret_Fake_Wpdb();
$store = new Managed_Backend_Secret_Store();

$assert($store->available(), 'Managed secret persistence must be available in the focused fixture.');
$assert(
    ! method_exists(\ArgentVideo\Backend_Secret_Store::class, 'create')
        && ! method_exists($store, 'create'),
    'Managed secret persistence must not expose an unclassifiable generated-ID create path.'
);

$backend_id = 'peertube-one';
$other_backend_id = 'peertube-two';
$secret_ref = 'managed_11111111111111111111111111111111';
$provisioning_id = 'provision_22222222222222222222222222222222';
$other_provisioning_id = 'provision_33333333333333333333333333333333';
$secret_one = array(
    'access_token'       => 'awvp-access-token-one-plaintext-sentinel',
    'refresh_token'      => 'awvp-refresh-token-one-plaintext-sentinel',
    'access_expires_at'  => 1900000001,
    'refresh_expires_at' => 1900003601,
);
$secret_two = array(
    'access_token'       => 'awvp-access-token-two-plaintext-sentinel',
    'refresh_token'      => 'awvp-refresh-token-two-plaintext-sentinel',
    'access_expires_at'  => 1900000002,
    'refresh_expires_at' => 1900003602,
);
$secret_three = array(
    'access_token'       => 'awvp-access-token-three-plaintext-sentinel',
    'refresh_token'      => 'awvp-refresh-token-three-plaintext-sentinel',
    'access_expires_at'  => 1900000003,
    'refresh_expires_at' => 1900003603,
);

// Every prospective reservation API must preserve an authoritative manifest
// read failure as indeterminate. In particular, a failed preflight must not
// consume the request-local plan or mutate the target slot.
$prospective_store = new Managed_Backend_Secret_Store();
$prospective_manifest = $prospective_store->initialize_classified();
$assert_result(
    $prospective_manifest,
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_APPLIED,
    'Prospective fixture manifest initialization'
);
$prospective_plan_result = $prospective_store->prepare_reservation(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    'mutation_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
);
$prospective_plan = $prospective_plan_result->plan();
$assert(
    Atomic_Option_Plan_Result::READY === $prospective_plan_result->status()
        && null !== $prospective_plan,
    'Prospective reservation fixture did not produce an exact plan.'
);
$prospective_evidence = $prospective_plan->evidence();
$prospective_option = $record_option($secret_ref);
$mutations_before_manifest_failures = count($GLOBALS['wpdb']->mutations);

$GLOBALS['wpdb']->failed_reads[Managed_Backend_Secret_Store::OPTION] = 1;
$initialize_manifest_failure = $prospective_store->initialize_classified();
$assert_result(
    $initialize_manifest_failure,
    Atomic_Option_Result::INDETERMINATE,
    Atomic_Option_Result::MUTATION_NONE,
    'Prospective manifest initialization read failure'
);

$GLOBALS['wpdb']->failed_reads[Managed_Backend_Secret_Store::OPTION] = 1;
$prepare_manifest_failure = $prospective_store->prepare_reservation(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    'mutation_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
);
$assert(
    Atomic_Option_Plan_Result::INDETERMINATE === $prepare_manifest_failure->status()
        && null === $prepare_manifest_failure->plan(),
    'Prospective reservation planning collapsed a manifest read failure.'
);

$GLOBALS['wpdb']->failed_reads[Managed_Backend_Secret_Store::OPTION] = 1;
$apply_manifest_failure = $prospective_store->apply_reservation_plan(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $prospective_plan
);
$assert_result(
    $apply_manifest_failure,
    Atomic_Option_Result::INDETERMINATE,
    Atomic_Option_Result::MUTATION_NONE,
    'Prospective reservation apply manifest read failure'
);

$GLOBALS['wpdb']->failed_reads[Managed_Backend_Secret_Store::OPTION] = 1;
$probe_manifest_failure = $prospective_store->probe_reservation(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $prospective_evidence
);
$assert(
    Atomic_Option_Store::PROBE_INDETERMINATE === $probe_manifest_failure,
    'Prospective reservation probe collapsed a manifest read failure.'
);

$GLOBALS['wpdb']->failed_reads[Managed_Backend_Secret_Store::OPTION] = 1;
$reconcile_manifest_failure = $prospective_store->reconcile_reservation(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $prospective_evidence
);
$assert_result(
    $reconcile_manifest_failure,
    Atomic_Option_Result::INDETERMINATE,
    Atomic_Option_Result::MUTATION_NONE,
    'Prospective reservation reconcile manifest read failure'
);
$assert(
    $mutations_before_manifest_failures === count($GLOBALS['wpdb']->mutations)
        && ! isset($GLOBALS['wpdb']->rows[$prospective_option]),
    'Manifest read failures crossed the prospective target mutation boundary.'
);

$prospective_applied = $prospective_store->apply_reservation_plan(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $prospective_plan
);
$assert_result(
    $prospective_applied,
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_APPLIED,
    'Prospective plan reuse after manifest read failure'
);

// Token commitment is a second prospective boundary. Planning requires the
// exact pending record, stores only encrypted material in request-local
// authority, and emits bounded hash evidence suitable for the journal.
$prospective_pending_raw = $GLOBALS['wpdb']->rows[$prospective_option]['option_value'];
$reordered_pending = array(
    'state'           => 'pending',
    'version'         => 2,
    'backend_id'      => $backend_id,
    'provisioning_id' => $provisioning_id,
    'generation'      => 0,
    'envelope'        => array(),
);
$GLOBALS['wpdb']->rows[$prospective_option]['option_value'] = serialize($reordered_pending);
$reordered_plan_result = $prospective_store->prepare_commit_reserved(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $secret_one,
    'mutation_abababababababababababababababab'
);
$assert(
    Atomic_Option_Plan_Result::CONFLICT === $reordered_plan_result->status()
        && null === $reordered_plan_result->plan(),
    'Commit planning accepted non-exact pending bytes that could not be reconciled later.'
);
$GLOBALS['wpdb']->rows[$prospective_option]['option_value'] = $prospective_pending_raw;

$commit_mutations_before = count($GLOBALS['wpdb']->mutations);
$commit_plan_result = $prospective_store->prepare_commit_reserved(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $secret_one,
    'mutation_cccccccccccccccccccccccccccccccc'
);
$commit_plan = $commit_plan_result->plan();
$assert(
    Atomic_Option_Plan_Result::READY === $commit_plan_result->status()
        && null !== $commit_plan,
    'Prospective secret commitment did not produce an exact plan.'
);
$assert(
    $commit_mutations_before === count($GLOBALS['wpdb']->mutations),
    'Prospective secret commitment planning mutated durable state.'
);
$commit_evidence = $commit_plan->evidence();
$assert(
    array(
        'kind',
        'mutation_id',
        'before_exists',
        'before_sha256',
        'before_bytes',
        'after_exists',
        'after_sha256',
        'after_bytes',
    ) === array_keys($commit_evidence)
    && 'secret_commit' === $commit_evidence['kind']
    && 'mutation_cccccccccccccccccccccccccccccccc' === $commit_evidence['mutation_id']
    && true === $commit_evidence['before_exists']
    && true === $commit_evidence['after_exists'],
    'Prospective secret commitment evidence shape or identity mismatch.'
);
$assert(
    Atomic_Option_Store::PROBE_BEFORE === $prospective_store->probe_commit(
        $secret_ref,
        $backend_id,
        $provisioning_id,
        $commit_evidence
    ),
    'Pending secret commitment did not probe as the exact before-state.'
);
$commit_observations = serialize(
    array(
        'evidence' => $commit_evidence,
        'debug'    => $commit_plan->__debugInfo(),
        'written'  => $commit_plan->written()->raw(),
    )
);
$assert(
    ! str_contains($commit_observations, $secret_one['access_token'])
        && ! str_contains($commit_observations, $secret_one['refresh_token']),
    'Prospective commitment evidence or plan debug output exposed token plaintext.'
);

$forged_evidence = $commit_evidence;
$forged_evidence['before_sha256'] = str_repeat('0', 64);
$assert(
    Atomic_Option_Store::PROBE_REFUSED === $prospective_store->probe_commit(
        $secret_ref,
        $backend_id,
        $provisioning_id,
        $forged_evidence
    ),
    'Commit probing accepted evidence not bound to the exact pending record.'
);

// The pending state can authenticate only the exact before side. A forged but
// structurally valid after hash therefore remains a read-only before
// classification; it cannot authorize or replay the lost request-local plan.
$forged_after_evidence = $commit_evidence;
$forged_after_evidence['after_sha256'] = str_repeat('f', 64);
$forged_after_probe_mutations = count($GLOBALS['wpdb']->mutations);
$forged_after_probe_raw = $GLOBALS['wpdb']->rows[$prospective_option]['option_value'];
$assert(
    Atomic_Option_Store::PROBE_BEFORE === $prospective_store->probe_commit(
        $secret_ref,
        $backend_id,
        $provisioning_id,
        $forged_after_evidence
    ),
    'Forged after evidence gained semantic authority while the exact pending record remained.'
);
$assert(
    $forged_after_probe_mutations === count($GLOBALS['wpdb']->mutations)
        && $forged_after_probe_raw === $GLOBALS['wpdb']->rows[$prospective_option]['option_value'],
    'Forged after evidence authorized or replayed a pending secret write.'
);

// Read failures at either authoritative option preserve an indeterminate
// classification and never consume or apply the request-local plan.
$GLOBALS['wpdb']->failed_reads[Managed_Backend_Secret_Store::OPTION] = 1;
$commit_prepare_manifest_failure = $prospective_store->prepare_commit_reserved(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $secret_one,
    'mutation_dddddddddddddddddddddddddddddddd'
);
$assert(
    Atomic_Option_Plan_Result::INDETERMINATE === $commit_prepare_manifest_failure->status()
        && null === $commit_prepare_manifest_failure->plan(),
    'Commit planning collapsed a manifest read failure.'
);

$GLOBALS['wpdb']->failed_reads[$prospective_option] = 1;
$commit_prepare_target_failure = $prospective_store->prepare_commit_reserved(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $secret_one,
    'mutation_eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'
);
$assert(
    Atomic_Option_Plan_Result::INDETERMINATE === $commit_prepare_target_failure->status()
        && null === $commit_prepare_target_failure->plan(),
    'Commit planning collapsed a target read failure.'
);

$GLOBALS['wpdb']->failed_reads[Managed_Backend_Secret_Store::OPTION] = 1;
$commit_apply_manifest_failure = $prospective_store->apply_commit_plan(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $secret_one,
    $commit_plan
);
$assert_result(
    $commit_apply_manifest_failure,
    Atomic_Option_Result::INDETERMINATE,
    Atomic_Option_Result::MUTATION_NONE,
    'Commit apply manifest read failure'
);

$GLOBALS['wpdb']->failed_reads[Managed_Backend_Secret_Store::OPTION] = 1;
$assert(
    Atomic_Option_Store::PROBE_INDETERMINATE === $prospective_store->probe_commit(
        $secret_ref,
        $backend_id,
        $provisioning_id,
        $commit_evidence
    ),
    'Commit probe collapsed a manifest read failure.'
);

$GLOBALS['wpdb']->failed_reads[$prospective_option] = 1;
$assert(
    Atomic_Option_Store::PROBE_INDETERMINATE === $prospective_store->probe_commit(
        $secret_ref,
        $backend_id,
        $provisioning_id,
        $commit_evidence
    ),
    'Commit probe collapsed a target read failure.'
);

$commit_mutations_before_refusals = count($GLOBALS['wpdb']->mutations);
$wrong_secret_apply = $prospective_store->apply_commit_plan(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $secret_two,
    $commit_plan
);
$assert_result(
    $wrong_secret_apply,
    Atomic_Option_Result::REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    'Wrong-secret prospective commit apply'
);
$wrong_binding_apply = $prospective_store->apply_commit_plan(
    $secret_ref,
    $backend_id,
    $other_provisioning_id,
    $secret_one,
    $commit_plan
);
$assert_result(
    $wrong_binding_apply,
    Atomic_Option_Result::REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    'Wrong-binding prospective commit apply'
);
$assert(
    $commit_mutations_before_refusals === count($GLOBALS['wpdb']->mutations),
    'Refused prospective commit apply crossed the target mutation boundary.'
);

// The lower-level planner is not secret-store authority. Even a structurally
// plausible plan is refused unless its encrypted ready record decrypts to the
// exact secret supplied to this store boundary.
$pending_snapshot = (new Atomic_Option_Store($prospective_option))->snapshot();
$foreign_envelope = Backend_Secret_Crypto::encrypt(
    $secret_two,
    'awvp-secret-v2|'
        . $secret_ref . '|'
        . $backend_id . '|'
        . $provisioning_id . '|1'
);
$foreign_ready = array(
    'version'         => 2,
    'state'           => 'ready',
    'backend_id'      => $backend_id,
    'provisioning_id' => $provisioning_id,
    'generation'      => 1,
    'envelope'        => $foreign_envelope,
);
$foreign_plan_result = (new Atomic_Option_Store($prospective_option))->prepare_compare_exchange(
    $pending_snapshot,
    $foreign_ready,
    'secret_commit',
    'mutation_ffffffffffffffffffffffffffffffff'
);
$foreign_plan = $foreign_plan_result->plan();
$assert(
    Atomic_Option_Plan_Result::READY === $foreign_plan_result->status()
        && null !== $foreign_plan,
    'Foreign secret commitment fixture did not produce a lower-level plan.'
);
$foreign_apply = $prospective_store->apply_commit_plan(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $secret_one,
    $foreign_plan
);
$assert_result(
    $foreign_apply,
    Atomic_Option_Result::REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    'Foreign encrypted prospective commit apply'
);

// A matching after-hash is not enough: probe_commit also authenticates and
// decrypts the exact generation-one record before reporting success.
$invalid_ready = $foreign_ready;
$invalid_ready['envelope']['ciphertext'] = 'not-valid-authenticated-ciphertext';
$invalid_plan_result = (new Atomic_Option_Store($prospective_option))->prepare_compare_exchange(
    $pending_snapshot,
    $invalid_ready,
    'secret_commit',
    'mutation_0123456789abcdef0123456789abcdef'
);
$invalid_plan = $invalid_plan_result->plan();
$assert(
    Atomic_Option_Plan_Result::READY === $invalid_plan_result->status()
        && null !== $invalid_plan,
    'Unreadable secret commitment fixture did not produce lower-level evidence.'
);
$pending_raw = $GLOBALS['wpdb']->rows[$prospective_option]['option_value'];
$GLOBALS['wpdb']->rows[$prospective_option]['option_value'] = serialize($invalid_ready);
$assert(
    Atomic_Option_Store::PROBE_REFUSED === $prospective_store->probe_commit(
        $secret_ref,
        $backend_id,
        $provisioning_id,
        $invalid_plan->evidence()
    ),
    'Commit probe accepted a matching but unauthenticated ready record.'
);
$GLOBALS['wpdb']->rows[$prospective_option]['option_value'] = $pending_raw;

$commit_applied = $prospective_store->apply_commit_plan(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $secret_one,
    $commit_plan
);
$assert_result(
    $commit_applied,
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_APPLIED,
    'Exact prospective secret commitment'
);
$assert(
    Atomic_Option_Store::PROBE_AFTER === $prospective_store->probe_commit(
        $secret_ref,
        $backend_id,
        $provisioning_id,
        $commit_evidence
    ),
    'Applied secret commitment did not probe as the exact authenticated after-state.'
);
$prospective_expected_read = $secret_one;
$prospective_expected_read['generation'] = 1;
$assert(
    $prospective_expected_read === $prospective_store->read($secret_ref, $backend_id),
    'Prospective encrypted commitment did not round-trip at generation one.'
);
$assert(
    ! str_contains($GLOBALS['wpdb']->rows[$prospective_option]['option_value'], $secret_one['access_token'])
        && ! str_contains($GLOBALS['wpdb']->rows[$prospective_option]['option_value'], $secret_one['refresh_token']),
    'Prospective encrypted commitment persisted token plaintext.'
);
$ready_replan = $prospective_store->prepare_commit_reserved(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $secret_one,
    'mutation_10101010101010101010101010101010'
);
$assert(
    Atomic_Option_Plan_Result::CONFLICT === $ready_replan->status()
        && null === $ready_replan->plan(),
    'A ready record minted a replacement initial-commit plan.'
);
$commit_plan_reuse = $prospective_store->apply_commit_plan(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $secret_one,
    $commit_plan
);
$assert_result(
    $commit_plan_reuse,
    Atomic_Option_Result::REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    'Consumed prospective commit plan reuse'
);

$ready_raw_for_probe = $GLOBALS['wpdb']->rows[$prospective_option]['option_value'];
$GLOBALS['wpdb']->rows[$prospective_option]['option_value'] = serialize($foreign_ready);
$assert(
    Atomic_Option_Store::PROBE_OTHER === $prospective_store->probe_commit(
        $secret_ref,
        $backend_id,
        $provisioning_id,
        $commit_evidence
    ),
    'Commit probe accepted a different authenticated ready record.'
);
$GLOBALS['wpdb']->rows[$prospective_option]['option_value'] = $ready_raw_for_probe;

$GLOBALS['wpdb']->failed_reads[$prospective_option] = 1;
$assert(
    Atomic_Option_Store::PROBE_INDETERMINATE === $prospective_store->probe_commit(
        $secret_ref,
        $backend_id,
        $provisioning_id,
        $commit_evidence
    ),
    'Applied commit probe collapsed an authoritative target read failure.'
);

$GLOBALS['wpdb'] = new Awvp_Managed_Secret_Fake_Wpdb();
$store = new Managed_Backend_Secret_Store();

// A missing manifest may be initialized before reserve() discovers a
// pre-existing slot. The composite result must retain that definite mutation
// instead of claiming that the whole call made no persistent change.
$preexisting_ref = 'managed_00000000000000000000000000000000';
$preexisting_provisioning = 'provision_00000000000000000000000000000000';
$preexisting_option = $record_option($preexisting_ref);
$preexisting_pending = array(
    'version'         => 2,
    'state'           => 'pending',
    'backend_id'      => $backend_id,
    'provisioning_id' => $preexisting_provisioning,
    'generation'      => 0,
    'envelope'        => array(),
);
$GLOBALS['wpdb']->rows[$preexisting_option] = array(
    'option_value' => serialize($preexisting_pending),
    'autoload'     => $expected_autoload,
);
$manifest_then_satisfied = $store->reserve(
    $preexisting_ref,
    $backend_id,
    $preexisting_provisioning
);
$assert_result(
    $manifest_then_satisfied,
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_APPLIED,
    'Manifest creation before satisfied reservation'
);
$assert(
    array('version' => 1) === $decode_row(Managed_Backend_Secret_Store::OPTION)
        && $preexisting_pending === $decode_row($preexisting_option),
    'Manifest creation changed the pre-existing satisfied reservation.'
);

$GLOBALS['wpdb'] = new Awvp_Managed_Secret_Fake_Wpdb();
$store = new Managed_Backend_Secret_Store();
$conflicting_ref = 'managed_00000000000000000000000000000001';
$conflicting_option = $record_option($conflicting_ref);
$conflicting_pending = $preexisting_pending;
$conflicting_pending['provisioning_id'] = $other_provisioning_id;
$GLOBALS['wpdb']->rows[$conflicting_option] = array(
    'option_value' => serialize($conflicting_pending),
    'autoload'     => $expected_autoload,
);
$manifest_then_conflict = $store->reserve(
    $conflicting_ref,
    $backend_id,
    $preexisting_provisioning
);
$assert_result(
    $manifest_then_conflict,
    Atomic_Option_Result::INDETERMINATE,
    Atomic_Option_Result::MUTATION_APPLIED,
    'Manifest creation before reservation conflict'
);
$assert(
    Atomic_Option_Result::PHASE_VALIDATION === $manifest_then_conflict->phase()
        && array('version' => 1) === $decode_row(Managed_Backend_Secret_Store::OPTION)
        && $conflicting_pending === $decode_row($conflicting_option),
    'Partial reservation conflict did not preserve its exact slot and manifest evidence.'
);

$GLOBALS['wpdb'] = new Awvp_Managed_Secret_Fake_Wpdb();
$store = new Managed_Backend_Secret_Store();

// Reserving the known reference first creates a non-autoloaded manifest and
// an exact empty pending row, without persisting any credential or envelope.
$reserved = $store->reserve($secret_ref, $backend_id, $provisioning_id);
$assert_result(
    $reserved,
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_APPLIED,
    'Initial reservation'
);

$manifest = $decode_row(Managed_Backend_Secret_Store::OPTION);
$assert(array('version' => 1) === $manifest, 'Managed secret manifest shape mismatch.');
$assert(
    $expected_autoload === $GLOBALS['wpdb']->rows[Managed_Backend_Secret_Store::OPTION]['autoload'],
    'Managed secret manifest must be non-autoloaded with the version-correct raw value.'
);

$main_option = $record_option($secret_ref);
$pending = $decode_row($main_option);
$assert(
    array('version', 'state', 'backend_id', 'provisioning_id', 'generation', 'envelope')
        === array_keys($pending),
    'Pending record must have the exact version-2 key set.'
);
$assert(
    2 === $pending['version']
    && 'pending' === $pending['state']
    && $backend_id === $pending['backend_id']
    && $provisioning_id === $pending['provisioning_id']
    && 0 === $pending['generation']
    && array() === $pending['envelope'],
    'Pending reservation binding or empty-envelope invariant failed.'
);
$assert(
    $expected_autoload === $GLOBALS['wpdb']->rows[$main_option]['autoload'],
    'Pending record must be non-autoloaded.'
);
$assert(
    ! str_contains($GLOBALS['wpdb']->rows[$main_option]['option_value'], 'cipher')
    && ! str_contains($GLOBALS['wpdb']->rows[$main_option]['option_value'], 'nonce')
    && ! str_contains($GLOBALS['wpdb']->rows[$main_option]['option_value'], $secret_one['access_token'])
    && ! str_contains($GLOBALS['wpdb']->rows[$main_option]['option_value'], $secret_one['refresh_token']),
    'Pending record must contain neither an envelope nor plaintext token material.'
);
$assert(
    array('state' => Managed_Backend_Secret_Store::PROVISION_PENDING, 'generation' => 0)
        === $store->provisioning_state($secret_ref, $backend_id, $provisioning_id),
    'Reserved slot must report pending generation zero.'
);
$assert(null === $store->read($secret_ref, $backend_id), 'Pending records must not be readable as secrets.');

$mutation_count = count($GLOBALS['wpdb']->mutations);
$same_reservation = $store->reserve($secret_ref, $backend_id, $provisioning_id);
$assert_result(
    $same_reservation,
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_NONE,
    'Idempotent reservation'
);
$assert(
    $mutation_count === count($GLOBALS['wpdb']->mutations),
    'Idempotent reservation must not issue a mutation.'
);
$binding_conflict = $store->reserve($secret_ref, $other_backend_id, $provisioning_id);
$assert_result(
    $binding_conflict,
    Atomic_Option_Result::CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    'Backend reservation conflict'
);
$assert(
    Atomic_Option_Result::PHASE_VALIDATION === $binding_conflict->phase(),
    'Backend reservation conflict must be classified at validation.'
);
$provisioning_conflict = $store->reserve($secret_ref, $backend_id, $other_provisioning_id);
$assert_result(
    $provisioning_conflict,
    Atomic_Option_Result::CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    'Provisioning reservation conflict'
);
$assert(
    Atomic_Option_Result::PHASE_VALIDATION === $provisioning_conflict->phase(),
    'Provisioning reservation conflict must be classified at validation.'
);
$assert($pending === $decode_row($main_option), 'Reservation conflicts must preserve exact pending state.');

// A structurally valid but autoloaded pending row is not a satisfied
// reservation. Repair is a separate operation followed by a fresh snapshot.
$GLOBALS['wpdb']->rows[$main_option]['autoload'] = 'yes';
$autoloaded_pending = $store->reserve($secret_ref, $backend_id, $provisioning_id);
$assert_result(
    $autoloaded_pending,
    Atomic_Option_Result::REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    'Autoloaded pending reservation'
);
$assert(
    array('state' => Managed_Backend_Secret_Store::PROVISION_UNREADABLE, 'generation' => 0)
        === $store->provisioning_state($secret_ref, $backend_id, $provisioning_id),
    'Autoloaded pending row must not be exposed as provisioned.'
);
$assert(
    Atomic_Option_Result::REFUSED
        === $store->delete_reserved_if_pending($secret_ref, $backend_id, $provisioning_id)->status(),
    'Autoloaded pending cleanup must be refused rather than silently repaired.'
);
$GLOBALS['wpdb']->rows[$main_option]['autoload'] = $expected_autoload;

// Only the exact reservation may become an encrypted ready record at
// generation one. Reads enforce the backend binding and expose no envelope.
$committed = $store->commit_reserved(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $secret_one
);
$assert_result(
    $committed,
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_APPLIED,
    'Exact reservation commit'
);
$ready_raw = $GLOBALS['wpdb']->rows[$main_option]['option_value'];
$ready = $decode_row($main_option);
$assert(
    array('version', 'state', 'backend_id', 'provisioning_id', 'generation', 'envelope')
        === array_keys($ready),
    'Ready record must retain the exact version-2 key set.'
);
$assert(
    2 === $ready['version']
    && 'ready' === $ready['state']
    && $backend_id === $ready['backend_id']
    && $provisioning_id === $ready['provisioning_id']
    && 1 === $ready['generation']
    && is_array($ready['envelope'])
    && array() !== $ready['envelope'],
    'Committed ready record shape or generation mismatch.'
);
$assert(
    ! str_contains($ready_raw, $secret_one['access_token'])
    && ! str_contains($ready_raw, $secret_one['refresh_token']),
    'Ready record persisted plaintext token material.'
);
$expected_read_one = $secret_one;
$expected_read_one['generation'] = 1;
$assert(
    $expected_read_one === $store->read($secret_ref, $backend_id),
    'Encrypted ready record did not round-trip through the exact backend binding.'
);
$assert(null === $store->read($secret_ref, $other_backend_id), 'Wrong backend binding must not read a managed secret.');
$assert(
    array('state' => Managed_Backend_Secret_Store::PROVISION_READY, 'generation' => 1)
        === $store->provisioning_state($secret_ref, $backend_id, $provisioning_id),
    'Committed slot must report ready generation one.'
);
$assert(
    array('state' => Managed_Backend_Secret_Store::PROVISION_CONFLICT, 'generation' => 0)
        === $store->provisioning_state($secret_ref, $backend_id, $other_provisioning_id),
    'Wrong provisioning binding must report a conflict without leaking generation.'
);
$assert(
    array('state' => Managed_Backend_Secret_Store::PROVISION_ABSENT, 'generation' => 0)
        === $store->provisioning_state(
            'managed_44444444444444444444444444444444',
            $backend_id,
            $provisioning_id
        ),
    'Missing reserved slot must report absent.'
);

// A ready row with unsupported autoload state is neither an idempotently
// committed result nor a readable secret.
$GLOBALS['wpdb']->rows[$main_option]['autoload'] = 'yes';
$autoloaded_ready_commit = $store->commit_reserved(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $secret_one
);
$assert_result(
    $autoloaded_ready_commit,
    Atomic_Option_Result::REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    'Autoloaded ready commit'
);
$assert(null === $store->read($secret_ref, $backend_id), 'Autoloaded ready secret must not be readable.');
$assert(
    array('state' => Managed_Backend_Secret_Store::PROVISION_UNREADABLE, 'generation' => 0)
        === $store->provisioning_state($secret_ref, $backend_id, $provisioning_id),
    'Autoloaded ready row must report unreadable without a generation claim.'
);
$assert(
    Atomic_Option_Result::REFUSED
        === $store->replace_classified($secret_ref, $backend_id, $secret_two, 1)->status(),
    'Autoloaded ready replacement must be refused.'
);
$assert(
    Atomic_Option_Result::REFUSED
        === $store->delete_classified($secret_ref, $backend_id, 1)->status(),
    'Autoloaded ready deletion must be refused.'
);
$GLOBALS['wpdb']->rows[$main_option]['autoload'] = $expected_autoload;

$mutation_count = count($GLOBALS['wpdb']->mutations);
$same_commit = $store->commit_reserved(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $secret_one
);
$assert_result(
    $same_commit,
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_NONE,
    'Idempotent ready commit'
);
$assert(
    $mutation_count === count($GLOBALS['wpdb']->mutations)
    && $ready_raw === $GLOBALS['wpdb']->rows[$main_option]['option_value'],
    'Idempotent commit must preserve exact ready bytes without mutation.'
);
$different_commit = $store->commit_reserved(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $secret_two
);
$assert_result(
    $different_commit,
    Atomic_Option_Result::CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    'Different-secret ready commit'
);
$assert(
    $ready_raw === $GLOBALS['wpdb']->rows[$main_option]['option_value'],
    'Different-secret commit conflict must preserve exact ready bytes.'
);

// Safe reconciliation distinguishes authenticated-envelope tampering,
// structurally unreadable bytes, and an indeterminate database read.
$tampered = $ready;
$ciphertext = (string) ($tampered['envelope']['ciphertext'] ?? '');
$assert('' !== $ciphertext, 'Ready test envelope must contain ciphertext.');
$tampered['envelope']['ciphertext'] = ('A' === $ciphertext[0] ? 'B' : 'A')
    . substr($ciphertext, 1);
$GLOBALS['wpdb']->rows[$main_option]['option_value'] = serialize($tampered);
$assert(
    array('state' => Managed_Backend_Secret_Store::PROVISION_UNREADABLE, 'generation' => 1)
        === $store->provisioning_state($secret_ref, $backend_id, $provisioning_id),
    'Authenticated-envelope tampering must report unreadable at the stored generation.'
);
$assert(null === $store->read($secret_ref, $backend_id), 'Tampered envelope must never decrypt.');

$GLOBALS['wpdb']->rows[$main_option]['option_value'] = 'not-a-canonical-serialized-array';
$assert(
    array('state' => Managed_Backend_Secret_Store::PROVISION_UNREADABLE, 'generation' => 0)
        === $store->provisioning_state($secret_ref, $backend_id, $provisioning_id),
    'Structurally unreadable row must report unreadable without a generation claim.'
);

$GLOBALS['wpdb']->rows[$main_option]['option_value'] = $ready_raw;
$GLOBALS['wpdb']->failed_reads[$main_option] = 1;
$assert(
    array('state' => Managed_Backend_Secret_Store::PROVISION_INDETERMINATE, 'generation' => 0)
        === $store->provisioning_state($secret_ref, $backend_id, $provisioning_id),
    'Database read failure must report indeterminate without a generation claim.'
);
$assert(
    $expected_read_one === $store->read($secret_ref, $backend_id),
    'A consumed synthetic read failure must leave durable ready state untouched.'
);

// Exact-generation replacement advances monotonically and rejects stale
// observations without altering the winner.
$replaced = $store->replace_classified($secret_ref, $backend_id, $secret_two, 1);
$assert_result(
    $replaced,
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_APPLIED,
    'Exact-generation replacement'
);
$expected_read_two_generation_two = $secret_two;
$expected_read_two_generation_two['generation'] = 2;
$assert(
    $expected_read_two_generation_two === $store->read($secret_ref, $backend_id),
    'Exact replacement must round-trip at generation two.'
);
$generation_two_raw = $GLOBALS['wpdb']->rows[$main_option]['option_value'];
$generation_two_replay = $store->commit_reserved(
    $secret_ref,
    $backend_id,
    $provisioning_id,
    $secret_two
);
$assert_result(
    $generation_two_replay,
    Atomic_Option_Result::CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    'Generation-two initial-commit replay'
);
$assert(
    Atomic_Option_Result::PHASE_VALIDATION === $generation_two_replay->phase(),
    'Generation-two initial-commit replay must conflict at validation.'
);
$assert(
    $generation_two_raw === $GLOBALS['wpdb']->rows[$main_option]['option_value'],
    'Initial-commit replay changed a later secret generation.'
);
$stale_replace = $store->replace_classified($secret_ref, $backend_id, $secret_three, 1);
$assert_result(
    $stale_replace,
    Atomic_Option_Result::CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    'Stale exact-generation replacement'
);
$assert(
    Atomic_Option_Result::PHASE_VALIDATION === $stale_replace->phase(),
    'Pre-snapshot stale replacement must conflict at validation.'
);
$assert(
    $expected_read_two_generation_two === $store->read($secret_ref, $backend_id),
    'Stale replacement must preserve the current winner.'
);

$reserve_and_commit = static function (
    string $ref,
    string $provision
) use ($assert_result, $backend_id, $secret_one, $store): void {
    $assert_result(
        $store->reserve($ref, $backend_id, $provision),
        Atomic_Option_Result::APPLIED,
        Atomic_Option_Result::MUTATION_APPLIED,
        'Race fixture reservation'
    );
    $assert_result(
        $store->commit_reserved($ref, $backend_id, $provision, $secret_one),
        Atomic_Option_Result::APPLIED,
        Atomic_Option_Result::MUTATION_APPLIED,
        'Race fixture commit'
    );
};

// Deterministically inject one replacement during another writer's pre-action.
// The inner exact CAS wins; the outer writer must detect the hook-side target
// change before SQL and retain unknown mutation authority.
$race_ref = 'managed_55555555555555555555555555555555';
$race_provision = 'provision_66666666666666666666666666666666';
$reserve_and_commit($race_ref, $race_provision);
$race_option = $record_option($race_ref);
$inner_replace = null;
$GLOBALS['awvp_managed_secret_action_callbacks']['update_option'] = static function (
    string $option
) use (&$inner_replace, $backend_id, $race_option, $race_ref, $secret_two, $store): void {
    if ($race_option !== $option) {
        return;
    }

    unset($GLOBALS['awvp_managed_secret_action_callbacks']['update_option']);
    $inner_replace = $store->replace_classified($race_ref, $backend_id, $secret_two, 1);
};
$outer_replace = $store->replace_classified($race_ref, $backend_id, $secret_three, 1);
$assert($inner_replace instanceof Atomic_Option_Result, 'Concurrent replacement callback did not run.');
$assert_result(
    $inner_replace,
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_APPLIED,
    'Concurrent winning replacement'
);
$assert_result(
    $outer_replace,
    Atomic_Option_Result::INDETERMINATE,
    Atomic_Option_Result::MUTATION_UNKNOWN,
    'Hook-side replacement'
);
$assert(
    Atomic_Option_Result::PHASE_PRE_ACTION === $outer_replace->phase(),
    'Hook-side replacement must be classified before the outer SQL statement.'
);
$assert(
    $expected_read_two_generation_two === $store->read($race_ref, $backend_id),
    'Two-writer CAS must preserve the inner replacement winner.'
);

// The same pre-action guard prevents a delete from erasing a replacement made
// by its own normally returning delete hook.
$delete_race_ref = 'managed_77777777777777777777777777777777';
$delete_race_provision = 'provision_88888888888888888888888888888888';
$reserve_and_commit($delete_race_ref, $delete_race_provision);
$delete_race_option = $record_option($delete_race_ref);
$inner_delete_race_replace = null;
$GLOBALS['awvp_managed_secret_action_callbacks']['delete_option'] = static function (
    string $option
) use (
    &$inner_delete_race_replace,
    $backend_id,
    $delete_race_option,
    $delete_race_ref,
    $secret_two,
    $store
): void {
    if ($delete_race_option !== $option) {
        return;
    }

    unset($GLOBALS['awvp_managed_secret_action_callbacks']['delete_option']);
    $inner_delete_race_replace = $store->replace_classified(
        $delete_race_ref,
        $backend_id,
        $secret_two,
        1
    );
};
$stale_delete = $store->delete_classified($delete_race_ref, $backend_id, 1);
$assert(
    $inner_delete_race_replace instanceof Atomic_Option_Result,
    'Delete-versus-replace callback did not run.'
);
$assert_result(
    $inner_delete_race_replace,
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_APPLIED,
    'Delete-race winning replacement'
);
$assert_result(
    $stale_delete,
    Atomic_Option_Result::INDETERMINATE,
    Atomic_Option_Result::MUTATION_UNKNOWN,
    'Delete-hook replacement'
);
$assert(
    Atomic_Option_Result::PHASE_PRE_ACTION === $stale_delete->phase(),
    'Delete-hook replacement must be classified before delete SQL.'
);
$assert(
    $expected_read_two_generation_two === $store->read($delete_race_ref, $backend_id),
    'Stale delete must preserve a concurrent replacement.'
);

// Generation is a caller precondition as well as a SQL-time byte predicate.
// A replacement completed before delete() begins must still protect the newer
// generation from a caller that observed generation one.
$predelete_ref = 'managed_eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
$predelete_provision = 'provision_ffffffffffffffffffffffffffffffff';
$reserve_and_commit($predelete_ref, $predelete_provision);
$assert_result(
    $store->replace_classified($predelete_ref, $backend_id, $secret_two, 1),
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_APPLIED,
    'Pre-delete replacement'
);
$predelete_option = $record_option($predelete_ref);
$predelete_generation_two = $GLOBALS['wpdb']->rows[$predelete_option]['option_value'];
$predelete_stale = $store->delete_classified($predelete_ref, $backend_id, 1);
$assert_result(
    $predelete_stale,
    Atomic_Option_Result::CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    'Pre-snapshot stale delete'
);
$assert(
    Atomic_Option_Result::PHASE_VALIDATION === $predelete_stale->phase(),
    'Pre-snapshot stale delete must conflict at validation.'
);
$assert(
    $predelete_generation_two === $GLOBALS['wpdb']->rows[$predelete_option]['option_value'],
    'A caller that observed generation one deleted generation two.'
);
$assert(
    ! $store->delete($predelete_ref, $backend_id, 1),
    'Boolean deletion accepted a stale caller generation.'
);
$assert(
    $store->delete($predelete_ref, $backend_id, 2),
    'Boolean deletion refused the exact current generation.'
);
$assert(
    ! array_key_exists($predelete_option, $GLOBALS['wpdb']->rows),
    'Exact generation-bound deletion left the ready row behind.'
);

// Generic deletion must refuse a pending slot. Only exact reservation-bound
// cleanup can remove it, and the same cleanup is idempotent once absent.
$pending_ref = 'managed_99999999999999999999999999999999';
$pending_provision = 'provision_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$assert_result(
    $store->reserve($pending_ref, $backend_id, $pending_provision),
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_APPLIED,
    'Cleanup fixture reservation'
);
$pending_option = $record_option($pending_ref);
$generic_pending_delete = $store->delete_classified($pending_ref, $backend_id, 1);
$assert_result(
    $generic_pending_delete,
    Atomic_Option_Result::CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    'Generic pending delete'
);
$assert(
    Atomic_Option_Result::PHASE_VALIDATION === $generic_pending_delete->phase(),
    'Generic pending delete must conflict at validation.'
);
$assert(! $store->delete($pending_ref, $backend_id, 1), 'Boolean generic delete must refuse pending state.');
$assert(array_key_exists($pending_option, $GLOBALS['wpdb']->rows), 'Generic pending delete removed the reservation.');
$wrong_cleanup = $store->delete_reserved_if_pending(
    $pending_ref,
    $backend_id,
    $other_provisioning_id
);
$assert_result(
    $wrong_cleanup,
    Atomic_Option_Result::CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    'Wrong-binding pending cleanup'
);
$exact_cleanup = $store->delete_reserved_if_pending(
    $pending_ref,
    $backend_id,
    $pending_provision
);
$assert_result(
    $exact_cleanup,
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_APPLIED,
    'Exact pending cleanup'
);
$assert(! array_key_exists($pending_option, $GLOBALS['wpdb']->rows), 'Exact pending cleanup left the row behind.');
$repeat_cleanup = $store->delete_reserved_if_pending(
    $pending_ref,
    $backend_id,
    $pending_provision
);
$assert_result(
    $repeat_cleanup,
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_NONE,
    'Idempotent absent pending cleanup'
);
$ready_cleanup = $store->delete_reserved_if_pending(
    $secret_ref,
    $backend_id,
    $provisioning_id
);
$assert_result(
    $ready_cleanup,
    Atomic_Option_Result::CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    'Ready-row pending cleanup'
);

// A persistent fence closes the deletion ABA: neither an older absent-to-
// pending reservation plan nor reserve() may recreate the exact pending bytes.
$absent_fence_ref = 'managed_12121212121212121212121212121212';
$absent_fence_provision = 'provision_34343434343434343434343434343434';
$old_reservation = $store->prepare_reservation(
    $absent_fence_ref,
    $backend_id,
    $absent_fence_provision,
    'mutation_12121212121212121212121212121212'
);
$old_reservation_plan = $old_reservation->plan();
$absent_fence = $store->prepare_fence_reserved(
    $absent_fence_ref,
    $backend_id,
    $absent_fence_provision,
    'mutation_34343434343434343434343434343434'
);
$absent_fence_plan = $absent_fence->plan();
$assert(
    Atomic_Option_Plan_Result::READY === $old_reservation->status()
        && null !== $old_reservation_plan
        && Atomic_Option_Plan_Result::READY === $absent_fence->status()
        && null !== $absent_fence_plan,
    'Absent fence fixture did not prospectively prepare both exact plans.'
);
$assert_result(
    $store->apply_fence_plan(
        $absent_fence_ref,
        $backend_id,
        $absent_fence_provision,
        $absent_fence_plan
    ),
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_APPLIED,
    'Absent-to-fenced apply'
);
$assert(
    array('state' => Managed_Backend_Secret_Store::PROVISION_FENCED, 'generation' => 0)
        === $store->provisioning_state(
            $absent_fence_ref,
            $backend_id,
            $absent_fence_provision
        ),
    'Absent-to-fenced apply did not leave the exact durable marker.'
);
$assert_result(
    $store->apply_reservation_plan(
        $absent_fence_ref,
        $backend_id,
        $absent_fence_provision,
        $old_reservation_plan
    ),
    Atomic_Option_Result::CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    'Fenced stale reservation apply'
);
$assert_result(
    $store->reserve($absent_fence_ref, $backend_id, $absent_fence_provision),
    Atomic_Option_Result::CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    'Fenced direct reservation'
);
$assert_result(
    $store->apply_fence_plan(
        $absent_fence_ref,
        $backend_id,
        $other_provisioning_id,
        $absent_fence_plan
    ),
    Atomic_Option_Result::REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    'Wrong-binding fence plan'
);

// The same marker wins an exact pending-to-fenced race and invalidates a
// still-live request-local pending-to-ready token plan.
$pending_fence_ref = 'managed_56565656565656565656565656565656';
$pending_fence_provision = 'provision_78787878787878787878787878787878';
$assert_result(
    $store->reserve($pending_fence_ref, $backend_id, $pending_fence_provision),
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_APPLIED,
    'Pending fence fixture reservation'
);
$old_commit = $store->prepare_commit_reserved(
    $pending_fence_ref,
    $backend_id,
    $pending_fence_provision,
    $secret_three,
    'mutation_56565656565656565656565656565656'
);
$old_commit_plan = $old_commit->plan();
$pending_fence = $store->prepare_fence_reserved(
    $pending_fence_ref,
    $backend_id,
    $pending_fence_provision,
    'mutation_78787878787878787878787878787878'
);
$pending_fence_plan = $pending_fence->plan();
$assert(
    Atomic_Option_Plan_Result::READY === $old_commit->status()
        && null !== $old_commit_plan
        && Atomic_Option_Plan_Result::READY === $pending_fence->status()
        && null !== $pending_fence_plan,
    'Pending fence fixture did not prospectively prepare both exact plans.'
);
$assert_result(
    $store->apply_fence_plan(
        $pending_fence_ref,
        $backend_id,
        $pending_fence_provision,
        $pending_fence_plan
    ),
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_APPLIED,
    'Pending-to-fenced apply'
);
$assert_result(
    $store->apply_commit_plan(
        $pending_fence_ref,
        $backend_id,
        $pending_fence_provision,
        $secret_three,
        $old_commit_plan
    ),
    Atomic_Option_Result::CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    'Fenced stale token commit apply'
);
$assert(
    array('state' => Managed_Backend_Secret_Store::PROVISION_FENCED, 'generation' => 0)
        === $store->provisioning_state(
            $pending_fence_ref,
            $backend_id,
            $pending_fence_provision
        )
        && null === $store->read($pending_fence_ref, $backend_id),
    'Pending-to-fenced recovery exposed a credential or lost its marker.'
);
unset(
    $old_reservation,
    $old_reservation_plan,
    $absent_fence,
    $absent_fence_plan,
    $old_commit,
    $old_commit_plan,
    $pending_fence,
    $pending_fence_plan
);

// Existing version-1 records remain readable and replacement preserves their
// exact legacy shape while advancing the generation under legacy AAD.
$legacy_ref = 'managed_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
$legacy_option = $record_option($legacy_ref);
$legacy_envelope = Backend_Secret_Crypto::encrypt(
    $secret_one,
    'awvp-secret|' . $legacy_ref . '|' . $backend_id . '|1'
);
$legacy_record = array(
    'version'    => 1,
    'backend_id' => $backend_id,
    'generation' => 1,
    'envelope'   => $legacy_envelope,
);
$GLOBALS['wpdb']->rows[$legacy_option] = array(
    'option_value' => serialize($legacy_record),
    'autoload'     => $expected_autoload,
);
$assert(
    $expected_read_one === $store->read($legacy_ref, $backend_id),
    'Legacy version-1 record must remain readable.'
);
$legacy_replace = $store->replace_classified($legacy_ref, $backend_id, $secret_two, 1);
$assert_result(
    $legacy_replace,
    Atomic_Option_Result::APPLIED,
    Atomic_Option_Result::MUTATION_APPLIED,
    'Legacy exact-generation replacement'
);
$legacy_ready = $decode_row($legacy_option);
$assert(
    array('version', 'backend_id', 'generation', 'envelope') === array_keys($legacy_ready)
    && 1 === $legacy_ready['version']
    && 2 === $legacy_ready['generation'],
    'Legacy replacement must preserve the exact version-1 record shape.'
);
$assert(
    $expected_read_two_generation_two === $store->read($legacy_ref, $backend_id),
    'Legacy replacement must round-trip at generation two.'
);
$legacy_stale = $store->replace_classified($legacy_ref, $backend_id, $secret_three, 1);
$assert_result(
    $legacy_stale,
    Atomic_Option_Result::CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    'Legacy stale replacement'
);

// Persistence rows, option actions, and cache traces may contain encrypted
// envelopes and identifiers, but never token plaintext.
$durable_observations = serialize(
    array(
        'rows'          => $GLOBALS['wpdb']->rows,
        'actions'       => $GLOBALS['awvp_managed_secret_actions'],
        'cache_deletes' => $GLOBALS['awvp_managed_secret_cache_deletes'],
    )
);
foreach (array($secret_one, $secret_two, $secret_three) as $secret) {
    $assert(
        ! str_contains($durable_observations, $secret['access_token'])
        && ! str_contains($durable_observations, $secret['refresh_token']),
        'A raw persistence observation exposed plaintext token material.'
    );
}

fwrite(
    STDOUT,
    'MANAGED_SECRET_PERSISTENCE=PASS AUTOLOAD=' . $expected_autoload . PHP_EOL
);
