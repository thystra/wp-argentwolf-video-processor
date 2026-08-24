<?php
/**
 * Real-WordPress persistence assertions for the R35 development checkpoint.
 */

declare(strict_types=1);

use ArgentVideo\Atomic_Option_Result;
use ArgentVideo\Atomic_Option_Store;
use ArgentVideo\Backend_Registry;
use ArgentVideo\Managed_Backend_Secret_Store;
use ArgentVideo\PeerTube_Connection_Operation_Store;
use ArgentVideo\PeerTube_Connection_State_Machine;

if (! defined('ABSPATH')) {
    throw new RuntimeException('The persistence fixture requires a loaded WordPress runtime.');
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    is_plugin_active('argentwolf-video-processor/argentwolf-video-processor.php'),
    'The AWVP plugin is not active.'
);
$assert(defined('WP_DEBUG') && true === WP_DEBUG, 'WP_DEBUG must be enabled.');
$assert(
    defined('WP_HTTP_BLOCK_EXTERNAL') && true === WP_HTTP_BLOCK_EXTERNAL,
    'External WordPress HTTP must be blocked.'
);

global $wpdb;
$assert(is_object($wpdb) && isset($wpdb->options), 'The WordPress options table is unavailable.');

$raw_row = static function (string $option) use ($wpdb): ?array {
    $query = $wpdb->prepare(
        'SELECT option_value, autoload FROM %i WHERE option_name = %s LIMIT 1',
        $wpdb->options,
        $option
    );
    $row = $wpdb->get_row($query, ARRAY_A);
    if ('' !== (string) $wpdb->last_error) {
        throw new RuntimeException('An authoritative option read failed.');
    }
    return is_array($row) ? $row : null;
};

$decode_row = static function (?array $row): ?array {
    if (! is_array($row) || ! is_string($row['option_value'] ?? null)) {
        return null;
    }
    $value = unserialize($row['option_value'], array('allowed_classes' => false));
    return is_array($value) ? $value : null;
};

$clear_option_cache = static function (string $option): void {
    wp_cache_delete($option, 'options');
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
};

$assert_nonautoload = static function (string $option) use ($assert, $raw_row): void {
    $row = $raw_row($option);
    $assert(is_array($row), 'Expected a persisted non-autoload option.');
    $autoload = (string) ($row['autoload'] ?? '');
    $expected = function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no';
    $assert($expected === $autoload, 'The raw non-autoload representation was not version-correct.');
    $assert(
        ! array_key_exists($option, wp_load_alloptions(true)),
        'A persistence option appeared in wp_load_alloptions().'
    );
};

$upload_directory = wp_get_upload_dir();
$assert(
    is_array($upload_directory)
        && false === ($upload_directory['error'] ?? null)
        && is_string($upload_directory['basedir'] ?? null)
        && '' !== $upload_directory['basedir'],
    'WordPress did not resolve a usable upload directory.'
);
$upload_root = (string) $upload_directory['basedir'];

$uploads_snapshot = static function (string $root): array {
    $snapshot = array(
        'root'    => 'absent',
        'entries' => array(),
    );

    if (is_link($root)) {
        $target = readlink($root);
        $snapshot['root'] = 'symlink:' . (is_string($target) ? $target : 'unreadable');
        return $snapshot;
    }

    if (! file_exists($root)) {
        return $snapshot;
    }

    if (is_file($root)) {
        $hash = hash_file('sha256', $root);
        $size = filesize($root);
        $snapshot['root'] = 'file:' . (is_int($size) ? (string) $size : 'unreadable') . ':'
            . (is_string($hash) ? $hash : 'unreadable');
        return $snapshot;
    }

    if (! is_dir($root)) {
        $snapshot['root'] = 'other';
        return $snapshot;
    }

    $snapshot['root'] = 'directory';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        $relative = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
        if ($entry->isLink()) {
            $target = $entry->getLinkTarget();
            $snapshot['entries'][$relative] = 'symlink:'
                . (is_string($target) ? $target : 'unreadable');
        } elseif ($entry->isDir()) {
            $snapshot['entries'][$relative] = 'directory';
        } elseif ($entry->isFile()) {
            $hash = hash_file('sha256', $path);
            if (! is_string($hash)) {
                throw new RuntimeException('An uploads snapshot could not hash a file.');
            }
            $snapshot['entries'][$relative] = 'file:' . $entry->getSize() . ':' . $hash;
        } else {
            $snapshot['entries'][$relative] = 'other';
        }
    }
    ksort($snapshot['entries'], SORT_STRING);
    return $snapshot;
};

