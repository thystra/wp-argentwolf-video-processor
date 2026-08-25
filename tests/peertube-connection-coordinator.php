<?php
/**
 * Focused dependency-free tests for local PeerTube connection coordination.
 *
 * Run once without AWVP_ATOMIC_MODERN_AUTOLOAD to model the legacy `no`
 * value, and once with it set to 1 to model the modern `off` value.
 */

declare(strict_types=1);

namespace ArgentVideo {
    function random_bytes(int $length): string
    {
        return \Awvp_Coordinator_Test_Entropy::bytes($length);
    }
}

namespace {

define('ARRAY_A', 'ARRAY_A');
define(
    'AUTH_KEY',
    'awvp-coordinator-test-key-with-no-production-value-2026'
);

if ('1' === getenv('AWVP_ATOMIC_MODERN_AUTOLOAD')) {
    function wp_autoload_values_to_autoload(): array
    {
        return array('yes', 'on', 'auto-on', 'auto');
    }
}

$GLOBALS['awvp_coordinator_actions'] = array();
$GLOBALS['awvp_coordinator_action_callbacks'] = array();
$GLOBALS['awvp_coordinator_cache_deletes'] = array();

final class Awvp_Coordinator_Test_Entropy
{
    private static int $counter = 0;

    public static function reset(): void
    {
        self::$counter = 0;
    }

    public static function bytes(int $length): string
    {
        if ($length < 1) {
            throw new RuntimeException('Invalid deterministic entropy length.');
        }

        $output = '';
        while (strlen($output) < $length) {
            self::$counter++;
            $output .= hash(
                'sha256',
                'awvp-coordinator-test-entropy-' . self::$counter,
                true
            );
        }

        return substr($output, 0, $length);
    }
}

function sanitize_text_field(mixed $value): string
{
    return is_string($value) ? trim(strip_tags($value)) : '';
}

/** @return array<string, mixed>|int|string|false|null */
function wp_parse_url(string $url, int $component = -1): array|int|string|false|null
{
    return -1 === $component ? parse_url($url) : parse_url($url, $component);
}

function do_action(string $hook, mixed ...$arguments): void
{
    $GLOBALS['awvp_coordinator_actions'][] = array($hook, $arguments);
    $callback = $GLOBALS['awvp_coordinator_action_callbacks'][$hook] ?? null;
    if (is_callable($callback)) {
        $callback(...$arguments);
    }
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    $GLOBALS['awvp_coordinator_cache_deletes'][] = array($key, $group);
    return true;
}

function get_option(string $option, mixed $default = false): mixed
{
    $row = $GLOBALS['wpdb']->rows[$option] ?? null;
    if (! is_array($row)) {
        return $default;
    }

    $value = @unserialize($row['option_value'], array('allowed_classes' => false));
    return is_array($value) ? $value : $default;
}

final class Awvp_Coordinator_Fake_Wpdb
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

    /** @var 'normal'|'false_no_apply'|'false_apply' */
    public string $next_query_mode = 'normal';

    /** @var array{option:string,row:array{option_value:string,autoload:string}}|null */
    public ?array $row_before_next_query = null;

    /** @var array{option:string,row:array{option_value:string,autoload:string}|null}|null */
    public ?array $row_after_next_query = null;

    public int $reads_after_next_query_before_injection = 0;

    /** @var array{option:string,row:array{option_value:string,autoload:string}|null}|null */
    private ?array $pending_read_injection = null;

    private int $pending_reads_before_injection = 0;

    private int $query_id = 0;

    public function prepare(string $query, mixed ...$arguments): string
    {
        $token = 'awvp-coordinator-prepared-' . (++$this->query_id);
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
            throw new RuntimeException('Unexpected coordinator fixture read query.');
        }

        $maximum_bytes = (int) ($prepared['args'][0] ?? 0);
        $option = (string) ($prepared['args'][2] ?? '');
        if (
            null !== $this->pending_read_injection
            && $option === $this->pending_read_injection['option']
        ) {
            if ($this->pending_reads_before_injection > 0) {
                --$this->pending_reads_before_injection;
            } else {
                $injected = $this->pending_read_injection;
                $this->pending_read_injection = null;
                if (null === $injected['row']) {
                    unset($this->rows[$injected['option']]);
                } else {
                    $this->rows[$injected['option']] = $injected['row'];
                }
            }
        }
        if (($this->failed_reads[$option] ?? 0) > 0) {
            $this->failed_reads[$option]--;
            $this->last_error = 'synthetic targeted coordinator read failure';
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
            throw new RuntimeException('Unexpected coordinator fixture mutation query.');
        }

        $this->mutations[] = $prepared;
        $mode = $this->next_query_mode;
        $this->next_query_mode = 'normal';

        if ('false_no_apply' === $mode) {
            $this->last_error = 'synthetic failed coordinator write';
            return false;
        }

        if (null !== $this->row_before_next_query) {
            $injected = $this->row_before_next_query;
            $this->row_before_next_query = null;
            $this->rows[$injected['option']] = $injected['row'];
        }

        $affected = $this->apply($prepared['template'], $prepared['args']);
        if (null !== $this->row_after_next_query) {
            $this->pending_read_injection = $this->row_after_next_query;
            $this->pending_reads_before_injection =
                $this->reads_after_next_query_before_injection;
            $this->row_after_next_query = null;
            $this->reads_after_next_query_before_injection = 0;
        }
        if ('false_apply' === $mode) {
            $this->last_error = 'synthetic uncertain coordinator write';
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

        throw new RuntimeException('Unsupported coordinator fixture mutation query.');
    }
}

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Snapshot.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Result.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Mutation_Plan.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Plan_Result.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Store.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_State_Machine.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_Operation_Store.php';
require_once dirname(__DIR__) . '/includes/Backend_Secret_Store.php';
require_once dirname(__DIR__) . '/includes/Backend_Secret_Crypto.php';
require_once dirname(__DIR__) . '/includes/Managed_Backend_Secret_Store.php';
require_once dirname(__DIR__) . '/includes/Backend_Registry.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_Coordinator.php';

use ArgentVideo\Atomic_Option_Result;
use ArgentVideo\Backend_Registry;
use ArgentVideo\Backend_Secret_Crypto;
use ArgentVideo\Managed_Backend_Secret_Store;
use ArgentVideo\PeerTube_Connection_Coordinator as Coordinator;
use ArgentVideo\PeerTube_Connection_Operation_Store as Operation_Store;
use ArgentVideo\PeerTube_Connection_State_Machine as Machine;

$expected_autoload = function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no';
$autoloaded_value = function_exists('wp_autoload_values_to_autoload') ? 'on' : 'yes';
$projection_keys = array(
    'status',
    'mutation',
    'operation_id',
    'backend_id',
    'phase',
    'record_revision',
);

