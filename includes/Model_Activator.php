<?php
/**
 * File: includes/Model_Activator.php
 */

declare(strict_types=1);

namespace ArgentVideo;

final class Model_Activator
{
    public const DB_VERSION = '1';
    public const DB_OPTION = 'argent_video_processor_model_db_version';
    public const DB_AUTOLOAD = true;
    public const REMOTE_ASSETS_TABLE = 'argent_video_remote_assets';
    public const TASKS_TABLE = 'argent_video_tasks';

    public static function install(): bool
    {
        global $wpdb;

        if (! function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        foreach (self::schema_queries() as $sql) {
            dbDelta($sql);

            if ('' !== (string) $wpdb->last_error) {
                return false;
            }
        }

        if (! self::schema_is_current()) {
            return false;
        }

        update_option(self::DB_OPTION, self::DB_VERSION, self::DB_AUTOLOAD);
        return true;
    }

    public static function maybe_upgrade(): void
    {
        if (self::DB_VERSION !== (string) get_option(self::DB_OPTION, '')) {
            self::install();
        }
    }

    /** @return list<string> */
    public static function schema_queries(): array
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $remote_assets = $wpdb->prefix . self::REMOTE_ASSETS_TABLE;
        $tasks = $wpdb->prefix . self::TASKS_TABLE;

        $remote_sql = "CREATE TABLE {$remote_assets} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            video_post_id bigint(20) unsigned NOT NULL,
            backend_id varchar(64) NOT NULL,
            channel_id varchar(191) DEFAULT NULL,
            remote_id varchar(127) DEFAULT NULL,
            role varchar(24) NOT NULL DEFAULT 'secondary',
            state varchar(32) NOT NULL DEFAULT 'creating',
            desired_privacy varchar(32) DEFAULT NULL,
            actual_privacy varchar(32) DEFAULT NULL,
            remote_processing_state varchar(64) DEFAULT NULL,
            remote_url text DEFAULT NULL,
            embed_url text DEFAULT NULL,
            last_synced_at datetime DEFAULT NULL,
            last_verified_at datetime DEFAULT NULL,
            error_code varchar(64) DEFAULT NULL,
            error_message text DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY backend_remote (backend_id, remote_id),
            KEY video_role (video_post_id, role),
            KEY video_state (video_post_id, state),
            KEY backend_state (backend_id, state),
            KEY state_synced (state, last_synced_at)
        ) {$charset_collate};";

