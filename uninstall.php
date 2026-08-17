<?php
/**
 * File: uninstall.php
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Preserve queue history, settings, original videos, and derivatives by default.
// Define ARGENT_VIDEO_REMOVE_DATA_ON_UNINSTALL as true before uninstalling to remove plugin data.
if (! defined('ARGENT_VIDEO_REMOVE_DATA_ON_UNINSTALL') || true !== ARGENT_VIDEO_REMOVE_DATA_ON_UNINSTALL) {
    return;
}

global $wpdb;

// Explicit opt-in destructive uninstall must remove both plugin-owned tables.
// Keep table names explicit so uninstall.php creates no global-scope iterator.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query(
    $wpdb->prepare(
        'DROP TABLE IF EXISTS %i',
        $wpdb->prefix . 'argent_video_jobs'
    )
);
$wpdb->query(
    $wpdb->prepare(
        'DROP TABLE IF EXISTS %i',
        $wpdb->prefix . 'argentwolf_video_processor_logs'
    )
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
delete_option('argent_video_processor_settings');
delete_option('argent_video_processor_db_version');
delete_option('argent_video_processor_worker_lock');
delete_option('argent_video_processor_last_worker_run');
delete_option('argent_video_processor_last_launch');

// EOF: uninstall.php
