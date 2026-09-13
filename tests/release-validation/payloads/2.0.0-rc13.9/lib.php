<?php
declare(strict_types=1);

require __DIR__ . '/../../php/common.php';

function awvp_rc_assert_jobs_schema(): void
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

function awvp_rc_assert_logs_schema(): void
{
    global $wpdb;

    awvp_release_assert(
        class_exists(\ArgentVideo\Worker_Log_Repository::class),
        'Worker_Log_Repository class is not loaded.'
    );

    $table = $wpdb->prefix . \ArgentVideo\Worker_Log_Repository::TABLE_SUFFIX;
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

function awvp_rc_assert_events_schema(): void
{
    global $wpdb;

    $table = $wpdb->prefix . \ArgentVideo\Model_Activator::EVENTS_TABLE;
    awvp_release_assert(awvp_release_table_exists($table), 'argent_video_events table is missing.');

    $columns = awvp_release_columns($table);
    $expected = array(
        'id', 'video_post_id', 'task_id', 'operation_id', 'remote_asset_id',
        'backend_id', 'pipeline_step', 'event_code', 'severity', 'http_status',
        'message', 'automatic_action', 'operator_action', 'context_json', 'created_at',
    );
    foreach ($expected as $column) {
        awvp_release_assert(isset($columns[$column]), "Missing events column {$column}");
    }

    $indexes = awvp_release_indexes($table);
    awvp_release_assert_index($indexes, 'PRIMARY', true, array('id'));
    awvp_release_assert_index($indexes, 'video_created', false, array('video_post_id', 'created_at'));
    awvp_release_assert_index($indexes, 'task_created', false, array('task_id', 'created_at'));
    awvp_release_assert_index($indexes, 'backend_created', false, array('backend_id', 'created_at'));
    awvp_release_assert_index($indexes, 'severity_created', false, array('severity', 'created_at'));
}

function awvp_rc_assert_publication_health_schema(): void
{
    global $wpdb;

    $table = $wpdb->prefix . \ArgentVideo\Model_Activator::PUBLICATION_HEALTH_TABLE;
    awvp_release_assert(awvp_release_table_exists($table), 'argent_video_publication_health table is missing.');

    $columns = awvp_release_columns($table);
    $expected = array(
        'remote_asset_id', 'video_post_id', 'backend_id', 'status', 'eligible',
        'failure_since', 'last_checked_at', 'last_healthy_at', 'success_streak',
        'failure_streak', 'http_status', 'reason_code', 'message', 'next_check_at', 'updated_at',
    );
    foreach ($expected as $column) {
        awvp_release_assert(isset($columns[$column]), "Missing publication-health column {$column}");
    }

    $indexes = awvp_release_indexes($table);
    awvp_release_assert_index($indexes, 'PRIMARY', true, array('remote_asset_id'));
    awvp_release_assert_index($indexes, 'video_status', false, array('video_post_id', 'status'));
    awvp_release_assert_index($indexes, 'backend_status', false, array('backend_id', 'status'));
    awvp_release_assert_index($indexes, 'next_check', false, array('next_check_at'));
}

function awvp_rc_assert_model_schema(): void
{
    global $wpdb;

    $model_db = awvp_release_env('AWVP_TEST_CANDIDATE_MODEL_DB_VERSION');
    awvp_release_assert(
        class_exists(\ArgentVideo\Model_Activator::class),
        '2.0 model activator is not loaded.'
    );
    awvp_release_assert(
        $model_db === (string) get_option(\ArgentVideo\Model_Activator::DB_OPTION, ''),
        "AWVP model DB schema-version option is not {$model_db}."
    );
    awvp_release_assert(
        \ArgentVideo\Model_Activator::schema_is_current(),
        'AWVP 2.0 model schema is not current.'
    );

    foreach (array(
        \ArgentVideo\Model_Activator::REMOTE_ASSETS_TABLE,
        \ArgentVideo\Model_Activator::TASKS_TABLE,
        \ArgentVideo\Model_Activator::EVENTS_TABLE,
        \ArgentVideo\Model_Activator::PUBLICATION_HEALTH_TABLE,
    ) as $suffix) {
        $table = $wpdb->prefix . $suffix;
        awvp_release_assert(awvp_release_table_exists($table), "2.0 model table {$suffix} is missing.");
    }

    awvp_rc_assert_events_schema();
    awvp_rc_assert_publication_health_schema();
}

function awvp_rc_assert_candidate_common(): void
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
        "AWVP legacy DB schema-version option is not {$candidate_db}."
    );

    awvp_rc_assert_jobs_schema();
    awvp_rc_assert_logs_schema();
    awvp_rc_assert_model_schema();

    awvp_release_assert(
        post_type_exists(\ArgentVideo\Video_Post_Type::POST_TYPE),
        'AWVP Video post type is not registered.'
    );
    awvp_release_assert(
        \WP_Block_Type_Registry::get_instance()->is_registered(\ArgentVideo\Video_Block::NAME),
        'AWVP Video block is not registered.'
    );

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