        $task_sql = "CREATE TABLE {$tasks} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            task_type varchar(64) NOT NULL,
            video_post_id bigint(20) unsigned DEFAULT NULL,
            remote_asset_id bigint(20) unsigned DEFAULT NULL,
            backend_id varchar(64) DEFAULT NULL,
            idempotency_key char(64) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            priority smallint(5) unsigned NOT NULL DEFAULT 100,
            run_after datetime NOT NULL,
            attempts int(10) unsigned NOT NULL DEFAULT 0,
            max_attempts int(10) unsigned NOT NULL DEFAULT 5,
            lock_token char(36) DEFAULT NULL,
            locked_at datetime DEFAULT NULL,
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            payload_json longtext DEFAULT NULL,
            error_message text DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY status_run (status, run_after, priority),
            KEY locked_at (locked_at),
            KEY video_type (video_post_id, task_type),
            KEY backend_status (backend_id, status)
        ) {$charset_collate};";

        return array($remote_sql, $task_sql);
    }

    public static function schema_is_current(): bool
    {
        global $wpdb;

        foreach (self::schema_contract() as $table => $contract) {
            $table_query = $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($table)
            );
            if ($table !== (string) $wpdb->get_var($table_query)) {
                return false;
            }

            $column_query = $wpdb->prepare('SHOW COLUMNS FROM %i', $table);
            $column_rows = $wpdb->get_results($column_query, ARRAY_A);
            if (! is_array($column_rows)) {
                return false;
            }

            $columns = array();
            foreach ($column_rows as $row) {
                if (isset($row['Field'])) {
                    $columns[] = (string) $row['Field'];
                }
            }

            foreach ($contract['columns'] as $required_column) {
                if (! in_array($required_column, $columns, true)) {
                    return false;
                }
            }

            $index_query = $wpdb->prepare('SHOW INDEX FROM %i', $table);
            $index_rows = $wpdb->get_results($index_query, ARRAY_A);
            if (! is_array($index_rows)) {
                return false;
            }

            $indexes = array();
            foreach ($index_rows as $row) {
                if (
                    ! isset(
                        $row['Key_name'],
                        $row['Column_name'],
                        $row['Seq_in_index'],
                        $row['Non_unique']
                    )
                ) {
                    continue;
                }

                $name = (string) $row['Key_name'];
                $sequence = (int) $row['Seq_in_index'];
                $indexes[$name]['unique'] = 0 === (int) $row['Non_unique'];
                $indexes[$name]['columns'][$sequence] = (string) $row['Column_name'];
            }

            foreach ($contract['indexes'] as $name => $required_index) {
                if (! isset($indexes[$name])) {
                    return false;
                }

                ksort($indexes[$name]['columns']);
                $actual_columns = array_values($indexes[$name]['columns']);

                if ($required_index['unique'] !== $indexes[$name]['unique']) {
                    return false;
                }

                if ($required_index['columns'] !== $actual_columns) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<string, array{
     *     columns:list<string>,
     *     indexes:array<string, array{unique:bool, columns:list<string>}>
     * }>
     */
    private static function schema_contract(): array
    {
        global $wpdb;

        return array(
            $wpdb->prefix . self::REMOTE_ASSETS_TABLE => array(
                'columns' => array(
                    'id',
                    'video_post_id',
                    'backend_id',
                    'channel_id',
                    'remote_id',
                    'role',
                    'state',
                    'desired_privacy',
                    'actual_privacy',
                    'remote_processing_state',
                    'remote_url',
                    'embed_url',
                    'last_synced_at',
                    'last_verified_at',
                    'error_code',
                    'error_message',
                    'created_at',
                    'updated_at',
                ),
                'indexes' => array(
                    'PRIMARY' => array('unique' => true, 'columns' => array('id')),
                    'backend_remote' => array(
                        'unique'  => true,
                        'columns' => array('backend_id', 'remote_id'),
                    ),
                    'video_role' => array(
                        'unique'  => false,
                        'columns' => array('video_post_id', 'role'),
                    ),
                    'video_state' => array(
                        'unique'  => false,
                        'columns' => array('video_post_id', 'state'),
                    ),
                    'backend_state' => array(
                        'unique'  => false,
                        'columns' => array('backend_id', 'state'),
                    ),
                    'state_synced' => array(
                        'unique'  => false,
                        'columns' => array('state', 'last_synced_at'),
                    ),
                ),
            ),
            $wpdb->prefix . self::TASKS_TABLE => array(
                'columns' => array(
                    'id',
                    'task_type',
                    'video_post_id',
                    'remote_asset_id',
                    'backend_id',
                    'idempotency_key',
                    'status',
                    'priority',
                    'run_after',
                    'attempts',
                    'max_attempts',
                    'lock_token',
                    'locked_at',
                    'started_at',
                    'completed_at',
                    'payload_json',
                    'error_message',
                    'created_at',
                    'updated_at',
                ),
                'indexes' => array(
                    'PRIMARY' => array('unique' => true, 'columns' => array('id')),
                    'idempotency_key' => array(
                        'unique'  => true,
                        'columns' => array('idempotency_key'),
                    ),
                    'status_run' => array(
                        'unique'  => false,
                        'columns' => array('status', 'run_after', 'priority'),
                    ),
                    'locked_at' => array(
                        'unique'  => false,
                        'columns' => array('locked_at'),
                    ),
                    'video_type' => array(
                        'unique'  => false,
                        'columns' => array('video_post_id', 'task_type'),
                    ),
                    'backend_status' => array(
                        'unique'  => false,
                        'columns' => array('backend_id', 'status'),
                    ),
                ),
            ),
        );
    }
}

// EOF: includes/Model_Activator.php
