<?php
/**
 * File: includes/Settings_Hub.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Single administrator entry point for ArgentWolf Video Processor settings and tools. */
final class Settings_Hub
{
    public const PAGE_SLUG = 'argent-video-processor';
    public const TAB_OVERVIEW = 'overview';
    public const TAB_HISTORY = 'publication-history';
    public const TAB_VIDEOS = 'videos-routing';
    public const TAB_LOCAL = 'local-processing';
    public const TAB_PEERTUBE = 'peertube-servers';
    public const TAB_PUBLISHING = 'publishing';
    public const TAB_MIGRATION = 'video-migration';
    public const TAB_RETENTION = 'local-retention';

    public function __construct(
        private readonly Admin $local,
        private readonly Video_Routing_Admin $routing,
        private readonly PeerTube_Connection_Admin $peertube,
        private readonly Video_Publishing_Admin $publishing,
        private readonly PeerTube_Migration_Admin $migration,
        private readonly Local_Retention_Admin $retention,
        private readonly ?PeerTube_Overview_Admin $overview = null,
        private readonly ?Publication_History_Admin $history = null
    ) {
    }

    public function menu(): void
    {
        add_options_page(
            __('ArgentWolf Video Processor', 'argentwolf-video-processor'),
            __('ArgentWolf Video Processor', 'argentwolf-video-processor'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'page')
        );
    }

    public function page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage ArgentWolf Video Processor settings.', 'argentwolf-video-processor'));
        }

        $tab = self::current_tab();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('ArgentWolf Video Processor', 'argentwolf-video-processor'); ?></h1>
            <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('ArgentWolf Video Processor settings', 'argentwolf-video-processor'); ?>">
                <?php foreach (self::tabs() as $tab_id => $label) : ?>
                    <a class="nav-tab<?php echo $tab === $tab_id ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(self::tab_url($tab_id)); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="awvp-settings-tab" style="margin-top:1.5em">
                <?php
                switch ($tab) {
                    case self::TAB_OVERVIEW:
                        if (null !== $this->overview) {
                            $this->overview->render_tab();
                        } else {
                            $this->local->render_tab();
                        }
                        break;
                    case self::TAB_HISTORY:
                        if (null !== $this->history) {
                            $this->history->render_tab();
                        } else {
                            $this->local->render_tab();
                        }
                        break;
                    case self::TAB_VIDEOS:
                        $this->routing->render_tab();
                        break;
                    case self::TAB_PEERTUBE:
                        $this->peertube->render_tab();
                        break;
                    case self::TAB_PUBLISHING:
                        $this->publishing->render_tab();
                        break;
                    case self::TAB_MIGRATION:
                        $this->migration->render_tab();
                        break;
                    case self::TAB_RETENTION:
                        $this->retention->render_tab();
                        break;
                    default:
                        $this->local->render_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /** @return array<string,string> */
    public static function tabs(): array
    {
        return array(
            self::TAB_OVERVIEW => __('Overview', 'argentwolf-video-processor'),
            self::TAB_HISTORY => __('History & Logs', 'argentwolf-video-processor'),
            self::TAB_VIDEOS => __('Videos & Routing', 'argentwolf-video-processor'),
            self::TAB_LOCAL => __('Local Processing', 'argentwolf-video-processor'),
            self::TAB_PEERTUBE => __('PeerTube Servers', 'argentwolf-video-processor'),
            self::TAB_PUBLISHING => __('Publishing', 'argentwolf-video-processor'),
            self::TAB_MIGRATION => __('Video Migration', 'argentwolf-video-processor'),
            self::TAB_RETENTION => __('Local Retention', 'argentwolf-video-processor'),
        );
    }

    public static function current_tab(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only settings-tab selector.
        $raw = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        return array_key_exists($raw, self::tabs()) ? $raw : self::TAB_OVERVIEW;
    }

    public static function is_tab(string $tab): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only settings-page selector.
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return self::PAGE_SLUG === $page && $tab === self::current_tab();
    }

    /** @param array<string,string|int> $args */
    public static function tab_url(string $tab, array $args = array()): string
    {
        if (! array_key_exists($tab, self::tabs())) {
            $tab = self::TAB_OVERVIEW;
        }
        return add_query_arg(
            array_merge(array('page' => self::PAGE_SLUG, 'tab' => $tab), $args),
            admin_url('options-general.php')
        );
    }

    public static function format_datetime(int $timestamp): string
    {
        return Operator_Time::format($timestamp);
    }

    public static function format_mysql_utc(string $value): string
    {
        return Operator_Time::mysql_utc($value);
    }
}

// EOF: includes/Settings_Hub.php
