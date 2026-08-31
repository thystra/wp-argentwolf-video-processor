<?php
/**
 * Focused dependency-free tests for the durable PeerTube connection journal.
 *
 * Run once without AWVP_ATOMIC_MODERN_AUTOLOAD to model WordPress 6.4/6.5
 * (`no`), and once with it set to 1 to model WordPress 6.6+ (`off`).
 */

declare(strict_types=1);

namespace ArgentVideo {
    function random_bytes(int $length): string
    {
        return \Awvp_Operation_Test_Entropy::bytes($length);
    }
}

namespace {

define('ARRAY_A', 'ARRAY_A');

if ('1' === getenv('AWVP_ATOMIC_MODERN_AUTOLOAD')) {
    function wp_autoload_values_to_autoload(): array
    {
        return array('yes', 'on', 'auto-on', 'auto');
    }
}

$GLOBALS['awvp_operation_actions'] = array();
$GLOBALS['awvp_operation_cache_deletes'] = array();

final class Awvp_Operation_Test_Entropy
{
    /** @var list<string> */
    private static array $queued = array();

    public static function reset(): void
    {
        self::$queued = array();
    }

    public static function queue_hex(string ...$values): void
    {
        foreach ($values as $value) {
            $bytes = hex2bin($value);
            if (! is_string($bytes)) {
                throw new RuntimeException('Invalid deterministic entropy fixture.');
            }
            self::$queued[] = $bytes;
        }
    }

    public static function bytes(int $length): string
    {
        if ([] === self::$queued) {
            return \random_bytes($length);
        }

        $bytes = array_shift(self::$queued);
        if (! is_string($bytes) || strlen($bytes) !== $length) {
            throw new RuntimeException('Deterministic entropy fixture length mismatch.');
        }
        return $bytes;
    }
}

function do_action(string $hook, mixed ...$arguments): void
{
    $GLOBALS['awvp_operation_actions'][] = array($hook, $arguments);
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    $GLOBALS['awvp_operation_cache_deletes'][] = array($key, $group);
    return true;
}

final class Awvp_Operation_Fake_Wpdb
{
    public string $options = 'wp_options';
    public string $last_error = '';

    /** @var array<string, array{option_value:string,autoload:string}> */
    public array $rows = array();

    /** @var array<string, array{template:string,args:list<mixed>}> */
    public array $prepared = array();

    /** @var list<array{template:string,args:list<mixed>}> */
    public array $mutations = array();

    /** @var callable|null */
    public $before_query = null;

    private int $query_id = 0;

    public function prepare(string $query, mixed ...$arguments): string
    {
        $token = 'awvp-operation-prepared-' . (++$this->query_id);
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
            throw new RuntimeException('Unexpected operation-store read query.');
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
            throw new RuntimeException('Unexpected operation-store mutation query.');
        }

        $this->mutations[] = $prepared;
        $callback = $this->before_query;
        $this->before_query = null;
        if (is_callable($callback)) {
            $callback($this, $prepared);
        }

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

        throw new RuntimeException('Unsupported operation-store mutation query.');
    }
}

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Snapshot.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Result.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Store.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_State_Machine.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_Operation_Store.php';

use ArgentVideo\Atomic_Option_Result;
use ArgentVideo\PeerTube_Connection_Operation_Store as Operation_Store;
use ArgentVideo\PeerTube_Connection_State_Machine as Machine;

$option = Operation_Store::OPTION;
$expected_autoload = function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$reset = static function (): Operation_Store {
    Awvp_Operation_Test_Entropy::reset();
    $GLOBALS['wpdb'] = new Awvp_Operation_Fake_Wpdb();
    $GLOBALS['awvp_operation_actions'] = array();
    $GLOBALS['awvp_operation_cache_deletes'] = array();
    return new Operation_Store();
};

/** @return array<string, mixed> */
$stored_journal = static function () use ($option, $assert): array {
    $raw = $GLOBALS['wpdb']->rows[$option]['option_value'] ?? null;
    $assert(is_string($raw), 'The journal option row is missing.');
    $journal = unserialize($raw, array('allowed_classes' => false));
    $assert(is_array($journal), 'The journal option row is not an array.');
    return $journal;
};

