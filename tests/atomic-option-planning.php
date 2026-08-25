<?php
/**
 * Focused dependency-free tests for prospective atomic option mutations.
 *
 * Run once without AWVP_ATOMIC_MODERN_AUTOLOAD to model WordPress 6.4/6.5
 * (`no`), and once with it set to 1 to model WordPress 6.6+ (`off`).
 */

declare(strict_types=1);

define('ARRAY_A', 'ARRAY_A');

if ('1' === getenv('AWVP_ATOMIC_MODERN_AUTOLOAD')) {
    function wp_autoload_values_to_autoload(): array
    {
        return array('yes', 'on', 'auto-on', 'auto');
    }
}

$GLOBALS['awvp_plan_actions'] = array();
$GLOBALS['awvp_plan_cache_deletes'] = array();

function do_action(string $hook, mixed ...$arguments): void
{
    $GLOBALS['awvp_plan_actions'][] = array($hook, $arguments);
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    $GLOBALS['awvp_plan_cache_deletes'][] = array($key, $group);
    return true;
}

final class Awvp_Atomic_Planning_Fake_Wpdb
{
    public string $options = 'wp_options';
    public string $last_error = '';

    /** @var array<string, array{option_value:string,autoload:string}> */
    public array $rows = array();

    /** @var array<string, array{template:string,args:list<mixed>}> */
    public array $prepared = array();

    /** @var list<array{template:string,args:list<mixed>}> */
    public array $mutations = array();

    public bool $fail_next_read = false;

    private int $query_id = 0;

    public function prepare(string $query, mixed ...$arguments): string
    {
        $token = 'awvp-plan-prepared-' . (++$this->query_id);
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

        if ($this->fail_next_read) {
            $this->fail_next_read = false;
            $this->last_error = 'synthetic planning read failure';
            return null;
        }

        $prepared = $this->prepared[$query] ?? null;
        if (! is_array($prepared) || ! str_starts_with(ltrim($prepared['template']), 'SELECT ')) {
            throw new RuntimeException('Unexpected planning fixture read query.');
        }

        $this->last_error = '';
        $maximum_bytes = (int) ($prepared['args'][0] ?? 0);
        $option = (string) ($prepared['args'][2] ?? '');
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
            throw new RuntimeException('Unexpected planning fixture mutation query.');
        }

        $this->mutations[] = $prepared;
        $this->last_error = '';
        $template = ltrim($prepared['template']);
        $arguments = $prepared['args'];

