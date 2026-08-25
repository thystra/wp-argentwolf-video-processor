<?php
/**
 * Focused dependency-free tests for byte-exact atomic option persistence.
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

$GLOBALS['awvp_atomic_actions'] = array();
$GLOBALS['awvp_atomic_action_callbacks'] = array();
$GLOBALS['awvp_atomic_cache'] = array();
$GLOBALS['awvp_atomic_cache_deletes'] = array();
$GLOBALS['awvp_atomic_cache_throws'] = false;

function do_action(string $hook, mixed ...$arguments): void
{
    $GLOBALS['awvp_atomic_actions'][] = array($hook, $arguments);
    $callback = $GLOBALS['awvp_atomic_action_callbacks'][$hook] ?? null;
    if (is_callable($callback)) {
        $callback(...$arguments);
    }
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    $GLOBALS['awvp_atomic_cache_deletes'][] = array($key, $group);
    if ($GLOBALS['awvp_atomic_cache_throws']) {
        throw new RuntimeException('Synthetic object-cache failure.');
    }

    $cache_key = $group . ':' . $key;
    $existed = array_key_exists($cache_key, $GLOBALS['awvp_atomic_cache']);
    unset($GLOBALS['awvp_atomic_cache'][$cache_key]);
    return $existed;
}

function get_option(string $option, mixed $default = false): mixed
{
    $decode = static function (string $raw): mixed {
        $value = @unserialize($raw, array('allowed_classes' => false));
        return false === $value && 'b:0;' !== $raw ? $raw : $value;
    };

    $alloptions = $GLOBALS['awvp_atomic_cache']['options:alloptions'] ?? null;
    if (is_array($alloptions) && array_key_exists($option, $alloptions)) {
        return $decode((string) $alloptions[$option]);
    }

    $notoptions = $GLOBALS['awvp_atomic_cache']['options:notoptions'] ?? null;
    if (is_array($notoptions) && isset($notoptions[$option])) {
        return $default;
    }

    $cache_key = 'options:' . $option;
    if (array_key_exists($cache_key, $GLOBALS['awvp_atomic_cache'])) {
        return $decode((string) $GLOBALS['awvp_atomic_cache'][$cache_key]);
    }

    $row = $GLOBALS['wpdb']->rows[$option] ?? null;
    if (! is_array($row)) {
        return $default;
    }

    $GLOBALS['awvp_atomic_cache'][$cache_key] = $row['option_value'];
    return $decode($row['option_value']);
}

final class Awvp_Atomic_Fake_Wpdb
{
    public string $options = 'wp_options';
    public string $last_error = '';

    /** @var array<string, array{option_value:string,autoload:string}> */
    public array $rows = array();

    /** @var array<string, array{template:string,args:list<mixed>}> */
    public array $prepared = array();

    /** @var list<array{template:string,args:list<mixed>}> */
    public array $mutations = array();

    public string $next_query_mode = 'normal';
    public bool $fail_next_read = false;

    /** @var callable|null */
    public $after_query = null;

    private int $query_id = 0;

    public function prepare(string $query, mixed ...$arguments): string
    {
        $token = 'awvp-prepared-' . (++$this->query_id);
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
            $this->last_error = 'synthetic read failure';
            return null;
        }

        $prepared = $this->prepared[$query] ?? null;
        if (! is_array($prepared) || ! str_starts_with(ltrim($prepared['template']), 'SELECT ')) {
            throw new RuntimeException('Unexpected fake get_row query.');
        }

        $this->last_error = '';
        $maximum_bytes = (int) ($prepared['args'][0] ?? 0);
        $option = (string) ($prepared['args'][2] ?? '');
        $stored_option = $this->case_insensitive_option($option);
        if (
            null !== $stored_option
            && str_contains($prepared['template'], 'BINARY option_name = BINARY %s')
            && $stored_option !== $option
        ) {
            $stored_option = null;
        }

        $row = null === $stored_option ? null : $this->rows[$stored_option];
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
        $mode = $this->next_query_mode;
        $this->next_query_mode = 'normal';

        if ('false_no_apply' === $mode) {
            $this->last_error = 'synthetic unknown query outcome';
            return false;
        }

        $affected = $this->apply($prepared['template'], $prepared['args']);
        $callback = $this->after_query;
        $this->after_query = null;
        if (is_callable($callback)) {
            $callback($this);
        }

        if ('false_apply' === $mode) {
            $this->last_error = 'synthetic lost response after apply';
            return false;
        }

        $this->last_error = '';
        return $affected;
    }

    /** @param list<mixed> $arguments */
    private function apply(string $template, array $arguments): int
    {
        $normalized = ltrim($template);

        if (str_starts_with($normalized, 'INSERT INTO ')) {
            $option = (string) ($arguments[1] ?? '');
            if (null !== $this->case_insensitive_option($option)) {
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

    private function case_insensitive_option(string $option): ?string
    {
        foreach (array_keys($this->rows) as $stored_option) {
            if (0 === strcasecmp($option, $stored_option)) {
                return $stored_option;
            }
        }

        return null;
    }
}

require_once dirname(__DIR__) . '/includes/Atomic_Option_Snapshot.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Result.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Store.php';

use ArgentVideo\Atomic_Option_Result;
use ArgentVideo\Atomic_Option_Snapshot;
use ArgentVideo\Atomic_Option_Store;

$option = 'argentwolf_video_processor_atomic_test';
$expected_autoload = function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$reset = static function () use ($option): Atomic_Option_Store {
    $GLOBALS['wpdb'] = new Awvp_Atomic_Fake_Wpdb();
    $GLOBALS['awvp_atomic_actions'] = array();
    $GLOBALS['awvp_atomic_action_callbacks'] = array();
    $GLOBALS['awvp_atomic_cache'] = array();
    $GLOBALS['awvp_atomic_cache_deletes'] = array();
    $GLOBALS['awvp_atomic_cache_throws'] = false;
    return new Atomic_Option_Store($option);
};

$action_names = static function (): array {
    return array_map(
        static fn (array $event): string => (string) $event[0],
        $GLOBALS['awvp_atomic_actions']
    );
};

$invalid_scope_rejected = false;
try {
    new Atomic_Option_Store('unrelated_plugin_option');
} catch (InvalidArgumentException) {
    $invalid_scope_rejected = true;
}
$assert($invalid_scope_rejected, 'Atomic store must be restricted to an AWVP-owned option prefix.');

$uppercase_scope_rejected = false;
try {
    new Atomic_Option_Store('argentwolf_video_processor_Atomic_test');
} catch (InvalidArgumentException) {
    $uppercase_scope_rejected = true;
}
$assert(
    $uppercase_scope_rejected,
    'Atomic option names must be lowercase to avoid case-insensitive option-name aliases.'
);

$satisfied = Atomic_Option_Result::satisfied();
$assert(Atomic_Option_Result::APPLIED === $satisfied->status(), 'Satisfied postcondition must use the applied status.');
$assert(Atomic_Option_Result::MUTATION_NONE === $satisfied->mutation(), 'Satisfied postcondition must classify no mutation.');
$assert(Atomic_Option_Result::PHASE_COMPLETE === $satisfied->phase(), 'Satisfied postcondition phase mismatch.');
$assert(null === $satisfied->before() && null === $satisfied->written(), 'Satisfied postcondition must not claim rollback authority.');

$validation_conflict = Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION);
$assert(
    Atomic_Option_Result::CONFLICT === $validation_conflict->status()
    && Atomic_Option_Result::PHASE_VALIDATION === $validation_conflict->phase(),
    'Conflict result did not retain its caller-selected classified phase.'
);

// Absent create: exact bytes, version-correct non-autoload value, cache
// invalidation, and core add-action order.
$store = $reset();
$before_absent = $store->snapshot();
$assert($before_absent->is_absent(), 'Missing option snapshot must be classified absent.');
$GLOBALS['awvp_atomic_cache'] = array(
    'options:' . $option => 'stale',
    'options:alloptions' => array($option => 'stale'),
    'options:notoptions' => array($option => true),
);
$created_value = array('version' => 1, 'marker' => 'created');
$created = $store->compare_exchange($before_absent, $created_value);
$assert(Atomic_Option_Result::APPLIED === $created->status(), 'Absent compare_exchange must apply.');
$assert(Atomic_Option_Result::MUTATION_APPLIED === $created->mutation(), 'Applied create mutation classification mismatch.');
$assert(Atomic_Option_Result::PHASE_COMPLETE === $created->phase(), 'Applied create phase mismatch.');
$assert(
    serialize($created_value) === $GLOBALS['wpdb']->rows[$option]['option_value'],
    'Create must persist the canonical serialized bytes exactly.'
);
$assert(
    $expected_autoload === $GLOBALS['wpdb']->rows[$option]['autoload'],
    'Create used the wrong WordPress-version non-autoload value.'
);
$assert(
    array('add_option', 'add_option_' . $option, 'added_option') === $action_names(),
    'Create actions did not match core order.'
);
$assert(
    array($option, $created_value) === $GLOBALS['awvp_atomic_actions'][0][1]
    && array($option, $created_value) === $GLOBALS['awvp_atomic_actions'][1][1]
    && array($option, $created_value) === $GLOBALS['awvp_atomic_actions'][2][1],
    'Create action arguments did not match WordPress core.'
);
$assert(
    array(
        array($option, 'options'),
        array('alloptions', 'options'),
        array('notoptions', 'options'),
        array($option, 'options'),
        array('alloptions', 'options'),
        array('notoptions', 'options'),
    ) === $GLOBALS['awvp_atomic_cache_deletes'],
    'Create must invalidate individual, alloptions, and notoptions caches before and after post actions.'
);
$select = $GLOBALS['wpdb']->prepared['awvp-prepared-1']['template'] ?? '';
$assert(
    is_string($select) && str_contains($select, 'BINARY option_name = BINARY %s'),
    'Authoritative snapshot must compare the option name byte-for-byte.'
);
$insert = $GLOBALS['wpdb']->mutations[0]['template'];
$assert(str_contains($insert, 'WHERE NOT EXISTS'), 'Absent creation must have an atomic absence predicate.');
$assert(
    str_contains($insert, 'BINARY option_name = BINARY %s'),
    'Absent creation must use an exact option-name predicate.'
);

// A differently cased legacy/corrupt row may collide under wp_options' usual
// case-insensitive unique collation, but it is never read or mutated as this
// lowercase fixed-scope option.
$store = $reset();
$uppercase_option = 'argentwolf_video_processor_Atomic_test';
$uppercase_value = array('version' => 1, 'marker' => 'uppercase-row');
$GLOBALS['wpdb']->rows[$uppercase_option] = array(
    'option_value' => serialize($uppercase_value),
    'autoload'     => $expected_autoload,
);
$lowercase_snapshot = $store->snapshot();
$assert(
    $lowercase_snapshot->is_absent(),
    'Binary snapshot predicate treated a differently cased option as authoritative.'
);
$uppercase_collision = $store->compare_exchange(
    $lowercase_snapshot,
    array('version' => 1, 'marker' => 'lowercase-row')
);
$assert(
    Atomic_Option_Result::APPLIED !== $uppercase_collision->status(),
    'Case-insensitive option-name collision was reported applied.'
);
$assert(
    array($uppercase_option) === array_keys($GLOBALS['wpdb']->rows)
    && serialize($uppercase_value) === $GLOBALS['wpdb']->rows[$uppercase_option]['option_value'],
    'Lowercase CAS changed or duplicated a differently cased option row.'
);

// A stale absent snapshot conflicts and cannot overwrite an independently
// created row. Conflict has only the same pre-action WordPress core would run.
$store = $reset();
$stale_absent = $store->snapshot();
$concurrent_value = array('version' => 1, 'marker' => 'concurrent');
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($concurrent_value),
    'autoload'     => $expected_autoload,
);
$GLOBALS['awvp_atomic_cache'] = array(
    'options:' . $option => serialize(array('version' => 1, 'marker' => 'stale-individual')),
    'options:alloptions' => array(
        $option => serialize(array('version' => 1, 'marker' => 'stale-alloptions')),
    ),
    'options:notoptions' => array($option => true),
);
$conflicting_create = $store->compare_exchange(
    $stale_absent,
    array('version' => 1, 'marker' => 'loser')
);
$assert(Atomic_Option_Result::CONFLICT === $conflicting_create->status(), 'Stale absent create must conflict.');
$assert(serialize($concurrent_value) === $GLOBALS['wpdb']->rows[$option]['option_value'], 'Create conflict overwrote concurrent state.');
$assert(array('add_option') === $action_names(), 'Create conflict must not emit post-add actions.');
$assert(
    array(
        array($option, 'options'),
        array('alloptions', 'options'),
        array('notoptions', 'options'),
    ) === $GLOBALS['awvp_atomic_cache_deletes'],
    'Definite conflict must invalidate every option-read cache exactly once.'
);
$assert(
    $concurrent_value === get_option($option, null),
    'A get_option-style read observed stale cached state after the concurrent winner.'
);

