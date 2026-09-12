<?php
/**
 * File: tests/uninstall-data.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/Uninstall_Data.php';
require_once dirname(__DIR__) . '/includes/Activator.php';
require_once dirname(__DIR__) . '/includes/Model_Activator.php';
require_once dirname(__DIR__) . '/includes/Worker_Log_Repository.php';
require_once dirname(__DIR__) . '/includes/Video_Post_Type.php';
require_once dirname(__DIR__) . '/includes/Video_Meta.php';

use ArgentVideo\Activator;
use ArgentVideo\Model_Activator;
use ArgentVideo\Uninstall_Data;
use ArgentVideo\Video_Meta;
use ArgentVideo\Video_Post_Type;
use ArgentVideo\Worker_Log_Repository;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$sorted = static function (array $values): array {
    $values = array_values(array_unique($values));
    sort($values, SORT_STRING);
    return $values;
};

$expected_tables = array(
    'argent_video_jobs',
    Worker_Log_Repository::TABLE_SUFFIX,
    Model_Activator::REMOTE_ASSETS_TABLE,
    Model_Activator::TASKS_TABLE,
    Model_Activator::EVENTS_TABLE,
    Model_Activator::PUBLICATION_HEALTH_TABLE,
);
$assert(
    $sorted($expected_tables) === $sorted(Uninstall_Data::table_suffixes()),
    'Destructive uninstall table manifest must cover every current AWVP-owned table.'
);

$assert(
    $sorted(array(
        Activator::CRON_HOOK,
        Activator::PEERTUBE_RECOVERY_HOOK,
        Activator::REMOTE_HEALTH_HOOK,
        Activator::BACKEND_MAINTENANCE_HOOK,
    )) === $sorted(Uninstall_Data::cron_hooks()),
    'Destructive uninstall cron manifest must cover every current AWVP scheduled hook.'
);

$assert(
    Video_Post_Type::POST_TYPE === Uninstall_Data::POST_TYPE,
    'Destructive uninstall post-type ownership must match the AWVP Video post type.'
);

$video_meta_keys = array_keys(Video_Meta::definitions());
foreach ($video_meta_keys as $meta_key) {
    $assert(
        in_array($meta_key, Uninstall_Data::post_meta_keys(), true),
        "Destructive uninstall is missing registered AWVP Video meta key {$meta_key}."
    );
}

$include_dir = dirname(__DIR__) . '/includes';
$source_files = glob($include_dir . '/*.php') ?: array();

$owned_meta_literals = array();
$owned_option_constants = array();
$owned_option_prefixes = array();
$owned_transient_constants = array();
$owned_table_constants = array();
$literal_option_calls = array();

foreach ($source_files as $source_file) {
    $source = (string) file_get_contents($source_file);

    if (preg_match_all("/['\"](_argent(?:wolf)?_video_[^'\"]+)['\"]/", $source, $matches)) {
        $owned_meta_literals = array_merge($owned_meta_literals, $matches[1]);
    }

    if (preg_match_all(
        "/(?:public|private) const [A-Z0-9_]*OPTION\\s*=\\s*['\"]((?:argent|argentwolf)_video_processor[^'\"]+)['\"]/",
        $source,
        $matches
    )) {
        $owned_option_constants = array_merge($owned_option_constants, $matches[1]);
    }

    if (preg_match_all(
        "/(?:public|private) const [A-Z0-9_]*(?:OPTION_PREFIX|LOCK_PREFIX)\\s*=\\s*['\"]((?:argent|argentwolf)_video_processor[^'\"]+)['\"]/",
        $source,
        $matches
    )) {
        $owned_option_prefixes = array_merge($owned_option_prefixes, $matches[1]);
    }

    if (preg_match_all(
        "/private const LAUNCH_LOCK\\s*=\\s*['\"]((?:argent|argentwolf)_video_processor[^'\"]+)['\"]/",
        $source,
        $matches
    )) {
        $owned_transient_constants = array_merge($owned_transient_constants, $matches[1]);
    }

    if (preg_match_all(
        "/(?:public|private) const [A-Z0-9_]*(?:TABLE|TABLE_SUFFIX)\\s*=\\s*['\"]((?:argent|argentwolf)_video_[^'\"]+)['\"]/",
        $source,
        $matches
    )) {
        $owned_table_constants = array_merge($owned_table_constants, $matches[1]);
    }

    if (preg_match_all(
        "/(?:get_option|update_option|add_option|delete_option)\\(\\s*['\"]((?:argent|argentwolf)_video_processor[^'\"]+)['\"]/",
        $source,
        $matches
    )) {
        $literal_option_calls = array_merge($literal_option_calls, $matches[1]);
    }
}

foreach ($sorted($owned_meta_literals) as $meta_key) {
    $assert(
        in_array($meta_key, Uninstall_Data::post_meta_keys(), true),
        "Destructive uninstall meta manifest is missing source-owned key {$meta_key}."
    );
}

foreach ($sorted(array_merge($owned_option_constants, $literal_option_calls)) as $option) {
    $assert(
        in_array($option, Uninstall_Data::option_names(), true),
        "Destructive uninstall fixed-option manifest is missing {$option}."
    );
}

foreach ($sorted($owned_option_prefixes) as $prefix) {
    $assert(
        in_array($prefix, Uninstall_Data::option_prefixes(), true),
        "Destructive uninstall option-prefix manifest is missing {$prefix}."
    );
}

foreach ($sorted($owned_transient_constants) as $transient) {
    $assert(
        in_array($transient, Uninstall_Data::transient_names(), true),
        "Destructive uninstall transient manifest is missing {$transient}."
    );
}

foreach ($sorted($owned_table_constants) as $suffix) {
    $assert(
        in_array($suffix, Uninstall_Data::table_suffixes(), true),
        "Destructive uninstall table manifest is missing source-owned table {$suffix}."
    );
}

$GLOBALS['awvp_uninstall_cron'] = array();
$GLOBALS['awvp_uninstall_transients'] = array();
$GLOBALS['awvp_uninstall_options'] = array();
$GLOBALS['awvp_uninstall_meta'] = array();
$GLOBALS['awvp_uninstall_posts'] = array();

function wp_clear_scheduled_hook(string $hook): void
{
    $GLOBALS['awvp_uninstall_cron'][] = $hook;
}

function delete_transient(string $transient): bool
{
    $GLOBALS['awvp_uninstall_transients'][] = $transient;
    return true;
}

function delete_option(string $option): bool
{
    $GLOBALS['awvp_uninstall_options'][] = $option;
    return true;
}

function delete_post_meta_by_key(string $meta_key): bool
{
    $GLOBALS['awvp_uninstall_meta'][] = $meta_key;
    return true;
}

function wp_delete_post(int $post_id, bool $force_delete = false): object|false
{
    $GLOBALS['awvp_uninstall_posts'][] = array($post_id, $force_delete);
    return (object) array('ID' => $post_id);
}

$GLOBALS['wpdb'] = new class {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public string $posts = 'wp_posts';

    /** @var list<string> */
    public array $dropped = array();

    public function prepare(string $query, mixed ...$args): string
    {
        return base64_encode(serialize(array($query, $args)));
    }

    public function query(string $prepared): int
    {
        [$query, $args] = unserialize(base64_decode($prepared), array('allowed_classes' => false));
        if ('DROP TABLE IF EXISTS %i' === $query) {
            $this->dropped[] = (string) $args[0];
        }
        return 1;
    }

    /** @return list<string|int> */
    public function get_col(string $prepared): array
    {
        [$query, $args] = unserialize(base64_decode($prepared), array('allowed_classes' => false));
        if ('SELECT option_name FROM %i WHERE option_name LIKE %s' === $query) {
            $prefix = rtrim((string) $args[1], '%');
            return array($prefix . 'fixture-a', $prefix . 'fixture-b');
        }
        if ('SELECT ID FROM %i WHERE post_type = %s' === $query) {
            return array(9101, 9102);
        }
        return array();
    }

    public function esc_like(string $value): string
    {
        return $value;
    }
};