        if (str_starts_with($template, 'INSERT INTO ')) {
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

        if (str_starts_with($template, 'UPDATE ')) {
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

        throw new RuntimeException('Unsupported planning fixture mutation query.');
    }
}

require_once dirname(__DIR__) . '/includes/Atomic_Option_Snapshot.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Result.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Mutation_Plan.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Plan_Result.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Store.php';

use ArgentVideo\Atomic_Option_Mutation_Plan;
use ArgentVideo\Atomic_Option_Plan_Result;
use ArgentVideo\Atomic_Option_Result;
use ArgentVideo\Atomic_Option_Snapshot;
use ArgentVideo\Atomic_Option_Store;

$option = 'argentwolf_video_processor_atomic_planning_test';
$other_option = 'argentwolf_video_processor_atomic_planning_other';
$expected_autoload = function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no';
$autoloaded_value = function_exists('wp_autoload_values_to_autoload') ? 'on' : 'yes';
$mutation_one = 'mutation_' . str_repeat('1', 32);
$mutation_two = 'mutation_' . str_repeat('2', 32);

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$reset = static function (int $maximum_bytes = Atomic_Option_Store::MAX_SERIALIZED_BYTES) use (
    $option
): Atomic_Option_Store {
    $GLOBALS['wpdb'] = new Awvp_Atomic_Planning_Fake_Wpdb();
    $GLOBALS['awvp_plan_actions'] = array();
    $GLOBALS['awvp_plan_cache_deletes'] = array();
    return new Atomic_Option_Store($option, $maximum_bytes);
};

$assert_no_mutation = static function (string $message) use ($assert): void {
    $assert(
        [] === $GLOBALS['wpdb']->mutations
            && [] === $GLOBALS['awvp_plan_actions']
            && [] === $GLOBALS['awvp_plan_cache_deletes'],
        $message
    );
};

$exact_evidence_keys = array(
    'kind',
    'mutation_id',
    'before_exists',
    'before_sha256',
    'before_bytes',
    'after_exists',
    'after_sha256',
    'after_bytes',
);

// An absent-row plan is deterministic evidence only and does not mutate.
$store = $reset();
$absent = $store->snapshot();
$reservation = array(
    'version'         => 2,
    'state'           => 'pending',
    'backend_id'      => 'planning-backend',
    'provisioning_id' => 'provision_' . str_repeat('a', 32),
    'generation'      => 0,
    'envelope'        => array(),
);
$prepared_absent = $store->prepare_compare_exchange(
    $absent,
    $reservation,
    'secret_reserve',
    $mutation_one
);
$assert(
    Atomic_Option_Plan_Result::READY === $prepared_absent->status(),
    'An exact absent reservation did not produce a ready plan.'
);
$absent_plan = $prepared_absent->plan();
$assert($absent_plan instanceof Atomic_Option_Mutation_Plan, 'A ready result did not contain a mutation plan.');
$absent_raw = serialize($reservation);
$absent_evidence = $absent_plan->evidence();
$assert(array_keys($absent_evidence) === $exact_evidence_keys, 'Plan evidence keys/order changed unexpectedly.');
$assert(
    array(
        'kind'          => 'secret_reserve',
        'mutation_id'   => $mutation_one,
        'before_exists' => false,
        'before_sha256' => '',
        'before_bytes'  => 0,
        'after_exists'  => true,
        'after_sha256'  => hash('sha256', $absent_raw),
        'after_bytes'   => strlen($absent_raw),
    ) === $absent_evidence,
    'Absent plan evidence did not exactly describe the canonical serialized bytes.'
);
$assert(
    $option === $absent_plan->option()
        && 'secret_reserve' === $absent_plan->kind()
        && $mutation_one === $absent_plan->mutation_id()
        && $absent === $absent_plan->before()
        && $absent_raw === $absent_plan->written()->raw()
        && $reservation === $absent_plan->written()->value()
        && $expected_autoload === $absent_plan->written()->autoload(),
    'The absent plan did not retain its exact request-local authority.'
);

ob_start();
var_dump($prepared_absent);
$debug_dump = (string) ob_get_clean();
$printed = print_r($prepared_absent, true);
$exported = var_export($prepared_absent, true);
$assert(
    ! str_contains($debug_dump, 'planning-backend')
        && ! str_contains($debug_dump, $reservation['provisioning_id'])
        && ! str_contains($printed, 'planning-backend')
        && ! str_contains($printed, $reservation['provisioning_id'])
        && ! str_contains($exported, 'planning-backend')
        && ! str_contains($exported, $reservation['provisioning_id']),
    'Debug output exposed request-local planned values.'
);
$assert_no_mutation('Preparing an absent mutation changed WordPress state or caches.');
$assert(
    Atomic_Option_Store::PROBE_BEFORE === $store->probe_evidence($absent_evidence),
    'Absent authoritative state did not probe as the planned before state.'
);

$serialize_refused = false;
try {
    serialize($absent_plan);
} catch (LogicException) {
    $serialize_refused = true;
}
$assert($serialize_refused, 'A request-local mutation plan was serializable.');

$clone_refused = false;
try {
    clone $absent_plan;
} catch (Error) {
    $clone_refused = true;
}
$assert($clone_refused, 'A request-local mutation plan was cloneable.');

// Even a caller-constructed plan must not bypass the evidence reference ban.
$forged_hash = $absent_evidence['after_sha256'];
$forged_evidence = $absent_evidence;
$forged_evidence['after_sha256'] = &$forged_hash;
$forged_plan = Atomic_Option_Mutation_Plan::create(
    $absent_plan->option(),
    $absent_plan->kind(),
    $absent_plan->mutation_id(),
    $absent_plan->before(),
    $absent_plan->written(),
    $forged_evidence
);
$assert(
    Atomic_Option_Result::REFUSED === $store->apply_plan($forged_plan)->status(),
    'Reference-bearing caller-constructed plan evidence reached CAS.'
);
$assert_no_mutation('Reference-bearing caller-constructed plan reached mutation side effects.');

// A present-row plan binds both exact before and after bytes, applies once,
// and becomes reconcilable from its bounded evidence.
$store = $reset();
$old_value = array('version' => 1, 'marker' => 'old');
$new_value = array('version' => 1, 'marker' => 'new');
$old_raw = serialize($old_value);
$new_raw = serialize($new_value);
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => $old_raw,
    'autoload'     => $expected_autoload,
);
$present = $store->snapshot();
$prepared_update = $store->prepare_compare_exchange(
    $present,
    $new_value,
    'registry_link',
    $mutation_two
);
$assert(Atomic_Option_Plan_Result::READY === $prepared_update->status(), 'Exact update plan was not ready.');
$update_plan = $prepared_update->plan();
$assert($update_plan instanceof Atomic_Option_Mutation_Plan, 'Exact update plan was unavailable.');
$update_evidence = $update_plan->evidence();
$assert(
    array(
        'kind'          => 'registry_link',
        'mutation_id'   => $mutation_two,
        'before_exists' => true,
        'before_sha256' => hash('sha256', $old_raw),
        'before_bytes'  => strlen($old_raw),
        'after_exists'  => true,
        'after_sha256'  => hash('sha256', $new_raw),
        'after_bytes'   => strlen($new_raw),
    ) === $update_evidence,
    'Present plan evidence did not bind exact before/after serialized bytes.'
);
$assert_no_mutation('Preparing an update mutation changed WordPress state or caches.');
$assert(
    Atomic_Option_Store::PROBE_BEFORE === $store->probe_evidence($update_evidence),
    'Exact present state did not probe as before.'
);