$store = $reset();
$stale_absent = $store->snapshot();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($concurrent_value),
    'autoload'     => $expected_autoload,
);
$GLOBALS['awvp_atomic_cache_throws'] = true;
$conflict_cache_unknown = $store->compare_exchange(
    $stale_absent,
    array('version' => 1, 'marker' => 'loser')
);
$assert(
    Atomic_Option_Result::INDETERMINATE === $conflict_cache_unknown->status()
    && Atomic_Option_Result::MUTATION_NONE === $conflict_cache_unknown->mutation()
    && Atomic_Option_Result::PHASE_CACHE === $conflict_cache_unknown->phase(),
    'Conflict with failed cache invalidation must be indeterminate/no-mutation/cache.'
);

// Exact present-row CAS, including byte and autoload predicates and core update
// actions. The option must already be non-autoloaded so an exact rollback can
// never restore an autoload=true row.
$store = $reset();
$old_value = array('version' => 1, 'marker' => 'old');
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($old_value),
    'autoload'     => $expected_autoload,
);
$before_update = $store->snapshot();
$new_value = array('version' => 1, 'marker' => 'new');
$updated = $store->compare_exchange($before_update, $new_value);
$assert(Atomic_Option_Result::APPLIED === $updated->status(), 'Present compare_exchange must apply.');
$assert(
    array('update_option', 'update_option_' . $option, 'updated_option') === $action_names(),
    'Update actions did not match core order.'
);
$assert(
    array($option, $old_value, $new_value) === $GLOBALS['awvp_atomic_actions'][0][1]
    && array($old_value, $new_value, $option) === $GLOBALS['awvp_atomic_actions'][1][1]
    && array($option, $old_value, $new_value) === $GLOBALS['awvp_atomic_actions'][2][1],
    'Update action arguments did not match WordPress core.'
);
$update_sql = $GLOBALS['wpdb']->mutations[0]['template'];
$assert(str_contains($update_sql, 'OCTET_LENGTH(option_value) = %d'), 'Update must compare the expected byte length.');
$assert(str_contains($update_sql, 'BINARY option_value = BINARY %s'), 'Update must compare option bytes under a binary collation.');
$assert(str_contains($update_sql, 'BINARY autoload = BINARY %s'), 'Update must compare exact raw autoload state.');
$assert(str_contains($update_sql, 'BINARY option_name = BINARY %s'), 'Update must compare the option name byte-for-byte.');