$uploads_before = $uploads_snapshot($upload_root);
$http_requests = 0;
$http_guard = static function (mixed $preempt, array $arguments, string $url) use (&$http_requests): WP_Error {
    unset($preempt, $arguments, $url);
    ++$http_requests;
    return new WP_Error('awvp_r35_http_refused', 'Unexpected HTTP in persistence smoke.');
};
add_filter('pre_http_request', $http_guard, 1, 3);

/*
 * Open one genuinely independent database connection. A one-shot `query`
 * filter uses it to change the authoritative row after the production code's
 * snapshot/pre-action and immediately before its exact SQL predicate runs.
 */
$db_host = (string) DB_HOST;
$db_port = 3306;
if (1 === preg_match('/^([^:]+):([1-9][0-9]*)$/D', $db_host, $host_match)) {
    $db_host = $host_match[1];
    $db_port = (int) $host_match[2];
}
$independent_db = mysqli_init();
$assert(false !== $independent_db, 'Could not initialize the independent database connection.');
$connected = @mysqli_real_connect(
    $independent_db,
    $db_host,
    (string) DB_USER,
    (string) DB_PASSWORD,
    (string) DB_NAME,
    $db_port
);
$assert(true === $connected, 'Could not establish the independent database connection.');
$assert(
    true === mysqli_set_charset($independent_db, 'utf8mb4'),
    'Could not configure the independent database connection.'
);
$assert(
    1 === preg_match('/^[A-Za-z0-9_]+$/D', (string) $wpdb->options),
    'The options table identifier is outside the fixture contract.'
);
$options_table_sql = '`' . (string) $wpdb->options . '`';

$independent_value_update = static function (string $option, array $value) use (
    $assert,
    $independent_db,
    $options_table_sql
): void {
    $raw = serialize($value);
    $statement = mysqli_prepare(
        $independent_db,
        'UPDATE ' . $options_table_sql . ' SET option_value = ? WHERE option_name = ?'
    );
    $assert(false !== $statement, 'Could not prepare an independent value mutation.');
    $assert(
        true === mysqli_stmt_bind_param($statement, 'ss', $raw, $option),
        'Could not bind an independent value mutation.'
    );
    $assert(true === mysqli_stmt_execute($statement), 'The independent value mutation failed.');
    $assert(1 === mysqli_stmt_affected_rows($statement), 'The independent value mutation did not change one row.');
    mysqli_stmt_close($statement);
};

$independent_autoload_update = static function (string $option, string $autoload) use (
    $assert,
    $independent_db,
    $options_table_sql
): void {
    $statement = mysqli_prepare(
        $independent_db,
        'UPDATE ' . $options_table_sql . ' SET autoload = ? WHERE option_name = ?'
    );
    $assert(false !== $statement, 'Could not prepare an independent autoload mutation.');
    $assert(
        true === mysqli_stmt_bind_param($statement, 'ss', $autoload, $option),
        'Could not bind an independent autoload mutation.'
    );
    $assert(true === mysqli_stmt_execute($statement), 'The independent autoload mutation failed.');
    $assert(1 === mysqli_stmt_affected_rows($statement), 'The independent autoload mutation did not change one row.');
    mysqli_stmt_close($statement);
};

$run_interleaved = static function (
    string $verb,
    string $option,
    callable $mutation,
    callable $operation
) use ($assert): mixed {
    $fired = false;
    $needle = "option_name = '" . $option . "'";
    $filter = static function (string $query) use (
        &$fired,
        $verb,
        $needle,
        $mutation
    ): string {
        if (
            ! $fired
            && 1 === preg_match('/^\\s*' . preg_quote($verb, '/') . '\\b/i', $query)
            && str_contains($query, $needle)
        ) {
            $fired = true;
            $mutation();
        }
        return $query;
    };

    add_filter('query', $filter, 10, 1);
    try {
        $result = $operation();
    } finally {
        remove_filter('query', $filter, 10);
    }
    $assert($fired, 'The deterministic SQL interleaving point was not reached.');
    return $result;
};

