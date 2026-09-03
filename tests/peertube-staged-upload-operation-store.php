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
        return \Awvp_Upload_Store_Test_Entropy::bytes($length);
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

$GLOBALS['awvp_upload_store_actions'] = array();
$GLOBALS['awvp_upload_store_cache_deletes'] = array();

final class Awvp_Upload_Store_Test_Entropy
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
    $GLOBALS['awvp_upload_store_actions'][] = array($hook, $arguments);
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    $GLOBALS['awvp_upload_store_cache_deletes'][] = array($key, $group);
    return true;
}

final class Awvp_Upload_Store_Fake_Wpdb
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
        $token = 'awvp-upload-store-prepared-' . (++$this->query_id);
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
            throw new RuntimeException('Unexpected upload-store read query.');
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
            throw new RuntimeException('Unexpected upload-store mutation query.');
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

        throw new RuntimeException('Unsupported upload-store mutation query.');
    }
}

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Snapshot.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Result.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Store.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_Input.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Source_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Upload_State_Machine.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Upload_Operation_Store.php';

use ArgentVideo\Atomic_Option_Result;
use ArgentVideo\PeerTube_Staged_Upload_Operation_Store as Operation_Store;
use ArgentVideo\PeerTube_Staged_Upload_State_Machine as Machine;

$option = Operation_Store::OPTION;
$expected_autoload = function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$reset = static function (): Operation_Store {
    Awvp_Upload_Store_Test_Entropy::reset();
    $GLOBALS['wpdb'] = new Awvp_Upload_Store_Fake_Wpdb();
    $GLOBALS['awvp_upload_store_actions'] = array();
    $GLOBALS['awvp_upload_store_cache_deletes'] = array();
    return new Operation_Store();
};

$source = static function (string $digit = 'a'): array {
    return array(
        'kind'          => 'wordpress_staging',
        'relative_path' => '77/staging/source-' . $digit . '.mp4',
        'sha256'        => str_repeat($digit, 64),
        'bytes'         => 123456,
    );
};

$intent = static function (string $backend_id = 'peertube-primary', string $digit = 'a') use ($source): array {
    return array(
        'video_post_id'  => 77,
        'backend_id'     => $backend_id,
        'origin'         => 'https://video.example.org',
        'destination_id' => '41',
        'source'         => $source($digit),
        'upload'         => array(
            'filename'     => 'source-' . $digit . '.mp4',
            'content_type' => 'video/mp4',
            'name'         => 'Staged upload ' . strtoupper($digit),
            'privacy'      => Machine::PRIVATE_PRIVACY,
        ),
    );
};

$store = $reset();
Awvp_Upload_Store_Test_Entropy::queue_hex(str_repeat('1', 32));
$begun = $store->begin($intent(), 7, 1000);
$record = $begun['record'];
$assert(is_array($record), 'Valid staged-upload begin did not return a record.');
$assert(Atomic_Option_Result::APPLIED === $begun['result']->status(), 'Absent staged-upload journal begin did not apply.');
$assert('upload_' . str_repeat('1', 32) === $record['operation_id'], 'Deterministic upload operation ID drifted.');
$assert(Machine::PHASE_READY === $record['phase'], 'Stored upload operation did not start ready.');
$assert($record === $store->get($record['operation_id']), 'Stored upload record did not read back exactly.');
$assert($expected_autoload === $GLOBALS['wpdb']->rows[$option]['autoload'], 'Upload journal was not explicitly non-autoloaded.');

$raw = $GLOBALS['wpdb']->rows[$option]['option_value'] ?? '';
$journal = is_string($raw) ? unserialize($raw, array('allowed_classes' => false)) : null;
$assert(
    is_array($journal)
    && array('version', 'operations') === array_keys($journal)
    && array($record['operation_id']) === array_keys($journal['operations']),
    'Upload journal envelope drifted.'
);
$assert(
    ! str_contains((string) $raw, '/tmp/')
    && ! str_contains((string) $raw, 'access_token')
    && ! str_contains((string) $raw, 'refresh_token'),
    'Upload journal contains a forbidden absolute-path or credential marker.'
);

