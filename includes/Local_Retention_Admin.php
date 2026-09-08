<?php
/**
 * File: includes/Local_Retention_Admin.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Administrator surface for per-video local-retention policy. */
final class Local_Retention_Admin
{
    public const PAGE_SLUG = 'argent-video-local-retention';
    public const ACTION_CONFIGURE = 'argent_video_configure_local_retention';
    private const NONCE = 'argent_video_configure_local_retention';

    public function __construct(private readonly Local_Retention_Service $service)
    {
    }

    public function menu(): void
    {
        add_management_page(
            __('ArgentWolf Video Processor — Local Retention', 'argentwolf-video-processor'),
            __('AWVP Local Retention', 'argentwolf-video-processor'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'page')
        );
    }

    public function configure_action(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to configure video retention.', 'argentwolf-video-processor'));
        }
        $video_id = isset($_POST['video_id']) && is_string($_POST['video_id'])
            ? Video_Meta::sanitize_positive_id(sanitize_text_field(wp_unslash($_POST['video_id'])))
            : 0;
        check_admin_referer(self::NONCE . ':' . $video_id);
        if ($video_id < 1 || ! current_user_can('edit_post', $video_id)) {
            wp_die(esc_html__('You do not have permission to edit this video.', 'argentwolf-video-processor'));
        }

        $mode = isset($_POST['mode']) && is_string($_POST['mode'])
            ? sanitize_key(wp_unslash($_POST['mode']))
            : '';
        $master = isset($_POST['master_authority']) && is_string($_POST['master_authority'])
            ? sanitize_key(wp_unslash($_POST['master_authority']))
            : '';
        $grace = isset($_POST['grace_days']) && is_string($_POST['grace_days'])
            ? absint(wp_unslash($_POST['grace_days']))
            : 0;
        $confirmed = isset($_POST['confirm_cleanup'])
            && is_string($_POST['confirm_cleanup'])
            && '1' === sanitize_key(wp_unslash($_POST['confirm_cleanup']));

        if (Local_Retention_Policy::MODE_KEEP !== $mode && ! $confirmed) {
            $result = array('status' => Local_Retention_Service::REFUSED);
        } else {
            $result = $this->service->configure(
                $video_id,
                $mode,
                $grace,
                $master,
                get_current_user_id(),
                time()
            );
        }

