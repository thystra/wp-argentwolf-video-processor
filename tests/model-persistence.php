<?php
/**
 * Focused tests for the AWVP 2.0 persistence skeleton.
 */

declare(strict_types=1);

define('ABSPATH', '/tmp/wordpress/');

$GLOBALS['argent_video_test_post_type'] = null;
$GLOBALS['argent_video_test_post_type_args'] = array();
$GLOBALS['argent_video_test_meta'] = array();
$GLOBALS['argent_video_test_user_can'] = array();
$GLOBALS['argent_video_test_dbdelta'] = array();
$GLOBALS['argent_video_test_option_updates'] = array();

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

function __(string $text, string $domain = ''): string
{
    unset($domain);
    return $text;
}

function register_post_type(string $post_type, array $args): object
{
    $GLOBALS['argent_video_test_post_type'] = $post_type;
    $GLOBALS['argent_video_test_post_type_args'] = $args;
    return (object) array('name' => $post_type);
}

function register_post_meta(string $post_type, string $meta_key, array $args): bool
{
    $GLOBALS['argent_video_test_meta'][$meta_key] = array(
        'post_type' => $post_type,
        'args'      => $args,
    );
    return true;
}

function user_can(int $user_id, string $capability, mixed ...$args): bool
{
    $GLOBALS['argent_video_test_user_can'][] = array($user_id, $capability, $args);
    return 7 === $user_id && 'edit_post' === $capability && 42 === ($args[0] ?? 0);
}

function absint(mixed $value): int
{
    return abs((int) $value);
}

function sanitize_key(mixed $key): string
{
    $key = strtolower((string) $key);
    return preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
}

function sanitize_text_field(mixed $value): string
{
    $value = preg_replace('/[\r\n\t ]+/', ' ', (string) $value) ?? '';
    return trim(strip_tags($value));
}

function dbDelta(string $sql): array
{
    $GLOBALS['argent_video_test_dbdelta'][] = $sql;
    return array();
}

function get_option(string $option, mixed $default = false): mixed
{
    unset($option);
    return $default;
}

function update_option(string $option, mixed $value, ?bool $autoload = null): bool
{
    $GLOBALS['argent_video_test_option_updates'][] = array($option, $value, $autoload);
    return true;
}

require_once dirname(__DIR__) . '/includes/Model_Activator.php';
require_once dirname(__DIR__) . '/includes/Video_Post_Type.php';
require_once dirname(__DIR__) . '/includes/Video_Meta.php';

use ArgentVideo\Model_Activator;
use ArgentVideo\Video_Meta;
use ArgentVideo\Video_Post_Type;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

Video_Post_Type::register();

$assert('argent_video_asset' === Video_Post_Type::POST_TYPE, 'Unexpected CPT key.');
$assert(strlen(Video_Post_Type::POST_TYPE) <= 20, 'CPT key exceeds WordPress limit.');
$assert(Video_Post_Type::POST_TYPE === $GLOBALS['argent_video_test_post_type'], 'CPT was not registered.');

$args = $GLOBALS['argent_video_test_post_type_args'];
$assert(false === $args['public'], 'CPT must not be public.');
$assert(false === $args['publicly_queryable'], 'CPT must not be publicly queryable.');
$assert(false === $args['show_ui'], 'CPT must not expose the default UI.');
$assert(false === $args['show_in_rest'], 'CPT must not have native REST exposure yet.');
$assert(false === $args['rewrite'], 'CPT must not create rewrite rules.');
$assert(false === $args['query_var'], 'CPT query_var must be disabled.');
$assert(false === $args['can_export'], 'CPT export must remain disabled.');
$assert('post' === $args['capability_type'], 'CPT capability_type must use post capabilities.');
$assert(true === $args['map_meta_cap'], 'CPT must map meta capabilities.');
$assert(
    array('title', 'editor', 'author', 'custom-fields') === $args['supports'],
    'CPT supports do not match the reviewed contract.'
);

Video_Meta::register();

