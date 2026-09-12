<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

global $wpdb;

awvp_rc_assert_candidate_common();

$jobs = $wpdb->prefix . 'argent_video_jobs';
$logs = $wpdb->prefix . \ArgentVideo\Worker_Log_Repository::TABLE_SUFFIX;
$remote = $wpdb->prefix . \ArgentVideo\Model_Activator::REMOTE_ASSETS_TABLE;
$tasks = $wpdb->prefix . \ArgentVideo\Model_Activator::TASKS_TABLE;
$events = $wpdb->prefix . \ArgentVideo\Model_Activator::EVENTS_TABLE;
$health = $wpdb->prefix . \ArgentVideo\Model_Activator::PUBLICATION_HEALTH_TABLE;

awvp_release_assert(0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$jobs}`"), 'Clean activation unexpectedly created legacy queue jobs.');
awvp_release_assert(0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$logs}`"), 'Clean activation unexpectedly created worker diagnostic rows.');
awvp_release_assert(0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$remote}`"), 'Clean activation unexpectedly created remote assets.');
awvp_release_assert(0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$tasks}`"), 'Clean activation unexpectedly created PeerTube tasks.');
awvp_release_assert(0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$events}`"), 'Clean activation unexpectedly created PeerTube operator events.');
awvp_release_assert(0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$health}`"), 'Clean activation unexpectedly created publication-health rows.');
awvp_release_assert(
    0 === (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_type = %s",
            \ArgentVideo\Video_Post_Type::POST_TYPE
        )
    ),
    'Clean activation unexpectedly created AWVP Video posts.'
);

echo "AWVP_RC_CLEAN_ACTIVATION_PASS\n";
