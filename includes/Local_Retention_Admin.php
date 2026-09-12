<?php
/**
 * File: includes/Local_Retention_Admin.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Administrator surface for site-wide and bulk local-retention policy. */
final class Local_Retention_Admin
{
    public const PAGE_SLUG = 'argent-video-local-retention';
    public const ACTION_CONFIGURE = 'argent_video_configure_local_retention';
    public const ACTION_ARCHIVE_POLICY = 'argent_video_configure_archive_of_record';
    public const ACTION_DEFAULT_POLICY = 'argent_video_configure_default_local_retention';
    public const ACTION_BULK_APPLY = 'argent_video_bulk_apply_local_retention';
    public const ACTION_BULK_CLEANUP = 'argent_video_bulk_cleanup_local_retention';
    private const NONCE = 'argent_video_configure_local_retention';
    private const NONCE_ARCHIVE_POLICY = 'argent_video_configure_archive_of_record';
    private const NONCE_DEFAULT_POLICY = 'argent_video_configure_default_local_retention';
    private const NONCE_BULK_APPLY = 'argent_video_bulk_apply_local_retention';
    private const MAX_BULK_VIDEOS = 500;

    public function __construct(
        private readonly Local_Retention_Service $service,
        private readonly Archive_Of_Record_Policy_Store $archive_policy,
        private readonly Local_Retention_Default_Policy_Store $default_policy
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

    public function enqueue_assets(): void
    {
        if (! Settings_Hub::is_tab(Settings_Hub::TAB_RETENTION)) {
            return;
        }
        wp_enqueue_script(
            'argent-video-local-retention-admin',
            ARGENT_VIDEO_URL . 'assets/js/local-retention-admin.js',
            array(),
            ARGENT_VIDEO_VERSION,
            true
        );
        wp_localize_script(
            'argent-video-local-retention-admin',
            'awvpRetentionAdmin',
            array(
                /* translators: 1: number of videos shown after filtering, 2: total number of videos. */
                'shown' => __('%1$d / %2$d videos shown', 'argentwolf-video-processor'),
            )
        );
    }

    /** Backward-compatible single-video endpoint; the primary UI is now bulk. */
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
        $grace_mode = isset($_POST['grace_mode']) && is_string($_POST['grace_mode'])
            ? sanitize_key(wp_unslash($_POST['grace_mode']))
            : 'site';
        $grace_days_override = isset($_POST['grace_days_override']) && is_string($_POST['grace_days_override'])
            ? sanitize_text_field(wp_unslash($_POST['grace_days_override']))
            : '';
        $grace_override = $this->grace_override_from_values($grace_mode, $grace_days_override);
        $result = $this->service->configure_for_site_policy($video_id, $mode, get_current_user_id(), time(), $grace_override);

        wp_safe_redirect(
            Settings_Hub::tab_url(
                Settings_Hub::TAB_RETENTION,
                array('awvp_retention_notice'=>(string) ($result['status'] ?? Local_Retention_Service::REFUSED))
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
                array('awvp_retention_notice'=>'archive-' . (string) ($result['status'] ?? Archive_Of_Record_Policy_Store::REFUSED))
            )
        );
        exit;
    }