$expected_meta = array(
    Video_Meta::ATTACHMENT_ID,
    Video_Meta::ORIGIN_POST_ID,
    Video_Meta::ORIGIN_SEQUENCE,
    Video_Meta::INGEST_KIND,
    Video_Meta::MASTER_AUTHORITY,
    Video_Meta::SOURCE_STATE,
    Video_Meta::DESTINATION,
    Video_Meta::PROFILE_SNAPSHOT,
    Video_Meta::PUBLICATION_POLICY,
    Video_Meta::METADATA_ORIGIN,
    Video_Meta::CLEANUP_STATE,
    Video_Meta::LAST_ERROR,
);
sort($expected_meta);
$actual_meta = array_keys($GLOBALS['argent_video_test_meta']);
sort($actual_meta);
$assert($expected_meta === $actual_meta, 'Registered meta-key set differs from the contract.');

foreach ($GLOBALS['argent_video_test_meta'] as $meta_key => $registration) {
    $meta_args = $registration['args'];
    $assert(Video_Post_Type::POST_TYPE === $registration['post_type'], "Wrong post type for {$meta_key}.");
    $assert(true === $meta_args['single'], "{$meta_key} must be single-valued.");
    $assert(false === $meta_args['show_in_rest'], "{$meta_key} must not have REST exposure yet.");
    $assert(is_callable($meta_args['auth_callback']), "{$meta_key} must have an auth callback.");
    $assert(is_callable($meta_args['sanitize_callback']), "{$meta_key} must have a sanitize callback.");
}

$assert(
    Video_Meta::authorize(false, Video_Meta::INGEST_KIND, 42, 7),
    'Object-aware meta authorization should allow the stub editor.'
);
$assert(
    ! Video_Meta::authorize(true, Video_Meta::INGEST_KIND, 42, 8),
    'Object-aware meta authorization should reject another stub user.'
);

$assert('unknown' === Video_Meta::sanitize_ingest_kind('NOT VALID'), 'Invalid ingest kind must fail safe.');
$assert('unknown' === Video_Meta::sanitize_master_authority('NOT VALID'), 'Invalid master authority must fail safe.');
$assert('error' === Video_Meta::sanitize_source_state('NOT VALID'), 'Invalid source state must fail safe.');
$assert('none' === Video_Meta::sanitize_cleanup_state('NOT VALID'), 'Invalid cleanup state must fail safe.');

$assert(42 === Video_Meta::sanitize_positive_id(42), 'Positive integer ID should be preserved.');
$assert(42 === Video_Meta::sanitize_positive_id('42'), 'Canonical decimal ID string should be accepted.');
$assert(0 === Video_Meta::sanitize_positive_id(-42), 'Negative ID must be rejected, not made positive.');
$assert(0 === Video_Meta::sanitize_positive_id('-42'), 'Negative ID string must be rejected.');
$assert(0 === Video_Meta::sanitize_positive_id('42x'), 'Malformed ID string must be rejected.');
$assert(0 === Video_Meta::sanitize_positive_id('0'), 'Zero is not a positive foreign ID.');

$assert('home-pt' === Video_Meta::sanitize_backend_id('home-pt'), 'Canonical backend ID should be preserved.');
$assert('' === Video_Meta::sanitize_backend_id('Home-PT'), 'Backend ID case must not be silently rewritten.');
$assert('' === Video_Meta::sanitize_backend_id('home pt'), 'Malformed backend ID must be rejected.');

$destination = Video_Meta::sanitize_destination(
    array('version' => 1, 'backend_id' => 'home-pt', 'channel_id' => 'travel')
);
$assert('home-pt' === ($destination['backend_id'] ?? ''), 'Canonical backend ID was not retained.');
$assert('travel' === ($destination['channel_id'] ?? ''), 'Canonical channel ID was not retained.');

$assert(
    array() === Video_Meta::sanitize_destination(
        array('version' => 1, 'backend_id' => 'Home-PT', 'channel_id' => 'travel')
    ),
    'Destination with a rewritten backend ID must fail closed.'
);
$assert(
    array() === Video_Meta::sanitize_destination(
        array('version' => 1, 'backend_id' => 'home-pt', 'channel_id' => '<b>travel</b>')
    ),
    'Destination with a rewritten remote channel ID must fail closed.'
);

