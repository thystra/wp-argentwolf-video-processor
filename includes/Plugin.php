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
        $queue = new Queue($jobs);
        $bulk = new Bulk_Queue($jobs, $queue);
        $runner = new Process_Runner();
        $probe = new Probe($runner);
        $transcoder = new Transcoder($runner, $probe);
        $worker = new Worker($jobs, $transcoder);
        $launcher = new Worker_Launcher($jobs);
        $player = new Player();
        $renderer = new Renderer($player);
        $diagnostics = new Diagnostics();
        $admin = new Admin($jobs, $queue, $bulk, $launcher, $diagnostics);

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
            add_action('admin_init', array($admin, 'register'));
            add_filter('plugin_action_links_' . plugin_basename(ARGENT_VIDEO_FILE), array($admin, 'plugin_action_links'));
            add_action('admin_menu', array($admin, 'menu'));
            add_filter('manage_media_columns', array($admin, 'media_columns'));
            add_action('manage_media_custom_column', array($admin, 'media_column'), 10, 2);
            add_action('admin_post_argent_video_queue_attachment', array($admin, 'queue_action'));
            add_action('admin_post_argent_video_bulk_queue', array($admin, 'bulk_action'));
            add_action('admin_post_argent_video_cancel_attachment', array($admin, 'cancel_action'));
            add_action('admin_post_argent_video_dispatch', array($admin, 'dispatch_action'));
            add_action('admin_notices', array($admin, 'notices'));
        }

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('argent-video', new CLI_Command($jobs, $queue, $bulk, $worker, $diagnostics));
        }
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