// Byte-authoritative create/update/CAS, cache invalidation, actions, rollback,
// and explicit non-autoload behavior on both supported WordPress generations.
$atomic_option = 'argentwolf_video_processor_r35_atomic_smoke';
$assert(null === $raw_row($atomic_option), 'The disposable atomic option was not initially absent.');
$hooks = array();
add_action('add_option', static function (string $option) use (&$hooks, $atomic_option): void {
    if ($atomic_option === $option) {
        $hooks[] = 'add_option';
    }
}, 10, 1);
add_action('add_option_' . $atomic_option, static function () use (&$hooks): void {
    $hooks[] = 'add_option_dynamic';
}, 10, 0);
add_action('added_option', static function (string $option) use (&$hooks, $atomic_option): void {
    if ($atomic_option === $option) {
        $hooks[] = 'added_option';
    }
}, 10, 1);
add_action('update_option', static function (string $option) use (&$hooks, $atomic_option): void {
    if ($atomic_option === $option) {
        $hooks[] = 'update_option';
    }
}, 10, 1);
add_action('update_option_' . $atomic_option, static function () use (&$hooks): void {
    $hooks[] = 'update_option_dynamic';
}, 10, 0);
add_action('updated_option', static function (string $option) use (&$hooks, $atomic_option): void {
    if ($atomic_option === $option) {
        $hooks[] = 'updated_option';
    }
}, 10, 1);
add_action('delete_option', static function (string $option) use (&$hooks, $atomic_option): void {
    if ($atomic_option === $option) {
        $hooks[] = 'delete_option';
    }
}, 10, 1);
add_action('delete_option_' . $atomic_option, static function () use (&$hooks): void {
    $hooks[] = 'delete_option_dynamic';
}, 10, 0);
add_action('deleted_option', static function (string $option) use (&$hooks, $atomic_option): void {
    if ($atomic_option === $option) {
        $hooks[] = 'deleted_option';
    }
}, 10, 1);

$atomic = new Atomic_Option_Store($atomic_option);
$absent = $atomic->snapshot();
$assert($absent->is_absent(), 'The atomic snapshot did not classify absence.');
$assert('__absent__' === get_option($atomic_option, '__absent__'), 'Could not prime the absent option cache.');
$created_value = array('version' => 1, 'marker' => 'created');
$created = $atomic->compare_exchange($absent, $created_value);
$assert(Atomic_Option_Result::APPLIED === $created->status(), 'The atomic create did not apply.');
$assert(Atomic_Option_Result::MUTATION_APPLIED === $created->mutation(), 'The atomic create mutation was misclassified.');
$assert(serialize($created_value) === ($raw_row($atomic_option)['option_value'] ?? null), 'Atomic create bytes differed.');
$assert($created_value === get_option($atomic_option), 'Atomic create left a stale option cache.');
$assert_nonautoload($atomic_option);
$assert(
    array('add_option', 'add_option_dynamic', 'added_option') === $hooks,
    'Atomic create actions did not match the WordPress action contract.'
);

$created_snapshot = $atomic->snapshot();
$updated_value = array('version' => 1, 'marker' => 'updated');
$updated = $atomic->compare_exchange($created_snapshot, $updated_value);
$assert(Atomic_Option_Result::APPLIED === $updated->status(), 'The atomic update did not apply.');
$assert($updated_value === get_option($atomic_option), 'Atomic update left a stale option cache.');
$assert(
    array(
        'add_option',
        'add_option_dynamic',
        'added_option',
        'update_option',
        'update_option_dynamic',
        'updated_option',
    ) === $hooks,
    'Atomic update actions did not match the WordPress action contract.'
);

$stale_snapshot = $atomic->snapshot();
$assert($updated_value === get_option($atomic_option), 'Could not prime the stale atomic cache case.');
$concurrent_value = array('version' => 1, 'marker' => 'concurrent-winner');
$stale = $run_interleaved(
    'UPDATE',
    $atomic_option,
    static function () use ($independent_value_update, $atomic_option, $concurrent_value): void {
        $independent_value_update($atomic_option, $concurrent_value);
    },
    static function () use ($atomic, $stale_snapshot): Atomic_Option_Result {
        return $atomic->compare_exchange(
            $stale_snapshot,
            array('version' => 1, 'marker' => 'must-not-win')
        );
    }
);
$assert(Atomic_Option_Result::CONFLICT === $stale->status(), 'A stale atomic update was not classified conflict.');
$assert(serialize($concurrent_value) === ($raw_row($atomic_option)['option_value'] ?? null), 'A stale CAS replaced its winner.');
$assert($concurrent_value === get_option($atomic_option), 'A stale CAS conflict left the independent winner hidden by cache.');