$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize(array('marker' => 'Case ')),
    'autoload'     => $expected_autoload,
);
$case_snapshot = $store->snapshot();
$GLOBALS['wpdb']->rows[$option]['option_value'] = serialize(array('marker' => 'case'));
$case_conflict = $store->compare_exchange($case_snapshot, array('marker' => 'replacement'));
$assert(Atomic_Option_Result::CONFLICT === $case_conflict->status(), 'Case/trailing-byte change must conflict under byte-exact CAS.');
$assert(
    serialize(array('marker' => 'case')) === $GLOBALS['wpdb']->rows[$option]['option_value'],
    'Byte-exact conflict must preserve the concurrent row.'
);

// A present autoload=true row is intentionally refused. Callers must repair it
// through wp_set_option_autoload(false), then acquire a fresh raw snapshot.
$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize(array('marker' => 'autoloaded')),
    'autoload'     => function_exists('wp_autoload_values_to_autoload') ? 'on' : 'yes',
);
$autoloaded_snapshot = $store->snapshot();
$autoload_refused = $store->compare_exchange($autoloaded_snapshot, array('marker' => 'not-written'));
$assert(Atomic_Option_Result::REFUSED === $autoload_refused->status(), 'CAS must refuse an existing autoload=true row.');
$assert([] === $GLOBALS['wpdb']->mutations, 'Autoload=true refusal must precede SQL.');