$applied = $store->apply_plan($update_plan);
$assert(
    Atomic_Option_Result::APPLIED === $applied->status()
        && Atomic_Option_Result::MUTATION_APPLIED === $applied->mutation()
        && Atomic_Option_Result::PHASE_COMPLETE === $applied->phase(),
    'Applying an exact prospective plan did not report an applied mutation.'
);
$assert(
    $new_raw === $GLOBALS['wpdb']->rows[$option]['option_value']
        && $expected_autoload === $GLOBALS['wpdb']->rows[$option]['autoload'],
    'Applying a plan did not persist the exact planned bytes/autoload state.'
);
$assert(
    Atomic_Option_Store::PROBE_AFTER === $store->probe_evidence($update_evidence),
    'Exact written state did not probe as after.'
);
$mutation_count = count($GLOBALS['wpdb']->mutations);
$replayed = $store->apply_plan($update_plan);
$assert(
    Atomic_Option_Result::REFUSED === $replayed->status()
        && $mutation_count === count($GLOBALS['wpdb']->mutations),
    'A consumed mutation plan was applied more than once.'
);

$other_value = array('version' => 1, 'marker' => 'concurrent-other');
$GLOBALS['wpdb']->rows[$option]['option_value'] = serialize($other_value);
$assert(
    Atomic_Option_Store::PROBE_OTHER === $store->probe_evidence($update_evidence),
    'A third authoritative state did not probe as other.'
);

$malformed_evidence = $update_evidence;
$malformed_evidence['unexpected'] = true;
$assert(
    Atomic_Option_Store::PROBE_REFUSED === $store->probe_evidence($malformed_evidence),
    'Evidence with an unexpected key was not refused.'
);
$oversized_evidence = $update_evidence;
$oversized_evidence['after_bytes'] = Atomic_Option_Store::MAX_SERIALIZED_BYTES + 1;
$assert(
    Atomic_Option_Store::PROBE_REFUSED === $store->probe_evidence($oversized_evidence),
    'Evidence outside the option byte ceiling was not refused.'
);
$referenced_hash = $update_evidence['after_sha256'];
$referenced_evidence = $update_evidence;
$referenced_evidence['after_sha256'] = &$referenced_hash;
$assert(
    Atomic_Option_Store::PROBE_REFUSED === $store->probe_evidence($referenced_evidence),
    'Reference-bearing evidence was not refused.'
);

$GLOBALS['wpdb']->fail_next_read = true;
$assert(
    Atomic_Option_Store::PROBE_INDETERMINATE === $store->probe_evidence($update_evidence),
    'An authoritative read failure was not probed as indeterminate.'
);