$clear_option_cache($atomic_option);
$rollback_before = $atomic->snapshot();
$rollback_write = $atomic->compare_exchange(
    $rollback_before,
    array('version' => 1, 'marker' => 'rollback-candidate')
);
$assert(Atomic_Option_Result::APPLIED === $rollback_write->status(), 'The rollback candidate did not apply.');
$rolled_back = $atomic->rollback($rollback_write);
$assert(Atomic_Option_Result::APPLIED === $rolled_back->status(), 'An unchanged conditional rollback did not apply.');
$assert(serialize($concurrent_value) === ($raw_row($atomic_option)['option_value'] ?? null), 'Conditional rollback did not restore exact before bytes.');

$clear_option_cache($atomic_option);
$conflict_before = $atomic->snapshot();
$conflict_write = $atomic->compare_exchange(
    $conflict_before,
    array('version' => 1, 'marker' => 'rollback-race-candidate')
);
$assert(Atomic_Option_Result::APPLIED === $conflict_write->status(), 'The rollback-race write did not apply.');
$rollback_winner = array('version' => 1, 'marker' => 'rollback-race-winner');
$rollback_conflict = $run_interleaved(
    'UPDATE',
    $atomic_option,
    static function () use ($independent_value_update, $atomic_option, $rollback_winner): void {
        $independent_value_update($atomic_option, $rollback_winner);
    },
    static function () use ($atomic, $conflict_write): Atomic_Option_Result {
        return $atomic->rollback($conflict_write);
    }
);
$assert(Atomic_Option_Result::CONFLICT === $rollback_conflict->status(), 'A stale rollback was not classified conflict.');
$assert(serialize($rollback_winner) === ($raw_row($atomic_option)['option_value'] ?? null), 'A stale rollback replaced its winner.');
$clear_option_cache($atomic_option);
$deleted = $atomic->compare_delete($atomic->snapshot());
$assert(Atomic_Option_Result::APPLIED === $deleted->status(), 'The disposable atomic option could not be deleted exactly.');
$assert(null === $raw_row($atomic_option), 'The exact atomic delete left a row behind.');
$assert('__absent__' === get_option($atomic_option, '__absent__'), 'Atomic delete left a stale option cache.');
$assert(
    array('delete_option', 'delete_option_dynamic', 'deleted_option') === array_slice($hooks, -3),
    'Atomic delete actions did not match the WordPress action contract.'
);

$autoload_refusal_option = 'argentwolf_video_processor_r35_autoload_refusal';
$autoload_refusal_value = array('version' => 1, 'marker' => 'must-remain-autoloaded');
$assert(
    add_option($autoload_refusal_option, $autoload_refusal_value, '', true),
    'Could not seed the autoload-refusal case.'
);
$autoload_refusal_before = $raw_row($autoload_refusal_option);
$assert(is_array($autoload_refusal_before), 'The autoload-refusal row was not stored.');
$autoload_value = (string) ($autoload_refusal_before['autoload'] ?? '');
$autoloaded_values = function_exists('wp_autoload_values_to_autoload')
    ? wp_autoload_values_to_autoload()
    : array('yes');
$assert(in_array($autoload_value, $autoloaded_values, true), 'The refusal fixture was not autoloaded.');
$autoload_refusal_store = new Atomic_Option_Store($autoload_refusal_option);
$autoload_refused = $autoload_refusal_store->compare_exchange(
    $autoload_refusal_store->snapshot(),
    array('version' => 1, 'marker' => 'must-not-repair')
);
$assert(Atomic_Option_Result::REFUSED === $autoload_refused->status(), 'An autoloaded target was not refused.');
$assert($autoload_refusal_before === $raw_row($autoload_refusal_option), 'Autoload refusal rewrote or repaired the target row.');
$assert(delete_option($autoload_refusal_option), 'Could not remove the autoload-refusal fixture row.');
echo "AUTHORITATIVE_RAW_CAS=PASS\n";
echo "ATOMIC_CACHE_HOOKS_NONAUTOLOAD=PASS\n";
echo "ATOMIC_AUTOLOAD_REPAIR_REFUSED=PASS\n";

