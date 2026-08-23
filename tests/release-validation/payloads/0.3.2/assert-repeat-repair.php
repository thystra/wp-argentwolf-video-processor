<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

global $wpdb;

awvp_release_assert_candidate_common();

$table = $wpdb->prefix . \ArgentVideo\Worker_Log_Repository::TABLE_SUFFIX;
$now = current_time('mysql', true);
$inserted = $wpdb->insert(
    $table,
    array(
        'trigger_source' => 'manual',
        'status'         => 'complete',
        'message'        => 'awvp schema repair sentinel',
        'created_at'     => $now,
        'updated_at'     => $now,
        'completed_at'   => $now,
    ),
    array('%s', '%s', '%s', '%s', '%s', '%s')
);
awvp_release_assert(false !== $inserted, 'Could not create schema-repair sentinel.');
$sentinel_id = (int) $wpdb->insert_id;
awvp_release_assert($sentinel_id > 0, 'Schema-repair sentinel ID is invalid.');

$indexes = awvp_release_indexes($table);
awvp_release_assert(isset($indexes['completed_at']), 'completed_at index missing before repair fixture.');

$wpdb->query("ALTER TABLE `{$table}` DROP INDEX `completed_at`");
$indexes = awvp_release_indexes($table);
awvp_release_assert(! isset($indexes['completed_at']), 'Could not remove completed_at index for repair fixture.');

$base_db = awvp_release_env('AWVP_TEST_BASE_DB_VERSION');
$candidate_db = awvp_release_env('AWVP_TEST_CANDIDATE_DB_VERSION');

update_option(\ArgentVideo\Activator::DB_OPTION, $base_db, false);
\ArgentVideo\Activator::maybe_upgrade();

awvp_release_assert(
    $candidate_db === (string) get_option(\ArgentVideo\Activator::DB_OPTION, ''),
    "Schema repair did not restore DB version {$candidate_db}."
);
$indexes = awvp_release_indexes($table);
awvp_release_assert(isset($indexes['completed_at']), 'Schema repair did not restore completed_at index.');
awvp_release_assert(
    1 === (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE id = %d", $sentinel_id)
    ),
    'Schema repair deleted the diagnostics sentinel.'
);

// Second invocation with a current version must be harmless.
\ArgentVideo\Activator::maybe_upgrade();
awvp_release_assert(
    1 === (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE id = %d", $sentinel_id)
    ),
    'Repeated schema check deleted the diagnostics sentinel.'
);

$upgrade_job_id = (int) get_option('awvp_release_job_id', 0);
if ($upgrade_job_id > 0) {
    $jobs = $wpdb->prefix . 'argent_video_jobs';
    awvp_release_assert(
        1 === (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM `{$jobs}` WHERE id = %d", $upgrade_job_id)
        ),
        'Schema repair deleted the upgrade queue sentinel.'
    );
}

$wpdb->delete($table, array('id' => $sentinel_id), array('%d'));

echo "AWVP_RELEASE_DBDELTA_REPEAT_REPAIR_PASS\n";
