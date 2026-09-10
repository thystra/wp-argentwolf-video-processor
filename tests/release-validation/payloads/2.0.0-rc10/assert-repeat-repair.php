<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

global $wpdb;

awvp_rc_assert_candidate_common();

// Re-run the established legacy schema repair from a deliberately stale
// schema-version marker. 1.0 and 2.0 both use legacy DB version 2, so using the
// real 1.0 marker would not exercise repair.
$logs = $wpdb->prefix . \ArgentVideo\Worker_Log_Repository::TABLE_SUFFIX;
$now = current_time('mysql', true);
$inserted = $wpdb->insert(
    $logs,
    array(
        'trigger_source' => 'manual',
        'status'         => 'complete',
        'message'        => 'awvp rc schema repair sentinel',
        'created_at'     => $now,
        'updated_at'     => $now,
        'completed_at'   => $now,
    ),
    array('%s', '%s', '%s', '%s', '%s', '%s')
);
awvp_release_assert(false !== $inserted, 'Could not create legacy schema-repair sentinel.');
$log_id = (int) $wpdb->insert_id;
awvp_release_assert($log_id > 0, 'Legacy schema-repair sentinel ID is invalid.');

$indexes = awvp_release_indexes($logs);
awvp_release_assert(isset($indexes['completed_at']), 'completed_at index missing before repair fixture.');
$wpdb->query("ALTER TABLE `{$logs}` DROP INDEX `completed_at`");
$indexes = awvp_release_indexes($logs);
awvp_release_assert(! isset($indexes['completed_at']), 'Could not remove completed_at index for repair fixture.');

$candidate_db = awvp_release_env('AWVP_TEST_CANDIDATE_DB_VERSION');
update_option(\ArgentVideo\Activator::DB_OPTION, '1', false);
\ArgentVideo\Activator::maybe_upgrade();
awvp_release_assert(
    $candidate_db === (string) get_option(\ArgentVideo\Activator::DB_OPTION, ''),
    "Legacy schema repair did not restore DB version {$candidate_db}."
);
$indexes = awvp_release_indexes($logs);
awvp_release_assert(isset($indexes['completed_at']), 'Legacy schema repair did not restore completed_at index.');
awvp_release_assert(
    1 === (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$logs}` WHERE id = %d", $log_id)),
    'Legacy schema repair deleted its sentinel.'
);

// Exercise the 2.0 model repair independently. Drop required task/event
// indexes, force the model version stale, then prove dbDelta restores schema 2
// without creating work or losing model data.
$tasks = $wpdb->prefix . \ArgentVideo\Model_Activator::TASKS_TABLE;
$task_indexes = awvp_release_indexes($tasks);
awvp_release_assert(isset($task_indexes['locked_at']), '2.0 tasks locked_at index missing before repair fixture.');
$wpdb->query("ALTER TABLE `{$tasks}` DROP INDEX `locked_at`");
$task_indexes = awvp_release_indexes($tasks);
awvp_release_assert(! isset($task_indexes['locked_at']), 'Could not remove 2.0 tasks locked_at index for repair fixture.');

$events = $wpdb->prefix . \ArgentVideo\Model_Activator::EVENTS_TABLE;
$event_indexes = awvp_release_indexes($events);
awvp_release_assert(isset($event_indexes['severity_created']), '2.0 events severity_created index missing before repair fixture.');
$wpdb->query("ALTER TABLE `{$events}` DROP INDEX `severity_created`");
$event_indexes = awvp_release_indexes($events);
awvp_release_assert(! isset($event_indexes['severity_created']), 'Could not remove 2.0 events severity_created index for repair fixture.');

$model_db = awvp_release_env('AWVP_TEST_CANDIDATE_MODEL_DB_VERSION');
update_option(\ArgentVideo\Model_Activator::DB_OPTION, '0', false);
\ArgentVideo\Model_Activator::maybe_upgrade();
awvp_release_assert(
    $model_db === (string) get_option(\ArgentVideo\Model_Activator::DB_OPTION, ''),
    "2.0 model schema repair did not restore DB version {$model_db}."
);
awvp_release_assert(\ArgentVideo\Model_Activator::schema_is_current(), '2.0 model schema repair did not restore the schema contract.');
$task_indexes = awvp_release_indexes($tasks);
$event_indexes = awvp_release_indexes($events);
awvp_release_assert(isset($task_indexes['locked_at']), '2.0 model schema repair did not restore tasks locked_at index.');
awvp_release_assert(isset($event_indexes['severity_created']), '2.0 model schema repair did not restore events severity_created index.');
awvp_release_assert(0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$tasks}`"), '2.0 model schema repair created task rows.');

// Repeated current-version checks must be harmless.
\ArgentVideo\Activator::maybe_upgrade();
\ArgentVideo\Model_Activator::maybe_upgrade();
awvp_release_assert(
    1 === (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$logs}` WHERE id = %d", $log_id)),
    'Repeated schema checks deleted the legacy diagnostics sentinel.'
);

$upgrade_job_id = (int) get_option('awvp_rc_legacy_job_id', 0);
if ($upgrade_job_id > 0) {
    $jobs = $wpdb->prefix . 'argent_video_jobs';
    awvp_release_assert(
        1 === (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$jobs}` WHERE id = %d", $upgrade_job_id)),
        'Repeated schema repair deleted the 1.0 completed queue sentinel.'
    );
}

$wpdb->delete($logs, array('id' => $log_id), array('%d'));

echo "AWVP_RC_DBDELTA_REPEAT_REPAIR_PASS\n";