// Unknown SQL outcomes remain indeterminate whether the fake applies or does
// not apply the statement. Neither case emits an unprovable post-update action.
foreach (array('false_no_apply', 'false_apply') as $unknown_mode) {
    $store = $reset();
    $GLOBALS['wpdb']->rows[$option] = array(
        'option_value' => serialize($old_value),
        'autoload'     => $expected_autoload,
    );
    $unknown_snapshot = $store->snapshot();
    $GLOBALS['wpdb']->next_query_mode = $unknown_mode;
    $unknown = $store->compare_exchange($unknown_snapshot, $new_value);
    $assert(Atomic_Option_Result::INDETERMINATE === $unknown->status(), 'Unknown query outcome must be indeterminate.');
    $assert(Atomic_Option_Result::MUTATION_UNKNOWN === $unknown->mutation(), 'Unknown query mutation classification mismatch.');
    $assert(Atomic_Option_Result::PHASE_SQL === $unknown->phase(), 'Unknown query phase mismatch.');
    $assert(array('update_option') === $action_names(), 'Unknown query must not emit post-update actions.');
    $assert(3 === count($GLOBALS['awvp_atomic_cache_deletes']), 'Unknown query must invalidate all option caches once.');

    $rollback_unknown = $store->rollback($unknown);
    $assert(
        Atomic_Option_Result::REFUSED === $rollback_unknown->status(),
        'An indeterminate write must never be accepted as rollback authority.'
    );
}

// Cache exceptions occur after a definite row mutation. All cache keys are
// still attempted, post actions still run, and the result is indeterminate
// with an explicit applied mutation classification.
$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($old_value),
    'autoload'     => $expected_autoload,
);
$cache_failure_snapshot = $store->snapshot();
$GLOBALS['awvp_atomic_cache_throws'] = true;
$cache_failure = $store->compare_exchange($cache_failure_snapshot, $new_value);
$assert(Atomic_Option_Result::INDETERMINATE === $cache_failure->status(), 'Cache failure must be indeterminate.');
$assert(Atomic_Option_Result::MUTATION_APPLIED === $cache_failure->mutation(), 'Cache failure must retain applied mutation state.');
$assert(Atomic_Option_Result::PHASE_CACHE === $cache_failure->phase(), 'Cache failure phase mismatch.');
$assert(6 === count($GLOBALS['awvp_atomic_cache_deletes']), 'Cache failure must still attempt all keys in both invalidation passes.');
$assert(
    array('update_option', 'update_option_' . $option, 'updated_option') === $action_names(),
    'Cache failure must not suppress actions for a definitely applied mutation.'
);