$publication = Video_Meta::sanitize_publication_policy(
    array('version' => 1, 'mode' => 'publish_with_post')
);
$assert('manual' === ($publication['mode'] ?? ''), 'Missing publication anchor must fail safe to manual.');

$publication = Video_Meta::sanitize_publication_policy(
    array('version' => 1, 'mode' => 'publish_with_post', 'anchor_post_id' => 99)
);
$assert('publish_with_post' === ($publication['mode'] ?? ''), 'Valid anchored publication policy was changed.');
$assert(99 === ($publication['anchor_post_id'] ?? 0), 'Publication anchor was not retained.');

$publication = Video_Meta::sanitize_publication_policy(
    array('version' => 1, 'mode' => 'publish_with_post', 'anchor_post_id' => '-99')
);
$assert('manual' === ($publication['mode'] ?? ''), 'Negative publication anchor must fail closed.');

$metadata_origin = Video_Meta::sanitize_metadata_origin(
    array(
        'version' => 1,
        'fields'  => array(
            'title'       => false,
            'description' => 'false',
            'tags'        => 'not-a-boolean',
        ),
    )
);
$assert(false === ($metadata_origin['fields']['title'] ?? null), 'Boolean false metadata flag was changed.');
$assert(false === ($metadata_origin['fields']['description'] ?? null), 'Canonical false string was not parsed.');
$assert(! array_key_exists('tags', $metadata_origin['fields']), 'Invalid boolean metadata flag must be omitted.');

$GLOBALS['wpdb'] = new class {
    public string $prefix = 'wp_test_';
    public string $last_error = '';
    public bool $omit_backend_remote_index = false;

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function esc_like(string $value): string
    {
        return addcslashes($value, '_%\\');
    }

    public function prepare(string $query, mixed ...$args): string
    {
        if ('SHOW TABLES LIKE %s' === $query) {
            return 'TEST_TABLE:' . (string) ($args[0] ?? '');
        }

        if ('SHOW COLUMNS FROM %i' === $query) {
            return 'TEST_COLUMNS:' . (string) ($args[0] ?? '');
        }

        if ('SHOW INDEX FROM %i' === $query) {
            return 'TEST_INDEXES:' . (string) ($args[0] ?? '');
        }

        throw new RuntimeException('Unexpected prepared query: ' . $query);
    }

    public function get_var(string $query): ?string
    {
        if (str_starts_with($query, 'TEST_TABLE:')) {
            $escaped = substr($query, strlen('TEST_TABLE:'));
            return str_replace(array('\\_', '\\%'), array('_', '%'), $escaped);
        }

        return null;
    }

    /** @return list<array<string, string|int>> */
    public function get_results(string $query, string $output): array
    {
        unset($output);

        if (str_starts_with($query, 'TEST_COLUMNS:')) {
            $table = substr($query, strlen('TEST_COLUMNS:'));
            $columns = str_ends_with($table, 'argent_video_remote_assets')
                ? array(
                    'id', 'video_post_id', 'backend_id', 'channel_id', 'remote_id',
                    'role', 'state', 'desired_privacy', 'actual_privacy',
                    'remote_processing_state', 'remote_url', 'embed_url',
                    'last_synced_at', 'last_verified_at', 'error_code',
                    'error_message', 'created_at', 'updated_at',
                )
                : array(
                    'id', 'task_type', 'video_post_id', 'remote_asset_id',
                    'backend_id', 'idempotency_key', 'status', 'priority',
                    'run_after', 'attempts', 'max_attempts', 'lock_token',
                    'locked_at', 'started_at', 'completed_at', 'payload_json',
                    'error_message', 'created_at', 'updated_at',
                );

            return array_map(
                static fn (string $field): array => array('Field' => $field),
                $columns
            );
        }

        if (str_starts_with($query, 'TEST_INDEXES:')) {
            $table = substr($query, strlen('TEST_INDEXES:'));
            $definitions = str_ends_with($table, 'argent_video_remote_assets')
                ? array(
                    'PRIMARY' => array(0, array('id')),
                    'backend_remote' => array(0, array('backend_id', 'remote_id')),
                    'video_role' => array(1, array('video_post_id', 'role')),
                    'video_state' => array(1, array('video_post_id', 'state')),
                    'backend_state' => array(1, array('backend_id', 'state')),
                    'state_synced' => array(1, array('state', 'last_synced_at')),
                )
                : array(
                    'PRIMARY' => array(0, array('id')),
                    'idempotency_key' => array(0, array('idempotency_key')),
                    'status_run' => array(1, array('status', 'run_after', 'priority')),
                    'locked_at' => array(1, array('locked_at')),
                    'video_type' => array(1, array('video_post_id', 'task_type')),
                    'backend_status' => array(1, array('backend_id', 'status')),
                );

            if ($this->omit_backend_remote_index) {
                unset($definitions['backend_remote']);
            }

            $rows = array();
            foreach ($definitions as $name => [$non_unique, $columns]) {
                foreach ($columns as $offset => $column) {
                    $rows[] = array(
                        'Key_name'     => $name,
                        'Column_name'  => $column,
                        'Seq_in_index' => $offset + 1,
                        'Non_unique'   => $non_unique,
                    );
                }
            }
            return $rows;
        }

        return array();
    }
};