function awvp_coordinator_assert(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function awvp_coordinator_reset(): Awvp_Coordinator_Fake_Wpdb
{
    $database = new Awvp_Coordinator_Fake_Wpdb();
    $GLOBALS['wpdb'] = $database;
    $GLOBALS['awvp_coordinator_actions'] = array();
    $GLOBALS['awvp_coordinator_action_callbacks'] = array();
    $GLOBALS['awvp_coordinator_cache_deletes'] = array();
    Awvp_Coordinator_Test_Entropy::reset();
    return $database;
}

function awvp_coordinator_clear_activity(): void
{
    $GLOBALS['wpdb']->mutations = array();
    $GLOBALS['awvp_coordinator_actions'] = array();
    $GLOBALS['awvp_coordinator_cache_deletes'] = array();
}

/** @return array<string, mixed> */
function awvp_coordinator_decode(string $option): array
{
    $row = $GLOBALS['wpdb']->rows[$option] ?? null;
    awvp_coordinator_assert(is_array($row), "Missing fixture option {$option}.");
    $value = @unserialize($row['option_value'], array('allowed_classes' => false));
    awvp_coordinator_assert(is_array($value), "Fixture option {$option} did not decode to an array.");
    return $value;
}

/** @param array<string, mixed> $value */
function awvp_coordinator_seed(
    string $option,
    array $value,
    ?string $autoload = null
): void {
    $GLOBALS['wpdb']->rows[$option] = array(
        'option_value' => serialize($value),
        'autoload'     => $autoload ?? (
            function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no'
        ),
    );
}

/** @return array<string, mixed> */
function awvp_coordinator_record(string $operation_id): array
{
    $journal = awvp_coordinator_decode(Operation_Store::OPTION);
    $record = $journal['operations'][$operation_id] ?? null;
    awvp_coordinator_assert(is_array($record), 'Expected operation record is absent.');
    return $record;
}

/** @return array<string, mixed> */
function awvp_coordinator_intent(): array
{
    return array(
        'backend_id' => 'primary_peertube',
        'origin'     => 'https://video.example.com',
        'label'      => 'Primary PeerTube',
    );
}

/** @return array<string, mixed> */
function awvp_coordinator_start(int $now = 1000): array
{
    return (new Coordinator())->start(awvp_coordinator_intent(), 17, $now);
}

/**
 * @param array{operation_id:string,time:int,result:array<string,mixed>} $path
 * @return array<string, mixed>
 */
function awvp_coordinator_step(array &$path): array
{
    $path['time']++;
    $path['result'] = (new Coordinator())->resume($path['operation_id'], $path['time']);
    return $path['result'];
}

/**
 * Begin a fresh path and execute a fixed number of distinct-request resumes.
 *
 * @return array{operation_id:string,time:int,result:array<string,mixed>}
 */
function awvp_coordinator_drive(int $resumes): array
{
    $result = awvp_coordinator_start();
    $path = array(
        'operation_id' => (string) $result['operation_id'],
        'time'         => 1000,
        'result'       => $result,
    );

    for ($index = 0; $index < $resumes; $index++) {
        awvp_coordinator_step($path);
    }

    return $path;
}

/** @param array<string, mixed> $projection */
function awvp_coordinator_assert_projection(
    array $projection,
    string $status,
    string $mutation,
    string $phase,
    int $revision,
    string $message
): void {
    $keys = array(
        'status',
        'mutation',
        'operation_id',
        'backend_id',
        'phase',
        'record_revision',
    );
    awvp_coordinator_assert(array_keys($projection) === $keys, "{$message}: projection shape changed.");
    awvp_coordinator_assert($status === $projection['status'], "{$message}: unexpected status.");
    awvp_coordinator_assert($mutation === $projection['mutation'], "{$message}: unexpected mutation class.");
    awvp_coordinator_assert($phase === $projection['phase'], "{$message}: unexpected phase.");
    awvp_coordinator_assert($revision === $projection['record_revision'], "{$message}: unexpected revision.");
    awvp_coordinator_assert(strlen(serialize($projection)) < 1024, "{$message}: projection is not bounded.");
}

/** @return list<string> */
function awvp_coordinator_mutation_targets(): array
{
    $targets = array();
    foreach ($GLOBALS['wpdb']->mutations as $mutation) {
        $template = ltrim($mutation['template']);
        if (str_starts_with($template, 'INSERT INTO ')) {
            $targets[] = (string) ($mutation['args'][1] ?? '');
        } elseif (str_starts_with($template, 'UPDATE ')) {
            $targets[] = (string) ($mutation['args'][3] ?? '');
        } elseif (str_starts_with($template, 'DELETE FROM ')) {
            $targets[] = (string) ($mutation['args'][1] ?? '');
        } else {
            $targets[] = 'unsupported';
        }
    }
    return $targets;
}

/** @return array<string, mixed> */
function awvp_coordinator_local_descriptor(): array
{
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
}

/** @return array<string, mixed> */
function awvp_coordinator_other_descriptor(
    string $backend_id,
    string $suffix = 'f'
): array {
    return array(
        'id'                  => $backend_id,
        'type'                => 'peertube',
        'label'               => 'Pre-existing PeerTube',
        'state'               => 'disabled',
        'default_destination' => '',
        'secret_ref'          => 'managed_' . str_repeat($suffix, 32),
        'config_version'      => 1,
        'config'              => array('origin' => 'https://existing.example.net'),
    );
}

awvp_coordinator_assert(
    Backend_Secret_Crypto::available(),
    'The dependency-free fixture requires an available supported crypto provider.'
);

// Input is an exact allowlist: credential-bearing intent is refused before a write.
$database = awvp_coordinator_reset();
$credential_intent = awvp_coordinator_intent() + array(
    'password'      => 'coordinator-password-canary',
    'otp'           => 'coordinator-otp-canary',
    'access_token'  => 'coordinator-access-canary',
    'refresh_token' => 'coordinator-refresh-canary',
);
$credential_result = (new Coordinator())->start($credential_intent, 17, 1000);
awvp_coordinator_assert_projection(
    $credential_result,
    Coordinator::STATUS_REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    '',
    0,
    'Credential-bearing start'
);
awvp_coordinator_assert([] === $database->rows, 'Credential-bearing start created option state.');
awvp_coordinator_assert([] === $database->mutations, 'Credential-bearing start attempted a database mutation.');

// Arbitrary caller text is never reflected through the bounded operation-ID
// projection, including early invalid-time and normal missing-record paths.
$database = awvp_coordinator_reset();
$invalid_operation_id = "r36-access-token-canary\n" . str_repeat('x', 20000);
foreach (array(0, 2000) as $resume_time) {
    $invalid_resume = (new Coordinator())->resume($invalid_operation_id, $resume_time);
    awvp_coordinator_assert_projection(
        $invalid_resume,
        Coordinator::STATUS_REFUSED,
        Atomic_Option_Result::MUTATION_NONE,
        '',
        0,
        "Invalid operation projection at time {$resume_time}"
    );
    awvp_coordinator_assert(
        '' === $invalid_resume['operation_id']
            && '' === $invalid_resume['backend_id']
            && ! str_contains(serialize($invalid_resume), 'r36-access-token-canary'),
        'Invalid operation input escaped through the public projection.'
    );
}
awvp_coordinator_assert([] === $database->rows, 'Invalid operation resume created option state.');
awvp_coordinator_assert([] === $database->mutations, 'Invalid operation resume attempted a mutation.');

// A definitely refused journal cannot make generated local IDs resumable.
// Malformed, future, and autoloaded journal authorities are preserved exactly.
foreach (array('malformed', 'future', 'autoloaded') as $journal_case) {
    $database = awvp_coordinator_reset();
    $journal_value = match ($journal_case) {
        'malformed' => array('version' => 1, 'operations' => 'not-an-operation-map'),
        'future' => array('version' => 99, 'operations' => array()),
        default => array('version' => 1, 'operations' => array()),
    };
    awvp_coordinator_seed(Operation_Store::OPTION, $journal_value);
    if ('autoloaded' === $journal_case) {
        $database->rows[Operation_Store::OPTION]['autoload'] = $autoloaded_value;
    }
    $journal_before = $database->rows[Operation_Store::OPTION];
    awvp_coordinator_clear_activity();
    $refused_start = awvp_coordinator_start();
    awvp_coordinator_assert_projection(
        $refused_start,
        Coordinator::STATUS_REFUSED,
        Atomic_Option_Result::MUTATION_NONE,
        '',
        0,
        ucfirst($journal_case) . ' journal start refusal'
    );
    awvp_coordinator_assert(
        '' === $refused_start['operation_id'] && '' === $refused_start['backend_id'],
        ucfirst($journal_case) . ' journal start exposed a phantom generated identity.'
    );
    awvp_coordinator_assert(
        $journal_before === $database->rows[Operation_Store::OPTION]
            && [] === $database->mutations,
        ucfirst($journal_case) . ' journal start changed its refused authority.'
    );
}

// An already-occupied backend identity is refused before a durable operation
// can strand journal or pending-secret capacity.
$database = awvp_coordinator_reset();
$occupied_at_start = array(
    'version'  => 1,
    'backends' => array(
        'local'             => awvp_coordinator_local_descriptor(),
        'primary_peertube'  => awvp_coordinator_other_descriptor('primary_peertube'),
    ),
);
awvp_coordinator_seed(Backend_Registry::OPTION, $occupied_at_start);
$occupied_start_row = $database->rows[Backend_Registry::OPTION];
awvp_coordinator_clear_activity();
$occupied_start = awvp_coordinator_start();
awvp_coordinator_assert_projection(
    $occupied_start,
    Coordinator::STATUS_REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    '',
    0,
    'Occupied identity start preflight'
);
awvp_coordinator_assert(
    array(Backend_Registry::OPTION) === array_keys($database->rows)
        && $occupied_start_row === $database->rows[Backend_Registry::OPTION],
    'Occupied identity start preflight created or changed durable state.'
);
awvp_coordinator_assert([] === $database->mutations, 'Occupied identity start preflight attempted a mutation.');

// The happy path advances one durable boundary per fresh coordinator instance.
$database = awvp_coordinator_reset();
$happy = awvp_coordinator_drive(0);
$happy_results = array($happy['result']);
awvp_coordinator_assert_projection(
    $happy['result'],
    Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_PREPARED,
    1,
    'Happy start'
);
$operation_id = $happy['operation_id'];
$initial_record = awvp_coordinator_record($operation_id);
$secret_option = Managed_Backend_Secret_Store::OPTION . '_' . $initial_record['secret_ref'];
awvp_coordinator_assert(
    array_keys($database->rows) === array(Operation_Store::OPTION),
    'Happy start persisted more than the journal record.'
);
$mutations_before_duplicate = count($database->mutations);
$duplicate_start = awvp_coordinator_start(1001);
awvp_coordinator_assert_projection(
    $duplicate_start,
    Coordinator::STATUS_REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    '',
    0,
    'Duplicate unresolved backend start'
);
awvp_coordinator_assert(
    '' === $duplicate_start['operation_id'] && '' === $duplicate_start['backend_id'],
    'Definite duplicate-start refusal exposed non-durable generated identities.'
);
awvp_coordinator_assert(
    $mutations_before_duplicate === count($database->mutations),
    'Duplicate unresolved backend start attempted a mutation.'
);

$journal_before_manifest = $database->rows[Operation_Store::OPTION];
$manifest_result = awvp_coordinator_step($happy);
$happy_results[] = $manifest_result;
awvp_coordinator_assert_projection(
    $manifest_result,
    Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_PREPARED,
    1,
    'Manifest boundary'
);
awvp_coordinator_assert(
    $journal_before_manifest === $database->rows[Operation_Store::OPTION],
    'Manifest boundary rewrote the operation journal.'
);
awvp_coordinator_assert(
    array('version' => 1) === awvp_coordinator_decode(Managed_Backend_Secret_Store::OPTION),
    'Manifest boundary did not create only the supported provider manifest.'
);
awvp_coordinator_assert(
    ! isset($database->rows[$secret_option]) && ! isset($database->rows[Backend_Registry::OPTION]),
    'Manifest boundary crossed into operation-specific persistence.'
);

$rows_before_secret_plan = $database->rows;
$activity_before_secret_plan = count($database->mutations);
$secret_plan_result = awvp_coordinator_step($happy);
$happy_results[] = $secret_plan_result;
awvp_coordinator_assert_projection(
    $secret_plan_result,
    Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_SECRET_RESERVE_PLANNED,
    2,
    'Secret planning boundary'
);
awvp_coordinator_assert(! isset($database->rows[$secret_option]), 'Secret planning mutated the target secret option.');
awvp_coordinator_assert(! isset($database->rows[Backend_Registry::OPTION]), 'Secret planning mutated the registry.');
awvp_coordinator_assert(
    array_keys(array_diff_key($database->rows, $rows_before_secret_plan)) === array(),
    'Secret planning created a non-journal option.'
);
awvp_coordinator_assert(
    array(Operation_Store::OPTION) === array_slice(
        awvp_coordinator_mutation_targets(),
        $activity_before_secret_plan
    ),
    'Secret planning did not limit its mutation to journaled evidence.'
);
$secret_plan_record = awvp_coordinator_record($operation_id);
awvp_coordinator_assert(
    'secret_reserve' === $secret_plan_record['last_mutation']['kind'],
    'Secret planning journaled the wrong mutation kind.'
);
$secret_evidence = $secret_plan_record['last_mutation'];

$secret_result = awvp_coordinator_step($happy);
$happy_results[] = $secret_result;
awvp_coordinator_assert_projection(
    $secret_result,
    Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_SECRET_RESERVE_PLANNED,
    2,
    'Secret reservation boundary'
);
$reserved_record = awvp_coordinator_record($operation_id);
$expected_pending = array(
    'version'         => 2,
    'state'           => 'pending',
    'backend_id'      => $reserved_record['backend_id'],
    'provisioning_id' => $reserved_record['provisioning_id'],
    'generation'      => 0,
    'envelope'        => array(),
);
awvp_coordinator_assert(
    $expected_pending === awvp_coordinator_decode($secret_option),
    'Reservation did not create the exact empty pending secret record.'
);
awvp_coordinator_assert(
    $secret_evidence === $reserved_record['last_mutation'],
    'Secret confirmation replaced the journaled evidence.'
);

$secret_confirm_result = awvp_coordinator_step($happy);
$happy_results[] = $secret_confirm_result;
awvp_coordinator_assert_projection(
    $secret_confirm_result,
    Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_SECRET_RESERVED,
    3,
    'Secret confirmation boundary'
);
$reserved_record = awvp_coordinator_record($operation_id);
$asserted_secret_evidence = $reserved_record['last_mutation'];
awvp_coordinator_assert(
    $secret_evidence === $asserted_secret_evidence,
    'Secret confirmation replaced the journaled reservation evidence.'
);

$registry_before_plan = $database->rows[Backend_Registry::OPTION] ?? null;
$activity_before_registry_plan = count($database->mutations);
$link_plan_result = awvp_coordinator_step($happy);
$happy_results[] = $link_plan_result;
awvp_coordinator_assert_projection(
    $link_plan_result,
    Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_LINK_PLANNED,
    4,
    'Registry planning boundary'
);
awvp_coordinator_assert(
    $registry_before_plan === ($database->rows[Backend_Registry::OPTION] ?? null),
    'Registry planning mutated its target option.'
);
awvp_coordinator_assert(
    array(Operation_Store::OPTION) === array_slice(
        awvp_coordinator_mutation_targets(),
        $activity_before_registry_plan
    ),
    'Registry planning did not limit its mutation to journaled evidence.'
);
$link_plan_record = awvp_coordinator_record($operation_id);
awvp_coordinator_assert(
    'registry_link' === $link_plan_record['last_mutation']['kind'],
    'Registry planning journaled the wrong mutation kind.'
);

$link_result = awvp_coordinator_step($happy);
$happy_results[] = $link_result;
awvp_coordinator_assert_projection(
    $link_result,
    Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_LINK_PLANNED,
    4,
    'Disabled-link boundary'
);
$linked_record = awvp_coordinator_record($operation_id);
$expected_descriptor = array(
    'id'                  => $linked_record['backend_id'],
    'type'                => 'peertube',
    'label'               => $linked_record['label'],
    'state'               => 'disabled',
    'default_destination' => '',
    'secret_ref'          => $linked_record['secret_ref'],
    'config_version'      => 1,
    'config'              => array('origin' => $linked_record['origin']),
);
$expected_registry = array(
    'version'  => 1,
    'backends' => array(
        'local'                     => awvp_coordinator_local_descriptor(),
        $linked_record['backend_id'] => $expected_descriptor,
    ),
);
awvp_coordinator_assert(
    $expected_registry === awvp_coordinator_decode(Backend_Registry::OPTION),
    'Coordinator did not create the exact disabled PeerTube descriptor.'
);

$link_confirm_result = awvp_coordinator_step($happy);
$happy_results[] = $link_confirm_result;
awvp_coordinator_assert_projection(
    $link_confirm_result,
    Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_DISABLED,
    5,
    'Disabled-link confirmation boundary'
);
$ready_record = awvp_coordinator_record($operation_id);

$rows_before_ready = $database->rows;
$mutations_before_ready = count($database->mutations);
$ready = awvp_coordinator_step($happy);
$happy_results[] = $ready;
awvp_coordinator_assert_projection(
    $ready,
    Coordinator::STATUS_READY_FOR_GRANT,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_DISABLED,
    5,
    'Ready-for-grant recheck'
);
awvp_coordinator_assert($rows_before_ready === $database->rows, 'Ready recheck changed durable state.');
awvp_coordinator_assert(
    $mutations_before_ready === count($database->mutations),
    'Ready recheck attempted a mutation.'
);

// Unrelated later registry appends do not erase this operation's exact
// disabled-descriptor postcondition.
$shared_registry = $expected_registry;
$shared_registry['backends']['later_peertube'] = awvp_coordinator_other_descriptor(
    'later_peertube',
    'd'
);
awvp_coordinator_seed(Backend_Registry::OPTION, $shared_registry);
$rows_before_idempotent_ready = $database->rows;
$mutations_before_idempotent_ready = count($database->mutations);
$ready_again = awvp_coordinator_step($happy);
$happy_results[] = $ready_again;
awvp_coordinator_assert_projection(
    $ready_again,
    Coordinator::STATUS_READY_FOR_GRANT,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_DISABLED,
    5,
    'Idempotent ready resume'
);
awvp_coordinator_assert(
    $rows_before_idempotent_ready === $database->rows,
    'Idempotent ready resume changed durable state.'
);
awvp_coordinator_assert(
    $mutations_before_idempotent_ready === count($database->mutations),
    'Idempotent ready resume attempted a mutation.'
);

$expected_targets = array(
    Operation_Store::OPTION,
    Managed_Backend_Secret_Store::OPTION,
    Operation_Store::OPTION,
    $secret_option,
    Operation_Store::OPTION,
    Operation_Store::OPTION,
    Backend_Registry::OPTION,
    Operation_Store::OPTION,
);
awvp_coordinator_assert(
    $expected_targets === awvp_coordinator_mutation_targets(),
    'Happy-path persistence boundaries or mutation order changed.'
);
foreach ($database->rows as $option => $row) {
    awvp_coordinator_assert(
        $expected_autoload === $row['autoload'],
        "Coordinator option {$option} was not explicitly non-autoloaded."
    );
}
foreach ($happy_results as $index => $projection) {
    $encoded = serialize($projection);
    awvp_coordinator_assert(
        ! str_contains($encoded, $ready_record['origin'])
        && ! str_contains($encoded, $ready_record['label'])
        && ! str_contains($encoded, 'managed_')
        && ! str_contains($encoded, 'provision_'),
        "Projection {$index} exposed coordinator-private connection state."
    );
}
$all_rows = serialize($database->rows);
foreach (
    array(
        'password',
        'otp',
        'access_token',
        'refresh_token',
        'coordinator-password-canary',
        'coordinator-otp-canary',
        'coordinator-access-canary',
        'coordinator-refresh-canary',
    ) as $forbidden
) {
    awvp_coordinator_assert(
        ! str_contains($all_rows, $forbidden),
        "Coordinator persisted forbidden credential material: {$forbidden}."
    );
}

// An indeterminate journal CAS remains indeterminate whether its exact write
// reached durable state or definitely did not. A durable plan keeps its exact
// evidence; a non-durable plan leaves the old phase authoritative.
$database = awvp_coordinator_reset();
$path = awvp_coordinator_drive(1);
$journal_before_unknown = $database->rows[Operation_Store::OPTION];
$record_before_unknown = awvp_coordinator_record($path['operation_id']);
$secret_option = Managed_Backend_Secret_Store::OPTION . '_' . $record_before_unknown['secret_ref'];
awvp_coordinator_clear_activity();
$database->next_query_mode = 'false_apply';
$unknown_applied_journal = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $unknown_applied_journal,
    Coordinator::STATUS_INDETERMINATE,
    Atomic_Option_Result::MUTATION_UNKNOWN,
    Machine::PHASE_SECRET_RESERVE_PLANNED,
    2,
    'Uncertain applied journal plan'
);
$durable_plan = awvp_coordinator_record($path['operation_id']);
awvp_coordinator_assert(
    $journal_before_unknown !== $database->rows[Operation_Store::OPTION]
        && 'secret_reserve' === $durable_plan['last_mutation']['kind'],
    'Uncertain applied journal plan did not retain its durable evidence.'
);
awvp_coordinator_assert(
    ! isset($database->rows[$secret_option])
        && array(Operation_Store::OPTION) === awvp_coordinator_mutation_targets(),
    'Uncertain applied journal plan crossed its journal-only boundary.'
);
$applied_after_unknown_journal = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $applied_after_unknown_journal,
    Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_SECRET_RESERVE_PLANNED,
    2,
    'Target application after uncertain applied journal plan'
);
awvp_coordinator_assert(
    $durable_plan['last_mutation']
        === awvp_coordinator_record($path['operation_id'])['last_mutation'],
    'Restart after an uncertain applied journal plan replaced durable evidence.'
);