/** @param array<string, mixed> $journal */
$seed_journal = static function (array $journal) use ($option, $expected_autoload): void {
    $GLOBALS['wpdb']->rows[$option] = array(
        'option_value' => serialize($journal),
        'autoload'     => $expected_autoload,
    );
    $GLOBALS['wpdb']->mutations = array();
    $GLOBALS['awvp_operation_actions'] = array();
    $GLOBALS['awvp_operation_cache_deletes'] = array();
};

/** @return array{backend_id:string,origin:string,label:string} */
$begin_intent = static function (string $backend_id): array {
    return array(
        'backend_id' => $backend_id,
        'origin'     => 'https://video.example.org',
        'label'      => 'PeerTube ' . $backend_id,
    );
};

$hex_id = static function (int $number): string {
    return str_pad(dechex($number), 32, '0', STR_PAD_LEFT);
};

/** @return array<string, mixed> */
$external_record = static function (int $number, string $backend_id) use ($hex_id, $assert): array {
    $record = Machine::create(
        array(
            'operation_id'    => 'connection_' . $hex_id($number),
            'backend_id'      => $backend_id,
            'origin'          => 'https://video.example.org',
            'label'           => 'Externally seeded ' . $number,
            'secret_ref'      => 'managed_' . $hex_id(1000 + $number),
            'provisioning_id' => 'provision_' . $hex_id(2000 + $number),
        ),
        7,
        1000 + $number
    );
    $assert(is_array($record), 'Could not build a valid external operation record.');
    return $record;
};

/** @return array<string, mixed> */
$mutation = static function (
    string $kind,
    string $digit,
    bool $before_exists
): array {
    return array(
        'kind'          => $kind,
        'mutation_id'   => 'mutation_' . str_repeat($digit, 32),
        'before_exists' => $before_exists,
        'before_sha256' => $before_exists ? str_repeat($digit, 64) : '',
        'before_bytes'  => $before_exists ? 512 : 0,
        'after_exists'  => true,
        'after_sha256'  => str_repeat('f', 64),
        'after_bytes'   => 640,
    );
};

/** @param array<string, mixed> $record
 *  @return array<string, mixed>
 */
$complete_record = static function (array $record) use ($mutation, $assert): array {
    $now = (int) $record['updated_at'];
    $events = array(
        array(Machine::EVENT_PLAN_SECRET_RESERVATION, $mutation('secret_reserve', '1', false)),
        array(Machine::EVENT_CONFIRM_SECRET_RESERVED, array()),
        array(Machine::EVENT_PLAN_DISABLED_LINK, $mutation('registry_link', '2', true)),
        array(Machine::EVENT_CONFIRM_DISABLED_LINK, array()),
        array(Machine::EVENT_BEGIN_GRANT, array(
            'attempt_capability' => str_repeat('3', 64),
        )),
        array(Machine::EVENT_PLAN_SECRET_STORAGE, $mutation('secret_commit', '4', true)),
        array(Machine::EVENT_CONFIRM_SECRET_STORED, array()),
        array(Machine::EVENT_BEGIN_VERIFICATION, array()),
        array(Machine::EVENT_VERIFICATION_SUCCEEDED, array(
            'identity' => array(
                'user_id'      => '17',
                'username'     => 'awvp_service',
                'account_id'   => '23',
                'account_name' => 'awvp_service',
            ),
            'secret_generation' => 1,
        )),
        array(Machine::EVENT_SELECT_DESTINATION, array(
            'destination_id' => '41',
            'actor_id'       => 7,
        )),
        array(Machine::EVENT_VERIFICATION_SUCCEEDED, array(
            'identity' => array(
                'user_id'      => '17',
                'username'     => 'awvp_service',
                'account_id'   => '23',
                'account_name' => 'awvp_service',
            ),
            'secret_generation' => 1,
        )),
        array(Machine::EVENT_PLAN_ACTIVATION, $mutation('registry_activate', '5', true)),
        array(Machine::EVENT_CONFIRM_ACTIVATION, array()),
        array(Machine::EVENT_COMPLETE, array()),
    );

    foreach ($events as [$event, $payload]) {
        $next = Machine::apply($record, $event, $payload, ++$now);
        $assert(is_array($next), 'Could not complete external operation at event: ' . $event);
        $record = $next;
    }

    $assert(Machine::PHASE_COMPLETE === $record['phase'], 'External record did not reach complete.');
    return $record;
};

