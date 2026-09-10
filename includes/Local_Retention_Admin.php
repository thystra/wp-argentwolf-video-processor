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
    public const ACTION_ARCHIVE_POLICY = 'argent_video_configure_archive_of_record';
    private const NONCE = 'argent_video_configure_local_retention';
    private const NONCE_ARCHIVE_POLICY = 'argent_video_configure_archive_of_record';

    public function __construct(
        private readonly Local_Retention_Service $service,
        private readonly Archive_Of_Record_Policy_Store $archive_policy
    ) {
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
        $result = $this->service->configure_for_site_policy(
            $video_id,
            $mode,
            get_current_user_id(),
            time()
        );

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


    public function archive_policy_action(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to configure the archive-of-record policy.', 'argentwolf-video-processor'));
        }
        check_admin_referer(self::NONCE_ARCHIVE_POLICY);
        $mode = isset($_POST['archive_of_record']) && is_string($_POST['archive_of_record'])
            ? sanitize_key(wp_unslash($_POST['archive_of_record']))
            : '';
        $grace = isset($_POST['grace_days']) && is_string($_POST['grace_days'])
            ? absint(wp_unslash($_POST['grace_days']))
            : 0;
        $confirmed = isset($_POST['confirm_archive_change'])
            && is_string($_POST['confirm_archive_change'])
            && '1' === sanitize_key(wp_unslash($_POST['confirm_archive_change']));
        $result = $this->archive_policy->save($mode, $grace, get_current_user_id(), time(), $confirmed);

        wp_safe_redirect(
            Settings_Hub::tab_url(
                Settings_Hub::TAB_RETENTION,
                array('awvp_retention_notice' => 'archive_' . (string) ($result['status'] ?? Archive_Of_Record_Policy_Store::REFUSED))
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
        $site_policy = $this->archive_policy->get();
        $wordpress_archive = Archive_Of_Record_Policy_Store::WORDPRESS === $site_policy['archive_of_record'];
        ?>
        <h2><?php esc_html_e('Local Retention', 'argentwolf-video-processor'); ?></h2>
        <div class="notice notice-info inline"><p><?php esc_html_e('Serving choice and source retention are separate. WordPress keeps original Media Library videos by default. Automatic original-source deletion is impossible while WordPress is the archive of record, even if an older cleanup task was already queued.', 'argentwolf-video-processor'); ?></p></div>
        <h3><?php esc_html_e('Archive of record for original videos', 'argentwolf-video-processor'); ?></h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:70em">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_ARCHIVE_POLICY); ?>">
            <?php wp_nonce_field(self::NONCE_ARCHIVE_POLICY); ?>
            <p><label><input type="radio" name="archive_of_record" value="wordpress" <?php checked(Archive_Of_Record_Policy_Store::WORDPRESS, $site_policy['archive_of_record']); ?>> <strong><?php esc_html_e('WordPress is the archive of record for original videos.', 'argentwolf-video-processor'); ?></strong></label><br>
            <span class="description"><?php esc_html_e('AWVP will never automatically delete the physical original Media Library video. Explicit Media Library deletion remains under normal WordPress control.', 'argentwolf-video-processor'); ?></span></p>
            <p><label><input type="radio" name="archive_of_record" value="not_wordpress" <?php checked(Archive_Of_Record_Policy_Store::NOT_WORDPRESS, $site_policy['archive_of_record']); ?>> <strong><?php esc_html_e('WordPress is not the archive of record for original videos.', 'argentwolf-video-processor'); ?></strong></label><br>
            <span class="description"><?php esc_html_e('This permits per-video delayed removal of the WordPress original only after all currently required remote publications are positively verified.', 'argentwolf-video-processor'); ?></span></p>
            <p><label><?php esc_html_e('Original-source grace period (days)', 'argentwolf-video-processor'); ?> <input type="number" name="grace_days" min="1" max="365" value="<?php echo esc_attr((string) $site_policy['grace_days']); ?>" style="width:5em"></label></p>
            <?php if ($wordpress_archive) : ?>
                <p><label><input type="checkbox" name="confirm_archive_change" value="1"> <?php esc_html_e('Required only to change away from WordPress-as-archive: I understand that this enables AWVP to remove original Media Library video files after verified remote publication and the configured grace period.', 'argentwolf-video-processor'); ?></label></p>
            <?php else : ?>
                <p class="description"><?php esc_html_e('The one-time destructive-policy acknowledgement has already been recorded. Changing the grace period does not require repeated per-video confirmations.', 'argentwolf-video-processor'); ?></p>
            <?php endif; ?>
            <?php submit_button(__('Save archive policy', 'argentwolf-video-processor'), 'secondary', 'submit', false); ?>
        </form>
        <h3><?php esc_html_e('Per-video retention after verified serving', 'argentwolf-video-processor'); ?></h3>
        <?php
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it cannot mutate state.
        $retention_notice = sanitize_key(
            sanitize_text_field(wp_unslash($_GET['awvp_retention_notice'] ?? ''))
        );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
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
                                        <option value="delete_managed"<?php selected($policy['mode'] ?? '', 'delete_managed'); ?>><?php esc_html_e('Delete generated local copies after the site grace period', 'argentwolf-video-processor'); ?></option>
                                        <?php if (! $wordpress_archive) : ?>
                                            <option value="delete_all"<?php selected($policy['mode'] ?? '', 'delete_all'); ?>><?php esc_html_e('Delete generated copies and the WordPress original after the site grace period', 'argentwolf-video-processor'); ?></option>
                                        <?php endif; ?>
                                    </select>
                                </label></p>
                                <?php if ($wordpress_archive && Local_Retention_Policy::MODE_DELETE_ALL === ($policy['mode'] ?? null)) : ?>
                                    <p class="description"><strong><?php esc_html_e('Original-source deletion is currently blocked by the site archive-of-record policy. Save Keep or generated-copies-only to replace this older policy.', 'argentwolf-video-processor'); ?></strong></p>
                                <?php else : ?>
                                    <p class="description"><?php echo esc_html(sprintf(
                                        /* translators: %d: site-wide grace period in days. */
                                        __('Current site grace period: %d days. No per-video destructive confirmation is required.', 'argentwolf-video-processor'),
                                        (int) $site_policy['grace_days']
                                    )); ?></p>
                                <?php endif; ?>
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