$database = awvp_coordinator_reset();
$path = awvp_coordinator_drive(1);
$journal_before_unknown = $database->rows[Operation_Store::OPTION];
$record_before_unknown = awvp_coordinator_record($path['operation_id']);
$secret_option = Managed_Backend_Secret_Store::OPTION . '_' . $record_before_unknown['secret_ref'];
awvp_coordinator_clear_activity();
$database->next_query_mode = 'false_no_apply';
$unknown_unapplied_journal = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $unknown_unapplied_journal,
    Coordinator::STATUS_INDETERMINATE,
    Atomic_Option_Result::MUTATION_UNKNOWN,
    Machine::PHASE_PREPARED,
    1,
    'Uncertain unapplied journal plan'
);
awvp_coordinator_assert(
    $journal_before_unknown === $database->rows[Operation_Store::OPTION]
        && ! isset($database->rows[$secret_option]),
    'Uncertain unapplied journal plan changed durable coordinator state.'
);
awvp_coordinator_assert(
    array(Operation_Store::OPTION) === awvp_coordinator_mutation_targets(),
    'Uncertain unapplied journal plan attempted an unrelated target.'
);
$planned_after_unknown_journal = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $planned_after_unknown_journal,
    Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_SECRET_RESERVE_PLANNED,
    2,
    'Journal planning after uncertain unapplied write'
);
awvp_coordinator_assert(
    ! isset($database->rows[$secret_option]),
    'Recovery from an uncertain unapplied journal write also changed the target slot.'
);

