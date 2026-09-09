<?php
/**
 * File: includes/PeerTube_Overview_Admin.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Human-oriented RC9 operational overview for PeerTube-owned videos. */
final class PeerTube_Overview_Admin
{
    public const ACTION_RESUME = 'argentwolf_video_processor_resume_incomplete';
    private const MAX_ROWS = 100;

    public function __construct(
        private readonly PeerTube_Staged_Upload_Operation_Store $operations,
        private readonly PeerTube_Incomplete_Work_Reconciler $recovery
    ) {
    }

    public function render_tab(): void
    {
        $rows = $this->rows(time());
        ?>
        <h2><?php esc_html_e('Status & Needs Attention', 'argentwolf-video-processor'); ?></h2>
        <p><?php esc_html_e('PeerTube publication is event-driven. This view uses WordPress media and post names for primary identity; internal operation and remote identifiers are available only under diagnostics.', 'argentwolf-video-processor'); ?></p>
        <?php if (array() === $rows) : ?>
            <p><?php esc_html_e('No PeerTube publication work currently needs operational attention.', 'argentwolf-video-processor'); ?></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:1200px">
                <thead><tr>
                    <th><?php esc_html_e('Media', 'argentwolf-video-processor'); ?></th>
                    <th><?php esc_html_e('Affected post / author', 'argentwolf-video-processor'); ?></th>
                    <th><?php esc_html_e('Status', 'argentwolf-video-processor'); ?></th>
                    <th><?php esc_html_e('Action', 'argentwolf-video-processor'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html((string) $row['media_title']); ?></strong><br>
                            <code><?php echo esc_html((string) $row['filename']); ?></code>
                            <?php if ('' !== $row['size']) : ?><br><?php echo esc_html((string) $row['size']); ?><?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $row['anchor_post_id'] > 0) : ?>
                                <a href="<?php echo esc_url(get_edit_post_link((int) $row['anchor_post_id'], '')); ?>"><?php echo esc_html((string) $row['post_title']); ?></a>
                                <?php if ('' !== $row['author']) : ?><br><?php echo esc_html((string) $row['author']); ?><?php endif; ?>
                            <?php else : ?>
                                <?php esc_html_e('Origin post unavailable', 'argentwolf-video-processor'); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo esc_html((string) $row['status_label']); ?></strong>
                            <?php if ('' !== $row['progress']) : ?><br><?php echo esc_html((string) $row['progress']); ?><?php endif; ?>
                            <details style="margin-top:.5em"><summary><?php esc_html_e('Diagnostics', 'argentwolf-video-processor'); ?></summary>
                                <code><?php echo esc_html('video_id=' . (string) $row['video_id']); ?></code><br>
                                <?php if ('' !== $row['operation_id']) : ?><code><?php echo esc_html('operation_id=' . (string) $row['operation_id']); ?></code><br><?php endif; ?>
                                <?php if ('' !== $row['remote_uuid']) : ?><code><?php echo esc_html('remote_uuid=' . (string) $row['remote_uuid']); ?></code><?php endif; ?>
                            </details>
                        </td>
                        <td>
                            <?php if (true === $row['resumable']) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_RESUME); ?>">
                                    <input type="hidden" name="video_id" value="<?php echo esc_attr((string) $row['video_id']); ?>">
                                    <?php wp_nonce_field(self::ACTION_RESUME . ':' . (string) $row['video_id']); ?>
                                    <?php submit_button(__('Resume', 'argentwolf-video-processor'), 'secondary small', 'submit', false); ?>
                                </form>
                            <?php elseif (true === $row['recovering']) : ?>
                                <?php esc_html_e('Automatic recovery active', 'argentwolf-video-processor'); ?>
                            <?php else : ?>
                                <?php esc_html_e('Review diagnostics', 'argentwolf-video-processor'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    public function resume_action(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to resume ArgentWolf Video Processor recovery.', 'argentwolf-video-processor'));
        }
        $video_id = isset($_POST['video_id'])
            ? Video_Meta::sanitize_positive_id(sanitize_text_field(wp_unslash($_POST['video_id'])))
            : 0;
        check_admin_referer(self::ACTION_RESUME . ':' . (string) $video_id);
        $result = $video_id > 0 && $this->recovery->resume($video_id, time()) ? 'resumed' : 'refused';
        wp_safe_redirect(Settings_Hub::tab_url(Settings_Hub::TAB_OVERVIEW, array('awvp_recovery' => $result)));
        exit;
    }