$claim = $store->apply_event(
    $record['operation_id'],
    1,
    Machine::EVENT_CLAIM_UPLOAD,
    array('attempt_capability' => str_repeat('2', 64), 'request_kind'=>'init', 'request_start'=>0, 'request_bytes'=>0),
    1001
);
$assert(Atomic_Option_Result::APPLIED === $claim->status(), 'Exact revision upload claim did not apply.');
$claimed = $store->get($record['operation_id']);
$assert(is_array($claimed) && 2 === $claimed['record_revision'], 'Upload claim revision did not persist.');
$assert(Machine::PHASE_UPLOAD_IN_FLIGHT === $claimed['phase'], 'Upload claim phase did not persist.');

$stale = $store->apply_event(
    $record['operation_id'],
    1,
    Machine::EVENT_UPLOAD_INDETERMINATE,
    array(
        'attempt_capability' => str_repeat('2', 64),
        'code'               => 'peertube.upload.indeterminate',
        'http_status'        => 0,
    ),
    1002
);
$assert(Atomic_Option_Result::CONFLICT === $stale->status(), 'Stale upload journal revision did not conflict.');

$indeterminate = $store->apply_event(
    $record['operation_id'],
    2,
    Machine::EVENT_UPLOAD_INDETERMINATE,
    array(
        'attempt_capability' => str_repeat('2', 64),
        'code'               => 'peertube.upload.indeterminate',
        'http_status'        => 0,
    ),
    1002
);
$assert(Atomic_Option_Result::APPLIED === $indeterminate->status(), 'Indeterminate upload fence did not persist.');
$current = $store->get($record['operation_id']);
$assert(is_array($current) && Machine::PHASE_UPLOAD_INDETERMINATE === $current['phase'], 'Indeterminate phase did not persist.');
$assert(
    Atomic_Option_Result::REFUSED === $store->apply_event(
        $record['operation_id'],
        3,
        Machine::EVENT_CLAIM_UPLOAD,
        array('attempt_capability' => str_repeat('3', 64), 'request_kind'=>'init', 'request_start'=>0, 'request_bytes'=>0),
        1003
    )->status(),
    'Indeterminate journal record permitted silent upload replay.'
);

// The same immutable source/destination intent is fenced even after a future
// terminal state; operation IDs are not an idempotency escape hatch.
Awvp_Upload_Store_Test_Entropy::queue_hex(str_repeat('4', 32));
$duplicate = $store->begin($intent(), 7, 1010);
$assert(Atomic_Option_Result::CONFLICT === $duplicate['result']->status(), 'Duplicate staged-upload intent was not fenced.');

// A distinct source commitment is a distinct intent and may coexist.
Awvp_Upload_Store_Test_Entropy::queue_hex(str_repeat('5', 32));
$distinct = $store->begin($intent('peertube-primary', 'b'), 7, 1011);
$assert(Atomic_Option_Result::APPLIED === $distinct['result']->status(), 'Distinct staged-upload intent was incorrectly blocked.');
$open = $store->open_operations();
$assert(is_array($open) && 2 === count($open), 'Open upload journal projection drifted.');

// Unsupported autoload state is fail-closed for both reads and writes.
$GLOBALS['wpdb']->rows[$option]['autoload'] = 'yes';
$assert(null === $store->get($record['operation_id']), 'Autoloaded upload journal was exposed.');
$refused = $store->apply_event(
    $record['operation_id'],
    3,
    Machine::EVENT_RECONCILE_REMOTE_FOUND,
    array('remote_identity' => array(
        'id'   => '901',
        'uuid' => '12345678-1234-4abc-9def-1234567890ab',
    )),
    1012
);
$assert(Atomic_Option_Result::REFUSED === $refused->status(), 'Autoloaded upload journal mutation was not refused.');

fwrite(STDOUT, "PeerTube staged upload operation-store tests passed.\n");
}