// An absent journal is created atomically with generated, pre-reserved local
// identities and a WordPress-version-correct non-autoload value.
$store = $reset();
$begun = $store->begin($begin_intent('peertube-primary'), 7, 1000);
$record = $begun['record'];
$assert(is_array($record), 'Valid begin did not return its prospective record.');
$assert(Atomic_Option_Result::APPLIED === $begun['result']->status(), 'Absent begin did not apply.');
$assert(1 === $record['record_revision'], 'A begun operation must start at revision one.');
$assert(Machine::PHASE_PREPARED === $record['phase'], 'A begun operation must start prepared.');
$assert(
    1 === preg_match('/^connection_[a-f0-9]{32}$/D', $record['operation_id']),
    'Begin did not generate a strict operation ID.'
);
$assert(
    1 === preg_match('/^managed_[a-f0-9]{32}$/D', $record['secret_ref']),
    'Begin did not reserve a strict managed-secret reference.'
);
$assert(
    1 === preg_match('/^provision_[a-f0-9]{32}$/D', $record['provisioning_id']),
    'Begin did not reserve a strict provisioning ID.'
);
$journal = $stored_journal();
$assert(
    array('version', 'operations') === array_keys($journal)
    && Operation_Store::VERSION === $journal['version']
    && array($record['operation_id']) === array_keys($journal['operations'])
    && $record === $journal['operations'][$record['operation_id']],
    'Absent begin did not persist the exact versioned journal envelope.'
);
$assert(
    $expected_autoload === $GLOBALS['wpdb']->rows[$option]['autoload'],
    'The connection journal was not explicitly non-autoloaded.'
);
$assert($record === $store->get($record['operation_id']), 'get() did not return the exact begun record.');
$assert(
    array($record['operation_id']) === array_keys($store->open_operations() ?? array()),
    'open_operations() did not expose the begun operation.'
);

// A valid journal in an unsupported autoload state is not an authoritative
// readable or writable journal. Repair requires a separate operation and a
// fresh snapshot.
$journal_raw = $GLOBALS['wpdb']->rows[$option]['option_value'];
$mutation_count = count($GLOBALS['wpdb']->mutations);
$GLOBALS['wpdb']->rows[$option]['autoload'] = 'yes';
$assert(null === $store->get($record['operation_id']), 'Autoloaded journal record was exposed by get().');
$assert(null === $store->open_operations(), 'Autoloaded journal was exposed by open_operations().');
$autoloaded_event = $store->apply_event(
    $record['operation_id'],
    1,
    Machine::EVENT_PLAN_SECRET_RESERVATION,
    $mutation('secret_reserve', '9', false),
    1001
);
$assert(Atomic_Option_Result::REFUSED === $autoloaded_event->status(), 'Autoloaded journal event was not refused.');
$autoloaded_begin = $store->begin($begin_intent('peertube-autoload-refused'), 7, 1001);
$assert(Atomic_Option_Result::REFUSED === $autoloaded_begin['result']->status(), 'Autoloaded journal begin was not refused.');
$assert(
    $mutation_count === count($GLOBALS['wpdb']->mutations)
        && $journal_raw === $GLOBALS['wpdb']->rows[$option]['option_value'],
    'Autoloaded journal refusal issued SQL or changed journal bytes.'
);
$GLOBALS['wpdb']->rows[$option]['autoload'] = $expected_autoload;

