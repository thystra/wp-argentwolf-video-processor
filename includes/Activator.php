<?php
/**
 * File: includes/Activator.php
 */

declare(strict_types=1);

namespace ArgentVideo;

final class Activator
{
    public const DB_VERSION = '1';
    public const DB_OPTION = 'argent_video_processor_db_version';
    public const CRON_HOOK = 'argent_video_processor_dispatch';

    public static function activate(): void
    {
        self::create_table();
        Model_Activator::install();
        self::schedule_dispatch();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function maybe_upgrade(): void
    {
        if (self::DB_VERSION !== (string) get_option(self::DB_OPTION, '')) {
            self::create_table();
        }
    }

    public static function schedule_dispatch(): void
    {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'argent_video_five_minutes', self::CRON_HOOK);
        }
    }

    private static function create_table(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'argent_video_jobs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            source_path text NOT NULL,
            source_signature char(64) NOT NULL,
            profile varchar(32) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            attempts int(10) unsigned NOT NULL DEFAULT 0,
            lock_token char(36) DEFAULT NULL,
            locked_at datetime DEFAULT NULL,
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            output_json longtext DEFAULT NULL,
            error_message text DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY attachment_id (attachment_id),
            KEY status_created (status, created_at),
            KEY locked_at (locked_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }
}

// EOF: includes/Activator.php