Uninstall_Data::remove();

$assert(
    $sorted(array_map(static fn (string $suffix): string => 'wp_' . $suffix, Uninstall_Data::table_suffixes()))
        === $sorted($GLOBALS['wpdb']->dropped),
    'Destructive uninstall must drop every owned table using the active WordPress table prefix.'
);
$assert(
    $sorted(Uninstall_Data::cron_hooks()) === $sorted($GLOBALS['awvp_uninstall_cron']),
    'Destructive uninstall must clear every owned cron hook.'
);
$assert(
    $sorted(Uninstall_Data::transient_names()) === $sorted($GLOBALS['awvp_uninstall_transients']),
    'Destructive uninstall must clear every owned transient lock.'
);
$assert(
    $sorted(Uninstall_Data::post_meta_keys()) === $sorted($GLOBALS['awvp_uninstall_meta']),
    'Destructive uninstall must delete every owned post-meta key globally.'
);
$assert(
    array(array(9101, true), array(9102, true)) === $GLOBALS['awvp_uninstall_posts'],
    'Destructive uninstall must permanently delete only discovered AWVP Video posts.'
);

foreach (Uninstall_Data::option_names() as $option) {
    $assert(
        in_array($option, $GLOBALS['awvp_uninstall_options'], true),
        "Destructive uninstall did not delete fixed option {$option}."
    );
}
foreach (Uninstall_Data::option_prefixes() as $prefix) {
    $assert(
        in_array($prefix . 'fixture-a', $GLOBALS['awvp_uninstall_options'], true)
            && in_array($prefix . 'fixture-b', $GLOBALS['awvp_uninstall_options'], true),
        "Destructive uninstall did not delete concrete options under prefix {$prefix}."
    );
}

$uninstall_source = (string) file_get_contents(dirname(__DIR__) . '/uninstall.php');
$assert(
    str_contains($uninstall_source, "defined('ARGENT_VIDEO_REMOVE_DATA_ON_UNINSTALL')"),
    'uninstall.php must retain explicit opt-in destructive cleanup.'
);
$assert(
    str_contains($uninstall_source, 'Uninstall_Data::remove()'),
    'uninstall.php must delegate destructive cleanup to the ownership manifest.'
);
$assert(
    ! str_contains($uninstall_source, 'PeerTube_Api') && ! str_contains($uninstall_source, 'wp_remote_'),
    'uninstall.php must not gain remote PeerTube/HTTP destruction behavior.'
);

if ([] !== $failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Destructive uninstall ownership/behavior tests passed.\n");

// EOF: tests/uninstall-data.php
