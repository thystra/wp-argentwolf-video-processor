<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

global $wpdb;

$base_version = awvp_release_env('AWVP_TEST_BASE_VERSION');
$base_db = awvp_release_env('AWVP_TEST_BASE_DB_VERSION');

awvp_release_assert(defined('ARGENT_VIDEO_VERSION'), 'Base plugin version constant missing.');
awvp_release_assert(
    $base_version === ARGENT_VIDEO_VERSION,
    "Upgrade base is not {$base_version}."
);
awvp_release_assert(
    $base_db === (string) get_option(\ArgentVideo\Activator::DB_OPTION, ''),
    "Base DB schema-version option is not {$base_db}."
);

$jobs = $wpdb->prefix . 'argent_video_jobs';
$logs = $wpdb->prefix . 'argentwolf_video_processor_logs';
awvp_release_assert(awvp_release_table_exists($jobs), 'Base jobs table is missing.');
awvp_release_assert(! awvp_release_table_exists($logs), 'Base unexpectedly has candidate logs table.');

$settings = \ArgentVideo\Settings::defaults();
$settings['auto_queue'] = false;
$settings['auto_dispatch'] = false;
$settings['max_width'] = 1024;
$settings['max_height'] = 576;
$settings['profile'] = 'compatibility';
$settings['nice_level'] = 13;

awvp_release_assert(
    ! array_key_exists('worker_log_success_limit', $settings)
    && ! array_key_exists('worker_log_error_limit', $settings),
    'Base settings fixture unexpectedly contains candidate retention keys.'
);

update_option(\ArgentVideo\Settings::OPTION, $settings, false);
$settings_hash = hash('sha256', wp_json_encode($settings));
update_option('awvp_release_expected_settings_hash', $settings_hash, false);

$attachment_id = wp_insert_post(
    array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_title'     => 'AWVP Upgrade Sentinel',
        'post_mime_type' => 'video/mp4',
    ),
    true
);
awvp_release_assert(! is_wp_error($attachment_id), 'Could not create upgrade attachment sentinel.');
awvp_release_assert((int) $attachment_id > 0, 'Upgrade attachment sentinel ID is invalid.');

$signature = hash('sha256', 'awvp-release-upgrade-sentinel');
update_post_meta((int) $attachment_id, '_argent_video_status', 'queued');
update_post_meta((int) $attachment_id, '_argent_video_source_signature', $signature);
update_post_meta(
    (int) $attachment_id,
    '_argent_video_outputs',
    array(
        'mp4' => array(
            'path' => '/release-test/sentinel.mp4',
            'url'  => 'https://example.invalid/release-test/sentinel.mp4',
        ),
    )
);

$now = current_time('mysql', true);
$inserted = $wpdb->insert(
    $jobs,
    array(
        'attachment_id'    => (int) $attachment_id,
        'source_path'      => '/release-test/sentinel-source.mp4',
        'source_signature' => $signature,
        'profile'          => 'compatibility',
        'status'           => 'queued',
        'attempts'         => 0,
        'created_at'       => $now,
        'updated_at'       => $now,
    ),
    array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
);
awvp_release_assert(false !== $inserted, 'Could not insert base queue sentinel.');

$job_id = (int) $wpdb->insert_id;
awvp_release_assert($job_id > 0, 'Base queue sentinel insert ID is invalid.');

update_option('awvp_release_attachment_id', (int) $attachment_id, false);
update_option('awvp_release_job_id', $job_id, false);
update_option('awvp_release_signature', $signature, false);

echo "AWVP_RELEASE_UPGRADE_SEED_PASS attachment_id={$attachment_id} job_id={$job_id}\n";