// A definite mutation superseded before its postread is indeterminate/applied,
// still emits the proven mutation's post actions, and preserves the newer row.
$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($old_value),
    'autoload'     => $expected_autoload,
);
$superseded_snapshot = $store->snapshot();
$superseding_value = array('marker' => 'superseding-query');
$GLOBALS['wpdb']->after_query = static function (Awvp_Atomic_Fake_Wpdb $database) use ($option, $superseding_value, $expected_autoload): void {
    $database->rows[$option] = array(
        'option_value' => serialize($superseding_value),
        'autoload'     => $expected_autoload,
    );
};
$superseded = $store->compare_exchange($superseded_snapshot, $new_value);
$assert(Atomic_Option_Result::INDETERMINATE === $superseded->status(), 'Superseded definite update must be indeterminate.');
$assert(Atomic_Option_Result::MUTATION_APPLIED === $superseded->mutation(), 'Superseded update must retain applied mutation classification.');
$assert(Atomic_Option_Result::PHASE_POSTCONDITION === $superseded->phase(), 'Superseded update phase mismatch.');
$assert(serialize($superseding_value) === $GLOBALS['wpdb']->rows[$option]['option_value'], 'Superseding row was not preserved.');
$assert(
    array('update_option', 'update_option_' . $option, 'updated_option') === $action_names(),
    'A definitely applied but superseded mutation must emit its post actions.'
);

// A post-update hook can itself mutate the row. The final authoritative
// post-action read must prevent a false APPLIED outcome and the second cache
// invalidation must run after that hook.
$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($old_value),
    'autoload'     => $expected_autoload,
);
$hook_snapshot = $store->snapshot();
$hook_value = array('marker' => 'hook-mutation');
$GLOBALS['awvp_atomic_action_callbacks']['update_option_' . $option] = static function () use ($option, $hook_value, $expected_autoload): void {
    $GLOBALS['wpdb']->rows[$option] = array(
        'option_value' => serialize($hook_value),
        'autoload'     => $expected_autoload,
    );
};
$hook_changed = $store->compare_exchange($hook_snapshot, $new_value);
$assert(Atomic_Option_Result::INDETERMINATE === $hook_changed->status(), 'Post-action row mutation must prevent APPLIED.');
$assert(Atomic_Option_Result::PHASE_POSTCONDITION === $hook_changed->phase(), 'Post-action mutation phase mismatch.');
$assert(6 === count($GLOBALS['awvp_atomic_cache_deletes']), 'Post-action mutation must still receive final cache invalidation.');
$assert(serialize($hook_value) === $GLOBALS['wpdb']->rows[$option]['option_value'], 'Post-action concurrent row must be preserved.');

// Oversized and object-bearing arrays are refused before hooks or SQL.
$store = new Atomic_Option_Store($option, 96);
$GLOBALS['wpdb'] = new Awvp_Atomic_Fake_Wpdb();
$GLOBALS['awvp_atomic_actions'] = array();
$oversized = $store->compare_exchange(
    Atomic_Option_Snapshot::absent($option),
    array('payload' => str_repeat('x', 200))
);
$assert(Atomic_Option_Result::REFUSED === $oversized->status(), 'Oversized replacement must be refused.');
$assert([] === $GLOBALS['wpdb']->mutations && [] === $GLOBALS['awvp_atomic_actions'], 'Oversized refusal must be side-effect free.');

$store = $reset();
$object_value = array('object' => new stdClass());
$object_refused = $store->compare_exchange($store->snapshot(), $object_value);
$assert(Atomic_Option_Result::REFUSED === $object_refused->status(), 'Object-bearing replacement must be refused.');
$assert([] === $GLOBALS['wpdb']->mutations, 'Object-bearing refusal must precede SQL.');

$store = $reset();
$GLOBALS['awvp_atomic_magic_serialize_called'] = false;
$magic_object = new class {
    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        $GLOBALS['awvp_atomic_magic_serialize_called'] = true;
        return array('side_effect' => true);
    }
};
$magic_object_refused = $store->compare_exchange(
    $store->snapshot(),
    array('object' => $magic_object)
);
$assert(
    Atomic_Option_Result::REFUSED === $magic_object_refused->status(),
    'Magic-serialization object must be refused.'
);
$assert(
    false === $GLOBALS['awvp_atomic_magic_serialize_called']
        && [] === $GLOBALS['wpdb']->mutations
        && [] === $GLOBALS['awvp_atomic_actions'],
    'Object validation invoked a magic serializer or reached hooks/SQL.'
);

$store = $reset();
$shared = 'aliased-scalar';
$reference_value = array(
    'nested' => array(
        'first'  => &$shared,
        'second' => &$shared,
    ),
);
$reference_refused = $store->compare_exchange($store->snapshot(), $reference_value);
$assert(
    Atomic_Option_Result::REFUSED === $reference_refused->status(),
    'Reference-bearing replacement must be refused.'
);
$assert(
    [] === $GLOBALS['wpdb']->mutations && [] === $GLOBALS['awvp_atomic_actions'],
    'Reference-bearing replacement refusal must precede hooks and SQL.'
);

$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($reference_value),
    'autoload'     => $expected_autoload,
);
$assert(
    Atomic_Option_Snapshot::REFUSED === $store->snapshot()->state(),
    'Reference-bearing authoritative row must not become a usable snapshot.'
);