$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => $new_raw,
    'autoload'     => $autoloaded_value,
);
$assert(
    Atomic_Option_Store::PROBE_REFUSED === $store->probe_evidence($update_evidence),
    'An autoloaded row matching evidence was not refused.'
);

// A stale plan performs one exact CAS attempt, preserves the winner, and is
// consumed even though the mutation conflicts.
$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => $old_raw,
    'autoload'     => $expected_autoload,
);
$stale_prepared = $store->prepare_compare_exchange(
    $store->snapshot(),
    $new_value,
    'registry_link',
    $mutation_one
);
$stale_plan = $stale_prepared->plan();
$assert($stale_plan instanceof Atomic_Option_Mutation_Plan, 'The stale-plan fixture was not prepared.');
$winner = array('version' => 1, 'marker' => 'winner');
$winner_raw = serialize($winner);
$GLOBALS['wpdb']->rows[$option]['option_value'] = $winner_raw;
$stale_result = $store->apply_plan($stale_plan);
$assert(Atomic_Option_Result::CONFLICT === $stale_result->status(), 'A stale plan was not classified conflict.');
$assert($winner_raw === $GLOBALS['wpdb']->rows[$option]['option_value'], 'A stale plan replaced its concurrent winner.');
$stale_mutation_count = count($GLOBALS['wpdb']->mutations);
$assert(
    Atomic_Option_Result::REFUSED === $store->apply_plan($stale_plan)->status()
        && $stale_mutation_count === count($GLOBALS['wpdb']->mutations),
    'A conflicted single-use plan retained mutation authority.'
);

// Planner classification fails closed before mutation for unsafe authority,
// kind preconditions, and non-canonical replacement values.
$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => $old_raw,
    'autoload'     => $autoloaded_value,
);
$autoloaded_snapshot = $store->snapshot();
$autoload_refusal = $store->prepare_compare_exchange(
    $autoloaded_snapshot,
    $new_value,
    'registry_link',
    $mutation_one
);
$assert(
    Atomic_Option_Plan_Result::REFUSED === $autoload_refusal->status()
        && null === $autoload_refusal->plan(),
    'Planning from an autoloaded row was not refused.'
);
$assert_no_mutation('Autoload planning refusal reached hooks, SQL, or cache mutation.');

$store = $reset();
$assert(
    Atomic_Option_Plan_Result::CONFLICT === $store->prepare_compare_exchange(
        $store->snapshot(),
        $new_value,
        'secret_commit',
        $mutation_one
    )->status(),
    'A secret commit planned from an absent row was not a definite conflict.'
);
$assert(
    Atomic_Option_Plan_Result::REFUSED === $store->prepare_compare_exchange(
        $store->snapshot(),
        $new_value,
        'unreviewed_kind',
        $mutation_one
    )->status(),
    'An unreviewed mutation kind was accepted.'
);
$assert(
    Atomic_Option_Plan_Result::REFUSED === $store->prepare_compare_exchange(
        $store->snapshot(),
        $new_value,
        'registry_link',
        'mutation-not-canonical'
    )->status(),
    'A non-canonical mutation ID was accepted.'
);

$object_refusal = $store->prepare_compare_exchange(
    $store->snapshot(),
    array('object' => new stdClass()),
    'registry_link',
    $mutation_one
);
$assert(Atomic_Option_Plan_Result::REFUSED === $object_refusal->status(), 'Object-bearing replacement was planned.');

$shared = 'aliased';
$reference_value = array(
    'first'  => &$shared,
    'second' => &$shared,
);
$reference_refusal = $store->prepare_compare_exchange(
    $store->snapshot(),
    $reference_value,
    'registry_link',
    $mutation_one
);
$assert(Atomic_Option_Plan_Result::REFUSED === $reference_refusal->status(), 'Reference-bearing replacement was planned.');
$assert_no_mutation('Unsafe replacement planning reached hooks, SQL, or cache mutation.');

$bounded_store = $reset(96);
$oversized_refusal = $bounded_store->prepare_compare_exchange(
    $bounded_store->snapshot(),
    array('payload' => str_repeat('x', 200)),
    'registry_link',
    $mutation_one
);
$assert(Atomic_Option_Plan_Result::REFUSED === $oversized_refusal->status(), 'Oversized replacement was planned.');
$assert_no_mutation('Oversized replacement planning reached hooks, SQL, or cache mutation.');