// Even when the journal writer itself reports APPLIED, the coordinator must
// not report advancement if the following authoritative probe observes the
// old row, absence, an unsupported autoload row, or different valid state.
foreach (array('old', 'absent', 'refused', 'different') as $post_write_state) {
    $database = awvp_coordinator_reset();
    $path = awvp_coordinator_drive(1);
    $operation_id = $path['operation_id'];
    $old_journal_row = $database->rows[Operation_Store::OPTION];
    $old_journal = awvp_coordinator_decode(Operation_Store::OPTION);
    $injected_row = $old_journal_row;
    if ('absent' === $post_write_state) {
        $injected_row = null;
    } elseif ('refused' === $post_write_state) {
        $injected_row['autoload'] = $autoloaded_value;
    } elseif ('different' === $post_write_state) {
        $old_journal['operations'][$operation_id]['label'] = 'Concurrent PeerTube';
        $injected_row['option_value'] = serialize($old_journal);
    }

    awvp_coordinator_clear_activity();
    $database->row_after_next_query = array(
        'option' => Operation_Store::OPTION,
        'row'    => $injected_row,
    );
    // Atomic_Option_Store performs two post-SQL reads before returning an
    // applied result. Inject on the coordinator's subsequent journal probe.
    $database->reads_after_next_query_before_injection = 2;
    $post_write_result = awvp_coordinator_step($path);
    awvp_coordinator_assert_projection(
        $post_write_result,
        Coordinator::STATUS_INDETERMINATE,
        Atomic_Option_Result::MUTATION_APPLIED,
        Machine::PHASE_PREPARED,
        1,
        "Applied journal write followed by {$post_write_state} state"
    );
    awvp_coordinator_assert(
        array(Operation_Store::OPTION) === awvp_coordinator_mutation_targets(),
        "Applied journal write followed by {$post_write_state} state crossed another boundary."
    );
    if (null === $injected_row) {
        awvp_coordinator_assert(
            ! isset($database->rows[Operation_Store::OPTION]),
            'Absent post-write journal probe was not preserved.'
        );
    } else {
        awvp_coordinator_assert(
            $injected_row === $database->rows[Operation_Store::OPTION],
            "The {$post_write_state} post-write journal state was not preserved."
        );
    }
}