        wp_safe_redirect(
            Settings_Hub::tab_url(
                Settings_Hub::TAB_RETENTION,
                array(
                    'awvp_retention_notice' => (string) ($result['status'] ?? Local_Retention_Service::REFUSED),
                    'video_id' => (string) $video_id,
                )
            )
        );
        exit;
    }

    public function page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view video retention.', 'argentwolf-video-processor'));
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('ArgentWolf Video Processor', 'argentwolf-video-processor'); ?></h1>
            <?php $this->render_tab(); ?>
        </div>
        <?php
    }

    public function render_tab(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view video retention.', 'argentwolf-video-processor'));
        }
        $rows = get_posts(
            array(
                'post_type' => Video_Post_Type::POST_TYPE,
                'post_status' => 'any',
                'numberposts' => 200,
                'orderby' => 'ID',
                'order' => 'ASC',
            )
        );
        ?>
        <h2><?php esc_html_e('Local Retention', 'argentwolf-video-processor'); ?></h2>
        <div class="notice notice-warning inline"><p><?php esc_html_e('Local video files are kept by default. You can choose a delayed cleanup policy for each video after PeerTube serving has been verified. Deleting all local copies can remove the physical WordPress source file, although the Media Library attachment record is preserved. Use that option only when PeerTube or another archive is the authoritative master copy.', 'argentwolf-video-processor'); ?></p></div>
        <?php
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it cannot mutate state.
        $retention_notice_raw = $_GET['awvp_retention_notice'] ?? '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $retention_notice = is_string($retention_notice_raw) ? sanitize_key(wp_unslash($retention_notice_raw)) : '';
        ?>
        <?php if ('' !== $retention_notice) : ?>
            <div class="notice notice-info"><p><?php echo esc_html('Retention request: ' . $retention_notice); ?></p></div>
        <?php endif; ?>
        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e('Video', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Serving and source', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Retention policy', 'argentwolf-video-processor'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <?php
                $video_id = (int) $row->ID;
                $authority = Video_Serving_Authority::sanitize(get_post_meta($video_id, Video_Meta::SERVING_AUTHORITY, true));
                $policy = Local_Retention_Policy::sanitize(get_post_meta($video_id, Video_Meta::LOCAL_RETENTION_POLICY, true));
                $execution = Local_Retention_Execution::sanitize(get_post_meta($video_id, Video_Meta::LOCAL_RETENTION_EXECUTION, true));
                $cleanup = Video_Meta::sanitize_cleanup_state(get_post_meta($video_id, Video_Meta::CLEANUP_STATE, true));
                $source = Video_Meta::sanitize_source_state(get_post_meta($video_id, Video_Meta::SOURCE_STATE, true));
                $master = Video_Meta::sanitize_master_authority(get_post_meta($video_id, Video_Meta::MASTER_AUTHORITY, true));
                $frozen = 'removed' === $source
                    || ('complete' === $cleanup
                        && array() !== $execution
                        && Local_Retention_Policy::MODE_DELETE_ALL === ($execution['mode'] ?? null));
                ?>
                <tr>
                    <td>#<?php echo esc_html((string) $video_id); ?> — <?php echo esc_html((string) $row->post_title); ?></td>
                    <td><?php echo esc_html(array() === $authority ? __('Local serving', 'argentwolf-video-processor') : __('PeerTube serving verified', 'argentwolf-video-processor')); ?><br><span class="description"><?php echo esc_html('Source: ' . $source . '; cleanup: ' . $cleanup); ?></span></td>
                    <td>
                        <?php if ($frozen) : ?>
                            <strong><?php esc_html_e('Full local cleanup is complete. The physical cleanup state is final.', 'argentwolf-video-processor'); ?></strong>
                        <?php else : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_CONFIGURE); ?>">
                                <input type="hidden" name="video_id" value="<?php echo esc_attr((string) $video_id); ?>">
                                <?php wp_nonce_field(self::NONCE . ':' . $video_id); ?>
                                <p><label><span class="screen-reader-text"><?php esc_html_e('Retention policy', 'argentwolf-video-processor'); ?></span>
                                    <select name="mode">
                                        <option value="keep"<?php selected($policy['mode'] ?? 'keep', 'keep'); ?>><?php esc_html_e('Keep all local copies', 'argentwolf-video-processor'); ?></option>
                                        <option value="delete_managed"<?php selected($policy['mode'] ?? '', 'delete_managed'); ?>><?php esc_html_e('Delete generated local copies', 'argentwolf-video-processor'); ?></option>
                                        <option value="delete_all"<?php selected($policy['mode'] ?? '', 'delete_all'); ?>><?php esc_html_e('Delete all local video files', 'argentwolf-video-processor'); ?></option>
                                    </select>
                                </label></p>
                                <p><label><span class="screen-reader-text"><?php esc_html_e('Master copy', 'argentwolf-video-processor'); ?></span>
                                    <select name="master_authority">
                                        <option value="wordpress_source"<?php selected($master, 'wordpress_source'); ?>><?php esc_html_e('WordPress source is the master copy', 'argentwolf-video-processor'); ?></option>
                                        <option value="backend_source"<?php selected($master, 'backend_source'); ?>><?php esc_html_e('PeerTube is the master copy', 'argentwolf-video-processor'); ?></option>
                                        <option value="external_archive"<?php selected($master, 'external_archive'); ?>><?php esc_html_e('External archive is the master copy', 'argentwolf-video-processor'); ?></option>
                                    </select>
                                </label></p>
                                <p><label><?php esc_html_e('Grace period (days)', 'argentwolf-video-processor'); ?> <input type="number" name="grace_days" min="1" max="365" value="<?php echo esc_attr((string) (((int) ($policy['grace_days'] ?? 0) > 0) ? (int) $policy['grace_days'] : 7)); ?>" style="width:5em"></label></p>
                                <p><label><input type="checkbox" name="confirm_cleanup" value="1"> <?php esc_html_e('I authorize the selected cleanup after the grace period and understand that deleted local files may not be recoverable from WordPress.', 'argentwolf-video-processor'); ?></label></p>
                                <button class="button" type="submit"><?php esc_html_e('Save retention policy', 'argentwolf-video-processor'); ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}

// EOF: includes/Local_Retention_Admin.php