// A second unresolved operation for the same backend is refused without
// changing the authoritative journal or evicting the first operation.
$before_duplicate_backend = $GLOBALS['wpdb']->rows[$option]['option_value'];
$duplicate_backend = $store->begin($begin_intent('peertube-primary'), 8, 1001);
$assert(
    Atomic_Option_Result::REFUSED === $duplicate_backend['result']->status(),
    'A second open operation for one backend was not refused.'
);
$assert(
    $before_duplicate_backend === $GLOBALS['wpdb']->rows[$option]['option_value'],
    'Refusing a duplicate-backend operation changed the journal.'
);

// An allowlisted event applies only at the exact record revision. Reusing the
// stale revision conflicts and preserves the newer event journal exactly.
$planned = $store->apply_event(
    $record['operation_id'],
    1,
    Machine::EVENT_PLAN_SECRET_RESERVATION,
    $mutation('secret_reserve', '6', false),
    1001
);
$assert(Atomic_Option_Result::APPLIED === $planned->status(), 'Exact-revision event did not apply.');
$planned_record = $store->get($record['operation_id']);
$assert(
    is_array($planned_record)
    && 2 === $planned_record['record_revision']
    && Machine::PHASE_SECRET_RESERVE_PLANNED === $planned_record['phase'],
    'Exact-revision event did not persist the expected next record.'
);
$before_stale_event = $GLOBALS['wpdb']->rows[$option]['option_value'];
$stale_event = $store->apply_event(
    $record['operation_id'],
    1,
    Machine::EVENT_CONFIRM_SECRET_RESERVED,
    array(),
    1002
);
$assert(Atomic_Option_Result::CONFLICT === $stale_event->status(), 'A stale record revision did not conflict.');
$assert(
    Atomic_Option_Result::PHASE_VALIDATION === $stale_event->phase(),
    'A stale record revision must conflict at validation.'
);
$assert(
    $before_stale_event === $GLOBALS['wpdb']->rows[$option]['option_value'],
    'A stale record-revision conflict changed the newer journal.'
);

// A concurrent whole-journal mutation after snapshot but before SQL makes the
// option CAS conflict. The competing record survives byte-for-byte.
$store = $reset();
$first = $store->begin($begin_intent('peertube-cas-a'), 7, 2000)['record'];
$second = $store->begin($begin_intent('peertube-cas-b'), 7, 2001)['record'];
$assert(is_array($first) && is_array($second), 'CAS fixture setup failed.');
$concurrent = $external_record(90, 'peertube-concurrent');
$GLOBALS['wpdb']->before_query = static function (Awvp_Operation_Fake_Wpdb $database) use (
    $option,
    $concurrent
): void {
    $journal = unserialize(
        $database->rows[$option]['option_value'],
        array('allowed_classes' => false)
    );
    if (! is_array($journal)) {
        throw new RuntimeException('Concurrent fixture could not decode the journal.');
    }
    $journal['operations'][$concurrent['operation_id']] = $concurrent;
    $database->rows[$option]['option_value'] = serialize($journal);
};
$cas_conflict = $store->apply_event(
    $first['operation_id'],
    1,
    Machine::EVENT_PLAN_SECRET_RESERVATION,
    $mutation('secret_reserve', '7', false),
    2002
);
$assert(Atomic_Option_Result::CONFLICT === $cas_conflict->status(), 'Concurrent journal update did not conflict.');
$assert(
    Atomic_Option_Result::PHASE_SQL === $cas_conflict->phase(),
    'A concurrent journal update must conflict at the exact SQL predicate.'
);
$concurrent_journal = $stored_journal();
$assert(
    $concurrent === ($concurrent_journal['operations'][$concurrent['operation_id']] ?? null),
    'CAS conflict did not preserve the competing journal record.'
);
$assert(
    1 === ($concurrent_journal['operations'][$first['operation_id']]['record_revision'] ?? null),
    'CAS conflict partially applied the losing event.'
);

