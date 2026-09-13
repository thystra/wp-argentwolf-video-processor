<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
require_once WP_PLUGIN_DIR . '/argentwolf-video-processor/includes/Uninstall_Data.php';

global $wpdb;

awvp_rc_assert_candidate_common();

$uploads = wp_upload_dir();
awvp_release_assert(is_array($uploads) && empty($uploads['error']), 'Uploads are unavailable for uninstall fixture.');
$base_dir = rtrim(wp_normalize_path((string) $uploads['basedir']), '/');
$fixture_dir = $base_dir . '/awvp-release-validation-uninstall';
awvp_release_assert(wp_mkdir_p($fixture_dir), 'Could not create uninstall fixture directory.');

$source_path = $fixture_dir . '/ordinary-source.mp4';
$managed_path = $base_dir . '/argentwolf-video-processor/uninstall-preserved/managed-derivative.mp4';
awvp_release_assert(false !== file_put_contents($source_path, "ordinary source survives uninstall\n"), 'Could not create ordinary source fixture.');
awvp_release_assert(wp_mkdir_p(dirname($managed_path)), 'Could not create managed-media preservation fixture directory.');
awvp_release_assert(false !== file_put_contents($managed_path, "managed derivative survives uninstall\n"), 'Could not create managed-media preservation fixture.');

$attachment_id = wp_insert_post(
    array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_title'     => 'AWVP uninstall source attachment',
        'post_mime_type' => 'video/mp4',
    ),
    true
);
awvp_release_assert(! is_wp_error($attachment_id) && (int) $attachment_id > 0, 'Could not create uninstall source attachment.');
$attachment_id = (int) $attachment_id;
update_attached_file($attachment_id, $source_path);

$video_id = wp_insert_post(
    array(
        'post_type'   => \ArgentVideo\Video_Post_Type::POST_TYPE,
        'post_status' => 'publish',
        'post_title'  => 'AWVP uninstall owned video',
    ),
    true
);
awvp_release_assert(! is_wp_error($video_id) && (int) $video_id > 0, 'Could not create AWVP Video uninstall fixture.');
$video_id = (int) $video_id;

foreach (\ArgentVideo\Uninstall_Data::post_meta_keys() as $meta_key) {
    update_post_meta($attachment_id, $meta_key, 'attachment-fixture');
    update_post_meta($video_id, $meta_key, 'video-fixture');
}

foreach (\ArgentVideo\Uninstall_Data::option_names() as $option) {
    update_option($option, array('uninstall_fixture' => $option), false);
}
foreach (\ArgentVideo\Uninstall_Data::option_prefixes() as $prefix) {
    update_option($prefix . 'release-fixture', array('uninstall_fixture' => $prefix), false);
}
foreach (\ArgentVideo\Uninstall_Data::transient_names() as $transient) {
    set_transient($transient, 'uninstall-fixture', HOUR_IN_SECONDS);
}
foreach (\ArgentVideo\Uninstall_Data::cron_hooks() as $hook) {
    if (false === wp_next_scheduled($hook)) {
        wp_schedule_single_event(time() + HOUR_IN_SECONDS, $hook);
    }
}

foreach (\ArgentVideo\Uninstall_Data::table_suffixes() as $suffix) {
    awvp_release_assert(
        awvp_release_table_exists($wpdb->prefix . $suffix),
        "Uninstall precondition table {$suffix} is missing."
    );
}

if (! defined('WP_UNINSTALL_PLUGIN')) {
    define('WP_UNINSTALL_PLUGIN', true);
}

// First prove the package's default uninstall remains non-destructive.
require WP_PLUGIN_DIR . '/argentwolf-video-processor/uninstall.php';

foreach (\ArgentVideo\Uninstall_Data::table_suffixes() as $suffix) {
    awvp_release_assert(
        awvp_release_table_exists($wpdb->prefix . $suffix),
        "Default uninstall removed owned table {$suffix}."
    );
}
awvp_release_assert(null !== get_post($video_id), 'Default uninstall removed the AWVP Video object.');
awvp_release_assert(null !== get_post($attachment_id), 'Default uninstall removed the ordinary WordPress attachment.');
awvp_release_assert(is_file($source_path), 'Default uninstall removed the ordinary source file.');
awvp_release_assert(is_file($managed_path), 'Default uninstall removed managed media from disk.');
foreach (\ArgentVideo\Uninstall_Data::option_names() as $option) {
    awvp_release_assert(
        '__awvp_missing__' !== get_option($option, '__awvp_missing__'),
        "Default uninstall removed fixed option {$option}."
    );
}

echo "AWVP_RC_DEFAULT_UNINSTALL_PRESERVES_DATA_PASS\n";

if (! defined('ARGENT_VIDEO_REMOVE_DATA_ON_UNINSTALL')) {
    define('ARGENT_VIDEO_REMOVE_DATA_ON_UNINSTALL', true);
}

require WP_PLUGIN_DIR . '/argentwolf-video-processor/uninstall.php';

foreach (\ArgentVideo\Uninstall_Data::table_suffixes() as $suffix) {
    awvp_release_assert(
        ! awvp_release_table_exists($wpdb->prefix . $suffix),
        "Destructive uninstall left owned table {$suffix}."
    );
}

foreach (\ArgentVideo\Uninstall_Data::option_names() as $option) {
    awvp_release_assert(
        '__awvp_missing__' === get_option($option, '__awvp_missing__'),
        "Destructive uninstall left fixed option {$option}."
    );
}
foreach (\ArgentVideo\Uninstall_Data::option_prefixes() as $prefix) {
    $count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name LIKE %s",
            $wpdb->esc_like($prefix) . '%'
        )
    );
    awvp_release_assert(0 === $count, "Destructive uninstall left namespaced option prefix {$prefix}.");
}

foreach (\ArgentVideo\Uninstall_Data::transient_names() as $transient) {
    awvp_release_assert(false === get_transient($transient), "Destructive uninstall left transient {$transient}.");
}
foreach (\ArgentVideo\Uninstall_Data::cron_hooks() as $hook) {
    awvp_release_assert(false === wp_next_scheduled($hook), "Destructive uninstall left cron hook {$hook}.");
}

awvp_release_assert(null === get_post($video_id), 'Destructive uninstall left the plugin-owned AWVP Video object.');
awvp_release_assert(null !== get_post($attachment_id), 'Destructive uninstall deleted an ordinary WordPress source attachment.');
foreach (\ArgentVideo\Uninstall_Data::post_meta_keys() as $meta_key) {
    awvp_release_assert(
        '' === get_post_meta($attachment_id, $meta_key, true),
        "Destructive uninstall left owned attachment metadata {$meta_key}."
    );
}

awvp_release_assert(is_file($source_path), 'Destructive uninstall deleted the ordinary WordPress source file.');
awvp_release_assert(is_file($managed_path), 'Destructive uninstall deleted managed media files from disk.');

@unlink($source_path);
@unlink($managed_path);
wp_delete_post($attachment_id, true);

echo "AWVP_RC_DESTRUCTIVE_UNINSTALL_PASS\n";