// Create-only disabled registry append and exact preservation of current-v1
// fields that belong to a future writer.
$registry_option = Backend_Registry::OPTION;
$assert(null === $raw_row($registry_option), 'The fresh site unexpectedly contained a backend registry.');
$local_descriptor = static function (): array {
    return array(
        'id'                  => 'local',
        'type'                => 'local',
        'label'               => 'Local AWVP',
        'state'               => 'active',
        'default_destination' => '',
        'secret_ref'          => '',
        'config_version'      => 1,
        'config'              => array(),
    );
};
$peertube_descriptor = static function (string $id, string $secret_ref): array {
    return array(
        'id'                  => $id,
        'type'                => 'peertube',
        'label'               => 'Persistence PeerTube ' . $id,
        'state'               => 'disabled',
        'default_destination' => '',
        'secret_ref'          => $secret_ref,
        'config_version'      => 1,
        'config'              => array('origin' => 'https://video.example.invalid'),
    );
};
$registry = new Backend_Registry();
$first_descriptor = $peertube_descriptor('persistence-one', 'managed_' . str_repeat('1', 32));
$first_append = $registry->create_disabled_peertube($first_descriptor);
$assert(Atomic_Option_Result::APPLIED === $first_append->status(), 'The absent-registry append did not apply.');
$registry_value = $decode_row($raw_row($registry_option));
$assert(1 === ($registry_value['version'] ?? null), 'The registry version was not created.');
$assert('active' === ($registry_value['backends']['local']['state'] ?? null), 'The absent-registry local backend was not active.');
$assert($first_descriptor === ($registry_value['backends']['persistence-one'] ?? null), 'The disabled descriptor was not stored exactly.');
$assert($first_descriptor === $registry->get('persistence-one'), 'The registry create left a stale option cache.');
$assert_nonautoload($registry_option);

$future_registry = $registry_value;
$future_registry['future_registry_field'] = array('writer' => 7, 'flags' => array(true, false));
$future_registry['backends']['future-kind'] = array(
    'id'                  => 'future-kind',
    'type'                => 'future-kind',
    'label'               => 'Future Kind',
    'state'               => 'retired',
    'default_destination' => 'opaque-destination',
    'secret_ref'          => 'managed:future-kind',
    'config_version'      => 7,
    'config'              => array('nested' => array('revision' => 9)),
    'future_field'        => 'preserve-verbatim',
);
$registry_atomic = new Atomic_Option_Store($registry_option);
$seed_future = $registry_atomic->compare_exchange($registry_atomic->snapshot(), $future_registry);
$assert(Atomic_Option_Result::APPLIED === $seed_future->status(), 'Could not seed valid future registry state.');
$second_descriptor = $peertube_descriptor('persistence-two', 'managed_' . str_repeat('2', 32));
$second_append = $registry->create_disabled_peertube($second_descriptor);
$assert(Atomic_Option_Result::APPLIED === $second_append->status(), 'The future-state registry append did not apply.');
$after_future_append = $decode_row($raw_row($registry_option));
$assert($second_descriptor === ($after_future_append['backends']['persistence-two'] ?? null), 'The second disabled descriptor was not stored.');
$assert($second_descriptor === $registry->get('persistence-two'), 'The registry update left a stale option cache.');
$assert_nonautoload($registry_option);
$preserved_future = $after_future_append;
unset($preserved_future['backends']['persistence-two']);
$assert($future_registry === $preserved_future, 'The append reconstructed or changed future registry state.');

$registry_winner = $after_future_append;
$registry_winner['concurrent_marker'] = array('winner' => true);
$third_descriptor = $peertube_descriptor('persistence-three', 'managed_' . str_repeat('3', 32));
$assert($after_future_append === get_option($registry_option), 'Could not prime the stale registry cache case.');
$registry_conflict = $run_interleaved(
    'UPDATE',
    $registry_option,
    static function () use ($independent_value_update, $registry_option, $registry_winner): void {
        $independent_value_update($registry_option, $registry_winner);
    },
    static function () use ($registry, $third_descriptor): Atomic_Option_Result {
        return $registry->create_disabled_peertube($third_descriptor);
    }
);
$assert(Atomic_Option_Result::CONFLICT === $registry_conflict->status(), 'A stale registry append was not classified conflict.');
$assert(serialize($registry_winner) === ($raw_row($registry_option)['option_value'] ?? null), 'A stale registry append replaced its winner.');
$assert($registry_winner === get_option($registry_option), 'A stale registry conflict left the independent winner hidden by cache.');
$assert(! isset($decode_row($raw_row($registry_option))['backends']['persistence-three']), 'A conflicted registry append appeared to succeed.');
echo "DISABLED_REGISTRY_APPEND=PASS\n";
echo "REGISTRY_FUTURE_STATE_PRESERVATION=PASS\n";

