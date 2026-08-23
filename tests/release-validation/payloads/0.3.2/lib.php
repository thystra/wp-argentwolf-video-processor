<?php
declare(strict_types=1);

require __DIR__ . '/../../php/common.php';

function awvp_release_assert_jobs_schema(): void
{
    global $wpdb;

    $table = $wpdb->prefix . 'argent_video_jobs';
    awvp_release_assert(awvp_release_table_exists($table), 'argent_video_jobs table is missing.');

    $columns = awvp_release_columns($table);
    $expected = array(
        'id', 'attachment_id', 'source_path', 'source_signature', 'profile',
        'status', 'attempts', 'lock_token', 'locked_at', 'started_at',
        'completed_at', 'output_json', 'error_message', 'created_at', 'updated_at',
    );
    foreach ($expected as $column) {
        awvp_release_assert(isset($columns[$column]), "Missing jobs column {$column}");
    }

    $indexes = awvp_release_indexes($table);
    awvp_release_assert_index($indexes, 'PRIMARY', true, array('id'));
    awvp_release_assert_index($indexes, 'attachment_id', true, array('attachment_id'));
    awvp_release_assert_index($indexes, 'status_created', false, array('status', 'created_at'));
    awvp_release_assert_index($indexes, 'locked_at', false, array('locked_at'));
}

function awvp_release_assert_logs_schema(): void
{
    global $wpdb;

    awvp_release_assert(
        class_exists(\ArgentVideo\Worker_Log_Repository::class),
        'Worker_Log_Repository class is not loaded.'
    );

    $table = $wpdb->prefix . \ArgentVideo\Worker_Log_Repository::TABLE_SUFFIX;
    awvp_release_assert(
        'argentwolf_video_processor_logs' === \ArgentVideo\Worker_Log_Repository::TABLE_SUFFIX,
        'Unexpected worker log table suffix.'
    );
    awvp_release_assert(awvp_release_table_exists($table), 'Worker diagnostics table is missing.');

    $columns = awvp_release_columns($table);
    $expected = array(
        'id', 'trigger_source', 'status', 'pid', 'exit_code', 'jobs_processed',
        'jobs_failed', 'jobs_recovered', 'message', 'diagnostic_output',
        'capture_path', 'started_at', 'completed_at', 'created_at', 'updated_at',
    );
    foreach ($expected as $column) {
        awvp_release_assert(isset($columns[$column]), "Missing logs column {$column}");
    }

    $indexes = awvp_release_indexes($table);
    awvp_release_assert_index($indexes, 'PRIMARY', true, array('id'));
    awvp_release_assert_index($indexes, 'status_created', false, array('status', 'created_at'));
    awvp_release_assert_index($indexes, 'completed_at', false, array('completed_at'));
}

function awvp_release_assert_candidate_common(): void
{
    $candidate_version = awvp_release_env('AWVP_TEST_CANDIDATE_VERSION');
    $candidate_db = awvp_release_env('AWVP_TEST_CANDIDATE_DB_VERSION');
    $success_retention = awvp_release_env_int('AWVP_TEST_SUCCESS_RETENTION');
    $error_retention = awvp_release_env_int('AWVP_TEST_ERROR_RETENTION');

    awvp_release_assert(defined('ARGENT_VIDEO_VERSION'), 'ARGENT_VIDEO_VERSION is not defined.');
    awvp_release_assert(
        $candidate_version === ARGENT_VIDEO_VERSION,
        "Loaded plugin version is not {$candidate_version}."
    );
    awvp_release_assert(
        $candidate_db === (string) get_option(\ArgentVideo\Activator::DB_OPTION, ''),
        "AWVP DB schema-version option is not {$candidate_db}."
    );

    awvp_release_assert_jobs_schema();
    awvp_release_assert_logs_schema();

    $settings = \ArgentVideo\Settings::all();
    awvp_release_assert(
        $success_retention === (int) ($settings['worker_log_success_limit'] ?? -1),
        "Effective successful-worker retention default is not {$success_retention}."
    );
    awvp_release_assert(
        $error_retention === (int) ($settings['worker_log_error_limit'] ?? -1),
        "Effective error-worker retention default is not {$error_retention}."
    );

    awvp_release_assert(
        false !== wp_next_scheduled(\ArgentVideo\Activator::CRON_HOOK),
        'AWVP dispatcher cron event is not scheduled.'
    );
}

/** @return array<string, mixed> */
function awvp_release_worker_row(int $id): array
{
    global $wpdb;
    $table = $wpdb->prefix . \ArgentVideo\Worker_Log_Repository::TABLE_SUFFIX;
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id),
        ARRAY_A
    );
    awvp_release_assert(is_array($row), "Worker diagnostic row {$id} not found.");
    return $row;
}

function awvp_release_count_worker_bucket(string $bucket): int
{
    global $wpdb;
    $table = $wpdb->prefix . \ArgentVideo\Worker_Log_Repository::TABLE_SUFFIX;

    if ('success' === $bucket) {
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE status = 'complete' AND jobs_failed = 0"
        );
    }

    if ('error' === $bucket) {
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE status = 'failed'
                OR (status = 'complete' AND jobs_failed > 0)"
        );
    }

    awvp_release_fail("Unknown worker bucket {$bucket}");
}
