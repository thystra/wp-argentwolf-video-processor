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
        $queue = new Queue($jobs);
        $bulk = new Bulk_Queue($jobs, $queue);
        $runner = new Process_Runner();
        $probe = new Probe($runner);
        $transcoder = new Transcoder($runner, $probe);
        $worker = new Worker($jobs, $transcoder);
        $launcher = new Worker_Launcher($jobs, $worker_logs);
        $player = new Player();
        $renderer = new Renderer($player);
        $diagnostics = new Diagnostics();
        $this->backend_registry = new Backend_Registry();
        $this->backend_factory = new Backend_Adapter_Factory(
            new Local_Backend_Adapter($queue, $diagnostics)
        );
        $admin = new Admin($jobs, $queue, $bulk, $launcher, $diagnostics, $worker_logs);

        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action('plugins_loaded', array(Activator::class, 'maybe_upgrade'));
        add_action('plugins_loaded', array(Model_Activator::class, 'maybe_upgrade'));
        add_action('init', array(Video_Post_Type::class, 'register'), 5);
        add_action('init', array(Video_Meta::class, 'register'), 6);
        add_action('init', array(Activator::class, 'schedule_dispatch'));
        add_action('add_attachment', array($queue, 'maybe_enqueue_attachment'));
        add_action('delete_attachment', array($queue, 'delete_attachment'));
        add_action(Activator::CRON_HOOK, array($launcher, 'dispatch'));
        add_filter('render_block_core/video', array($renderer, 'render_block'), 10, 2);
        add_filter('wp_video_shortcode', array($renderer, 'render_shortcode'), 10, 2);
        add_filter('site_status_tests', array($diagnostics, 'site_health_tests'));

        if (is_admin()) {
            $peertube_operations = new PeerTube_Connection_Operation_Store();
            $peertube_secrets = new Managed_Backend_Secret_Store();
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
            $peertube_admin = new PeerTube_Connection_Admin(
                new PeerTube_Connection_Admin_Service(
                    $peertube_operations,
                    $peertube_coordinator,
                    $peertube_grants
                )
            );

            add_action('admin_init', array($admin, 'register'));
            add_filter('plugin_action_links_' . plugin_basename(ARGENT_VIDEO_FILE), array($admin, 'plugin_action_links'));
            add_action('admin_menu', array($admin, 'menu'));
            add_action('admin_menu', array($peertube_admin, 'menu'));
            add_filter('manage_media_columns', array($admin, 'media_columns'));
            add_action('manage_media_custom_column', array($admin, 'media_column'), 10, 2);
            add_action('admin_post_argent_video_queue_attachment', array($admin, 'queue_action'));
            add_action('admin_post_argent_video_bulk_queue', array($admin, 'bulk_action'));
            add_action('admin_post_argent_video_cancel_attachment', array($admin, 'cancel_action'));
            add_action('admin_post_argent_video_dispatch', array($admin, 'dispatch_action'));
            add_action('admin_post_argentwolf_video_processor_clear_worker_logs', array($admin, 'clear_worker_logs_action'));
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
            add_action('admin_notices', array($admin, 'notices'));
            add_action('admin_notices', array($peertube_admin, 'notices'));
        }

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('argent-video', new CLI_Command($jobs, $queue, $bulk, $worker, $diagnostics, $worker_logs));
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
        return $schedules;
    }
}

// EOF: includes/Plugin.php