// Future and malformed authoritative journals fail closed and are never
// rewritten as a side effect of begin/read operations.
foreach (
    array(
        'future-version' => array('version' => 2, 'operations' => array()),
        'extra-field' => array('version' => 1, 'operations' => array(), 'future' => true),
        'malformed-record' => array(
            'version' => 1,
            'operations' => array(
                'connection_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => array('version' => 1),
            ),
        ),
    ) as $case => $invalid_journal
) {
    $store = $reset();
    $seed_journal($invalid_journal);
    $before_invalid = $GLOBALS['wpdb']->rows[$option]['option_value'];
    $invalid_begin = $store->begin($begin_intent('peertube-invalid-' . $case), 7, 3000);
    $assert(
        Atomic_Option_Result::REFUSED === $invalid_begin['result']->status(),
        'Invalid journal was not refused: ' . $case
    );
    $assert(null === $store->open_operations(), 'Invalid journal was exposed: ' . $case);
    $assert(
        $before_invalid === $GLOBALS['wpdb']->rows[$option]['option_value']
        && [] === $GLOBALS['wpdb']->mutations,
        'Invalid journal was rewritten: ' . $case
    );
}

// Externally seeded journals must retain global uniqueness of both secret
// references and provisioning IDs, even when every individual record is valid.
foreach (array('secret_ref', 'provisioning_id') as $duplicate_field) {
    $store = $reset();
    $record_one = $external_record(101, 'peertube-unique-one');
    $record_two = $external_record(102, 'peertube-unique-two');
    $record_two[$duplicate_field] = $record_one[$duplicate_field];
    $assert(Machine::valid($record_two), 'Duplicate fixture must remain individually valid.');
    $duplicate_journal = array(
        'version' => 1,
        'operations' => array(
            $record_one['operation_id'] => $record_one,
            $record_two['operation_id'] => $record_two,
        ),
    );
    $seed_journal($duplicate_journal);
    $before_duplicate = $GLOBALS['wpdb']->rows[$option]['option_value'];
    $duplicate_begin = $store->begin($begin_intent('peertube-unique-third'), 7, 4000);
    $assert(
        Atomic_Option_Result::REFUSED === $duplicate_begin['result']->status(),
        'Externally duplicated ' . $duplicate_field . ' was not refused.'
    );
    $assert(null === $store->open_operations(), 'Duplicate journal was exposed: ' . $duplicate_field);
    $assert(
        $before_duplicate === $GLOBALS['wpdb']->rows[$option]['option_value'],
        'Duplicate journal was changed: ' . $duplicate_field
    );
}

// Each identity generated by begin() is classified as a conflict if it is
// already durably reserved. Deterministic test entropy exercises all three
// independently without weakening production randomness.
$collision_record = $external_record(150, 'peertube-collision-existing');
$collision_journal = array(
    'version' => 1,
    'operations' => array($collision_record['operation_id'] => $collision_record),
);
$collision_cases = array(
    'operation_id' => array(
        substr($collision_record['operation_id'], strlen('connection_')),
        str_repeat('a', 32),
        str_repeat('b', 32),
    ),
    'secret_ref' => array(
        str_repeat('c', 32),
        substr($collision_record['secret_ref'], strlen('managed_')),
        str_repeat('d', 32),
    ),
    'provisioning_id' => array(
        str_repeat('e', 32),
        str_repeat('f', 32),
        substr($collision_record['provisioning_id'], strlen('provision_')),
    ),
);
foreach ($collision_cases as $field => $entropy) {
    $store = $reset();
    $seed_journal($collision_journal);
    Awvp_Operation_Test_Entropy::queue_hex(...$entropy);
    $before_collision = $GLOBALS['wpdb']->rows[$option]['option_value'];
    $collision = $store->begin($begin_intent('peertube-collision-' . $field), 7, 4500);
    $assert(
        Atomic_Option_Result::CONFLICT === $collision['result']->status(),
        'Generated ' . $field . ' collision was not classified conflict.'
    );
    $assert(
        Atomic_Option_Result::PHASE_VALIDATION === $collision['result']->phase(),
        'Generated ' . $field . ' collision was not classified at validation.'
    );
    $assert(
        $before_collision === $GLOBALS['wpdb']->rows[$option]['option_value']
            && [] === $GLOBALS['wpdb']->mutations,
        'Generated ' . $field . ' collision changed the journal.'
    );
}