/** @return array<string, list<mixed>> */
function awvp_rc_sorted_post_meta(int $post_id): array
{
    $meta = get_post_meta($post_id);
    awvp_release_assert(is_array($meta), "Could not read post meta for {$post_id}.");
    ksort($meta);
    foreach ($meta as &$values) {
        if (is_array($values)) {
            sort($values, SORT_STRING);
        }
    }
    unset($values);
    return $meta;
}

function awvp_rc_post_meta_hash(int $post_id): string
{
    return hash('sha256', wp_json_encode(awvp_rc_sorted_post_meta($post_id)));
}

/** @return array<string, mixed> */
function awvp_rc_worker_row(int $id): array
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

function awvp_rc_count_worker_bucket(string $bucket): int
{
    global $wpdb;
    $table = $wpdb->prefix . \ArgentVideo\Worker_Log_Repository::TABLE_SUFFIX;

    if ('success' === $bucket) {
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE status = 'complete' AND jobs_failed = 0"
        );
    }

    if ('error' === $bucket) {
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE status = 'failed' OR (status = 'complete' AND jobs_failed > 0)"
        );
    }

    awvp_release_fail("Unknown worker bucket {$bucket}");
}

/** @return array{attachment_id:int,origin_post_id:int,video_id:int} */
function awvp_rc_create_new_awvp_block_fixture(): array
{
    $uploads = wp_upload_dir();
    awvp_release_assert(is_array($uploads) && empty($uploads['error']), 'Uploads are unavailable.');

    $base_dir = rtrim(wp_normalize_path((string) $uploads['basedir']), '/');
    $base_url = rtrim((string) $uploads['baseurl'], '/');
    $fixture_dir = $base_dir . '/awvp-release-validation';
    awvp_release_assert(wp_mkdir_p($fixture_dir), 'Could not create RC block fixture directory.');

    $source = $fixture_dir . '/' . wp_unique_filename($fixture_dir, 'new-awvp-block.mp4');
    awvp_release_assert(
        false !== file_put_contents($source, "AWVP 2.0 RC new-block fixture\n"),
        'Could not create RC block source fixture.'
    );

    $relative = ltrim(substr(wp_normalize_path($source), strlen($base_dir)), '/');
    $attachment_id = wp_insert_post(
        array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_title'     => 'AWVP RC New Block',
            'post_mime_type' => 'video/mp4',
            'guid'           => $base_url . '/' . implode('/', array_map('rawurlencode', explode('/', $relative))),
        ),
        true
    );
    awvp_release_assert(! is_wp_error($attachment_id) && (int) $attachment_id > 0, 'Could not create RC block attachment.');
    update_attached_file((int) $attachment_id, $source);

    $origin_post_id = wp_insert_post(
        array(
            'post_type'    => 'post',
            'post_status'  => 'draft',
            'post_title'   => 'AWVP RC Block Origin',
            'post_content' => '',
        ),
        true
    );
    awvp_release_assert(! is_wp_error($origin_post_id) && (int) $origin_post_id > 0, 'Could not create RC block origin post.');

    $admin = get_user_by('login', 'awvpadmin');
    awvp_release_assert(is_object($admin) && (int) $admin->ID > 0, 'Release-validation administrator is missing.');

    $registry = new \ArgentVideo\Backend_Registry();
    $defaults = new \ArgentVideo\Video_Publishing_Defaults_Store($registry);
    $editor = new \ArgentVideo\Video_Block_Editor_Service($registry, $defaults);
    $result = $editor->bind_local_attachment(
        (int) $attachment_id,
        (int) $origin_post_id,
        (int) $admin->ID
    );
    awvp_release_assert(
        \ArgentVideo\Video_Block_Editor_Service::APPLIED === ($result['status'] ?? ''),
        'Could not bind a new attachment to an AWVP Video.'
    );
    $video_id = (int) ($result['video_id'] ?? 0);
    awvp_release_assert($video_id > 0, 'AWVP Video binding returned no video ID.');

    $block = sprintf(
        '<!-- wp:argentwolf-video-processor/video {"videoId":%d} /-->',
        $video_id
    );
    $updated = wp_update_post(
        array(
            'ID'           => (int) $origin_post_id,
            'post_content' => $block,
        ),
        true
    );
    awvp_release_assert(! is_wp_error($updated), 'Could not persist the AWVP Video block fixture.');

    return array(
        'attachment_id' => (int) $attachment_id,
        'origin_post_id' => (int) $origin_post_id,
        'video_id'       => $video_id,
    );
}
