<?php
/**
 * Focused dependency-free tests for the create-only PeerTube registry writer.
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

$GLOBALS['awvp_registry_atomic_actions'] = array();
$GLOBALS['awvp_registry_atomic_callbacks'] = array();
$GLOBALS['awvp_registry_filtered_reads'] = 0;

function sanitize_text_field(mixed $value): string
{
    return trim(strip_tags((string) $value));
}

/** @return array<string, mixed>|false */
function wp_parse_url(string $url): array|false
{
    $parsed = parse_url($url);
    return is_array($parsed) ? $parsed : false;
}

function get_option(string $option, mixed $default = false): mixed
{
    unset($option, $default);
    ++$GLOBALS['awvp_registry_filtered_reads'];

    // Deliberately poisoned. The consequential writer must never source its
    // before value from this filtered/cached API.
    return array(
        'version'  => 99,
        'backends' => array(),
    );
}

function do_action(string $hook, mixed ...$arguments): void
{
    $GLOBALS['awvp_registry_atomic_actions'][] = array($hook, $arguments);
    $callback = $GLOBALS['awvp_registry_atomic_callbacks'][$hook] ?? null;
    if (is_callable($callback)) {
        $callback(...$arguments);
    }
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    unset($key, $group);
    return true;
}

final class Awvp_Registry_Atomic_Fake_Wpdb
{
    public string $options = 'wp_options';
    public string $last_error = '';

    /** @var array<string, array{option_value:string,autoload:string}> */
    public array $rows = array();

    /** @var array<string, array{template:string,args:list<mixed>}> */
    private array $prepared = array();

    /** @var list<array{template:string,args:list<mixed>}> */
    public array $mutations = array();

    private int $query_id = 0;

    public function prepare(string $query, mixed ...$arguments): string
    {
        $token = 'awvp-registry-prepared-' . (++$this->query_id);
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
            throw new RuntimeException('Unexpected fake registry read query.');
        }

        $maximum_bytes = (int) ($prepared['args'][0] ?? 0);
        $option = (string) ($prepared['args'][2] ?? '');
        $row = $this->rows[$option] ?? null;
        $this->last_error = '';

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
            throw new RuntimeException('Unexpected fake registry mutation query.');
        }

        $this->mutations[] = $prepared;
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

        throw new RuntimeException('Unsupported fake registry mutation query.');
    }
}

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Snapshot.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Result.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Store.php';
require_once dirname(__DIR__) . '/includes/Backend_Registry.php';

use ArgentVideo\Atomic_Option_Result;
use ArgentVideo\Backend_Registry;

$option = Backend_Registry::OPTION;
$expected_autoload = function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$descriptor = static function (string $id = 'home-peertube'): array {
    return array(
        'id'                  => $id,
        'type'                => 'peertube',
        'label'               => 'Home PeerTube',
        'state'               => 'disabled',
        'default_destination' => '',
        'secret_ref'          => 'managed:' . $id,
        'config_version'      => 1,
        'config'              => array('origin' => 'https://video.example.com'),
    );
};

$local = static function (string $state = 'active'): array {
    return array(
        'id'                  => 'local',
        'type'                => 'local',
        'label'               => 'Local AWVP',
        'state'               => $state,
        'default_destination' => '',
        'secret_ref'          => '',
        'config_version'      => 1,
        'config'              => array(),
    );
};

$reset = static function (): Backend_Registry {
    $GLOBALS['wpdb'] = new Awvp_Registry_Atomic_Fake_Wpdb();
    $GLOBALS['awvp_registry_atomic_actions'] = array();
    $GLOBALS['awvp_registry_atomic_callbacks'] = array();
    $GLOBALS['awvp_registry_filtered_reads'] = 0;
    return new Backend_Registry();
};

$seed = static function (array $value, string $autoload) use ($option): void {
    $GLOBALS['wpdb']->rows[$option] = array(
        'option_value' => serialize($value),
        'autoload'     => $autoload,
    );
};