    /** @return list<array<string,mixed>> */
    public function rows(int $now): array
    {
        $ids = get_posts(array(
            'post_type'      => Video_Post_Type::POST_TYPE,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => self::MAX_ROWS,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ));
        if (! is_array($ids)) {
            return array();
        }
        $rows = array();
        foreach ($ids as $raw_id) {
            $video_id = Video_Meta::sanitize_positive_id($raw_id);
            if ($video_id < 1) {
                continue;
            }
            $destination = Video_Destination::resolve(
                get_post_meta($video_id, Video_Meta::DESTINATION, true),
                metadata_exists('post', $video_id, Video_Meta::DESTINATION)
            );
            if (array() === $destination || Video_Destination::is_local($destination)) {
                continue;
            }
            $execution = PeerTube_Publication_Execution::sanitize(
                get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_EXECUTION, true)
            );
            $operation_id = is_string($execution['operation_id'] ?? null) ? $execution['operation_id'] : '';
            $operation = '' !== $operation_id ? $this->operations->get($operation_id) : null;
            $recovery = $this->recovery->status($video_id, $now, false);
            $attention = true === ($recovery['pending'] ?? false)
                || (is_array($operation) && in_array((string) ($operation['phase'] ?? ''), array(
                    PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE,
                    PeerTube_Staged_Upload_State_Machine::PHASE_FAILED,
                ), true));
            $active = is_array($operation)
                && ! in_array((string) ($operation['phase'] ?? ''), array(
                    PeerTube_Staged_Upload_State_Machine::PHASE_COMPLETE,
                    PeerTube_Staged_Upload_State_Machine::PHASE_FAILED,
                ), true);
            if (! $attention && ! $active) {
                continue;
            }
            $rows[] = $this->row($video_id, $execution, $operation, $recovery);
        }
        return $rows;
    }

    /** @param array<string,mixed> $execution @param array<string,mixed>|null $operation @param array<string,mixed> $recovery @return array<string,mixed> */
    private function row(int $video_id, array $execution, ?array $operation, array $recovery): array
    {
        $attachment_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true));
        $media_title = $attachment_id > 0 ? (string) get_the_title($attachment_id) : '';
        if ('' === $media_title) {
            $media_title = __('Untitled video', 'argentwolf-video-processor');
        }
        $attached = $attachment_id > 0 ? (string) get_post_meta($attachment_id, '_wp_attached_file', true) : '';
        $filename = '' !== $attached ? basename($attached) : __('Media file unavailable', 'argentwolf-video-processor');
        $file = $attachment_id > 0 && function_exists('get_attached_file') ? get_attached_file($attachment_id) : false;
        $bytes = is_string($file) && '' !== $file && function_exists('wp_filesize') ? wp_filesize($file) : false;
        $size = is_int($bytes) && $bytes >= 0 && function_exists('size_format') ? size_format($bytes, 1) : '';

        $anchor_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, true));
        $post_title = $anchor_id > 0 ? (string) get_the_title($anchor_id) : '';
        if ('' === $post_title) {
            $post_title = __('Untitled post', 'argentwolf-video-processor');
        }
        $author = '';
        $anchor = $anchor_id > 0 ? get_post($anchor_id) : null;
        if (is_object($anchor) && (int) ($anchor->post_author ?? 0) > 0) {
            $author = (string) get_the_author_meta('display_name', (int) $anchor->post_author);
        }

        $phase = is_array($operation) ? (string) ($operation['phase'] ?? '') : '';
        $status_label = $this->status_label($phase, $recovery);
        $progress = '';
        if (is_array($operation)) {
            $total = (int) ($operation['source']['bytes'] ?? 0);
            $confirmed = (int) ($operation['confirmed_bytes'] ?? 0);
            if ($total > 0) {
                $percent = min(100, max(0, (int) floor(($confirmed / $total) * 100)));
                $progress = sprintf(
                    /* translators: 1: uploaded bytes, 2: total bytes, 3: percentage */
                    __('Uploaded %1$s / %2$s (%3$d%%)', 'argentwolf-video-processor'),
                    size_format($confirmed, 1),
                    size_format($total, 1),
                    $percent
                );
            }
        }
        return array(
            'video_id' => $video_id,
            'media_title' => $media_title,
            'filename' => $filename,
            'size' => $size,
            'anchor_post_id' => $anchor_id,
            'post_title' => $post_title,
            'author' => $author,
            'status_label' => $status_label,
            'progress' => $progress,
            'operation_id' => is_array($operation) ? (string) ($operation['operation_id'] ?? '') : '',
            'remote_uuid' => is_array($execution) ? (string) ($execution['remote_uuid'] ?? '') : '',
            'recovering' => true === ($recovery['eligible'] ?? false),
            'resumable' => true === ($recovery['resumable'] ?? false),
        );
    }

    /** @param array<string,mixed> $recovery */
    private function status_label(string $phase, array $recovery): string
    {
        if (true === ($recovery['pending'] ?? false)) {
            return true === ($recovery['eligible'] ?? false)
                ? __('Recovering incomplete publication', 'argentwolf-video-processor')
                : __('Publication needs attention', 'argentwolf-video-processor');
        }
        return match ($phase) {
            PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE => __('Upload outcome needs attention', 'argentwolf-video-processor'),
            PeerTube_Staged_Upload_State_Machine::PHASE_FAILED => __('PeerTube upload failed', 'argentwolf-video-processor'),
            PeerTube_Staged_Upload_State_Machine::PHASE_PROCESSING => __('PeerTube is processing the video', 'argentwolf-video-processor'),
            PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED => __('PeerTube copy verified; publication finalizing', 'argentwolf-video-processor'),
            default => __('PeerTube publication in progress', 'argentwolf-video-processor'),
        };
    }
}

// EOF: includes/PeerTube_Overview_Admin.php