// Durable journal begin, generated identities, revision advance, and stale
// revision conflict without changing authoritative bytes.
$journal_option = PeerTube_Connection_Operation_Store::OPTION;
$assert(null === $raw_row($journal_option), 'The fresh site unexpectedly contained a connection journal.');
$journal_store = new PeerTube_Connection_Operation_Store();
$begun = $journal_store->begin(
    array(
        'backend_id' => 'persistence-journal',
        'origin'     => 'https://journal.example.invalid',
        'label'      => 'Persistence Journal',
    ),
    1,
    2000000000
);
$record = $begun['record'] ?? null;
$begin_result = $begun['result'] ?? null;
$assert($begin_result instanceof Atomic_Option_Result, 'Journal begin did not return a classified result.');
$assert(Atomic_Option_Result::APPLIED === $begin_result->status(), 'Journal begin did not apply.');
$assert(is_array($record), 'Journal begin did not return its record.');
$assert(1 === preg_match('/^connection_[a-f0-9]{32}$/D', (string) ($record['operation_id'] ?? '')), 'Journal operation ID was not generated.');
$assert(1 === preg_match('/^managed_[a-f0-9]{32}$/D', (string) ($record['secret_ref'] ?? '')), 'Journal secret reference was not reserved.');
$assert(1 === preg_match('/^provision_[a-f0-9]{32}$/D', (string) ($record['provisioning_id'] ?? '')), 'Journal provisioning ID was not reserved.');
$assert_nonautoload($journal_option);
$mutation = array(
    'kind'          => 'secret_reserve',
    'mutation_id'   => 'mutation_' . str_repeat('4', 32),
    'before_exists' => false,
    'before_sha256' => '',
    'before_bytes'  => 0,
    'after_exists'  => true,
    'after_sha256'  => str_repeat('a', 64),
    'after_bytes'   => 128,
);
$event_result = $journal_store->apply_event(
    (string) $record['operation_id'],
    1,
    PeerTube_Connection_State_Machine::EVENT_PLAN_SECRET_RESERVATION,
    $mutation,
    2000000001
);
$assert(Atomic_Option_Result::APPLIED === $event_result->status(), 'The exact-revision journal event did not apply.');
$advanced_record = $journal_store->get((string) $record['operation_id']);
$assert(2 === ($advanced_record['record_revision'] ?? null), 'The journal record revision did not advance exactly once.');
$assert_nonautoload($journal_option);

$journal_before_race = $decode_row($raw_row($journal_option));
$assert(is_array($journal_before_race), 'Could not read the journal race fixture.');
$journal_winner_record = PeerTube_Connection_State_Machine::apply(
    $advanced_record,
    PeerTube_Connection_State_Machine::EVENT_CONFIRM_SECRET_RESERVED,
    array(),
    2000000003
);
$assert(is_array($journal_winner_record), 'Could not construct a valid concurrent journal winner.');
$journal_winner = $journal_before_race;
$journal_winner['operations'][(string) $record['operation_id']] = $journal_winner_record;
$assert($journal_before_race === get_option($journal_option), 'Could not prime the journal CAS cache case.');
$journal_race = $run_interleaved(
    'UPDATE',
    $journal_option,
    static function () use ($independent_value_update, $journal_option, $journal_winner): void {
        $independent_value_update($journal_option, $journal_winner);
    },
    static function () use ($journal_store, $record): Atomic_Option_Result {
        return $journal_store->apply_event(
            (string) $record['operation_id'],
            2,
            PeerTube_Connection_State_Machine::EVENT_CONFIRM_SECRET_RESERVED,
            array(),
            2000000002
        );
    }
);
$assert(Atomic_Option_Result::CONFLICT === $journal_race->status(), 'A stale journal CAS was not classified conflict.');
$assert(
    Atomic_Option_Result::PHASE_SQL === $journal_race->phase(),
    'A real journal CAS race was not classified at the SQL phase.'
);
$journal_after_event = serialize($journal_winner);
$assert($journal_after_event === ($raw_row($journal_option)['option_value'] ?? null), 'A stale journal CAS replaced its winner.');
$assert($journal_winner === get_option($journal_option), 'A stale journal CAS left the independent winner hidden by cache.');