$stored = static function () use ($option): array {
    $raw = $GLOBALS['wpdb']->rows[$option]['option_value'] ?? null;
    if (! is_string($raw)) {
        throw new RuntimeException('Expected a stored registry row.');
    }

    $value = unserialize($raw, array('allowed_classes' => false));
    if (! is_array($value)) {
        throw new RuntimeException('Expected an array-valued registry row.');
    }
    return $value;
};

// An absent option receives only the compatibility local descriptor and the
// exact validated, disabled PeerTube descriptor. Filtered reads are poisoned
// above, proving the writer's before state comes solely from the raw snapshot.
$registry = $reset();
$candidate = $descriptor();
$created = $registry->create_disabled_peertube($candidate);
$assert(Atomic_Option_Result::APPLIED === $created->status(), 'Absent registry append must apply.');
$assert(Atomic_Option_Result::MUTATION_APPLIED === $created->mutation(), 'Absent append mutation classification mismatch.');
$assert(0 === $GLOBALS['awvp_registry_filtered_reads'], 'Atomic writer consulted the filtered Options API.');
$after = $stored();
$assert(1 === ($after['version'] ?? null), 'Absent append did not create registry v1.');
$assert('active' === ($after['backends']['local']['state'] ?? null), 'Absent append lost the compatibility-active local backend.');
$assert($candidate === ($after['backends']['home-peertube'] ?? null), 'Absent append did not store the exact normalized PeerTube descriptor.');
$assert('disabled' === ($after['backends']['home-peertube']['state'] ?? null), 'PeerTube append must never create an active backend.');
$assert($expected_autoload === $GLOBALS['wpdb']->rows[$option]['autoload'], 'Registry append must create a non-autoloaded option.');

// Unknown current-v1 fields and future descriptor/config state are retained
// byte-for-byte at the PHP value level; only the new keyed descriptor appears.
$registry = $reset();
$future = array(
    'version'               => 1,
    'future_registry_field' => array('writer' => 3, 'flags' => array(true, false)),
    'backends'              => array(
        'local' => $local('active'),
        'future-kind' => array(
            'id'                  => 'future-kind',
            'type'                => 'future-kind',
            'label'               => 'Future Kind',
            'state'               => 'active',
            'default_destination' => 'opaque-destination',
            'secret_ref'          => 'managed:future-kind',
            'config_version'      => 7,
            'config'              => array('future_mode' => 'preserve', 'nested' => array('revision' => 9)),
            'future_field'        => array('opaque' => true),
        ),
        'future-peertube' => array(
            'id'                  => 'future-peertube',
            'type'                => 'peertube',
            'label'               => 'Future PeerTube',
            'state'               => 'retired',
            'default_destination' => 'channel-opaque',
            'secret_ref'          => 'managed:future-peertube',
            'config_version'      => 2,
            'config'              => array('origin' => 'https://future.example.com', 'mode' => 'v2'),
            'future_field'        => 'unchanged',
        ),
    ),
);
$seed($future, $expected_autoload);
$future_candidate = $descriptor('new-peertube');
$future_write = $registry->create_disabled_peertube($future_candidate);
$assert(Atomic_Option_Result::APPLIED === $future_write->status(), 'Current-v1 future-state append must apply.');
$after_future = $stored();
$assert($future_candidate === ($after_future['backends']['new-peertube'] ?? null), 'Future-state append did not add the requested descriptor.');
unset($after_future['backends']['new-peertube']);
$assert($future === $after_future, 'Append reconstructed or changed existing future state.');

// The operation is create-only: an existing target is refused before SQL and
// the authoritative bytes remain unchanged.
$registry = $reset();
$existing = array(
    'version'  => 1,
    'backends' => array(
        'local'         => $local('active'),
        'home-peertube' => $descriptor(),
    ),
);
$seed($existing, $expected_autoload);
$existing_raw = $GLOBALS['wpdb']->rows[$option]['option_value'];
$existing_result = $registry->create_disabled_peertube($descriptor());
$assert(Atomic_Option_Result::REFUSED === $existing_result->status(), 'Existing target must be refused.');
$assert([] === $GLOBALS['wpdb']->mutations, 'Existing-target refusal must precede SQL.');
$assert($existing_raw === $GLOBALS['wpdb']->rows[$option]['option_value'], 'Existing-target refusal changed registry bytes.');