// A valid unrelated registry append between target application and journal
// confirmation still satisfies the exact disabled-descriptor postcondition.
$database = awvp_coordinator_reset();
$path = awvp_coordinator_drive(6);
$link_record = awvp_coordinator_record($path['operation_id']);
$shared_registry = awvp_coordinator_decode(Backend_Registry::OPTION);
$between_descriptor = awvp_coordinator_other_descriptor(
    'between_link_and_confirm',
    'c'
);
$shared_registry['backends']['between_link_and_confirm'] = $between_descriptor;
awvp_coordinator_seed(Backend_Registry::OPTION, $shared_registry);
$shared_registry_row = $database->rows[Backend_Registry::OPTION];
awvp_coordinator_clear_activity();
$semantic_confirmation = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $semantic_confirmation,
    Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_DISABLED,
    5,
    'Semantic link confirmation after unrelated append'
);
awvp_coordinator_assert(
    $shared_registry_row === $database->rows[Backend_Registry::OPTION]
        && $between_descriptor
            === awvp_coordinator_decode(Backend_Registry::OPTION)['backends']['between_link_and_confirm'],
    'Semantic link confirmation rewrote or lost the unrelated registry append.'
);
awvp_coordinator_assert(
    array(Operation_Store::OPTION) === awvp_coordinator_mutation_targets(),
    'Semantic link confirmation crossed the journal boundary.'
);
awvp_coordinator_assert(
    'registry_link' === $link_record['last_mutation']['kind'],
    'Semantic link confirmation fixture did not begin with registry evidence.'
);

// The final ready result is a real prerequisite recheck, not a phase-only
// projection. Losing either the pending slot or exact descriptor fails closed
// without attempting a repair or journal transition.
$database = awvp_coordinator_reset();
$path = awvp_coordinator_drive(7);
$disabled_record = awvp_coordinator_record($path['operation_id']);
$secret_option = Managed_Backend_Secret_Store::OPTION . '_' . $disabled_record['secret_ref'];
$journal_before_ready_failure = $database->rows[Operation_Store::OPTION];
$registry_before_ready_failure = $database->rows[Backend_Registry::OPTION];
unset($database->rows[$secret_option]);
awvp_coordinator_clear_activity();
$missing_secret_ready = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $missing_secret_ready,
    Coordinator::STATUS_CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_DISABLED,
    5,
    'Ready recheck with missing pending secret'
);
awvp_coordinator_assert(
    $journal_before_ready_failure === $database->rows[Operation_Store::OPTION]
        && $registry_before_ready_failure === $database->rows[Backend_Registry::OPTION]
        && ! isset($database->rows[$secret_option])
        && [] === awvp_coordinator_mutation_targets(),
    'Missing pending secret ready recheck attempted a repair or transition.'
);

$database = awvp_coordinator_reset();
$path = awvp_coordinator_drive(7);
$disabled_record = awvp_coordinator_record($path['operation_id']);
$secret_option = Managed_Backend_Secret_Store::OPTION . '_' . $disabled_record['secret_ref'];
$registry_without_descriptor = awvp_coordinator_decode(Backend_Registry::OPTION);
unset($registry_without_descriptor['backends'][$disabled_record['backend_id']]);
awvp_coordinator_seed(Backend_Registry::OPTION, $registry_without_descriptor);
$journal_before_ready_failure = $database->rows[Operation_Store::OPTION];
$secret_before_ready_failure = $database->rows[$secret_option];
$registry_before_ready_failure = $database->rows[Backend_Registry::OPTION];
awvp_coordinator_clear_activity();
$missing_descriptor_ready = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $missing_descriptor_ready,
    Coordinator::STATUS_CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_DISABLED,
    5,
    'Ready recheck with missing disabled descriptor'
);
awvp_coordinator_assert(
    $journal_before_ready_failure === $database->rows[Operation_Store::OPTION]
        && $secret_before_ready_failure === $database->rows[$secret_option]
        && $registry_before_ready_failure === $database->rows[Backend_Registry::OPTION]
        && [] === awvp_coordinator_mutation_targets(),
    'Missing disabled descriptor ready recheck attempted a repair or transition.'
);

