<?php
/**
 * File: includes/Uninstall_Data.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Explicit ownership manifest for destructive local uninstall.
 *
 * This intentionally covers database/state ownership only. Ordinary WordPress
 * source attachments, external archives, PeerTube assets, and managed media
 * files on disk are never deleted by uninstall.
 */
final class Uninstall_Data
{
    public const POST_TYPE = 'argent_video_asset';

    /** @return list<string> */
    public static function table_suffixes(): array
    {
        return array(
            'argent_video_jobs',
            'argentwolf_video_processor_logs',
            'argent_video_remote_assets',
            'argent_video_tasks',
            'argent_video_events',
            'argent_video_publication_health',
        );
    }

    /** @return list<string> */
    public static function option_names(): array
    {
        return array(
            'argent_video_processor_settings',
            'argent_video_processor_db_version',
            'argent_video_processor_model_db_version',
            'argent_video_processor_worker_lock',
            'argent_video_processor_last_worker_run',
            'argent_video_processor_last_launch',
            'argent_video_processor_backends',
            'argent_video_processor_backend_processing_history',
            'argent_video_processor_overview_dispositions',
            'argentwolf_video_processor_archive_of_record',
            'argentwolf_video_processor_local_retention_default',
            'argentwolf_video_processor_peertube_upload_operations',
            'argentwolf_video_processor_peertube_connection_operations',
            'argent_video_processor_remote_health_notification_policy',
            'argent_video_processor_remote_health_notification_state',
            'argent_video_processor_backend_health_incidents',
            'argent_video_processor_serving_priorities',
            'argent_video_processor_backend_maintenance_status',
            'argent_video_processor_video_publishing_defaults',
            'argentwolf_video_processor_backend_secrets',
        );
    }

    /** @return list<string> */
    public static function option_prefixes(): array
    {
        return array(
            'argent_video_processor_peertube_upload_policy_',
            'argent_video_processor_pt_catalog_',
            'argentwolf_video_processor_peertube_lifecycle.',
            'argent_video_processor_publication_execution_lock_',
            'argent_video_processor_publication_sync_lock_',
            'argentwolf_video_processor_migration_execute_lock_',
            'argentwolf_video_processor_legacy_adopt_lock_',
            'argentwolf_video_processor_attachment_bind_lock_',
        );
    }

    /** @return list<string> */
    public static function cron_hooks(): array
    {
        return array(
            'argent_video_processor_dispatch',
            'argent_video_processor_peertube_recovery',
            'argent_video_processor_remote_health',
            'argent_video_processor_backend_maintenance',
        );
    }

    /** @return list<string> */
    public static function transient_names(): array
    {
        return array(
            'argent_video_processor_launch_lock',
            'argent_video_processor_peertube_task_launch_lock',
        );
    }

    /** @return list<string> */
    public static function post_meta_keys(): array
    {
        return array(
            '_argent_video_asset_id',
            '_argent_video_attachment_id',
            '_argent_video_cleanup_state',
            '_argent_video_destination',
            '_argent_video_ingest_kind',
            '_argent_video_job_id',
            '_argent_video_last_error',
            '_argent_video_local_retention_execution',
            '_argent_video_local_retention_policy',
            '_argent_video_master_authority',
            '_argent_video_metadata_origin',
            '_argent_video_origin_post_id',
            '_argent_video_origin_sequence',
            '_argent_video_outputs',
            '_argent_video_peertube_migration_execution',
            '_argent_video_peertube_migration_plan',
            '_argent_video_peertube_publication_execution',
            '_argent_video_peertube_publication_lifecycle',
            '_argent_video_peertube_publication_plan',
            '_argent_video_peertube_recovery_window',
            '_argent_video_processed_at',
            '_argent_video_processor_version',
            '_argent_video_profile',
            '_argent_video_profile_snapshot',
            '_argent_video_publication_policy',
            '_argent_video_remote_republish_request',
            '_argent_video_serving_authority',
            '_argent_video_source_signature',
            '_argent_video_source_state',
            '_argent_video_status',
            '_argentwolf_video_processor_local_delivery_rebuild_request',
            '_argentwolf_video_processor_remote_health_operator_check',
        );
    }

    public static function remove(): void
    {
        global $wpdb;

        foreach (self::cron_hooks() as $hook) {
            wp_clear_scheduled_hook($hook);
        }

        foreach (self::transient_names() as $transient) {
            delete_transient($transient);
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
        foreach (self::table_suffixes() as $suffix) {
            $wpdb->query(
                $wpdb->prepare(
                    'DROP TABLE IF EXISTS %i',
                    $wpdb->prefix . $suffix
                )
            );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

        foreach (self::option_names() as $option) {
            delete_option($option);
        }

        foreach (self::option_prefixes() as $prefix) {
            // Read the concrete names first so delete_option() also clears the
            // corresponding WordPress option caches.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $names = $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT option_name FROM %i WHERE option_name LIKE %s',
                    $wpdb->options,
                    $wpdb->esc_like($prefix) . '%'
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

            foreach ($names as $name) {
                if (is_string($name) && '' !== $name) {
                    delete_option($name);
                }
            }
        }

        foreach (self::post_meta_keys() as $meta_key) {
            delete_post_meta_by_key($meta_key);
        }

        // Delete only plugin-owned AWVP Video records. wp_delete_post() cleans
        // their remaining WordPress-owned post rows/meta/relationships while
        // leaving ordinary Media Library attachments untouched.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $video_ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT ID FROM %i WHERE post_type = %s',
                $wpdb->posts,
                self::POST_TYPE
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        foreach ($video_ids as $video_id) {
            $video_id = (int) $video_id;
            if ($video_id > 0) {
                wp_delete_post($video_id, true);
            }
        }
    }
}

// EOF: includes/Uninstall_Data.php
