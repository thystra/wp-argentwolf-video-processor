<?php
/**
 * File: includes/Plugin.php
 */

declare(strict_types=1);

namespace ArgentVideo;

final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;
    private ?Backend_Registry $backend_registry = null;
    private ?Backend_Adapter_Factory $backend_factory = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $jobs = new Job_Repository();
        $worker_logs = new Worker_Log_Repository();
        $this->backend_registry = new Backend_Registry();
        $video_publishing_defaults = new Video_Publishing_Defaults_Store($this->backend_registry);
        $queue = new Queue($jobs, $video_publishing_defaults);
        $bulk = new Bulk_Queue($jobs, $queue);
        $runner = new Process_Runner();
        $probe = new Probe($runner);
        $transcoder = new Transcoder($runner, $probe);
        $worker = new Worker($jobs, $transcoder, $queue);
        $launcher = new Worker_Launcher($jobs, $worker_logs);
        $peertube_tasks = new Task_Repository();
        $peertube_events = new PeerTube_Event_Repository();
        $peertube_upload_operations = new PeerTube_Staged_Upload_Operation_Store();
        $peertube_task_launcher = new PeerTube_Task_Worker_Launcher($peertube_tasks);
        $player = new Player();
        $renderer = new Renderer($player);
        $diagnostics = new Diagnostics();
        $peertube_secrets = new Managed_Backend_Secret_Store();
        $peertube_upload_policy = new PeerTube_Upload_Policy_Store($this->backend_registry);
        $video_block_editor_service = new Video_Block_Editor_Service($this->backend_registry, $video_publishing_defaults, $jobs);
        $video_block_editor_rest = new Video_Block_Editor_Rest($video_block_editor_service);
        $peertube_remote_assets = new Remote_Asset_Repository();
        $publication_health = new Remote_Publication_Health_Repository();
        $serving_priorities = new Backend_Serving_Priority_Store();
        $processing_estimator = new Backend_Processing_Estimator();
        $video_serving = new Video_Serving_Service($peertube_remote_assets, $publication_health, $serving_priorities);
        $backend_health_incidents = new Backend_Health_Incident_Store();
        $remote_health_notification_policy = new Remote_Health_Notification_Policy_Store();
        $remote_health_notification_state = new Remote_Health_Notification_State_Store();
        $remote_health_notifications = new Remote_Health_Notification_Service(
            $remote_health_notification_policy,
            $remote_health_notification_state,
            $video_serving,
            array($peertube_upload_operations, 'get'),
            $peertube_events
        );
        $legacy_video_serving_bridge = new Legacy_Video_Serving_Bridge($video_serving);
        $archive_of_record_policy = new Archive_Of_Record_Policy_Store();
        $local_retention_service = new Local_Retention_Service($peertube_tasks, $video_serving, $jobs, $archive_of_record_policy);
        $video_block = new Video_Block($video_serving, $renderer);
        $peertube_publication_catalogs = new PeerTube_Publication_Catalog_Store();
        $peertube_publication_editor = new PeerTube_Publication_Editor_Service(
            $this->backend_registry,
            $video_publishing_defaults,
            $peertube_publication_catalogs
        );
        $peertube_publication_editor_rest = new PeerTube_Publication_Editor_Rest($peertube_publication_editor);
        $editorial_publish_validator = new Editorial_Publish_Validator();
        $editorial_publish_gate = new Editorial_Publish_Gate($editorial_publish_validator);
        $peertube_publication_synchronizer = new PeerTube_Publication_Synchronizer(
            $peertube_tasks,
            $editorial_publish_validator,
            $jobs
        );
        $peertube_cutover = new PeerTube_Serving_Cutover_Service($peertube_remote_assets, $publication_health);
        $peertube_incomplete_work = new PeerTube_Incomplete_Work_Reconciler(
            $peertube_publication_synchronizer,
            $peertube_cutover
        );
        $remote_republish = new Remote_Republish_Service(
            $this->backend_registry,
            $video_publishing_defaults,
            $peertube_publication_catalogs,
            $peertube_publication_synchronizer,
            $peertube_events
        );
        $peertube_api_factory = static fn (string $origin): PeerTube_Api_Client =>
            new PeerTube_Api_Client(new PeerTube_Http_Client($origin));
        $serving_health_factory = new Serving_Health_Adapter_Factory(
            new PeerTube_Publication_Health_Probe($peertube_secrets, $peertube_api_factory)
        );
        $remote_publication_health = new Remote_Publication_Health_Service(
            $publication_health,
            $this->backend_registry,
            $serving_health_factory,
            $peertube_events,
            $processing_estimator,
            $backend_health_incidents,
            $remote_health_notifications
        );
        $peertube_lifecycle = new PeerTube_Token_Lifecycle_Service(
            new PeerTube_Token_Lifecycle_Store(),
            $peertube_secrets,
            $this->backend_registry
        );
        $peertube_publication_catalog_service = new PeerTube_Publication_Catalog_Service(
            $peertube_publication_catalogs,
            $peertube_secrets,
            $this->backend_registry,
            $peertube_api_factory
        );
        $peertube_publication_authority_repair = new PeerTube_Publication_Authority_Repair(
            $peertube_lifecycle,
            $peertube_publication_catalog_service,
            $peertube_publication_catalogs,
            $peertube_secrets,
            $this->backend_registry
        );
        $backend_maintenance_status = new Backend_Maintenance_Status_Store();
        $peertube_daily_maintenance = new PeerTube_Daily_Maintenance_Service(
            $this->backend_registry,
            $peertube_publication_authority_repair,
            $peertube_publication_catalog_service,
            $backend_maintenance_status
        );
        $this->backend_factory = new Backend_Adapter_Factory(
            new Local_Backend_Adapter($queue, $diagnostics),
            new PeerTube_Backend_Adapter($peertube_secrets)
        );
        $admin = new Admin($jobs, $queue, $bulk, $launcher, $diagnostics, $worker_logs);

        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action('plugins_loaded', array(Activator::class, 'maybe_upgrade'));
        add_action('plugins_loaded', array(Model_Activator::class, 'maybe_upgrade'));
        add_action('init', array(Video_Post_Type::class, 'register'), 5);
        add_action('init', array(Video_Meta::class, 'register'), 6);
        add_action('init', array($video_block, 'register'), 7);
        add_action('rest_api_init', array($video_block_editor_rest, 'register'));
        add_action('rest_api_init', array($peertube_publication_editor_rest, 'register'));
        $editorial_publish_gate->register();
        $peertube_publication_synchronizer->register();
        add_action('init', array(Activator::class, 'schedule_dispatch'));
        add_action('add_attachment', array($queue, 'maybe_enqueue_attachment'));
        add_action('delete_attachment', array($queue, 'delete_attachment'));
        add_action(Activator::CRON_HOOK, array($launcher, 'dispatch'));
        add_action('argentwolf_video_processor_task_enqueued', array($peertube_task_launcher, 'wake'), 10, 2);
        add_action(Activator::PEERTUBE_RECOVERY_HOOK, array($peertube_incomplete_work, 'recover'), 5);
        add_action(Activator::PEERTUBE_RECOVERY_HOOK, array($remote_republish, 'recover'), 7);
        add_action(Activator::PEERTUBE_RECOVERY_HOOK, array($peertube_task_launcher, 'recover'), 10);
        add_action(Activator::REMOTE_HEALTH_HOOK, array($remote_publication_health, 'run'));
        add_action(Activator::BACKEND_MAINTENANCE_HOOK, array($peertube_daily_maintenance, 'run'));
        add_filter('render_block_core/video', array($renderer, 'render_block'), 10, 2);
        add_filter('render_block_core/video', array($legacy_video_serving_bridge, 'render_block'), 20, 2);
        add_filter('wp_video_shortcode', array($renderer, 'render_shortcode'), 10, 2);
        add_filter('wp_video_shortcode', array($legacy_video_serving_bridge, 'render_shortcode'), 20, 2);
        add_filter('site_status_tests', array($diagnostics, 'site_health_tests'));

        if (is_admin()) {
            $peertube_operations = new PeerTube_Connection_Operation_Store();
            $peertube_coordinator = new PeerTube_Connection_Coordinator(
                $peertube_operations,
                $peertube_secrets,
                $this->backend_registry
            );
            $peertube_grants = new PeerTube_Password_Grant_Service(
                $peertube_operations,
                $peertube_secrets,
                $this->backend_registry
            );
            $peertube_identity_destinations = new PeerTube_Identity_Destination_Service(
                $peertube_operations,
                $peertube_secrets,
                $this->backend_registry
            );
            $peertube_activation = new PeerTube_Backend_Activation_Service(
                $peertube_operations,
                $peertube_secrets,
                $this->backend_registry,
                $this->backend_factory
            );
            $video_publishing_admin = new Video_Publishing_Admin(
                $video_publishing_defaults,
                $this->backend_registry,
                $peertube_publication_catalogs,
                $peertube_publication_catalog_service,
                $serving_priorities,
                $remote_health_notification_policy
            );
            $legacy_video_adoption = new Legacy_Video_Adoption_Service();
            $peertube_migration_planner = new PeerTube_Migration_Planner(
                $this->backend_registry,
                $video_publishing_defaults,
                $peertube_publication_catalogs,
                $legacy_video_adoption
            );
            $peertube_migration_executor = new PeerTube_Migration_Executor(
                $this->backend_registry,
                $peertube_publication_catalogs,
                $video_publishing_defaults,
                $peertube_publication_synchronizer,
                static function (array $descriptor, string $backend_id) use ($peertube_secrets): int {
                    $secret_ref = is_string($descriptor['secret_ref'] ?? null) ? $descriptor['secret_ref'] : '';
                    if ('' === $secret_ref) {
                        return 0;
                    }
                    try {
                        $secret = $peertube_secrets->read($secret_ref, $backend_id);
                    } catch (\Throwable) {
                        $secret = null;
                    }
                    $generation = is_array($secret) && is_int($secret['generation'] ?? null)
                        ? $secret['generation'] : 0;
                    unset($secret);
                    return $generation > 0 ? $generation : 0;
                }
            );
            $local_retention_default = new Local_Retention_Default_Policy_Store();
            $local_retention_admin = new Local_Retention_Admin($local_retention_service, $archive_of_record_policy, $local_retention_default);
            $peertube_migration_admin = new PeerTube_Migration_Admin(
                $peertube_migration_planner,
                $peertube_migration_executor,
                $this->backend_registry,
                $peertube_publication_catalogs
            );
            $peertube_admin = new PeerTube_Connection_Admin(
                new PeerTube_Connection_Admin_Service(
                    $peertube_operations,
                    $peertube_coordinator,
                    $peertube_grants,
                    $peertube_identity_destinations,
                    $peertube_activation,
                    $peertube_lifecycle,
                    $peertube_upload_policy,
                    $peertube_publication_authority_repair
                )
            );
            $overview_dispositions = new Overview_Disposition_Store();
            $peertube_overview = new PeerTube_Overview_Admin(
                $peertube_upload_operations,
                $peertube_incomplete_work,
                $peertube_events,
                $publication_health,
                $video_serving,
                $overview_dispositions,
                $backend_maintenance_status,
                $backend_health_incidents,
                $remote_republish
            );
            $settings_hub = new Settings_Hub(
                $admin,
                $peertube_admin,
                $video_publishing_admin,
                $peertube_migration_admin,
                $local_retention_admin,
                $peertube_overview
            );

            add_action('admin_init', array($admin, 'register'));
            add_filter('plugin_action_links_' . plugin_basename(ARGENT_VIDEO_FILE), array($admin, 'plugin_action_links'));
            add_action('admin_menu', array($settings_hub, 'menu'));
            add_action('admin_enqueue_scripts', array($local_retention_admin, 'enqueue_assets'));
            add_filter('manage_media_columns', array($admin, 'media_columns'));
            add_action('manage_media_custom_column', array($admin, 'media_column'), 10, 2);
            add_action('admin_post_argent_video_queue_attachment', array($admin, 'queue_action'));
            add_action('admin_post_argent_video_bulk_queue', array($admin, 'bulk_action'));
            add_action('admin_post_argent_video_cancel_attachment', array($admin, 'cancel_action'));
            add_action('admin_post_argent_video_dispatch', array($admin, 'dispatch_action'));
            add_action('admin_post_argentwolf_video_processor_clear_worker_logs', array($admin, 'clear_worker_logs_action'));
            add_action('admin_post_' . PeerTube_Overview_Admin::ACTION_RESUME, array($peertube_overview, 'resume_action'));
            add_action('admin_post_' . PeerTube_Overview_Admin::ACTION_REVIEW, static fn (): mixed => $peertube_overview->disposition_action(Overview_Disposition_Store::REVIEWED));
            add_action('admin_post_' . PeerTube_Overview_Admin::ACTION_UNREVIEW, static fn (): mixed => $peertube_overview->disposition_action('clear'));
            add_action('admin_post_' . PeerTube_Overview_Admin::ACTION_DISMISS, static fn (): mixed => $peertube_overview->disposition_action(Overview_Disposition_Store::DISMISSED));
            add_action('admin_post_' . PeerTube_Overview_Admin::ACTION_REPUBLISH, array($peertube_overview, 'republish_action'));
            add_action(
                'admin_post_' . PeerTube_Connection_Admin::ACTION_START,
                array($peertube_admin, 'start_action')
            );
            add_action(
                'admin_post_' . PeerTube_Connection_Admin::ACTION_RESUME,
                array($peertube_admin, 'resume_action')
            );
            add_action(
                'admin_post_' . PeerTube_Connection_Admin::ACTION_GRANT,
                array($peertube_admin, 'grant_action')
            );
            add_action(
                'admin_post_' . PeerTube_Connection_Admin::ACTION_RECONCILE,
                array($peertube_admin, 'reconcile_action')
            );
            add_action(
                'admin_post_' . PeerTube_Connection_Admin::ACTION_VERIFY_IDENTITY,
                array($peertube_admin, 'verify_identity_action')
            );
            add_action(
                'admin_post_' . PeerTube_Connection_Admin::ACTION_SELECT_DESTINATION,
                array($peertube_admin, 'select_destination_action')
            );
            add_action(
                'admin_post_' . PeerTube_Connection_Admin::ACTION_ACTIVATE,
                array($peertube_admin, 'activate_action')
            );
            add_action(
                'admin_post_' . PeerTube_Connection_Admin::ACTION_REFRESH,
                array($peertube_admin, 'refresh_action')
            );
            add_action(
                'admin_post_' . PeerTube_Connection_Admin::ACTION_DISCONNECT,
                array($peertube_admin, 'disconnect_action')
            );
            add_action(
                'admin_post_' . PeerTube_Connection_Admin::ACTION_UPLOAD_POLICY,
                array($peertube_admin, 'upload_policy_action')
            );
            add_action(
                'admin_post_' . Video_Publishing_Admin::ACTION_SAVE,
                array($video_publishing_admin, 'save_action')
            );
            add_action(
                'admin_post_' . Video_Publishing_Admin::ACTION_REFRESH_CHOICES,
                array($video_publishing_admin, 'refresh_choices_action')
            );
            add_action(
                'admin_post_' . Local_Retention_Admin::ACTION_CONFIGURE,
                array($local_retention_admin, 'configure_action')
            );
            add_action(
                'admin_post_' . Local_Retention_Admin::ACTION_ARCHIVE_POLICY,
                array($local_retention_admin, 'archive_policy_action')
            );
            add_action(
                'admin_post_' . Local_Retention_Admin::ACTION_DEFAULT_POLICY,
                array($local_retention_admin, 'default_policy_action')
            );
            add_action(
                'admin_post_' . Local_Retention_Admin::ACTION_BULK_APPLY,
                array($local_retention_admin, 'bulk_apply_action')
            );
            add_action(
                'admin_post_' . PeerTube_Migration_Admin::ACTION_PLAN,
                array($peertube_migration_admin, 'plan_action')
            );
            add_action(
                'admin_post_' . PeerTube_Migration_Admin::ACTION_REVIEW,
                array($peertube_migration_admin, 'review_action')
            );
            add_action(
                'admin_post_' . PeerTube_Migration_Admin::ACTION_EXECUTE,
                array($peertube_migration_admin, 'execute_action')
            );
            add_action('admin_notices', array($admin, 'notices'));
            add_action('admin_notices', array($peertube_admin, 'notices'));
        }

        if (defined('WP_CLI') && WP_CLI) {
            $peertube_upload = new PeerTube_Staged_Upload_Service(
                $peertube_upload_operations,
                $this->backend_registry,
                $peertube_secrets,
                $peertube_api_factory,
                array($peertube_upload_policy, 'chunk_mib')
            );
            $peertube_reconciliation = new PeerTube_Remote_Asset_Reconciliation_Service(
                $peertube_upload_operations,
                $peertube_remote_assets,
                $this->backend_registry,
                $peertube_secrets,
                $peertube_api_factory
            );
            $peertube_failure_notification = new PeerTube_Upload_Failure_Notification(
                $peertube_tasks,
                array($peertube_upload_operations, 'get')
            );
            $peertube_task_coordinator = new PeerTube_Upload_Task_Coordinator(
                $peertube_tasks,
                array($peertube_upload_operations, 'get'),
                array($peertube_upload, 'advance'),
                array($peertube_reconciliation, 'advance'),
                $peertube_failure_notification
            );
            $peertube_derivative_cleanup = new PeerTube_Derivative_Cleanup_Service($video_serving);
            $peertube_publication_tasks = new PeerTube_Publication_Task_Coordinator(
                $peertube_tasks,
                $peertube_upload_operations,
                $peertube_upload,
                $peertube_task_coordinator,
                new PeerTube_Publication_Staging_Service(),
                $peertube_remote_assets,
                $this->backend_registry,
                $peertube_secrets,
                $peertube_publication_catalogs,
                $video_publishing_defaults,
                $peertube_api_factory,
                $peertube_cutover,
                $peertube_derivative_cleanup,
                $peertube_publication_authority_repair,
                $remote_publication_health
            );
            $peertube_task_worker = new PeerTube_Task_Worker(
                $peertube_tasks,
                $peertube_task_coordinator,
                array($peertube_upload_operations, 'get'),
                array($peertube_publication_tasks, 'advance_claimed'),
                array($local_retention_service, 'advance_claimed'),
                $peertube_events
            );

            \WP_CLI::add_command(
                'argent-video',
                new CLI_Command(
                    $jobs,
                    $queue,
                    $bulk,
                    $worker,
                    $diagnostics,
                    $worker_logs,
                    $peertube_task_worker
                )
            );
        }
    }

    public function backend_registry(): Backend_Registry
    {
        if (null === $this->backend_registry) {
            throw new \RuntimeException('AWVP backend registry is not initialized.');
        }

        return $this->backend_registry;
    }

    public function backend_factory(): Backend_Adapter_Factory
    {
        if (null === $this->backend_factory) {
            throw new \RuntimeException('AWVP backend adapter factory is not initialized.');
        }

        return $this->backend_factory;
    }

    /** @param array<string, array<string, mixed>> $schedules
     *  @return array<string, array<string, mixed>>
     */
    public function cron_schedules(array $schedules): array
    {
        $schedules['argent_video_five_minutes'] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __('Every five minutes (ArgentWolf Video)', 'argentwolf-video-processor'),
        );
        $schedules['argent_video_one_minute'] = array(
            'interval' => MINUTE_IN_SECONDS,
            'display'  => __('Every minute (ArgentWolf Video PeerTube recovery)', 'argentwolf-video-processor'),
        );
        return $schedules;
    }
}

// EOF: includes/Plugin.php
