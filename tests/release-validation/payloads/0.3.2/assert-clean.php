<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

global $wpdb;

awvp_release_assert_candidate_common();

$jobs = $wpdb->prefix . 'argent_video_jobs';
$logs = $wpdb->prefix . \ArgentVideo\Worker_Log_Repository::TABLE_SUFFIX;

awvp_release_assert(
    0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$jobs}`"),
    'Clean activation unexpectedly created queue jobs.'
);
awvp_release_assert(
    0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$logs}`"),
    'Clean activation unexpectedly created worker diagnostic rows.'
);

echo "AWVP_RELEASE_CLEAN_ACTIVATION_PASS\n";