// The bounded journal accepts exactly 32 unresolved operations with unique
// generated identities. A 33rd begin is refused without evicting any record.
$store = $reset();
$operation_ids = array();
$secret_refs = array();
$provisioning_ids = array();
for ($index = 1; $index <= Operation_Store::MAX_OPERATIONS; $index++) {
    $entry = $store->begin(
        $begin_intent(sprintf('peertube-limit-%02d', $index)),
        7,
        5000 + $index
    );
    $assert(
        Atomic_Option_Result::APPLIED === $entry['result']->status()
        && is_array($entry['record']),
        'Could not fill unresolved journal position ' . $index . '.'
    );
    $operation_ids[] = $entry['record']['operation_id'];
    $secret_refs[] = $entry['record']['secret_ref'];
    $provisioning_ids[] = $entry['record']['provisioning_id'];
}
$assert(
    Operation_Store::MAX_OPERATIONS === count(array_unique($operation_ids))
    && Operation_Store::MAX_OPERATIONS === count(array_unique($secret_refs))
    && Operation_Store::MAX_OPERATIONS === count(array_unique($provisioning_ids)),
    'Generated operation, secret, or provisioning identities collided.'
);
$full_journal_raw = $GLOBALS['wpdb']->rows[$option]['option_value'];
$overflow = $store->begin($begin_intent('peertube-limit-overflow'), 7, 6000);
$assert(Atomic_Option_Result::REFUSED === $overflow['result']->status(), 'The 33rd unresolved operation was accepted.');
$assert(
    $full_journal_raw === $GLOBALS['wpdb']->rows[$option]['option_value']
    && Operation_Store::MAX_OPERATIONS === count($store->open_operations() ?? array()),
    'Capacity refusal evicted or rewrote an unresolved operation.'
);

// Removal is revision-bound and limited to terminal records. A prepared record
// cannot be removed, a stale terminal revision conflicts, and the exact
// terminal revision removes only that completed record.
$store = $reset();
$prepared_record = $external_record(201, 'peertube-removal');
$seed_journal(array(
    'version' => 1,
    'operations' => array($prepared_record['operation_id'] => $prepared_record),
));
$before_incomplete_remove = $GLOBALS['wpdb']->rows[$option]['option_value'];
$incomplete_remove = $store->remove_complete(
    $prepared_record['operation_id'],
    $prepared_record['record_revision']
);
$assert(Atomic_Option_Result::REFUSED === $incomplete_remove->status(), 'An unresolved operation was removed.');
$assert(
    $before_incomplete_remove === $GLOBALS['wpdb']->rows[$option]['option_value'],
    'Refused unresolved removal changed the journal.'
);

$completed_record = $complete_record($prepared_record);
$seed_journal(array(
    'version' => 1,
    'operations' => array($completed_record['operation_id'] => $completed_record),
));
$before_stale_remove = $GLOBALS['wpdb']->rows[$option]['option_value'];
$stale_remove = $store->remove_complete(
    $completed_record['operation_id'],
    $completed_record['record_revision'] - 1
);
$assert(Atomic_Option_Result::CONFLICT === $stale_remove->status(), 'A stale completed revision did not conflict.');
$assert(
    Atomic_Option_Result::PHASE_VALIDATION === $stale_remove->phase(),
    'A stale completed revision must conflict at validation.'
);
$assert(
    $before_stale_remove === $GLOBALS['wpdb']->rows[$option]['option_value'],
    'Stale completed removal changed the journal.'
);
$removed = $store->remove_complete(
    $completed_record['operation_id'],
    $completed_record['record_revision']
);
$assert(Atomic_Option_Result::APPLIED === $removed->status(), 'Exact completed removal did not apply.');
$after_removal = $stored_journal();
$assert(
    array('version' => 1, 'operations' => array()) === $after_removal,
    'Completed removal changed more than the selected operation.'
);
$assert(null === $store->get($completed_record['operation_id']), 'Removed completed operation remained readable.');

echo 'AWVP PeerTube connection operation-store tests passed ('
    . (function_exists('wp_autoload_values_to_autoload') ? 'modern/off' : 'wp64/no')
    . ").\n";

}

// EOF: tests/peertube-connection-operation-store.php