// An uncertain reservation write keeps the same evidence until a later exact probe confirms it.
$database = awvp_coordinator_reset();
$path = awvp_coordinator_drive(2);
$record_before_unknown = awvp_coordinator_record($path['operation_id']);
$journal_before_unknown = $database->rows[Operation_Store::OPTION];
$secret_option = Managed_Backend_Secret_Store::OPTION . '_' . $record_before_unknown['secret_ref'];
awvp_coordinator_clear_activity();
$database->next_query_mode = 'false_apply';
$unknown_reservation = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $unknown_reservation,
    Coordinator::STATUS_INDETERMINATE,
    Atomic_Option_Result::MUTATION_UNKNOWN,
    Machine::PHASE_SECRET_RESERVE_PLANNED,
    2,
    'Uncertain reservation write'
);
awvp_coordinator_assert(
    $journal_before_unknown === $database->rows[Operation_Store::OPTION],
    'Uncertain reservation write replaced its journal evidence.'
);
awvp_coordinator_assert(
    $record_before_unknown['last_mutation'] === awvp_coordinator_record($path['operation_id'])['last_mutation'],
    'Uncertain reservation write changed its mutation identity.'
);
awvp_coordinator_assert(
    array($secret_option) === awvp_coordinator_mutation_targets(),
    'Uncertain reservation write attempted an unrelated mutation.'
);
$confirmed_reservation = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $confirmed_reservation,
    Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_SECRET_RESERVED,
    3,
    'Reservation reconciliation after uncertain write'
);
awvp_coordinator_assert(
    $record_before_unknown['last_mutation'] === awvp_coordinator_record($path['operation_id'])['last_mutation'],
    'Reservation reconciliation generated fresh evidence instead of reusing the journaled plan.'
);

// Foreign pending secret state is classified and preserved byte-for-byte.
$database = awvp_coordinator_reset();
$path = awvp_coordinator_drive(2);
$planned = awvp_coordinator_record($path['operation_id']);
$secret_option = Managed_Backend_Secret_Store::OPTION . '_' . $planned['secret_ref'];
$foreign_pending = array(
    'version'         => 2,
    'state'           => 'pending',
    'backend_id'      => 'foreign_backend',
    'provisioning_id' => 'provision_' . str_repeat('f', 32),
    'generation'      => 0,
    'envelope'        => array(),
);
awvp_coordinator_seed($secret_option, $foreign_pending);
$foreign_raw = $database->rows[$secret_option];
$foreign_journal = $database->rows[Operation_Store::OPTION];
awvp_coordinator_clear_activity();
$foreign_result = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $foreign_result,
    Coordinator::STATUS_CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_SECRET_RESERVE_PLANNED,
    2,
    'Foreign pending secret'
);
awvp_coordinator_assert($foreign_raw === $database->rows[$secret_option], 'Foreign pending secret was overwritten.');
awvp_coordinator_assert($foreign_journal === $database->rows[Operation_Store::OPTION], 'Foreign pending secret changed the journal.');
awvp_coordinator_assert([] === $database->mutations, 'Foreign pending secret caused a mutation attempt.');
awvp_coordinator_assert(! isset($database->rows[Backend_Registry::OPTION]), 'Foreign pending secret reached registry persistence.');

// A ready encrypted secret is never accepted as this pending reservation or overwritten.
$database = awvp_coordinator_reset();
$path = awvp_coordinator_drive(2);
$planned = awvp_coordinator_record($path['operation_id']);
$secret_option = Managed_Backend_Secret_Store::OPTION . '_' . $planned['secret_ref'];
$secret_store = new Managed_Backend_Secret_Store();
$reserved = $secret_store->reserve(
    $planned['secret_ref'],
    $planned['backend_id'],
    $planned['provisioning_id']
);
awvp_coordinator_assert(Atomic_Option_Result::APPLIED === $reserved->status(), 'Ready-secret setup reservation failed.');
$committed = $secret_store->commit_reserved(
    $planned['secret_ref'],
    $planned['backend_id'],
    $planned['provisioning_id'],
    array(
        'access_token'       => 'coordinator-access-token-canary',
        'refresh_token'      => 'coordinator-refresh-token-canary',
        'access_expires_at'  => 2000003600,
        'refresh_expires_at' => 2002419200,
    )
);
awvp_coordinator_assert(Atomic_Option_Result::APPLIED === $committed->status(), 'Ready-secret setup commit failed.');
$ready_secret_raw = $database->rows[$secret_option];
awvp_coordinator_assert(
    ! str_contains($ready_secret_raw['option_value'], 'coordinator-access-token-canary')
    && ! str_contains($ready_secret_raw['option_value'], 'coordinator-refresh-token-canary'),
    'Ready-secret fixture did not encrypt token material.'
);
$ready_secret_journal = $database->rows[Operation_Store::OPTION];
awvp_coordinator_clear_activity();
$ready_secret_result = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $ready_secret_result,
    Coordinator::STATUS_CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_SECRET_RESERVE_PLANNED,
    2,
    'Ready secret in reservation slot'
);
awvp_coordinator_assert($ready_secret_raw === $database->rows[$secret_option], 'Ready secret was overwritten.');
awvp_coordinator_assert($ready_secret_journal === $database->rows[Operation_Store::OPTION], 'Ready secret changed the journal.');
awvp_coordinator_assert([] === $database->mutations, 'Ready secret caused a coordinator mutation attempt.');

// An occupied target registry identity refuses planning and preserves both stores.
$database = awvp_coordinator_reset();
$path = awvp_coordinator_drive(4);
$reserved_record = awvp_coordinator_record($path['operation_id']);
$occupied_descriptor = awvp_coordinator_other_descriptor($reserved_record['backend_id']);
$occupied_registry = array(
    'version'  => 1,
    'backends' => array(
        'local'                         => awvp_coordinator_local_descriptor(),
        $reserved_record['backend_id'] => $occupied_descriptor,
    ),
);
awvp_coordinator_seed(Backend_Registry::OPTION, $occupied_registry);
$occupied_raw = $database->rows[Backend_Registry::OPTION];
$occupied_secret = $database->rows[
    Managed_Backend_Secret_Store::OPTION . '_' . $reserved_record['secret_ref']
];
$occupied_journal = $database->rows[Operation_Store::OPTION];
awvp_coordinator_clear_activity();
$occupied_result = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $occupied_result,
    Coordinator::STATUS_REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_SECRET_RESERVED,
    3,
    'Occupied registry identity'
);
awvp_coordinator_assert($occupied_raw === $database->rows[Backend_Registry::OPTION], 'Occupied registry was overwritten.');
awvp_coordinator_assert(
    $occupied_secret === $database->rows[Managed_Backend_Secret_Store::OPTION . '_' . $reserved_record['secret_ref']],
    'Occupied registry altered the pending secret.'
);
awvp_coordinator_assert($occupied_journal === $database->rows[Operation_Store::OPTION], 'Occupied registry advanced the journal.');
awvp_coordinator_assert([] === $database->mutations, 'Occupied registry caused a mutation attempt.');