$store = $reset();
$reference_raw = serialize($reference_value);
$forged_reference_snapshot = Atomic_Option_Snapshot::present(
    $option,
    $reference_raw,
    $expected_autoload,
    $reference_value
);
$forged_reference_update = $store->compare_exchange(
    $forged_reference_snapshot,
    array('marker' => 'not-written')
);
$assert(
    Atomic_Option_Result::REFUSED === $forged_reference_update->status()
    && [] === $GLOBALS['wpdb']->mutations
    && [] === $GLOBALS['awvp_atomic_actions'],
    'Reference-bearing caller-constructed snapshot reached hooks or SQL.'
);
$assert(
    Atomic_Option_Result::REFUSED
        === $store->compare_delete($forged_reference_snapshot)->status(),
    'Conditional delete accepted a reference-bearing caller snapshot.'
);
$forged_reference_write = Atomic_Option_Result::applied(
    Atomic_Option_Snapshot::absent($option),
    $forged_reference_snapshot
);
$assert(
    Atomic_Option_Result::REFUSED === $store->rollback($forged_reference_write)->status(),
    'Rollback accepted forged reference-bearing write authority.'
);

$GLOBALS['wpdb'] = new Awvp_Atomic_Fake_Wpdb();
$bounded_store = new Atomic_Option_Store($option, 96);
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize(array('payload' => str_repeat('x', 200))),
    'autoload'     => $expected_autoload,
);
$assert(
    Atomic_Option_Snapshot::REFUSED === $bounded_store->snapshot()->state(),
    'Oversized authoritative row must be refused without transferring it as a usable snapshot.'
);

// Conditional rollback of a creation deletes only the exact created row.
$store = $reset();
$creation = $store->compare_exchange($store->snapshot(), $created_value);
$GLOBALS['awvp_atomic_actions'] = array();
$creation_rollback = $store->rollback($creation);
$assert(Atomic_Option_Result::APPLIED === $creation_rollback->status(), 'Exact creation rollback must apply.');
$assert(! array_key_exists($option, $GLOBALS['wpdb']->rows), 'Creation rollback must delete the exact created row.');
$assert(
    array('delete_option', 'delete_option_' . $option, 'deleted_option') === $action_names(),
    'Conditional delete actions did not match core order.'
);
$delete_sql = $GLOBALS['wpdb']->mutations[1]['template'];
$assert(str_contains($delete_sql, 'BINARY option_value = BINARY %s'), 'Conditional delete must compare exact written bytes.');
$assert(str_contains($delete_sql, 'BINARY option_name = BINARY %s'), 'Conditional delete must compare the option name byte-for-byte.');

// Conditional rollback of an update restores exact prior bytes/autoload. A
// concurrent replacement makes rollback conflict without erasing that state.
$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($old_value),
    'autoload'     => $expected_autoload,
);
$update_write = $store->compare_exchange($store->snapshot(), $new_value);
$GLOBALS['awvp_atomic_actions'] = array();
$update_rollback = $store->rollback($update_write);
$assert(Atomic_Option_Result::APPLIED === $update_rollback->status(), 'Exact update rollback must apply.');
$assert(serialize($old_value) === $GLOBALS['wpdb']->rows[$option]['option_value'], 'Update rollback did not restore exact prior bytes.');
$assert($expected_autoload === $GLOBALS['wpdb']->rows[$option]['autoload'], 'Update rollback did not restore exact safe autoload state.');
$assert(
    array('update_option', 'update_option_' . $option, 'updated_option') === $action_names(),
    'Rollback update actions did not match core order.'
);

$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($old_value),
    'autoload'     => $expected_autoload,
);
$write_before_concurrent = $store->compare_exchange($store->snapshot(), $new_value);
$concurrent_after_write = array('marker' => 'concurrent-after-write');
$GLOBALS['wpdb']->rows[$option]['option_value'] = serialize($concurrent_after_write);
$rollback_conflict = $store->rollback($write_before_concurrent);
$assert(Atomic_Option_Result::CONFLICT === $rollback_conflict->status(), 'Rollback against concurrent state must conflict.');
$assert(
    serialize($concurrent_after_write) === $GLOBALS['wpdb']->rows[$option]['option_value'],
    'Rollback conflict erased concurrent state.'
);

// Reconciliation can conditionally delete from a fresh snapshot without the
// original write-result object. A stale snapshot cannot delete a newer row.
$store = $reset();
$recovery_value = array('marker' => 'recoverable');
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($recovery_value),
    'autoload'     => $expected_autoload,
);
$recovery_snapshot = $store->snapshot();
$recovery_delete = $store->compare_delete($recovery_snapshot);
$assert(Atomic_Option_Result::APPLIED === $recovery_delete->status(), 'Fresh compare_delete must apply.');
$assert(! array_key_exists($option, $GLOBALS['wpdb']->rows), 'Fresh compare_delete did not delete its exact row.');

$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($recovery_value),
    'autoload'     => $expected_autoload,
);
$stale_delete_snapshot = $store->snapshot();
$replacement_before_delete = array('marker' => 'replacement-before-delete');
$GLOBALS['wpdb']->rows[$option]['option_value'] = serialize($replacement_before_delete);
$stale_delete = $store->compare_delete($stale_delete_snapshot);
$assert(Atomic_Option_Result::CONFLICT === $stale_delete->status(), 'Stale compare_delete must conflict.');
$assert(
    serialize($replacement_before_delete) === $GLOBALS['wpdb']->rows[$option]['option_value'],
    'Stale compare_delete erased a newer row.'
);