$stale_event = $journal_store->apply_event(
    (string) $record['operation_id'],
    1,
    PeerTube_Connection_State_Machine::EVENT_PLAN_SECRET_RESERVATION,
    $mutation,
    2000000002
);
$assert(Atomic_Option_Result::CONFLICT === $stale_event->status(), 'A stale journal revision was not classified conflict.');
$assert(
    Atomic_Option_Result::PHASE_VALIDATION === $stale_event->phase(),
    'A pre-SQL stale journal revision was not classified at validation.'
);
$assert($journal_after_event === ($raw_row($journal_option)['option_value'] ?? null), 'A stale journal event changed the journal.');
echo "CONNECTION_JOURNAL_REVISION_CAS=PASS\n";

// Reserve a known managed reference, commit only its exact pending slot,
// prove ciphertext-at-rest and reads, then preserve the winner on stale
// generation replacement and a delete interleaving.
$secret_store = new Managed_Backend_Secret_Store();
$assert($secret_store->available(), 'The managed secret store is unavailable in real WordPress.');
$secret_ref = (string) $record['secret_ref'];
$backend_id = (string) $record['backend_id'];
$provisioning_id = (string) $record['provisioning_id'];
$reserved = $secret_store->reserve($secret_ref, $backend_id, $provisioning_id);
$assert(Atomic_Option_Result::APPLIED === $reserved->status(), 'The managed pending reservation did not apply.');
$assert(
    array('state' => Managed_Backend_Secret_Store::PROVISION_PENDING, 'generation' => 0)
        === $secret_store->provisioning_state($secret_ref, $backend_id, $provisioning_id),
    'The managed reservation was not observably pending.'
);
$secret_record_option = Managed_Backend_Secret_Store::OPTION . '_' . $secret_ref;
$assert_nonautoload(Managed_Backend_Secret_Store::OPTION);
$assert_nonautoload($secret_record_option);

$secret_one = array(
    'access_token'       => 'r35-access-token-value-one',
    'refresh_token'      => 'r35-refresh-token-value-one',
    'access_expires_at'  => 2100000000,
    'refresh_expires_at' => 2200000000,
);
$committed = $secret_store->commit_reserved($secret_ref, $backend_id, $provisioning_id, $secret_one);
$assert(Atomic_Option_Result::APPLIED === $committed->status(), 'The managed encrypted commit did not apply.');
$assert_nonautoload($secret_record_option);
$expected_read_one = $secret_one;
$expected_read_one['generation'] = 1;
$assert($expected_read_one === $secret_store->read($secret_ref, $backend_id), 'The committed managed secret did not round-trip.');
$secret_row = $raw_row($secret_record_option);
foreach (array($secret_one['access_token'], $secret_one['refresh_token']) as $plaintext) {
    $assert(false === strpos((string) ($secret_row['option_value'] ?? ''), $plaintext), 'A managed token was stored as plaintext.');
}

$secret_two = array(
    'access_token'       => 'r35-access-token-value-two',
    'refresh_token'      => 'r35-refresh-token-value-two',
    'access_expires_at'  => 2100000100,
    'refresh_expires_at' => 2200000100,
);
$replaced = $secret_store->replace_classified($secret_ref, $backend_id, $secret_two, 1);
$assert(Atomic_Option_Result::APPLIED === $replaced->status(), 'The exact-generation managed replacement did not apply.');
$assert_nonautoload($secret_record_option);
$expected_read_two = $secret_two;
$expected_read_two['generation'] = 2;
$assert($expected_read_two === $secret_store->read($secret_ref, $backend_id), 'The replacement did not advance/read generation two.');
$ready_raw = $raw_row($secret_record_option)['option_value'] ?? null;
$stale_secret = array(
    'access_token'       => 'r35-access-token-must-not-win',
    'refresh_token'      => '',
    'access_expires_at'  => 2100000200,
    'refresh_expires_at' => 0,
);
$stale_replace = $secret_store->replace_classified($secret_ref, $backend_id, $stale_secret, 1);
$assert(Atomic_Option_Result::CONFLICT === $stale_replace->status(), 'A stale secret generation was not classified conflict.');
$assert($ready_raw === ($raw_row($secret_record_option)['option_value'] ?? null), 'A stale secret replacement changed ciphertext.');
$stale_generation_delete = $secret_store->delete_classified($secret_ref, $backend_id, 1);
$assert(Atomic_Option_Result::CONFLICT === $stale_generation_delete->status(), 'A stale secret delete generation was not classified conflict.');
$assert($ready_raw === ($raw_row($secret_record_option)['option_value'] ?? null), 'A stale generation delete changed ciphertext.');

