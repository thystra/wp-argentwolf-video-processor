<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

global $wpdb;

awvp_release_assert_candidate_common();

$base_version = awvp_release_env('AWVP_TEST_BASE_VERSION');
$success_retention = awvp_release_env_int('AWVP_TEST_SUCCESS_RETENTION');
$error_retention = awvp_release_env_int('AWVP_TEST_ERROR_RETENTION');

$expected_hash = (string) get_option('awvp_release_expected_settings_hash', '');
$attachment_id = (int) get_option('awvp_release_attachment_id', 0);
$job_id = (int) get_option('awvp_release_job_id', 0);
$signature = (string) get_option('awvp_release_signature', '');

awvp_release_assert('' !== $expected_hash, 'Upgrade settings-hash sentinel is missing.');
awvp_release_assert($attachment_id > 0 && $job_id > 0, 'Upgrade ID sentinels are missing.');
awvp_release_assert('' !== $signature, 'Upgrade signature sentinel is missing.');

$raw = get_option(\ArgentVideo\Settings::OPTION, array());
awvp_release_assert(is_array($raw), "Saved {$base_version} settings are no longer an array.");
awvp_release_assert(
    hash('sha256', wp_json_encode($raw)) === $expected_hash,
    "Saved {$base_version} settings were rewritten during upgrade."
);
awvp_release_assert(
    ! array_key_exists('worker_log_success_limit', $raw)
    && ! array_key_exists('worker_log_error_limit', $raw),
    'Upgrade rewrote old saved settings to inject new retention keys.'
);

$effective = \ArgentVideo\Settings::all();
awvp_release_assert(false === $effective['auto_queue'], 'auto_queue setting was not preserved.');
awvp_release_assert(false === $effective['auto_dispatch'], 'auto_dispatch setting was not preserved.');
awvp_release_assert(1024 === (int) $effective['max_width'], 'max_width setting was not preserved.');
awvp_release_assert(576 === (int) $effective['max_height'], 'max_height setting was not preserved.');
awvp_release_assert('compatibility' === $effective['profile'], 'profile setting was not preserved.');
awvp_release_assert(13 === (int) $effective['nice_level'], 'nice_level setting was not preserved.');
awvp_release_assert(
    $success_retention === (int) $effective['worker_log_success_limit'],
    'New success retention default is missing.'
);
awvp_release_assert(
    $error_retention === (int) $effective['worker_log_error_limit'],
    'New error retention default is missing.'
);

$jobs = $wpdb->prefix . 'argent_video_jobs';
$job = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM `{$jobs}` WHERE id = %d", $job_id),
    ARRAY_A
);
awvp_release_assert(is_array($job), 'Base queue sentinel disappeared during upgrade.');
awvp_release_assert($attachment_id === (int) $job['attachment_id'], 'Queue sentinel attachment ID changed.');
awvp_release_assert($signature === (string) $job['source_signature'], 'Queue sentinel signature changed.');
awvp_release_assert('compatibility' === (string) $job['profile'], 'Queue sentinel profile changed.');
awvp_release_assert('queued' === (string) $job['status'], 'Queue sentinel status changed.');

awvp_release_assert(
    'queued' === (string) get_post_meta($attachment_id, '_argent_video_status', true),
    'Attachment status metadata changed during upgrade.'
);
awvp_release_assert(
    $signature === (string) get_post_meta($attachment_id, '_argent_video_source_signature', true),
    'Attachment signature metadata changed during upgrade.'
);
$outputs = get_post_meta($attachment_id, '_argent_video_outputs', true);
awvp_release_assert(is_array($outputs), 'Attachment output metadata disappeared during upgrade.');
awvp_release_assert(
    '/release-test/sentinel.mp4' === (string) ($outputs['mp4']['path'] ?? ''),
    'Attachment output metadata changed during upgrade.'
);

$logs = $wpdb->prefix . \ArgentVideo\Worker_Log_Repository::TABLE_SUFFIX;
awvp_release_assert(
    0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$logs}`"),
    'Schema upgrade unexpectedly created worker history rows.'
);

echo "AWVP_RELEASE_UPGRADE_PRESERVATION_PASS\n";