// A pre-action exception proves no mutation; a post-action exception follows
// a definite mutation and therefore receives an indeterminate/applied result.
$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($old_value),
    'autoload'     => $expected_autoload,
);
$pre_exception_snapshot = $store->snapshot();
$GLOBALS['awvp_atomic_action_callbacks']['update_option'] = static function (): void {
    throw new RuntimeException('synthetic pre-action failure');
};
$pre_exception = $store->compare_exchange($pre_exception_snapshot, $new_value);
$assert(Atomic_Option_Result::REFUSED === $pre_exception->status(), 'Pre-action exception must be refused.');
$assert(Atomic_Option_Result::PHASE_PRE_ACTION === $pre_exception->phase(), 'Pre-action exception phase mismatch.');
$assert([] === $GLOBALS['wpdb']->mutations, 'Pre-action exception must precede SQL.');

// A pre-action can mutate the target and then throw. Such a partial hook
// outcome must never be reported REFUSED/NONE; create, update, and delete all
// require an authoritative cache-invalidated reread.
$store = $reset();
$hook_created = array('marker' => 'hook-created-before-throw');
$create_before = $store->snapshot();
$GLOBALS['awvp_atomic_action_callbacks']['add_option'] = static function () use (
    $option,
    $hook_created,
    $expected_autoload
): void {
    $GLOBALS['wpdb']->rows[$option] = array(
        'option_value' => serialize($hook_created),
        'autoload'     => $expected_autoload,
    );
    throw new RuntimeException('synthetic add pre-action partial mutation');
};
$partial_create = $store->compare_exchange($create_before, $created_value);
$assert(
    Atomic_Option_Result::INDETERMINATE === $partial_create->status()
    && Atomic_Option_Result::MUTATION_UNKNOWN === $partial_create->mutation()
    && Atomic_Option_Result::PHASE_PRE_ACTION === $partial_create->phase(),
    'Mutation-then-throw add action was not classified indeterminate/unknown/pre-action.'
);
$assert(
    serialize($hook_created) === $GLOBALS['wpdb']->rows[$option]['option_value']
    && [] === $GLOBALS['wpdb']->mutations
    && 3 === count($GLOBALS['awvp_atomic_cache_deletes']),
    'Partial add hook mutation was not preserved and cache-invalidated without SQL.'
);

$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($old_value),
    'autoload'     => $expected_autoload,
);
$update_before = $store->snapshot();
$hook_updated = array('marker' => 'hook-updated-before-throw');
$GLOBALS['awvp_atomic_action_callbacks']['update_option'] = static function () use (
    $option,
    $hook_updated,
    $expected_autoload
): void {
    $GLOBALS['wpdb']->rows[$option] = array(
        'option_value' => serialize($hook_updated),
        'autoload'     => $expected_autoload,
    );
    throw new RuntimeException('synthetic update pre-action partial mutation');
};
$partial_update = $store->compare_exchange($update_before, $new_value);
$assert(
    Atomic_Option_Result::INDETERMINATE === $partial_update->status()
    && Atomic_Option_Result::MUTATION_UNKNOWN === $partial_update->mutation()
    && Atomic_Option_Result::PHASE_PRE_ACTION === $partial_update->phase(),
    'Mutation-then-throw update action was not classified indeterminate/unknown/pre-action.'
);
$assert(
    serialize($hook_updated) === $GLOBALS['wpdb']->rows[$option]['option_value']
    && [] === $GLOBALS['wpdb']->mutations
    && 3 === count($GLOBALS['awvp_atomic_cache_deletes']),
    'Partial update hook mutation was not preserved and cache-invalidated without SQL.'
);

$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($old_value),
    'autoload'     => $expected_autoload,
);
$delete_before = $store->snapshot();
$GLOBALS['awvp_atomic_action_callbacks']['delete_option'] = static function () use ($option): void {
    unset($GLOBALS['wpdb']->rows[$option]);
    throw new RuntimeException('synthetic delete pre-action partial mutation');
};
$partial_delete = $store->compare_delete($delete_before);
$assert(
    Atomic_Option_Result::INDETERMINATE === $partial_delete->status()
    && Atomic_Option_Result::MUTATION_UNKNOWN === $partial_delete->mutation()
    && Atomic_Option_Result::PHASE_PRE_ACTION === $partial_delete->phase(),
    'Mutation-then-throw delete action was not classified indeterminate/unknown/pre-action.'
);
$assert(
    ! array_key_exists($option, $GLOBALS['wpdb']->rows)
    && [] === $GLOBALS['wpdb']->mutations
    && 3 === count($GLOBALS['awvp_atomic_cache_deletes']),
    'Partial delete hook mutation was not preserved and cache-invalidated without SQL.'
);

