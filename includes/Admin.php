<?php
/**
 * File: includes/Admin.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use RuntimeException;
use Throwable;

final class Admin
{
    public function __construct(
        private readonly Job_Repository $jobs,
        private readonly Queue $queue,
        private readonly Bulk_Queue $bulk,
        private readonly Worker_Launcher $launcher,
        private readonly Diagnostics $diagnostics
    ) {
    }

    public function register(): void
    {
        register_setting('argent_video_processor', Settings::OPTION, array('sanitize_callback' => array(Settings::class, 'sanitize')));
    }

    public function menu(): void
    {
        add_options_page(
            __('ArgentWolf Video Processor', 'argentwolf-video-processor'),
            __('ArgentWolf Video Processor', 'argentwolf-video-processor'),
            'manage_options',
            'argent-video-processor',
            array($this, 'page')
        );
    }

    /**
     * Add convenient Settings and project links to the plugin row.
     *
     * @param array<string, string> $links Existing plugin action links.
     * @return array<string, string>
     */
    public function plugin_action_links(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=argent-video-processor')),
            esc_html__('Settings', 'argentwolf-video-processor')
        );
        $project_link = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url('https://github.com/thystra/wp-argentwolf-video-processor'),
            esc_html__('GitHub project', 'argentwolf-video-processor')
        );

        array_unshift($links, $project_link);
        array_unshift($links, $settings_link);

        return $links;
    }

    /** @param array<string, string> $columns
     *  @return array<string, string>
     */
    public function media_columns(array $columns): array
    {
        $columns['argent_video_status'] = __('Video processing', 'argentwolf-video-processor');
        return $columns;
    }

    public function media_column(string $column, int $attachment_id): void
    {
        if ('argent_video_status' !== $column || ! str_starts_with((string) get_post_mime_type($attachment_id), 'video/')) {
            return;
        }
        $status = (string) get_post_meta($attachment_id, '_argent_video_status', true);
        $status = '' === $status ? 'not queued' : $status;
        echo '<strong>' . esc_html(ucfirst($status)) . '</strong>';
        $outputs = get_post_meta($attachment_id, '_argent_video_outputs', true);
        if (is_array($outputs) && ! empty($outputs['hls'])) {
            echo '<br><span class="description">' . esc_html__('Adaptive HLS ready', 'argentwolf-video-processor') . '</span>';
        }
        $error = (string) get_post_meta($attachment_id, '_argent_video_last_error', true);
        if ('' !== $error) {
            echo '<br><span class="description" title="' . esc_attr($error) . '">' . esc_html(wp_trim_words($error, 10)) . '</span>';
        }
        $url = wp_nonce_url(admin_url('admin-post.php?action=argent_video_queue_attachment&attachment_id=' . $attachment_id), 'argent_video_queue_' . $attachment_id);
        echo '<br><a href="' . esc_url($url) . '">' . esc_html__('Queue or reprocess', 'argentwolf-video-processor') . '</a>';
        if (in_array($status, array('queued', 'failed'), true)) {
            $cancel = wp_nonce_url(admin_url('admin-post.php?action=argent_video_cancel_attachment&attachment_id=' . $attachment_id), 'argent_video_cancel_' . $attachment_id);
            echo ' | <a href="' . esc_url($cancel) . '">' . esc_html__('Cancel', 'argentwolf-video-processor') . '</a>';
        }
    }

    public function queue_action(): void
    {
        if (! current_user_can('upload_files')) {
            wp_die(esc_html__('You are not allowed to process media.', 'argentwolf-video-processor'));
        }
        $attachment_id = isset($_GET['attachment_id']) ? absint($_GET['attachment_id']) : 0;
        check_admin_referer('argent_video_queue_' . $attachment_id);
        try {
            $this->queue->enqueue($attachment_id, true);
            $this->launcher->dispatch();
            $this->redirect('queued');
        } catch (RuntimeException $error) {
            $this->redirect('error', $error->getMessage());
        }
    }

    public function bulk_action(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to bulk-process media.', 'argentwolf-video-processor'));
        }
        check_admin_referer('argent_video_bulk_queue');
        $mode = sanitize_key((string) ($_POST['bulk_mode'] ?? 'smart'));
        $after = sanitize_text_field(wp_unslash((string) ($_POST['after'] ?? '')));
        $through = sanitize_text_field(wp_unslash((string) ($_POST['through'] ?? '')));
        try {
            $result = $this->bulk->queue($mode, $after, $through);
            $this->launcher->dispatch();
            $message = sprintf(
                'Queued %d; skipped %d; failed %d.',
                $result['queued'],
                $result['skipped'],
                $result['failed']
            );
            if ([] !== $result['errors']) {
                $message .= ' ' . implode(' | ', $result['errors']);
            }
            $this->redirect('bulk', $message);
        } catch (Throwable $error) {
            $this->redirect('error', $error->getMessage());
        }
    }

    public function cancel_action(): void
    {
        if (! current_user_can('upload_files')) {
            wp_die(esc_html__('You are not allowed to process media.', 'argentwolf-video-processor'));
        }
        $attachment_id = isset($_GET['attachment_id']) ? absint($_GET['attachment_id']) : 0;
        check_admin_referer('argent_video_cancel_' . $attachment_id);
        $this->jobs->cancel($attachment_id);
        update_post_meta($attachment_id, '_argent_video_status', 'cancelled');
        $this->redirect('cancelled');
    }

    public function dispatch_action(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to launch the worker.', 'argentwolf-video-processor'));
        }
        check_admin_referer('argent_video_dispatch');
        $result = $this->launcher->launch();
        update_option('argent_video_processor_last_launch', $result, false);
        $this->redirect(! empty($result['ok']) ? 'launched' : 'error', (string) ($result['message'] ?? ''));
    }

    public function notices(): void
    {
        $this->ffmpeg_security_notice();
        /*
         * These sanitized query parameters select a read-only notice after
         * nonce-protected action handlers redirect back to the settings page.
         * No state is changed while reading them.
         */
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (empty($_GET['argent_video_notice'])) {
            return;
        }
        $notice = sanitize_key((string) $_GET['argent_video_notice']);
        $message = isset($_GET['argent_video_message']) ? sanitize_text_field(wp_unslash((string) $_GET['argent_video_message'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $labels = array(
            'queued'    => __('Video queued for processing.', 'argentwolf-video-processor'),
            'bulk'      => $message ?: __('Video backlog queued.', 'argentwolf-video-processor'),
            'cancelled' => __('Queued video processing was cancelled.', 'argentwolf-video-processor'),
            'launched'  => __('The background video worker was launched.', 'argentwolf-video-processor'),
            'error'     => $message ?: __('The video action failed.', 'argentwolf-video-processor'),
        );
        $class = 'error' === $notice ? 'notice notice-error' : 'notice notice-success';
        if (isset($labels[$notice])) {
            echo '<div class="' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($labels[$notice]) . '</p></div>';
        }
    }

    public function page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $settings = Settings::all();
        $last_launch = get_option('argent_video_processor_last_launch', array());
        $last_worker = get_option('argent_video_processor_last_worker_run', array());
        $bulk = $this->bulk->summary();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('ArgentWolf Video Processor', 'argentwolf-video-processor'); ?></h1>
            <p><?php esc_html_e('Originals are preserved. A detached low-priority worker creates privacy-cleaned progressive derivatives and an adaptive HLS ladder.', 'argentwolf-video-processor'); ?></p>

            <h2><?php esc_html_e('Queue status', 'argentwolf-video-processor'); ?></h2>
            <table class="widefat striped" style="max-width:900px"><tbody>
                <?php foreach (array('queued', 'processing', 'complete', 'failed', 'cancelled') as $status) : ?>
                    <tr><th><?php echo esc_html(ucfirst($status)); ?></th><td><?php echo esc_html((string) $this->jobs->count($status)); ?></td></tr>
                <?php endforeach; ?>
                <tr><th><?php esc_html_e('Last launch', 'argentwolf-video-processor'); ?></th><td><code><?php echo esc_html(wp_json_encode($last_launch, JSON_UNESCAPED_SLASHES)); ?></code></td></tr>
                <tr><th><?php esc_html_e('Last worker run', 'argentwolf-video-processor'); ?></th><td><code><?php echo esc_html(wp_json_encode($last_worker, JSON_UNESCAPED_SLASHES)); ?></code></td></tr>
            </tbody></table>
            <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=argent_video_dispatch'), 'argent_video_dispatch')); ?>"><?php esc_html_e('Launch worker now', 'argentwolf-video-processor'); ?></a></p>

            <h2><?php esc_html_e('Process existing videos', 'argentwolf-video-processor'); ?></h2>
            <p><?php esc_html_e('Backlog jobs are added to the same one-at-a-time queue used for new uploads. The page request only queues work; it does not run FFmpeg.', 'argentwolf-video-processor'); ?></p>
            <table class="widefat striped" style="max-width:900px"><tbody>
                <tr><th><?php esc_html_e('Video attachments', 'argentwolf-video-processor'); ?></th><td><?php echo esc_html((string) $bulk['total']); ?></td></tr>
                <tr><th><?php esc_html_e('Completed derivatives', 'argentwolf-video-processor'); ?></th><td><?php echo esc_html((string) $bulk['complete']); ?></td></tr>
                <tr><th><?php esc_html_e('Completed but missing HLS', 'argentwolf-video-processor'); ?></th><td><?php echo esc_html((string) $bulk['missing_hls']); ?></td></tr>
                <tr><th><?php esc_html_e('Already queued or processing', 'argentwolf-video-processor'); ?></th><td><?php echo esc_html((string) $bulk['active']); ?></td></tr>
                <tr><th><?php esc_html_e('Smart backlog candidates', 'argentwolf-video-processor'); ?></th><td><?php echo esc_html((string) $bulk['smart_candidates']); ?></td></tr>
            </tbody></table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm('Queue the selected existing videos? Processing will occur one video at a time in the background.');" style="max-width:900px;margin-top:1em">
                <input type="hidden" name="action" value="argent_video_bulk_queue">
                <?php wp_nonce_field('argent_video_bulk_queue'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="argent-bulk-mode"><?php esc_html_e('Operation', 'argentwolf-video-processor'); ?></label></th><td>
                        <select id="argent-bulk-mode" name="bulk_mode">
                            <option value="smart"><?php esc_html_e('Smart queue: process missing videos and add HLS without recreating valid progressive files', 'argentwolf-video-processor'); ?></option>
                            <option value="adaptive"><?php esc_html_e('Add adaptive HLS only to completed videos that do not have it', 'argentwolf-video-processor'); ?></option>
                            <option value="all"><?php esc_html_e('Force reprocess every existing video with current settings', 'argentwolf-video-processor'); ?></option>
                        </select>
                    </td></tr>
                    <tr><th scope="row"><?php esc_html_e('Optional upload-date range', 'argentwolf-video-processor'); ?></th><td>
                        <label><?php esc_html_e('From', 'argentwolf-video-processor'); ?> <input type="date" name="after"></label>
                        &nbsp;
                        <label><?php esc_html_e('Through', 'argentwolf-video-processor'); ?> <input type="date" name="through"></label>
                    </td></tr>
                </table>
                <?php submit_button(__('Queue existing videos', 'argentwolf-video-processor'), 'secondary', 'submit', false); ?>
            </form>

            <h2><?php esc_html_e('Diagnostics', 'argentwolf-video-processor'); ?></h2>
            <table class="widefat striped" style="max-width:900px"><thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead><tbody>
                <?php foreach ($this->diagnostics->checks() as $check) : ?>
                    <tr><td><?php echo esc_html($check['check']); ?></td><td><?php echo esc_html(strtoupper($check['status'])); ?></td><td><code><?php echo esc_html($check['detail']); ?></code><?php if (! empty($check['url'])) : ?> <a href="<?php echo esc_url((string) $check['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string) ($check['url_label'] ?? __('Security advisory', 'argentwolf-video-processor'))); ?></a><?php endif; ?></td></tr>
                <?php endforeach; ?>
            </tbody></table>

            <form method="post" action="options.php">
                <?php settings_fields('argent_video_processor'); ?>
                <h2><?php esc_html_e('Settings', 'argentwolf-video-processor'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Automation</th><td>
                        <label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[auto_queue]" value="1" <?php checked(! empty($settings['auto_queue'])); ?>> Queue newly uploaded videos</label><br>
                        <label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[auto_dispatch]" value="1" <?php checked(! empty($settings['auto_dispatch'])); ?>> Launch detached worker from the five-minute WP-Cron dispatcher</label><br>
                        <label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[strip_metadata]" value="1" <?php checked(! empty($settings['strip_metadata'])); ?>> Strip GPS, device, chapter, and other source metadata from derivatives</label><br>
                        <label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[adaptive_hls]" value="1" <?php checked(! empty($settings['adaptive_hls'])); ?>> Generate adaptive HLS at 360p, 480p, and 720p where source resolution permits</label>
                    </td></tr>
                    <tr><th scope="row"><label for="argent-profile">Progressive fallback profile</label></th><td>
                        <select id="argent-profile" name="<?php echo esc_attr(Settings::OPTION); ?>[profile]">
                            <option value="dual" <?php selected($settings['profile'], 'dual'); ?>>Open WebM preferred + compatible MP4 fallback</option>
                            <option value="open" <?php selected($settings['profile'], 'open'); ?>>Open WebM only (original MP4 remains final fallback)</option>
                            <option value="compatibility" <?php selected($settings['profile'], 'compatibility'); ?>>Compatible MP4 only</option>
                        </select>
                    </td></tr>
                    <?php $this->number_row('max_width', 'Progressive maximum width', $settings); ?>
                    <?php $this->number_row('max_height', 'Progressive maximum height', $settings); ?>
                    <?php $this->number_row('mp4_crf', 'MP4 CRF', $settings); ?>
                    <?php $this->number_row('mp4_maxrate_kbps', 'MP4 maximum video rate (kb/s)', $settings); ?>
                    <?php $this->number_row('webm_crf', 'WebM CRF', $settings); ?>
                    <?php $this->number_row('webm_maxrate_kbps', 'WebM maximum video rate (kb/s)', $settings); ?>
                    <?php $this->number_row('audio_bitrate_kbps', 'Progressive audio rate (kb/s)', $settings); ?>
                    <?php $this->number_row('hls_segment_seconds', 'HLS segment length (seconds)', $settings); ?>
                    <?php $this->number_row('hls_360_video_kbps', 'HLS 360p maximum video rate (kb/s)', $settings); ?>
                    <?php $this->number_row('hls_480_video_kbps', 'HLS 480p maximum video rate (kb/s)', $settings); ?>
                    <?php $this->number_row('hls_720_video_kbps', 'HLS 720p maximum video rate (kb/s)', $settings); ?>
                    <?php $this->number_row('hls_audio_bitrate_kbps', 'HLS audio rate (kb/s)', $settings); ?>
                    <tr><th scope="row"><label for="argent-hls-preset">HLS H.264 preset</label></th><td><select id="argent-hls-preset" name="<?php echo esc_attr(Settings::OPTION); ?>[hls_preset]">
                        <?php foreach (array('veryfast', 'faster', 'fast', 'medium', 'slow') as $preset) : ?><option value="<?php echo esc_attr($preset); ?>" <?php selected($settings['hls_preset'], $preset); ?>><?php echo esc_html($preset); ?></option><?php endforeach; ?>
                    </select></td></tr>
                    <?php $this->path_row('ffmpeg_path', 'FFmpeg path', $settings); ?>
                    <?php $this->path_row('ffprobe_path', 'FFprobe path', $settings); ?>
                    <?php $this->path_row('wp_cli_path', 'WP-CLI path', $settings); ?>
                    <?php $this->number_row('nice_level', 'CPU nice level', $settings); ?>
                    <?php $this->number_row('ionice_class', 'I/O scheduling class', $settings); ?>
                    <?php $this->number_row('ionice_level', 'I/O priority level', $settings); ?>
                    <?php $this->number_row('stale_job_minutes', 'Stale worker recovery (minutes)', $settings); ?>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e('WP-CLI', 'argentwolf-video-processor'); ?></h2>
            <pre>wp argent-video diagnose
wp argent-video jobs --status=failed
wp argent-video enqueue &lt;attachment-id&gt; --force
wp argent-video scan --mode=smart
wp argent-video scan --mode=adaptive
wp argent-video worker --once</pre>
            <h2><?php esc_html_e('Support development', 'argentwolf-video-processor'); ?></h2>
            <p><?php esc_html_e('ArgentWolf Video Processor is free software. Source code, issue tracking, and ways to support continued development are available on GitHub.', 'argentwolf-video-processor'); ?></p>
            <p><a class="button button-secondary" href="<?php echo esc_url('https://github.com/thystra/wp-argentwolf-video-processor'); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('View project on GitHub', 'argentwolf-video-processor'); ?></a></p>
        </div>
        <?php
    }

    private function ffmpeg_security_notice(): void
    {
        $assessment = $this->diagnostics->ffmpeg_security();
        if (! empty($assessment['processing_allowed'])) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>'
            . esc_html__('ArgentWolf Video Processor: FFmpeg security gate is blocking new transcoding.', 'argentwolf-video-processor')
            . '</strong></p><ul>';
        foreach ((array) ($assessment['advisories'] ?? array()) as $advisory) {
            if (! is_array($advisory) || empty($advisory['blocking'])) {
                continue;
            }
            echo '<li><a href="' . esc_url((string) $advisory['url']) . '" target="_blank" rel="noopener noreferrer">'
                . esc_html((string) $advisory['id'])
                . '</a>: ' . esc_html((string) $advisory['reason']) . '</li>';
        }
        echo '</ul><p>'
            . esc_html__('Update FFmpeg to a fixed build or use a build where the affected decoder is not enabled. Existing generated media is not removed.', 'argentwolf-video-processor')
            . '</p></div>';
    }

    /** @param array<string, mixed> $settings */
    private function number_row(string $key, string $label, array $settings): void
    {
        ?>
        <tr><th scope="row"><label for="argent-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td>
            <input class="small-text" type="number" id="argent-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr(Settings::OPTION); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) $settings[$key]); ?>">
        </td></tr>
        <?php
    }

    /** @param array<string, mixed> $settings */
    private function path_row(string $key, string $label, array $settings): void
    {
        ?>
        <tr><th scope="row"><label for="argent-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td>
            <input class="regular-text code" type="text" id="argent-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr(Settings::OPTION); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) $settings[$key]); ?>">
        </td></tr>
        <?php
    }

    private function redirect(string $notice, string $message = ''): never
    {
        $url = add_query_arg(
            array(
                'page'                 => 'argent-video-processor',
                'argent_video_notice'  => $notice,
                'argent_video_message' => $message,
            ),
            admin_url('options-general.php')
        );
        wp_safe_redirect($url);
        exit;
    }
}

// EOF: includes/Admin.php