$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => 'not-a-canonical-serialized-array',
    'autoload'     => $expected_autoload,
);
$malformed_snapshot = $store->snapshot();
$assert(Atomic_Option_Snapshot::REFUSED === $malformed_snapshot->state(), 'Malformed row became usable authority.');
$assert(
    Atomic_Option_Plan_Result::REFUSED === $store->prepare_compare_exchange(
        $malformed_snapshot,
        $new_value,
        'registry_link',
        $mutation_one
    )->status(),
    'Malformed authoritative state produced a plan.'
);

$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($reference_value),
    'autoload'     => $expected_autoload,
);
$referenced_snapshot = $store->snapshot();
$assert(Atomic_Option_Snapshot::REFUSED === $referenced_snapshot->state(), 'Reference-bearing row became usable authority.');
$assert(
    Atomic_Option_Plan_Result::REFUSED === $store->prepare_compare_exchange(
        $referenced_snapshot,
        $new_value,
        'registry_link',
        $mutation_one
    )->status(),
    'Reference-bearing authoritative state produced a plan.'
);

$bounded_store = $reset(96);
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize(array('payload' => str_repeat('x', 200))),
    'autoload'     => $expected_autoload,
);
$oversized_snapshot = $bounded_store->snapshot();
$assert(Atomic_Option_Snapshot::REFUSED === $oversized_snapshot->state(), 'Oversized row became usable authority.');
$assert(
    Atomic_Option_Plan_Result::REFUSED === $bounded_store->prepare_compare_exchange(
        $oversized_snapshot,
        $new_value,
        'registry_link',
        $mutation_one
    )->status(),
    'Oversized authoritative state produced a plan.'
);

$store = $reset();
$indeterminate_snapshot = Atomic_Option_Snapshot::indeterminate($option);
$assert(
    Atomic_Option_Plan_Result::INDETERMINATE === $store->prepare_compare_exchange(
        $indeterminate_snapshot,
        $new_value,
        'registry_link',
        $mutation_one
    )->status(),
    'Indeterminate authoritative state did not produce an indeterminate plan result.'
);
$wrong_scope = Atomic_Option_Snapshot::absent($other_option);
$assert(
    Atomic_Option_Plan_Result::REFUSED === $store->prepare_compare_exchange(
        $wrong_scope,
        $new_value,
        'registry_link',
        $mutation_one
    )->status(),
    'A snapshot for a different option produced a plan.'
);
$assert_no_mutation('Planner validation failures reached hooks, SQL, or cache mutation.');

// The established direct compare_exchange path remains available and retains
// its exact create/update byte and non-autoload behavior.
$store = $reset();
$direct_created_value = array('version' => 1, 'marker' => 'direct-created');
$direct_created = $store->compare_exchange($store->snapshot(), $direct_created_value);
$assert(
    Atomic_Option_Result::APPLIED === $direct_created->status()
        && serialize($direct_created_value) === $GLOBALS['wpdb']->rows[$option]['option_value']
        && $expected_autoload === $GLOBALS['wpdb']->rows[$option]['autoload'],
    'The direct absent compare_exchange contract regressed.'
);
$direct_updated_value = array('version' => 1, 'marker' => 'direct-updated');
$direct_updated = $store->compare_exchange($store->snapshot(), $direct_updated_value);
$assert(
    Atomic_Option_Result::APPLIED === $direct_updated->status()
        && serialize($direct_updated_value) === $GLOBALS['wpdb']->rows[$option]['option_value']
        && $expected_autoload === $GLOBALS['wpdb']->rows[$option]['autoload'],
    'The direct present compare_exchange contract regressed.'
);
$assert(
    array(
        'add_option',
        'add_option_' . $option,
        'added_option',
        'update_option',
        'update_option_' . $option,
        'updated_option',
    ) === array_map(
        static fn (array $event): string => (string) $event[0],
        $GLOBALS['awvp_plan_actions']
    ),
    'Direct compare_exchange action ordering regressed.'
);

echo 'AWVP atomic option planning tests passed ('
    . (function_exists('wp_autoload_values_to_autoload') ? 'modern/off' : 'wp64/no')
    . ").\n";

// EOF: tests/atomic-option-planning.php