// A normally returning pre-action receives the same prospective guard. Its
// target mutation cannot be mislabeled as a zero-row SQL conflict, because
// that classification is later authority for a fresh coordinator plan.
$store = $reset();
$hook_created = array('marker' => 'hook-created-before-return');
$create_before = $store->snapshot();
$GLOBALS['awvp_atomic_action_callbacks']['add_option'] = static function () use (
    $option,
    $hook_created,
    $expected_autoload
): void {
    $GLOBALS['wpdb']->rows[$option] = array(
        'option_value' => serialize($hook_created),
        'autoload'     => $expected_autoload,
    );
};
$partial_create = $store->compare_exchange($create_before, $created_value);
$assert(
    Atomic_Option_Result::INDETERMINATE === $partial_create->status()
    && Atomic_Option_Result::MUTATION_UNKNOWN === $partial_create->mutation()
    && Atomic_Option_Result::PHASE_PRE_ACTION === $partial_create->phase(),
    'Mutation-then-return add action was not classified indeterminate/unknown/pre-action.'
);
$assert(
    serialize($hook_created) === $GLOBALS['wpdb']->rows[$option]['option_value']
    && [] === $GLOBALS['wpdb']->mutations
    && 3 === count($GLOBALS['awvp_atomic_cache_deletes']),
    'Normal-returning partial add hook reached SQL or lost its target mutation.'
);

$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($old_value),
    'autoload'     => $expected_autoload,
);
$update_before = $store->snapshot();
$hook_updated = array('marker' => 'hook-updated-before-return');
$GLOBALS['awvp_atomic_action_callbacks']['update_option'] = static function () use (
    $option,
    $hook_updated,
    $expected_autoload
): void {
    $GLOBALS['wpdb']->rows[$option] = array(
        'option_value' => serialize($hook_updated),
        'autoload'     => $expected_autoload,
    );
};
$partial_update = $store->compare_exchange($update_before, $new_value);
$assert(
    Atomic_Option_Result::INDETERMINATE === $partial_update->status()
    && Atomic_Option_Result::MUTATION_UNKNOWN === $partial_update->mutation()
    && Atomic_Option_Result::PHASE_PRE_ACTION === $partial_update->phase(),
    'Mutation-then-return update action was not classified indeterminate/unknown/pre-action.'
);
$assert(
    serialize($hook_updated) === $GLOBALS['wpdb']->rows[$option]['option_value']
    && [] === $GLOBALS['wpdb']->mutations
    && 3 === count($GLOBALS['awvp_atomic_cache_deletes']),
    'Normal-returning partial update hook reached SQL or lost its target mutation.'
);

$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($old_value),
    'autoload'     => $expected_autoload,
);
$delete_before = $store->snapshot();
$GLOBALS['awvp_atomic_action_callbacks']['delete_option'] = static function () use ($option): void {
    unset($GLOBALS['wpdb']->rows[$option]);
};
$partial_delete = $store->compare_delete($delete_before);
$assert(
    Atomic_Option_Result::INDETERMINATE === $partial_delete->status()
    && Atomic_Option_Result::MUTATION_UNKNOWN === $partial_delete->mutation()
    && Atomic_Option_Result::PHASE_PRE_ACTION === $partial_delete->phase(),
    'Mutation-then-return delete action was not classified indeterminate/unknown/pre-action.'
);
$assert(
    ! array_key_exists($option, $GLOBALS['wpdb']->rows)
    && [] === $GLOBALS['wpdb']->mutations
    && 3 === count($GLOBALS['awvp_atomic_cache_deletes']),
    'Normal-returning partial delete hook reached SQL or restored its target mutation.'
);

$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array(
    'option_value' => serialize($old_value),
    'autoload'     => $expected_autoload,
);
$post_exception_snapshot = $store->snapshot();
$GLOBALS['awvp_atomic_action_callbacks']['update_option_' . $option] = static function (): void {
    throw new RuntimeException('synthetic post-action failure');
};
$post_exception = $store->compare_exchange($post_exception_snapshot, $new_value);
$assert(Atomic_Option_Result::INDETERMINATE === $post_exception->status(), 'Post-action exception must be indeterminate.');
$assert(Atomic_Option_Result::MUTATION_APPLIED === $post_exception->mutation(), 'Post-action exception must retain applied mutation state.');
$assert(Atomic_Option_Result::PHASE_POST_ACTION === $post_exception->phase(), 'Post-action exception phase mismatch.');
$assert(serialize($new_value) === $GLOBALS['wpdb']->rows[$option]['option_value'], 'Post-action exception lost the definite database mutation.');

// A malformed/oversized authoritative row is refused, while a database read
// error is indeterminate. Neither snapshot consults WordPress option caches.
$store = $reset();
$GLOBALS['wpdb']->rows[$option] = array('option_value' => 'not-serialized', 'autoload' => $expected_autoload);
$assert(Atomic_Option_Snapshot::REFUSED === $store->snapshot()->state(), 'Malformed stored row must be refused.');
$GLOBALS['wpdb']->fail_next_read = true;
$assert(Atomic_Option_Snapshot::INDETERMINATE === $store->snapshot()->state(), 'Database read failure must be indeterminate.');

echo 'AWVP atomic option CAS tests passed ('
    . (function_exists('wp_autoload_values_to_autoload') ? 'modern/off' : 'wp64/no')
    . ").\n";