$queries = Model_Activator::schema_queries();
$assert(2 === count($queries), 'The 2.0 model must define exactly two supplemental tables in this tranche.');

$joined = implode("\n", $queries);
$assert(str_contains($joined, 'CREATE TABLE wp_test_argent_video_remote_assets'), 'Remote-assets table missing.');
$assert(str_contains($joined, 'CREATE TABLE wp_test_argent_video_tasks'), 'Task table missing.');
$assert(! str_contains($joined, 'argent_video_jobs'), '2.0 schema must not redefine the legacy queue table.');
$assert(str_contains($joined, 'PRIMARY KEY  (id)'), 'dbDelta-compatible PRIMARY KEY formatting missing.');
$assert(str_contains($joined, 'remote_id varchar(127) DEFAULT NULL'), 'Remote ID length must preserve the conservative composite-index budget.');
$assert(str_contains($joined, 'UNIQUE KEY backend_remote (backend_id, remote_id)'), 'Remote identity unique key missing.');
$assert(str_contains($joined, 'UNIQUE KEY idempotency_key (idempotency_key)'), 'Task idempotency unique key missing.');
$assert(str_contains($joined, 'KEY state_synced (state, last_synced_at)'), 'Remote reconciliation index missing.');
$assert(str_contains($joined, 'KEY status_run (status, run_after, priority)'), 'Runnable-task index missing.');
$assert(
    'argent_video_processor_model_db_version' === Model_Activator::DB_OPTION,
    'Model schema option must remain independent of the 1.x queue schema option.'
);
$assert(
    true === Model_Activator::DB_AUTOLOAD,
    'The tiny model schema-version option is checked on every request and should autoload.'
);

$GLOBALS['argent_video_test_dbdelta'] = array();
$GLOBALS['argent_video_test_option_updates'] = array();
$GLOBALS['wpdb']->omit_backend_remote_index = false;
$assert(Model_Activator::install(), 'Complete schema should be accepted after dbDelta.');
$assert(2 === count($GLOBALS['argent_video_test_dbdelta']), 'Installer should submit exactly two dbDelta queries.');
$assert(
    array(
        Model_Activator::DB_OPTION,
        Model_Activator::DB_VERSION,
        true,
    ) === ($GLOBALS['argent_video_test_option_updates'][0] ?? null),
    'Verified schema must persist the independent model version with the reviewed autoload policy.'
);

$GLOBALS['argent_video_test_option_updates'] = array();
$GLOBALS['wpdb']->omit_backend_remote_index = true;
$assert(! Model_Activator::install(), 'Schema missing a required index must fail verification.');
$assert(
    array() === $GLOBALS['argent_video_test_option_updates'],
    'Failed/partial schema must not advance the model schema-version option.'
);

echo "AWVP 2.0 persistence skeleton tests passed.\n";