    public function default_policy_action(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to configure the default retention policy.', 'argentwolf-video-processor'));
        }
        check_admin_referer(self::NONCE_DEFAULT_POLICY);
        $mode = isset($_POST['default_retention_mode']) && is_string($_POST['default_retention_mode'])
            ? Local_Retention_Default_Policy_Store::sanitize_mode(sanitize_key(wp_unslash($_POST['default_retention_mode'])))
            : '';
        if (in_array($mode, array(Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY, Local_Retention_Policy::MODE_DELETE_ALL), true)
            && $this->archive_policy->wordpress_is_archive()) {
            $result = array('status'=>Local_Retention_Default_Policy_Store::REFUSED);
        } else {
            $result = $this->default_policy->save($mode, get_current_user_id(), time());
        }
        wp_safe_redirect(
            Settings_Hub::tab_url(
                Settings_Hub::TAB_RETENTION,
                array('awvp_retention_notice'=>'default-' . (string) ($result['status'] ?? Local_Retention_Default_Policy_Store::REFUSED))
            )
        );
        exit;
    }

    public function bulk_apply_action(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to configure video retention.', 'argentwolf-video-processor'));
        }
        check_admin_referer(self::NONCE_BULK_APPLY);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce-protected bounded ID list is sanitized member-by-member below before use.
        $raw_ids = isset($_POST['video_ids']) && is_array($_POST['video_ids']) ? wp_unslash($_POST['video_ids']) : array();
        $ids = array();
        foreach (array_slice($raw_ids, 0, self::MAX_BULK_VIDEOS) as $raw_id) {
            if (! is_string($raw_id) && ! is_int($raw_id)) {
                continue;
            }
            $id = Video_Meta::sanitize_positive_id(sanitize_text_field((string) $raw_id));
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        $counts = array('applied'=>0, 'present'=>0, 'refused'=>0, 'indeterminate'=>0);
        $mode = $this->default_policy->mode();
        $grace_mode = isset($_POST['grace_mode']) && is_string($_POST['grace_mode'])
            ? sanitize_key(wp_unslash($_POST['grace_mode']))
            : 'site';
        $grace_days_override = isset($_POST['grace_days_override']) && is_string($_POST['grace_days_override'])
            ? sanitize_text_field(wp_unslash($_POST['grace_days_override']))
            : '';
        $grace_override = $this->grace_override_from_values($grace_mode, $grace_days_override);
        if (in_array($mode, array(Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY, Local_Retention_Policy::MODE_DELETE_ALL), true)
            && $this->archive_policy->wordpress_is_archive()) {
            $counts['refused'] = count($ids);
        } else {
            foreach ($ids as $video_id) {
                if (! current_user_can('edit_post', $video_id)) {
                    ++$counts['refused'];
                    continue;
                }
                $result = $this->service->configure_for_site_policy($video_id, $mode, get_current_user_id(), time(), $grace_override);
                $status = (string) ($result['status'] ?? Local_Retention_Service::INDETERMINATE);
                if (! array_key_exists($status, $counts)) {
                    $status = 'indeterminate';
                }
                ++$counts[$status];
            }
        }

        $notice = sprintf(
            'bulk-%d-%d-%d-%d',
            $counts['applied'],
            $counts['present'],
            $counts['refused'],
            $counts['indeterminate']
        );
        wp_safe_redirect(Settings_Hub::tab_url(Settings_Hub::TAB_RETENTION, array('awvp_retention_notice'=>$notice)));
        exit;
    }

    public function bulk_cleanup_action(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to clean up local video files.', 'argentwolf-video-processor'));
        }
        check_admin_referer(self::NONCE_BULK_APPLY);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce-protected bounded ID list is sanitized member-by-member below before use.
        $raw_ids = isset($_POST['video_ids']) && is_array($_POST['video_ids']) ? wp_unslash($_POST['video_ids']) : array();
        $ids = array();
        foreach (array_slice($raw_ids, 0, self::MAX_BULK_VIDEOS) as $raw_id) {
            if (! is_string($raw_id) && ! is_int($raw_id)) {
                continue;
            }
            $id = Video_Meta::sanitize_positive_id(sanitize_text_field((string) $raw_id));
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $counts = array('applied'=>0, 'present'=>0, 'refused'=>0, 'indeterminate'=>0);
        $actor = get_current_user_id();
        $now = time();
        foreach ($ids as $video_id) {
            if (! current_user_can('edit_post', $video_id)) {
                ++$counts['refused'];
                continue;
            }
            $policy = Local_Retention_Policy::sanitize(get_post_meta($video_id, Video_Meta::LOCAL_RETENTION_POLICY, true));
            if (array() === $policy) {
                $default_mode = $this->default_policy->mode();
                if (Local_Retention_Policy::MODE_KEEP === $default_mode) {
                    ++$counts['refused'];
                    continue;
                }
                // Snapshot the currently displayed server default before queuing
                // manual cleanup so the detached worker has durable policy authority.
                $configured = $this->service->configure_for_site_policy(
                    $video_id,
                    $default_mode,
                    $actor,
                    $now,
                    null
                );
                if (! in_array((string) ($configured['status'] ?? ''), array(Local_Retention_Service::APPLIED, Local_Retention_Service::PRESENT), true)) {
                    ++$counts['refused'];
                    continue;
                }
            }
            $result = $this->service->cleanup_now($video_id, $actor, $now);
            $status = (string) ($result['status'] ?? Local_Retention_Service::INDETERMINATE);
            if (! array_key_exists($status, $counts)) {
                $status = 'indeterminate';
            }
            ++$counts[$status];
        }
        $notice = sprintf(
            'cleanup-%d-%d-%d-%d',
            $counts['applied'],
            $counts['present'],
            $counts['refused'],
            $counts['indeterminate']
        );
        wp_safe_redirect(Settings_Hub::tab_url(Settings_Hub::TAB_RETENTION, array('awvp_retention_notice'=>$notice)));
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
                'post_type'=>Video_Post_Type::POST_TYPE,
                'post_status'=>'any',
                'numberposts'=>self::MAX_BULK_VIDEOS,
                'orderby'=>'ID',
                'order'=>'ASC',
            )
        );
        $site_policy = $this->archive_policy->get();
        $wordpress_archive = Archive_Of_Record_Policy_Store::WORDPRESS === $site_policy['archive_of_record'];
        $default_policy = $this->default_policy->get();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it cannot mutate state.
        $retention_notice = sanitize_key(sanitize_text_field(wp_unslash($_GET['awvp_retention_notice'] ?? '')));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        ?>
        <h2><?php esc_html_e('Local Retention', 'argentwolf-video-processor'); ?></h2>
        <div class="notice notice-info inline"><p><?php esc_html_e('Video serving choice and source retention policies are separate items. AWVP sets WordPress to retain the originals in your Media Library by default. Automatic original source file deletion is prevented while WordPress is marked as your Archive of Record. To automatically delete local files, mark WordPress as NOT the Archive of Record, indicating you either have a backup off-server or are comfortable with the chosen backend acting as your Archive of Record.', 'argentwolf-video-processor'); ?></p></div>
        <?php $this->render_notice($retention_notice); ?>

        <h3><?php esc_html_e('Archive of record for original videos', 'argentwolf-video-processor'); ?></h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:70em">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_ARCHIVE_POLICY); ?>">
            <?php wp_nonce_field(self::NONCE_ARCHIVE_POLICY); ?>
            <p><label><input type="radio" name="archive_of_record" value="wordpress" <?php checked(Archive_Of_Record_Policy_Store::WORDPRESS, $site_policy['archive_of_record']); ?>> <strong><?php esc_html_e('WordPress is the archive of record for original videos.', 'argentwolf-video-processor'); ?></strong></label><br>
            <span class="description"><?php esc_html_e('AWVP will never automatically delete the physical original Media Library video. Explicit Media Library deletion remains under normal WordPress control.', 'argentwolf-video-processor'); ?></span></p>
            <p><label><input type="radio" name="archive_of_record" value="not_wordpress" <?php checked(Archive_Of_Record_Policy_Store::NOT_WORDPRESS, $site_policy['archive_of_record']); ?>> <strong><?php esc_html_e('WordPress is not the archive of record for original videos.', 'argentwolf-video-processor'); ?></strong></label><br>
            <span class="description"><?php esc_html_e('This permits delayed removal of WordPress originals only after the delivery source being retained has been positively verified.', 'argentwolf-video-processor'); ?></span></p>
            <p><label><?php esc_html_e('Default original-source cleanup delay (days)', 'argentwolf-video-processor'); ?> <input type="number" name="grace_days" min="0" max="365" value="<?php echo esc_attr((string) $site_policy['grace_days']); ?>" style="width:5em"></label><br>
            <span class="description"><?php esc_html_e('0 means Never automatically delete an original. Selected videos may use a different delay when you apply the retention policy below.', 'argentwolf-video-processor'); ?></span></p>
            <?php if ($wordpress_archive) : ?>
                <p><label><input type="checkbox" name="confirm_archive_change" value="1"> <?php esc_html_e('Check this to enable saving WordPress as NOT your Archive of Record. I understand that this permits AWVP to remove original Media Library video files only after the configured delay and the retained delivery source has been positively verified. A delay of 0 means Never. Media retention policies on remote servers are controlled by those servers, not by ArgentWolf Video Processor.', 'argentwolf-video-processor'); ?></label></p>
            <?php else : ?>
                <p class="description"><?php esc_html_e('The one-time destructive-policy acknowledgement has already been recorded. Changing the grace period does not require repeated per-video confirmations.', 'argentwolf-video-processor'); ?></p>
            <?php endif; ?>
            <?php submit_button(__('Save archive policy', 'argentwolf-video-processor'), 'secondary', 'submit', false); ?>
        </form>

        <h3><?php esc_html_e('Server default retention policy', 'argentwolf-video-processor'); ?></h3>
        <p><?php esc_html_e('This is the retention policy applied to videos you select in the cleanup list below. Existing per-video policy remains unchanged until that video is selected and the default is applied again.', 'argentwolf-video-processor'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:70em;margin-bottom:1.5em">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_DEFAULT_POLICY); ?>">
            <?php wp_nonce_field(self::NONCE_DEFAULT_POLICY); ?>
            <label><strong><?php esc_html_e('Default retention policy', 'argentwolf-video-processor'); ?></strong>
                <select name="default_retention_mode">
                    <option value="keep"<?php selected((string) $default_policy['mode'], Local_Retention_Policy::MODE_KEEP); ?>><?php esc_html_e('Keep all local copies', 'argentwolf-video-processor'); ?></option>
                    <option value="delete_managed"<?php selected((string) $default_policy['mode'], Local_Retention_Policy::MODE_DELETE_MANAGED); ?>><?php esc_html_e('Keep original; remove generated local delivery copies', 'argentwolf-video-processor'); ?></option>
                    <?php if (! $wordpress_archive) : ?>
                        <option value="delete_source_keep_delivery"<?php selected((string) $default_policy['mode'], Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY); ?>><?php esc_html_e('Remove original; keep verified local HLS delivery copies', 'argentwolf-video-processor'); ?></option>
                        <option value="delete_all"<?php selected((string) $default_policy['mode'], Local_Retention_Policy::MODE_DELETE_ALL); ?>><?php esc_html_e('Remove original and generated local delivery copies after verified remote publication', 'argentwolf-video-processor'); ?></option>
                    <?php endif; ?>
                </select>
            </label>
            <?php if ($wordpress_archive && in_array((string) $default_policy['mode'], array(Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY, Local_Retention_Policy::MODE_DELETE_ALL), true)) : ?>
                <p class="description"><strong><?php esc_html_e('The stored default requests original-source deletion, but it is currently blocked because WordPress is the Archive of Record. Choose another default or change the archive policy first.', 'argentwolf-video-processor'); ?></strong></p>
            <?php endif; ?>
            <?php submit_button(__('Save server default retention policy', 'argentwolf-video-processor'), 'secondary', 'submit', false); ?>
        </form>

        <h3><?php esc_html_e('Video cleanup list', 'argentwolf-video-processor'); ?></h3>
        <p><?php esc_html_e('Search by AWVP Video title or origin-post title, select the videos you want to update, then apply retention settings or request an immediate manual cleanup.', 'argentwolf-video-processor'); ?></p>
        <p><label for="awvp-retention-filter"><strong><?php esc_html_e('Search videos', 'argentwolf-video-processor'); ?></strong></label>
        <input type="search" id="awvp-retention-filter" class="regular-text" placeholder="<?php echo esc_attr__('Video or post title', 'argentwolf-video-processor'); ?>"></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="awvp-retention-bulk-form">
            <?php wp_nonce_field(self::NONCE_BULK_APPLY); ?>
            <fieldset style="margin:1em 0"><legend><strong><?php esc_html_e('Cleanup delay for selected videos', 'argentwolf-video-processor'); ?></strong></legend>
                <label><input type="radio" name="grace_mode" value="site" checked> <?php echo esc_html($this->site_grace_label((int) $site_policy['grace_days'])); ?></label><br>
                <label><input type="radio" name="grace_mode" value="never"> <?php esc_html_e('Never automatically clean up these selected videos', 'argentwolf-video-processor'); ?></label><br>
                <label><input type="radio" name="grace_mode" value="custom"> <?php esc_html_e('Override for selected videos:', 'argentwolf-video-processor'); ?> <input type="number" name="grace_days_override" min="1" max="365" value="7" style="width:5em"> <?php esc_html_e('days', 'argentwolf-video-processor'); ?></label>
                <p class="description"><?php esc_html_e('The selected value is saved into each selected video. Changing the site default later does not silently rewrite existing video retention policies.', 'argentwolf-video-processor'); ?></p>
            </fieldset>
            <table class="widefat striped" id="awvp-retention-table">
                <thead><tr>
                    <th class="check-column"><input type="checkbox" id="awvp-retention-select-visible"><span class="screen-reader-text"><?php esc_html_e('Select all filtered videos', 'argentwolf-video-processor'); ?></span></th>
                    <th><?php esc_html_e('AWVP Video', 'argentwolf-video-processor'); ?></th>
                    <th><?php esc_html_e('Origin post', 'argentwolf-video-processor'); ?></th>
                    <th><?php esc_html_e('Serving / source', 'argentwolf-video-processor'); ?></th>
                    <th><?php esc_html_e('Current retention', 'argentwolf-video-processor'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php $this->render_video_row($row, $wordpress_archive, $default_policy, $site_policy); ?>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description" id="awvp-retention-filter-count"></p>
            <p>
                <button type="submit" class="button button-primary" name="action" value="<?php echo esc_attr(self::ACTION_BULK_APPLY); ?>"><?php esc_html_e('Apply retention settings to selected videos', 'argentwolf-video-processor'); ?></button>
                <button type="submit" class="button" name="action" value="<?php echo esc_attr(self::ACTION_BULK_CLEANUP); ?>"><?php esc_html_e('Clean up selected now', 'argentwolf-video-processor'); ?></button>
            </p>
            <p class="description"><?php esc_html_e('Clean up selected now bypasses only the waiting period. AWVP still requires the configured cleanup mode and every archive, serving, processing, source-identity, and managed-filesystem safety check.', 'argentwolf-video-processor'); ?></p>
        </form>
        <?php
    }

    private function grace_override_from_values(string $mode, string $raw): ?int
    {
        if ('never' === $mode) {
            return 0;
        }
        if ('custom' !== $mode) {
            return null;
        }
        if (1 !== preg_match('/^[1-9][0-9]{0,2}$/D', $raw)) {
            return Local_Retention_Policy::MAX_GRACE_DAYS + 1;
        }
        $days = (int) $raw;
        return $days <= Local_Retention_Policy::MAX_GRACE_DAYS ? $days : Local_Retention_Policy::MAX_GRACE_DAYS + 1;
    }

    private function site_grace_label(int $days): string
    {
        if (0 === $days) {
            return __('Use site default: Never automatically clean up', 'argentwolf-video-processor');
        }
        return sprintf(
            /* translators: %d: site default cleanup delay in days. */
            __('Use site default: %d days', 'argentwolf-video-processor'),
            $days
        );
    }

    private function render_notice(string $notice): void
    {
        if ('' === $notice) {
            return;
        }
        if (1 === preg_match('/^bulk-(\d+)-(\d+)-(\d+)-(\d+)$/D', $notice, $m)) {
            $message = sprintf(
                /* translators: 1: applied count, 2: already-current count, 3: refused count, 4: indeterminate count. */
                __('Bulk retention result: %1$d applied, %2$d already current, %3$d refused, %4$d indeterminate.', 'argentwolf-video-processor'),
                (int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]
            );
        } elseif (1 === preg_match('/^cleanup-(\d+)-(\d+)-(\d+)-(\d+)$/D', $notice, $m)) {
            $message = sprintf(
                /* translators: 1: queued count, 2: already-current count, 3: refused count, 4: indeterminate count. */
                __('Manual cleanup result: %1$d queued, %2$d already queued, %3$d refused, %4$d indeterminate.', 'argentwolf-video-processor'),
                (int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]
            );
        } else {
            $message = sprintf(
                /* translators: %s: bounded retention action result code. */
                __('Retention request: %s', 'argentwolf-video-processor'),
                $notice
            );
        }
        ?><div class="notice notice-info inline"><p><?php echo esc_html($message); ?></p></div><?php
    }

    private function render_video_row(object $row, bool $wordpress_archive, array $default_policy, array $site_policy): void
    {
        $video_id = (int) ($row->ID ?? 0);
        if ($video_id < 1) {
            return;
        }
        $video_title = trim((string) ($row->post_title ?? ''));
        $origin_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, true));
        $origin_title = $origin_id > 0 ? trim((string) get_the_title($origin_id)) : '';
        $authority = Video_Serving_Authority::sanitize(get_post_meta($video_id, Video_Meta::SERVING_AUTHORITY, true));
        $policy = Local_Retention_Policy::sanitize(get_post_meta($video_id, Video_Meta::LOCAL_RETENTION_POLICY, true));
        $execution = Local_Retention_Execution::sanitize(get_post_meta($video_id, Video_Meta::LOCAL_RETENTION_EXECUTION, true));
        $cleanup = Video_Meta::sanitize_cleanup_state(get_post_meta($video_id, Video_Meta::CLEANUP_STATE, true));
        $source = Video_Meta::sanitize_source_state(get_post_meta($video_id, Video_Meta::SOURCE_STATE, true));
        $frozen = 'removed' === $source
            || ('complete' === $cleanup && array() !== $execution && in_array((string) ($execution['mode'] ?? ''), array(Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY, Local_Retention_Policy::MODE_DELETE_ALL), true));
        $search = strtolower(trim($video_title . ' ' . $origin_title . ' ' . $source . ' ' . $cleanup));
        $override = array() !== $policy;
        $mode = $override ? (string) $policy['mode'] : (string) ($default_policy['mode'] ?? Local_Retention_Policy::MODE_KEEP);
        $grace_days = $override ? (int) $policy['grace_days'] : (int) ($site_policy['grace_days'] ?? 0);
        $scope_label = $override ? __('Override', 'argentwolf-video-processor') : __('Server default', 'argentwolf-video-processor');
        $behavior_label = match ($mode) {
            Local_Retention_Policy::MODE_KEEP => __('Keep all local copies', 'argentwolf-video-processor'),
            default => 0 === $grace_days
                ? __('Manual cleanup only', 'argentwolf-video-processor')
                : sprintf(
                    /* translators: %d: effective cleanup delay in days. */
                    __('Delete after %d days', 'argentwolf-video-processor'),
                    $grace_days
                ),
        };
        $policy_label = $scope_label . ' — ' . $behavior_label;
        $mode_detail = match ($mode) {
            Local_Retention_Policy::MODE_KEEP => '',
            Local_Retention_Policy::MODE_DELETE_MANAGED => __('Keep original; remove generated local delivery copies', 'argentwolf-video-processor'),
            Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY => __('Remove original; keep local HLS delivery copies', 'argentwolf-video-processor'),
            Local_Retention_Policy::MODE_DELETE_ALL => __('Remove original and generated local delivery copies', 'argentwolf-video-processor'),
            default => __('Unknown retention mode', 'argentwolf-video-processor'),
        };
        ?>
        <tr data-search="<?php echo esc_attr($search); ?>">
            <th scope="row" class="check-column"><input type="checkbox" name="video_ids[]" value="<?php echo esc_attr((string) $video_id); ?>" <?php disabled($frozen); ?>><span class="screen-reader-text"><?php echo esc_html(sprintf(
                /* translators: %s: video title. */
                __('Select %s', 'argentwolf-video-processor'),
                '' !== $video_title ? $video_title : '#' . $video_id
            )); ?></span></th>
            <td><strong>#<?php echo esc_html((string) $video_id); ?> — <?php echo esc_html('' !== $video_title ? $video_title : __('Untitled video', 'argentwolf-video-processor')); ?></strong></td>
            <td><?php if ($origin_id > 0) : ?>#<?php echo esc_html((string) $origin_id); ?> — <?php echo esc_html('' !== $origin_title ? $origin_title : __('Untitled post', 'argentwolf-video-processor')); ?><?php else : ?><em><?php esc_html_e('No origin post', 'argentwolf-video-processor'); ?></em><?php endif; ?></td>
            <td><?php echo esc_html(array() === $authority ? __('Local serving', 'argentwolf-video-processor') : __('Verified remote serving', 'argentwolf-video-processor')); ?><br><span class="description"><?php echo esc_html('Source: ' . $source . '; cleanup: ' . $cleanup); ?></span></td>
            <td><strong><?php echo esc_html($policy_label); ?></strong>
                <?php if ('' !== $mode_detail) : ?><br><span class="description"><?php echo esc_html($mode_detail); ?></span><?php endif; ?>
                <?php if ($frozen) : ?><br><span class="description"><?php esc_html_e('Original-source cleanup is complete; this row is read-only.', 'argentwolf-video-processor'); ?></span><?php endif; ?>
                <?php if ($wordpress_archive && in_array($mode, array(Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY, Local_Retention_Policy::MODE_DELETE_ALL), true)) : ?><br><span class="description"><strong><?php esc_html_e('Original deletion is currently blocked by the Archive of Record policy.', 'argentwolf-video-processor'); ?></strong></span><?php endif; ?>
            </td>
        </tr>
        <?php
    }
}

// EOF: includes/Local_Retention_Admin.php