// A pre-existing OTHER state cannot prove the old plan never applied, so it
// remains blocked on the same evidence and is never silently replanned.
$database = awvp_coordinator_reset();
$path = awvp_coordinator_drive(5);
$link_record = awvp_coordinator_record($path['operation_id']);
$old_evidence = $link_record['last_mutation'];
$unrelated_descriptor = awvp_coordinator_other_descriptor('unrelated_peertube', 'e');
$intervening_registry = array(
    'version'  => 1,
    'backends' => array(
        'local'               => awvp_coordinator_local_descriptor(),
        'unrelated_peertube'  => $unrelated_descriptor,
    ),
);
awvp_coordinator_seed(Backend_Registry::OPTION, $intervening_registry);
$intervening_raw = $database->rows[Backend_Registry::OPTION];
awvp_coordinator_clear_activity();
$unexplained_other = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $unexplained_other,
    Coordinator::STATUS_CONFLICT,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_LINK_PLANNED,
    4,
    'Unexplained registry state'
);
awvp_coordinator_assert(
    $old_evidence === awvp_coordinator_record($path['operation_id'])['last_mutation'],
    'Unexplained registry state replaced the existing mutation evidence.'
);
awvp_coordinator_assert(
    $intervening_raw === $database->rows[Backend_Registry::OPTION],
    'Unexplained registry state was overwritten.'
);
awvp_coordinator_assert(
    [] === awvp_coordinator_mutation_targets(),
    'Unexplained registry state caused a mutation attempt.'
);

// A normally returning WordPress pre-action can itself change the target row.
// That is an unknown partial mutation, never a zero-row SQL conflict that may
// authorize a fresh registry plan and mutation identity.
$database = awvp_coordinator_reset();
$path = awvp_coordinator_drive(5);
$link_record = awvp_coordinator_record($path['operation_id']);
$old_evidence = $link_record['last_mutation'];
$link_journal = $database->rows[Operation_Store::OPTION];
$unrelated_descriptor = awvp_coordinator_other_descriptor('unrelated_peertube', 'e');
$intervening_registry = array(
    'version'  => 1,
    'backends' => array(
        'local'              => awvp_coordinator_local_descriptor(),
        'unrelated_peertube' => $unrelated_descriptor,
    ),
);
awvp_coordinator_clear_activity();
$GLOBALS['awvp_coordinator_action_callbacks']['add_option'] = static function (
    string $option
) use ($intervening_registry): void {
    if (Backend_Registry::OPTION === $option) {
        awvp_coordinator_seed(Backend_Registry::OPTION, $intervening_registry);
    }
};
$hook_mutated_link = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $hook_mutated_link,
    Coordinator::STATUS_INDETERMINATE,
    Atomic_Option_Result::MUTATION_UNKNOWN,
    Machine::PHASE_LINK_PLANNED,
    4,
    'Normal-returning pre-action registry mutation'
);
awvp_coordinator_assert(
    $old_evidence === awvp_coordinator_record($path['operation_id'])['last_mutation'],
    'Normal-returning pre-action registry mutation replaced the existing evidence.'
);
awvp_coordinator_assert(
    $link_journal === $database->rows[Operation_Store::OPTION],
    'Normal-returning pre-action registry mutation replanned the journal.'
);
awvp_coordinator_assert(
    $intervening_registry === awvp_coordinator_decode(Backend_Registry::OPTION),
    'Normal-returning pre-action registry mutation was not preserved.'
);
awvp_coordinator_assert(
    [] === awvp_coordinator_mutation_targets(),
    'Normal-returning pre-action registry mutation reached SQL.'
);

// A zero-row exact-before CAS is definite no-mutation authority for one fresh,
// explicitly journaled plan with a new mutation ID.
$database = awvp_coordinator_reset();
$path = awvp_coordinator_drive(5);
$link_record = awvp_coordinator_record($path['operation_id']);
$old_evidence = $link_record['last_mutation'];
$unrelated_descriptor = awvp_coordinator_other_descriptor('unrelated_peertube', 'e');
$intervening_registry = array(
    'version'  => 1,
    'backends' => array(
        'local'              => awvp_coordinator_local_descriptor(),
        'unrelated_peertube' => $unrelated_descriptor,
    ),
);
$database->row_before_next_query = array(
    'option' => Backend_Registry::OPTION,
    'row'    => array(
        'option_value' => serialize($intervening_registry),
        'autoload'     => $expected_autoload,
    ),
);
awvp_coordinator_clear_activity();
$replanned = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $replanned,
    Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_LINK_PLANNED,
    5,
    'Definite registry conflict replan'
);
$replanned_record = awvp_coordinator_record($path['operation_id']);
awvp_coordinator_assert(
    $old_evidence['mutation_id'] !== $replanned_record['last_mutation']['mutation_id'],
    'Definite registry conflict reused the stale mutation ID.'
);
awvp_coordinator_assert(
    'registry_link' === $replanned_record['last_mutation']['kind'],
    'Definite registry conflict replanned the wrong mutation kind.'
);
awvp_coordinator_assert(
    $intervening_registry === awvp_coordinator_decode(Backend_Registry::OPTION),
    'Registry replan reconstructed the concurrent registry state.'
);
awvp_coordinator_assert(
    array(Backend_Registry::OPTION, Operation_Store::OPTION)
        === awvp_coordinator_mutation_targets(),
    'Registry replan did not record the zero-row target attempt and one journal boundary.'
);
$replan_applied = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $replan_applied,
    Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_LINK_PLANNED,
    5,
    'Replanned registry application'
);
$replan_confirmed = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $replan_confirmed,
    Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_DISABLED,
    6,
    'Replanned registry confirmation'
);
$replan_ready = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $replan_ready,
    Coordinator::STATUS_READY_FOR_GRANT,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_DISABLED,
    6,
    'Replanned registry ready recheck'
);
$replan_registry = awvp_coordinator_decode(Backend_Registry::OPTION);
awvp_coordinator_assert(
    $unrelated_descriptor === $replan_registry['backends']['unrelated_peertube'],
    'Replanned registry append reconstructed the unrelated descriptor.'
);
awvp_coordinator_assert(
    isset($replan_registry['backends'][$link_record['backend_id']])
    && 'disabled' === $replan_registry['backends'][$link_record['backend_id']]['state'],
    'Replanned registry append did not create the target disabled descriptor.'
);

// An uncertain registry write also retains evidence and is confirmed on restart.
$database = awvp_coordinator_reset();
$path = awvp_coordinator_drive(5);
$link_record = awvp_coordinator_record($path['operation_id']);
$link_evidence = $link_record['last_mutation'];
$link_journal = $database->rows[Operation_Store::OPTION];
awvp_coordinator_clear_activity();
$database->next_query_mode = 'false_apply';
$unknown_link = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $unknown_link,
    Coordinator::STATUS_INDETERMINATE,
    Atomic_Option_Result::MUTATION_UNKNOWN,
    Machine::PHASE_LINK_PLANNED,
    4,
    'Uncertain registry write'
);
awvp_coordinator_assert($link_journal === $database->rows[Operation_Store::OPTION], 'Uncertain registry write changed the journal.');
awvp_coordinator_assert(
    $link_evidence === awvp_coordinator_record($path['operation_id'])['last_mutation'],
    'Uncertain registry write replaced the journaled evidence.'
);
awvp_coordinator_assert(
    array(Backend_Registry::OPTION) === awvp_coordinator_mutation_targets(),
    'Uncertain registry write attempted an unrelated mutation.'
);
$confirmed_link = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $confirmed_link,
    Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_DISABLED,
    5,
    'Registry reconciliation after uncertain write'
);
awvp_coordinator_assert(
    $link_evidence === awvp_coordinator_record($path['operation_id'])['last_mutation'],
    'Registry reconciliation generated a new mutation ID after an uncertain write.'
);
$ready_after_unknown_link = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $ready_after_unknown_link,
    Coordinator::STATUS_READY_FOR_GRANT,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_DISABLED,
    5,
    'Ready recheck after uncertain registry write'
);