$delete_conflict = $run_interleaved(
    'DELETE',
    $secret_record_option,
    static function () use ($independent_autoload_update, $secret_record_option): void {
        $independent_autoload_update($secret_record_option, 'auto-off');
    },
    static function () use ($secret_store, $secret_ref, $backend_id): Atomic_Option_Result {
        return $secret_store->delete_classified($secret_ref, $backend_id, 2);
    }
);
$assert(Atomic_Option_Result::CONFLICT === $delete_conflict->status(), 'A concurrently stale secret delete was not classified conflict.');
$assert($ready_raw === ($raw_row($secret_record_option)['option_value'] ?? null), 'A stale secret delete changed ciphertext.');
$clear_option_cache($secret_record_option);
$assert($expected_read_two === $secret_store->read($secret_ref, $backend_id), 'A stale delete did not preserve the readable secret.');

$pending_ref = 'managed_' . str_repeat('b', 32);
$pending_provisioning = 'provision_' . str_repeat('c', 32);
$pending_option = Managed_Backend_Secret_Store::OPTION . '_' . $pending_ref;
$second_reserve = $secret_store->reserve($pending_ref, 'persistence-pending', $pending_provisioning);
$assert(Atomic_Option_Result::APPLIED === $second_reserve->status(), 'The reconciliation reservation did not apply.');
$pending_raw = $raw_row($pending_option)['option_value'] ?? null;
$wrong_pending_delete = $secret_store->delete_reserved_if_pending(
    $pending_ref,
    'persistence-pending',
    'provision_' . str_repeat('d', 32)
);
$assert(Atomic_Option_Result::CONFLICT === $wrong_pending_delete->status(), 'A foreign pending cleanup was not classified conflict.');
$assert($pending_raw === ($raw_row($pending_option)['option_value'] ?? null), 'Foreign pending cleanup changed the reservation.');
$pending_delete = $secret_store->delete_reserved_if_pending(
    $pending_ref,
    'persistence-pending',
    $pending_provisioning
);
$assert(Atomic_Option_Result::APPLIED === $pending_delete->status(), 'The exact pending reconciliation delete did not apply.');
$assert(null === $raw_row($pending_option), 'The reconciled pending reservation still exists.');

$secret_rows = $wpdb->get_col(
    $wpdb->prepare(
        'SELECT option_value FROM %i WHERE option_name = %s OR option_name LIKE %s',
        $wpdb->options,
        Managed_Backend_Secret_Store::OPTION,
        $wpdb->esc_like(Managed_Backend_Secret_Store::OPTION . '_') . '%'
    )
);
$assert('' === (string) $wpdb->last_error, 'The final managed-secret scan failed.');
foreach ($secret_rows as $stored_secret_value) {
    foreach (array_merge(
        array_values($secret_one),
        array_values($secret_two),
        array_values($stale_secret)
    ) as $candidate_plaintext) {
        if (is_string($candidate_plaintext) && '' !== $candidate_plaintext) {
            $assert(false === strpos((string) $stored_secret_value, $candidate_plaintext), 'Plaintext secret material appeared in WordPress options.');
        }
    }
}
echo "MANAGED_PENDING_RECONCILIATION=PASS\n";
echo "MANAGED_SECRET_ENCRYPTION_CAS=PASS\n";

remove_filter('pre_http_request', $http_guard, 1);
$assert(0 === $http_requests, 'Persistence primitives attempted WordPress HTTP.');
$assert(
    $uploads_before === $uploads_snapshot($upload_root),
    'Persistence primitives changed the WordPress-resolved uploads tree.'
);
mysqli_close($independent_db);
echo "WORDPRESS_HTTP_REQUEST_COUNT=0\n";
echo "WORDPRESS_UPLOAD_MUTATIONS=0\n";
echo "PEERTUBE_PERSISTENCE_WORDPRESS_ASSERTIONS=PASS\n";

// EOF: tests/fixtures/peertube-persistence-smoke/assert-persistence.php