// Active input, future top-level version, malformed current state, and an
// autoloaded option are all refused without producing an active remote.
$registry = $reset();
$active = $descriptor();
$active['state'] = 'active';
$active_result = $registry->create_disabled_peertube($active);
$assert(Atomic_Option_Result::REFUSED === $active_result->status(), 'Active PeerTube input must be refused.');
$assert(! isset($GLOBALS['wpdb']->rows[$option]), 'Active PeerTube refusal created a registry.');

$registry = $reset();
$future_top = array('version' => 2, 'backends' => array('local' => $local('active')));
$seed($future_top, $expected_autoload);
$future_top_raw = $GLOBALS['wpdb']->rows[$option]['option_value'];
$future_top_result = $registry->create_disabled_peertube($descriptor());
$assert(Atomic_Option_Result::REFUSED === $future_top_result->status(), 'Future top-level registry version must be refused.');
$assert($future_top_raw === $GLOBALS['wpdb']->rows[$option]['option_value'], 'Future top-level refusal changed registry bytes.');

$registry = $reset();
$malformed = array('version' => 1, 'backends' => array('local' => 'malformed'));
$seed($malformed, $expected_autoload);
$malformed_result = $registry->create_disabled_peertube($descriptor());
$assert(Atomic_Option_Result::REFUSED === $malformed_result->status(), 'Malformed registry must be refused.');
$assert([] === $GLOBALS['wpdb']->mutations, 'Malformed registry refusal must precede SQL.');

$registry = $reset();
$autoloaded = array('version' => 1, 'backends' => array('local' => $local('active')));
$seed($autoloaded, function_exists('wp_autoload_values_to_autoload') ? 'on' : 'yes');
$autoloaded_raw = $GLOBALS['wpdb']->rows[$option]['option_value'];
$autoloaded_result = $registry->create_disabled_peertube($descriptor());
$assert(Atomic_Option_Result::REFUSED === $autoloaded_result->status(), 'Autoloaded registry must be refused.');
$assert([] === $GLOBALS['wpdb']->mutations, 'Autoloaded registry refusal must precede SQL.');
$assert($autoloaded_raw === $GLOBALS['wpdb']->rows[$option]['option_value'], 'Autoloaded registry refusal changed registry bytes.');

// A pre-action concurrent mutation makes the snapshot stale. Exactly one SQL
// attempt reports conflict, preserves the winner, and never retries/rebases.
$registry = $reset();
$before_conflict = array('version' => 1, 'backends' => array('local' => $local('active')));
$concurrent = $before_conflict;
$concurrent['future_registry_field'] = array('winner' => true);
$seed($before_conflict, $expected_autoload);
$GLOBALS['awvp_registry_atomic_callbacks']['update_option'] = static function () use (
    $option,
    $concurrent,
    $expected_autoload
): void {
    $GLOBALS['wpdb']->rows[$option] = array(
        'option_value' => serialize($concurrent),
        'autoload'     => $expected_autoload,
    );
};
$conflicted = $registry->create_disabled_peertube($descriptor('conflicting-peertube'));
$assert(Atomic_Option_Result::CONFLICT === $conflicted->status(), 'Stale registry append must be classified conflict.');
$assert(Atomic_Option_Result::MUTATION_NONE === $conflicted->mutation(), 'Stale registry conflict must classify no mutation.');
$assert(1 === count($GLOBALS['wpdb']->mutations), 'Stale registry append must attempt CAS exactly once.');
$assert(serialize($concurrent) === $GLOBALS['wpdb']->rows[$option]['option_value'], 'Stale registry append overwrote the concurrent winner.');
$assert(! isset($stored()['backends']['conflicting-peertube']), 'Stale registry append falsely created a backend.');

echo 'AWVP atomic backend registry tests passed ('
    . (function_exists('wp_autoload_values_to_autoload') ? 'modern/off' : 'wp64/no')
    . ").\n";

// EOF: tests/backend-registry-atomic.php