// A manifest read failure while applying a journaled secret-reservation plan
// remains indeterminate. It must not consume the request-local reconstruction,
// mutate the slot, or replace the durable evidence.
$database = awvp_coordinator_reset();
$path = awvp_coordinator_drive(2);
$record = awvp_coordinator_record($path['operation_id']);
$secret_evidence = $record['last_mutation'];
$secret_option = Managed_Backend_Secret_Store::OPTION . '_' . $record['secret_ref'];
$journal_before = $database->rows[Operation_Store::OPTION];
$database->failed_reads[Managed_Backend_Secret_Store::OPTION] = 1;
awvp_coordinator_clear_activity();
$manifest_read_failure = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $manifest_read_failure,
    Coordinator::STATUS_INDETERMINATE,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_SECRET_RESERVE_PLANNED,
    2,
    'Indeterminate manifest read before secret reservation'
);
awvp_coordinator_assert(
    $journal_before === $database->rows[Operation_Store::OPTION],
    'Indeterminate manifest read rewrote the connection journal.'
);
awvp_coordinator_assert(
    $secret_evidence === awvp_coordinator_record($path['operation_id'])['last_mutation'],
    'Indeterminate manifest read replaced the secret reservation evidence.'
);
awvp_coordinator_assert(
    ! isset($database->rows[$secret_option]) && [] === awvp_coordinator_mutation_targets(),
    'Indeterminate manifest read crossed the secret reservation boundary.'
);
$secret_after_manifest_recovery = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $secret_after_manifest_recovery,
    Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    Machine::PHASE_SECRET_RESERVE_PLANNED,
    2,
    'Secret reservation after manifest read recovery'
);
awvp_coordinator_assert(
    isset($database->rows[$secret_option]),
    'Recovered manifest read did not leave the exact pending secret slot.'
);

// Autoloaded journal, manifest, secret, and registry rows all fail closed.
$autoload_cases = array('journal', 'manifest', 'secret', 'registry');
foreach ($autoload_cases as $case) {
    $database = awvp_coordinator_reset();
    $resumes = match ($case) {
        'journal' => 0,
        'manifest' => 1,
        'secret' => 3,
        'registry' => 6,
    };
    $path = awvp_coordinator_drive($resumes);
    $record = awvp_coordinator_record($path['operation_id']);
    $option = match ($case) {
        'journal' => Operation_Store::OPTION,
        'manifest' => Managed_Backend_Secret_Store::OPTION,
        'secret' => Managed_Backend_Secret_Store::OPTION . '_' . $record['secret_ref'],
        'registry' => Backend_Registry::OPTION,
    };
    $database->rows[$option]['autoload'] = $autoloaded_value;
    $rows_before = $database->rows;
    awvp_coordinator_clear_activity();
    $result = awvp_coordinator_step($path);
    awvp_coordinator_assert(
        in_array($result['status'], array(Coordinator::STATUS_REFUSED, Coordinator::STATUS_CONFLICT), true),
        "Autoloaded {$case} row did not fail closed."
    );
    awvp_coordinator_assert($rows_before === $database->rows, "Autoloaded {$case} row was rewritten.");
    awvp_coordinator_assert([] === $database->mutations, "Autoloaded {$case} row caused a mutation attempt.");
}

// A malformed journal remains authoritative and is never repaired in place.
$database = awvp_coordinator_reset();
$path = awvp_coordinator_drive(0);
awvp_coordinator_seed(
    Operation_Store::OPTION,
    array('version' => 1, 'operations' => 'not-an-operation-map')
);
$malformed_journal = $database->rows[Operation_Store::OPTION];
awvp_coordinator_clear_activity();
$malformed_journal_result = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $malformed_journal_result,
    Coordinator::STATUS_REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    '',
    0,
    'Malformed journal'
);
awvp_coordinator_assert($malformed_journal === $database->rows[Operation_Store::OPTION], 'Malformed journal was rewritten.');
awvp_coordinator_assert([] === $database->mutations, 'Malformed journal caused a mutation attempt.');

// Future manifest and registry versions are preserved and refuse advancement.
$database = awvp_coordinator_reset();
$path = awvp_coordinator_drive(0);
awvp_coordinator_seed(Managed_Backend_Secret_Store::OPTION, array('version' => 99));
$future_manifest = $database->rows[Managed_Backend_Secret_Store::OPTION];
$future_manifest_journal = $database->rows[Operation_Store::OPTION];
awvp_coordinator_clear_activity();
$future_manifest_result = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $future_manifest_result,
    Coordinator::STATUS_REFUSED,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_PREPARED,
    1,
    'Future manifest'
);
awvp_coordinator_assert($future_manifest === $database->rows[Managed_Backend_Secret_Store::OPTION], 'Future manifest was rewritten.');
awvp_coordinator_assert($future_manifest_journal === $database->rows[Operation_Store::OPTION], 'Future manifest advanced the journal.');
awvp_coordinator_assert([] === $database->mutations, 'Future manifest caused a mutation attempt.');

foreach (
    array(
        'future' => array('version' => 99, 'backends' => array()),
        'malformed' => array('version' => 1, 'backends' => 'not-a-backend-map'),
    ) as $case => $registry_value
) {
    $database = awvp_coordinator_reset();
    $path = awvp_coordinator_drive(4);
    awvp_coordinator_seed(Backend_Registry::OPTION, $registry_value);
    $registry_before = $database->rows[Backend_Registry::OPTION];
    $journal_before = $database->rows[Operation_Store::OPTION];
    awvp_coordinator_clear_activity();
    $result = awvp_coordinator_step($path);
    awvp_coordinator_assert_projection(
        $result,
        Coordinator::STATUS_REFUSED,
        Atomic_Option_Result::MUTATION_NONE,
        Machine::PHASE_SECRET_RESERVED,
        3,
        ucfirst($case) . ' registry'
    );
    awvp_coordinator_assert($registry_before === $database->rows[Backend_Registry::OPTION], "{$case} registry was rewritten.");
    awvp_coordinator_assert($journal_before === $database->rows[Operation_Store::OPTION], "{$case} registry advanced the journal.");
    awvp_coordinator_assert([] === $database->mutations, "{$case} registry caused a mutation attempt.");
}

// An authoritative read failure is indeterminate and cannot mint or replace evidence.
$database = awvp_coordinator_reset();
$path = awvp_coordinator_drive(5);
$record = awvp_coordinator_record($path['operation_id']);
$evidence = $record['last_mutation'];
$journal_before = $database->rows[Operation_Store::OPTION];
$database->failed_reads[Backend_Registry::OPTION] = 1;
awvp_coordinator_clear_activity();
$read_failure = awvp_coordinator_step($path);
awvp_coordinator_assert_projection(
    $read_failure,
    Coordinator::STATUS_INDETERMINATE,
    Atomic_Option_Result::MUTATION_NONE,
    Machine::PHASE_LINK_PLANNED,
    4,
    'Indeterminate registry read'
);
awvp_coordinator_assert($journal_before === $database->rows[Operation_Store::OPTION], 'Indeterminate registry read rewrote journal evidence.');
awvp_coordinator_assert($evidence === awvp_coordinator_record($path['operation_id'])['last_mutation'], 'Indeterminate registry read changed the mutation ID.');
awvp_coordinator_assert([] === $database->mutations, 'Indeterminate registry read caused a mutation attempt.');

fwrite(
    STDOUT,
    'PeerTube connection coordinator tests passed (' . $expected_autoload . " autoload mode).\n"
);

}
