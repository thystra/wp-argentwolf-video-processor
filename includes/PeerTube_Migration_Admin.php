<?php
/**
 * File: includes/PeerTube_Migration_Admin.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Administrator-only R46.7 migration planning/review UI. */
final class PeerTube_Migration_Admin
{
    public const PAGE_SLUG = 'argent-video-migration';
    public const ACTION_PLAN = 'argent_video_plan_peertube_migration';
    public const ACTION_REVIEW = 'argent_video_review_peertube_migration';
    public const ACTION_EXECUTE = 'argent_video_execute_peertube_migration';
    private const NONCE_PLAN = 'argent_video_plan_peertube_migration';
    private const NONCE_REVIEW = 'argent_video_review_peertube_migration';
    private const NONCE_EXECUTE = 'argent_video_execute_peertube_migration';

    public function __construct(
        private readonly PeerTube_Migration_Planner $planner,
        private readonly PeerTube_Migration_Executor $executor,
        private readonly Backend_Registry $registry,
        private readonly PeerTube_Publication_Catalog_Store $catalogs
    ) {
    }

    public function menu(): void
    {
        add_management_page(
            __('AWVP PeerTube Migration', 'argentwolf-video-processor'),
            __('AWVP Video Migration', 'argentwolf-video-processor'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'page')
        );
    }

    public function plan_action(): void
    {
        $this->require_admin();
        check_admin_referer(self::NONCE_PLAN);
        $target = isset($_POST['target']) && is_string($_POST['target']) ? sanitize_text_field(wp_unslash($_POST['target'])) : '';
        [$backend_id, $channel_id] = $this->parse_target($target);
        $mode = isset($_POST['selection_mode']) && is_string($_POST['selection_mode'])
            ? sanitize_key(wp_unslash($_POST['selection_mode'])) : 'selected';

        $video_ids = array();
        $truncated = false;
        if ('all' === $mode) {
            $scan = $this->planner->candidates(PeerTube_Migration_Planner::MAX_SELECT_ALL, 0);
            foreach ($scan['items'] as $item) {
                $video_id = Video_Meta::sanitize_positive_id($item['video_id'] ?? null);
                if ($video_id > 0) {
                    $video_ids[] = $video_id;
                }
            }
            $truncated = true === $scan['more'];
        } elseif (isset($_POST['video_ids']) && is_array($_POST['video_ids'])) {
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each nonce-protected list member is validated as a strict positive AWVP Video ID below.
            foreach (wp_unslash($_POST['video_ids']) as $raw) {
                $video_id = Video_Meta::sanitize_positive_id($raw);
                if ($video_id > 0 && ! in_array($video_id, $video_ids, true)) {
                    $video_ids[] = $video_id;
                }
            }
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }

        $result = $this->planner->plan($video_ids, $backend_id, $channel_id, time());
        $notice = 'planned';
        if (array() === $result['applied'] && array() === $result['present']) {
            $notice = array() !== $result['indeterminate'] ? 'indeterminate' : 'refused';
        }
        $args = array(
            'page'=>self::PAGE_SLUG,
            'awvp_migration_notice'=>$notice,
            'awvp_migration_count'=>(string) (count($result['applied']) + count($result['present'])),
        );
        if ($truncated) {
            $args['awvp_migration_more'] = '1';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('tools.php')));
        exit;
    }

    public function review_action(): void
    {
        $this->require_admin();
        $video_id = isset($_POST['video_id']) && is_string($_POST['video_id']) ? Video_Meta::sanitize_positive_id(sanitize_text_field(wp_unslash($_POST['video_id']))) : 0;
        check_admin_referer(self::NONCE_REVIEW . ':' . $video_id);
        $stored = $video_id > 0
            ? PeerTube_Migration_Plan::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_MIGRATION_PLAN, true))
            : array();
        if (array() === $stored || ! is_array($stored['publication_plan'] ?? null)) {
            $result = array('status'=>PeerTube_Migration_Planner::REFUSED,'issues'=>array('publication_prefill_incomplete'));
        } else {
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nonce-protected publication structure is normalized by form_to_plan() and the publication-plan sanitizer.
            $input = isset($_POST['publication']) && is_array($_POST['publication']) ? wp_unslash($_POST['publication']) : array();
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $plan = $this->form_to_plan($stored['publication_plan'], $input);
            $result = $this->planner->review($video_id, $plan, time());
        }
        $notice = match ($result['status'] ?? '') {
            PeerTube_Migration_Planner::APPLIED, PeerTube_Migration_Planner::PRESENT => 'reviewed',
            PeerTube_Migration_Planner::INDETERMINATE => 'indeterminate',
            default => 'refused',
        };
        wp_safe_redirect(add_query_arg(
            array('page'=>self::PAGE_SLUG,'review_video_id'=>(string)$video_id,'awvp_migration_notice'=>$notice),
            admin_url('tools.php')
        ));
        exit;
    }


    public function execute_action(): void
    {
        $this->require_admin();
        $video_id = isset($_POST['video_id']) && is_string($_POST['video_id']) ? Video_Meta::sanitize_positive_id(sanitize_text_field(wp_unslash($_POST['video_id']))) : 0;
        check_admin_referer(self::NONCE_EXECUTE . ':' . $video_id);
        if ($video_id < 1) {
            $result = array('status'=>PeerTube_Migration_Executor::REFUSED);
        } else {
            $existing = metadata_exists('post', $video_id, Video_Meta::PEERTUBE_MIGRATION_EXECUTION)
                ? PeerTube_Migration_Execution::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_MIGRATION_EXECUTION, true))
                : array();
            $confirmed = isset($_POST['confirm_one_way']) && is_string($_POST['confirm_one_way']) && '1' === sanitize_key(wp_unslash($_POST['confirm_one_way']));
            if (array() === $existing && ! $confirmed) {
                $result = array('status'=>PeerTube_Migration_Executor::REFUSED);
            } else {
                $result = $this->executor->execute($video_id, time());
            }
        }
        $notice = match ($result['status'] ?? '') {
            PeerTube_Migration_Executor::APPLIED => 'execution_started',
            PeerTube_Migration_Executor::PRESENT => 'execution_present',
            PeerTube_Migration_Executor::BUSY => 'busy',
            PeerTube_Migration_Executor::INDETERMINATE => 'execution_indeterminate',
            default => 'execution_refused',
        };
        wp_safe_redirect(add_query_arg(
            array('page'=>self::PAGE_SLUG,'awvp_migration_notice'=>$notice,'execution_video_id'=>(string)$video_id),
            admin_url('tools.php')
        ));
        exit;
    }

    public function page(): void
    {
        $this->require_admin();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only review selector; no state is changed by rendering the page.
        $review_video_id = isset($_GET['review_video_id']) && is_string($_GET['review_video_id']) ? Video_Meta::sanitize_positive_id(sanitize_text_field(wp_unslash($_GET['review_video_id']))) : 0;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('AWVP PeerTube Migration', 'argentwolf-video-processor'); ?></h1>
            <?php $this->notice(); ?>
            <p><?php esc_html_e('Planning and review remain inert. R46.8 adds an explicit one-way Start migration action for ready plans. Starting migration promotes the reviewed plan into live destination/publication state and hands it to AWVP’s existing durable PeerTube executor; frontend serving remains local until verified cutover.', 'argentwolf-video-processor'); ?></p>
            <p><?php esc_html_e('WordPress post tags are suggestions only. PeerTube tags require explicit per-video review, including an intentional zero-tag choice, and no more than five may be selected.', 'argentwolf-video-processor'); ?></p>
            <?php if ($review_video_id > 0) : ?>
                <?php $this->review_form($review_video_id); ?>
            <?php else : ?>
                <?php $this->planner_form(); ?>
                <?php $this->planned_table(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function planner_form(): void
    {
        $scan = $this->planner->candidates(100, 0);
        $targets = $this->targets();
        ?>
        <h2><?php esc_html_e('Plan local videos', 'argentwolf-video-processor'); ?></h2>
        <?php if (array() === $targets) : ?>
            <div class="notice notice-warning inline"><p><?php esc_html_e('No active PeerTube backend has a usable cached owned-channel catalog. Refresh publication choices in Settings > AWVP Video Publishing first.', 'argentwolf-video-processor'); ?></p></div>
        <?php elseif (array() === $scan['items']) : ?>
            <p><?php esc_html_e('No eligible local AWVP Videos were found in this batch.', 'argentwolf-video-processor'); ?></p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_PLAN); ?>">
                <?php wp_nonce_field(self::NONCE_PLAN); ?>
                <p><label><strong><?php esc_html_e('Target PeerTube channel', 'argentwolf-video-processor'); ?></strong><br>
                    <select name="target" required>
                        <?php foreach ($targets as $value=>$label) : ?>
                            <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label></p>
                <table class="widefat striped">
                    <thead><tr><td class="check-column"></td><th><?php esc_html_e('Video', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Anchor', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Current migration state', 'argentwolf-video-processor'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($scan['items'] as $item) : ?>
                        <tr>
                            <th class="check-column"><input type="checkbox" name="video_ids[]" value="<?php echo esc_attr((string)$item['video_id']); ?>"></th>
                            <td><?php echo esc_html((string)$item['title']); ?> <code>#<?php echo esc_html((string)$item['video_id']); ?></code></td>
                            <td>#<?php echo esc_html((string)$item['anchor_post_id']); ?> — <?php echo esc_html((string)$item['post_status']); ?></td>
                            <td><?php echo esc_html((string)$item['plan_status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p>
                    <button class="button button-primary" type="submit" name="selection_mode" value="selected"><?php esc_html_e('Plan selected', 'argentwolf-video-processor'); ?></button>
                    <button class="button" type="submit" name="selection_mode" value="all"><?php echo esc_html(sprintf(
                            /* translators: %d: maximum number of videos planned in one batch. */
                            __('Plan all eligible (up to %d)', 'argentwolf-video-processor'),
                            PeerTube_Migration_Planner::MAX_SELECT_ALL
                        )); ?></button>
                </p>
                <?php if (true === $scan['more']) : ?><p class="description"><?php esc_html_e('More candidates exist beyond the first displayed batch. “Plan all eligible” processes the first bounded migration batch; repeat after reviewing/planning that batch.', 'argentwolf-video-processor'); ?></p><?php endif; ?>
            </form>
        <?php endif;
    }

    private function planned_table(): void
    {
        $plans = $this->planner->planned(200);
        ?>
        <h2><?php esc_html_e('Migration review queue', 'argentwolf-video-processor'); ?></h2>
        <?php if (array() === $plans) : ?><p><?php esc_html_e('No migration plans exist yet.', 'argentwolf-video-processor'); ?></p><?php return; endif; ?>
        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e('AWVP Video', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Target', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Status', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Issues', 'argentwolf-video-processor'); ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($plans as $plan) : ?>
                <tr>
                    <td>#<?php echo esc_html((string)($plan['video_id'] ?? 0)); ?></td>
                    <td><?php echo esc_html((string)($plan['backend_id'] ?? '')); ?> / <?php echo esc_html((string)($plan['channel_id'] ?? '')); ?></td>
                    <td><strong><?php echo esc_html((string)($plan['status'] ?? 'invalid')); ?></strong></td>
                    <td><?php echo esc_html(implode(', ', is_array($plan['issues'] ?? null) ? $plan['issues'] : array())); ?></td>
                    <td>
                        <?php
                        $row_video_id = Video_Meta::sanitize_positive_id($plan['video_id'] ?? 0);
                        $execution_exists = $row_video_id > 0 && metadata_exists('post', $row_video_id, Video_Meta::PEERTUBE_MIGRATION_EXECUTION);
                        $execution = $execution_exists ? PeerTube_Migration_Execution::sanitize(get_post_meta($row_video_id, Video_Meta::PEERTUBE_MIGRATION_EXECUTION, true)) : array();
                        ?>
                        <?php if ($execution_exists && array() === $execution) : ?>
                            <strong><?php esc_html_e('Execution state invalid; preserved for manual repair.', 'argentwolf-video-processor'); ?></strong>
                        <?php elseif (array() !== $execution) : ?>
                            <strong><?php echo esc_html((string)$execution['status']); ?></strong>
                            <?php if (PeerTube_Migration_Execution::STATUS_DISPATCHED !== $execution['status']) : ?>
                                <form style="display:inline" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_EXECUTE); ?>">
                                    <input type="hidden" name="video_id" value="<?php echo esc_attr((string)$row_video_id); ?>">
                                    <?php wp_nonce_field(self::NONCE_EXECUTE . ':' . $row_video_id); ?>
                                    <button class="button" type="submit"><?php esc_html_e('Resume migration', 'argentwolf-video-processor'); ?></button>
                                </form>
                            <?php endif; ?>
                        <?php elseif (PeerTube_Migration_Plan::STATUS_READY === ($plan['status'] ?? null)) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_EXECUTE); ?>">
                                <input type="hidden" name="video_id" value="<?php echo esc_attr((string)$row_video_id); ?>">
                                <?php wp_nonce_field(self::NONCE_EXECUTE . ':' . $row_video_id); ?>
                                <label style="display:block;margin-bottom:4px"><input required type="checkbox" name="confirm_one_way" value="1"> <?php esc_html_e('I understand this migration is one-way.', 'argentwolf-video-processor'); ?></label>
                                <button class="button button-primary" type="submit"><?php esc_html_e('Start migration', 'argentwolf-video-processor'); ?></button>
                            </form>
                        <?php else : ?>
                            <a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>self::PAGE_SLUG,'review_video_id'=>(string)$row_video_id),admin_url('tools.php'))); ?>"><?php esc_html_e('Review', 'argentwolf-video-processor'); ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function review_form(int $video_id): void
    {
        if (metadata_exists('post', $video_id, Video_Meta::PEERTUBE_MIGRATION_EXECUTION)) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Migration execution has already started. The migration plan is frozen; return to the queue to resume or inspect execution.', 'argentwolf-video-processor') . '</p></div>';
            return;
        }
        $migration = PeerTube_Migration_Plan::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_MIGRATION_PLAN, true));
        if (array() === $migration) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('The migration plan is missing, malformed, or from a future schema. AWVP preserved it and will not overwrite it implicitly.', 'argentwolf-video-processor') . '</p></div>';
            return;
        }
        $plan = is_array($migration['publication_plan'] ?? null) ? $migration['publication_plan'] : null;
        if (null === $plan) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('This item has no valid publication prefill. Correct the underlying title/default/provider catalog before replanning it.', 'argentwolf-video-processor') . '</p></div>';
            return;
        }
        $catalog = $this->catalogs->get((string)$migration['backend_id']);
        ?>
        <p><a href="<?php echo esc_url(add_query_arg(array('page'=>self::PAGE_SLUG),admin_url('tools.php'))); ?>">&larr; <?php esc_html_e('Back to migration queue', 'argentwolf-video-processor'); ?></a></p>
        <h2><?php echo esc_html(sprintf(
            /* translators: %d: AWVP Video post ID. */
            __('Review AWVP Video #%d', 'argentwolf-video-processor'),
            $video_id
        )); ?></h2>
        <p><strong><?php esc_html_e('Target', 'argentwolf-video-processor'); ?>:</strong> <?php echo esc_html((string)$migration['backend_id']); ?> / <?php echo esc_html((string)$migration['channel_id']); ?></p>
        <?php if (array() !== $migration['suggested_tags']) : ?><p><strong><?php esc_html_e('WordPress tag suggestions', 'argentwolf-video-processor'); ?>:</strong> <?php echo esc_html(implode(', ', $migration['suggested_tags'])); ?></p><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_REVIEW); ?>">
            <input type="hidden" name="video_id" value="<?php echo esc_attr((string)$video_id); ?>">
            <?php wp_nonce_field(self::NONCE_REVIEW . ':' . $video_id); ?>
            <table class="form-table" role="presentation">
                <tr><th><label for="awvp-migration-title"><?php esc_html_e('PeerTube title', 'argentwolf-video-processor'); ?></label></th><td><input id="awvp-migration-title" class="regular-text" name="publication[title]" value="<?php echo esc_attr((string)$plan['title']); ?>" required></td></tr>
                <tr><th><label for="awvp-migration-description"><?php esc_html_e('Markdown description', 'argentwolf-video-processor'); ?></label></th><td><textarea id="awvp-migration-description" class="large-text" rows="5" name="publication[description_markdown]"><?php echo esc_textarea((string)$plan['description_markdown']); ?></textarea></td></tr>
                <tr><th><label for="awvp-migration-tags"><?php esc_html_e('PeerTube tags', 'argentwolf-video-processor'); ?></label></th><td><input id="awvp-migration-tags" class="regular-text" name="publication[tags]" value="<?php echo esc_attr(implode(', ', $plan['tags'])); ?>"><p class="description"><?php esc_html_e('Comma-separated; maximum five. An empty value is valid only when you explicitly review the zero-tag choice below.', 'argentwolf-video-processor'); ?></p></td></tr>
                <?php $this->provider_select('final_privacy_id', __('Final privacy', 'argentwolf-video-processor'), (string)$plan['final_privacy_id'], is_array($catalog)?$catalog['privacies']??array():array(), false); ?>
                <?php $this->provider_select('licence_id', __('Licence', 'argentwolf-video-processor'), (string)$plan['licence_id'], is_array($catalog)?$catalog['licences']??array():array(), true); ?>
                <?php $this->provider_select('category_id', __('Category', 'argentwolf-video-processor'), (string)$plan['category_id'], is_array($catalog)?$catalog['categories']??array():array(), true); ?>
                <?php $this->provider_select('language', __('Language', 'argentwolf-video-processor'), (string)$plan['language'], is_array($catalog)?$catalog['languages']??array():array(), true); ?>
                <tr><th><?php esc_html_e('Comments', 'argentwolf-video-processor'); ?></th><td><select name="publication[comments_policy]"><?php foreach(array('enabled'=>__('Enabled','argentwolf-video-processor'),'approval_required'=>__('Approval required','argentwolf-video-processor'),'disabled'=>__('Disabled','argentwolf-video-processor')) as $v=>$l): ?><option value="<?php echo esc_attr($v); ?>" <?php selected((string)$plan['comments_policy'],$v); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></td></tr>
                <tr><th><?php esc_html_e('Downloads', 'argentwolf-video-processor'); ?></th><td><label><input type="checkbox" name="publication[download_enabled]" value="1" <?php checked(true,(bool)$plan['download_enabled']); ?>> <?php esc_html_e('Allow downloads', 'argentwolf-video-processor'); ?></label></td></tr>
                <tr><th><label for="awvp-migration-thumbnail"><?php esc_html_e('Thumbnail attachment ID', 'argentwolf-video-processor'); ?></label></th><td><input id="awvp-migration-thumbnail" type="number" min="0" name="publication[thumbnail_attachment_id]" value="<?php echo esc_attr((string)$plan['thumbnail_attachment_id']); ?>"></td></tr>
                <tr><th><?php esc_html_e('Sensitive content', 'argentwolf-video-processor'); ?></th><td>
                    <label><input type="checkbox" name="publication[sensitive]" value="1" <?php checked(true,(bool)$plan['moderation']['sensitive']); ?>> <?php esc_html_e('Sensitive', 'argentwolf-video-processor'); ?></label><br>
                    <label><input type="checkbox" name="publication[violent]" value="1" <?php checked(true,(bool)$plan['moderation']['violent']); ?>> <?php esc_html_e('Violent', 'argentwolf-video-processor'); ?></label><br>
                    <label><input type="checkbox" name="publication[sexually_explicit]" value="1" <?php checked(true,(bool)$plan['moderation']['sexually_explicit']); ?>> <?php esc_html_e('Sexually explicit', 'argentwolf-video-processor'); ?></label><br>
                    <label><?php esc_html_e('Summary', 'argentwolf-video-processor'); ?> <input class="regular-text" name="publication[sensitive_reason]" value="<?php echo esc_attr((string)$plan['moderation']['reason']); ?>"></label>
                </td></tr>
                <tr><th><?php esc_html_e('Explicit review', 'argentwolf-video-processor'); ?></th><td>
                    <?php foreach(array('title'=>__('I reviewed the PeerTube title','argentwolf-video-processor'),'channel'=>__('I reviewed the target channel','argentwolf-video-processor'),'tags'=>__('I reviewed the PeerTube tags, including zero tags if applicable','argentwolf-video-processor'),'privacy'=>__('I reviewed final privacy','argentwolf-video-processor'),'moderation'=>__('I reviewed the sensitive-content declaration','argentwolf-video-processor')) as $field=>$label): ?>
                        <label style="display:block"><input type="checkbox" name="publication[review][<?php echo esc_attr($field); ?>]" value="1" <?php checked(true,(bool)($plan['review'][$field]??false)); ?>> <?php echo esc_html($label); ?></label>
                    <?php endforeach; ?>
                </td></tr>
            </table>
            <?php submit_button(__('Save migration review', 'argentwolf-video-processor')); ?>
        </form>
        <?php
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $input @return array<string,mixed> */
    private function form_to_plan(array $before, array $input): array
    {
        $next = $before;
        foreach (array('title','description_markdown','final_privacy_id','licence_id','category_id','language','comments_policy') as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $next[$field] = $input[$field];
            }
        }
        $tags = isset($input['tags']) && is_string($input['tags']) ? $input['tags'] : '';
        $next['tags'] = array_values(array_filter(array_map('trim', explode(',', $tags)), static fn(string $tag): bool => '' !== $tag));
        $next['download_enabled'] = isset($input['download_enabled']) && '1' === (string)$input['download_enabled'];
        $next['thumbnail_attachment_id'] = isset($input['thumbnail_attachment_id']) ? max(0, (int)$input['thumbnail_attachment_id']) : 0;
        $sensitive = isset($input['sensitive']) && '1' === (string)$input['sensitive'];
        $next['moderation'] = array(
            'reviewed'=>isset($input['review']['moderation']) && '1' === (string)$input['review']['moderation'],
            'sensitive'=>$sensitive,
            'reason'=>$sensitive && isset($input['sensitive_reason']) && is_string($input['sensitive_reason']) ? $input['sensitive_reason'] : '',
            'violent'=>$sensitive && isset($input['violent']) && '1' === (string)$input['violent'],
            'sexually_explicit'=>$sensitive && isset($input['sexually_explicit']) && '1' === (string)$input['sexually_explicit'],
        );
        foreach (array('title','channel','tags','privacy','moderation') as $field) {
            $next['review'][$field] = isset($input['review'][$field]) && '1' === (string)$input['review'][$field];
        }
        return $next;
    }

    /** @param array<string,string> $choices */
    private function provider_select(string $field, string $label, string $selected_value, array $choices, bool $allow_empty): void
    {
        echo '<tr><th><label for="awvp-migration-' . esc_attr($field) . '">' . esc_html($label) . '</label></th><td><select id="awvp-migration-' . esc_attr($field) . '" name="publication[' . esc_attr($field) . ']">';
        if ($allow_empty) {
            echo '<option value="">' . esc_html__('None / unspecified', 'argentwolf-video-processor') . '</option>';
        }
        foreach ($choices as $value=>$choice_label) {
            if ('final_privacy_id' === $field && '5' === (string)$value) {
                continue;
            }
            echo '<option value="' . esc_attr((string)$value) . '" ' . selected($selected_value,(string)$value,false) . '>' . esc_html((string)$choice_label) . '</option>';
        }
        echo '</select></td></tr>';
    }

    /** @return array<string,string> */
    private function targets(): array
    {
        $out = array();
        foreach ($this->registry->all() as $backend_id=>$descriptor) {
            if (! is_array($descriptor) || Backend_Registry::PEERTUBE_TYPE !== ($descriptor['type'] ?? null) || 'active' !== ($descriptor['state'] ?? null)) {
                continue;
            }
            $catalog = $this->catalogs->get((string)$backend_id);
            if (! is_array($catalog)) {
                continue;
            }
            $origin = PeerTube_Origin::sanitize($descriptor['config']['origin'] ?? null);
            if ('' === $origin || $origin !== ($catalog['origin'] ?? null)
                || (true === ($catalog['stale'] ?? false) && 'backend_context_changed' === ($catalog['stale_reason'] ?? ''))
            ) {
                continue;
            }
            foreach ($catalog['channels'] as $channel) {
                if (! is_array($channel) || 'owned' !== ($channel['authority'] ?? null)) {
                    continue;
                }
                $channel_id = is_string($channel['id'] ?? null) ? $channel['id'] : '';
                if ('' === $channel_id) {
                    continue;
                }
                $label = is_string($descriptor['label'] ?? null) && '' !== $descriptor['label'] ? $descriptor['label'] : (string)$backend_id;
                $channel_label = is_string($channel['display_name'] ?? null) ? $channel['display_name'] : $channel_id;
                $out[(string)$backend_id . '|' . $channel_id] = $label . ' — ' . $channel_label . ' (#' . $channel_id . ')';
            }
        }
        return $out;
    }

    /** @return array{0:string,1:string} */
    private function parse_target(string $target): array
    {
        $parts = explode('|', $target, 2);
        $backend = Backend_Identity::sanitize($parts[0] ?? '');
        $channel = isset($parts[1]) && 1 === preg_match('/^[1-9][0-9]*$/D', $parts[1]) ? $parts[1] : '';
        return array($backend,$channel);
    }

    private function require_admin(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage video migration.', 'argentwolf-video-processor'));
        }
    }

    private function notice(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Redirect notice/count fields are read-only presentation state.
        $notice = isset($_GET['awvp_migration_notice']) && is_string($_GET['awvp_migration_notice']) ? sanitize_key(wp_unslash($_GET['awvp_migration_notice'])) : '';
        if ('' === $notice) {
            return;
        }
        $message = match ($notice) {
            'planned' => sprintf(
                /* translators: %d: number of videos whose migration planning state was saved. */
                __('Migration planning state saved for %d video(s).', 'argentwolf-video-processor'),
                isset($_GET['awvp_migration_count']) && is_string($_GET['awvp_migration_count']) ? absint(wp_unslash($_GET['awvp_migration_count'])) : 0
            ),
            'reviewed' => __('Migration review saved.', 'argentwolf-video-processor'),
            'execution_started' => __('Migration was committed one-way and handed to AWVP’s durable publication executor.', 'argentwolf-video-processor'),
            'execution_present' => __('Migration execution was already committed and its durable handoff is present.', 'argentwolf-video-processor'),
            'busy' => __('Another migration execution attempt currently owns this video. Retry after it finishes.', 'argentwolf-video-processor'),
            'execution_indeterminate' => __('AWVP could not verify the migration execution write/handoff. The local journal was preserved; use Resume migration rather than starting another migration.', 'argentwolf-video-processor'),
            'execution_refused' => __('Migration execution was refused because the reviewed plan or current provider/source context is no longer safe to promote.', 'argentwolf-video-processor'),
            'indeterminate' => __('AWVP could not verify the migration-plan write. No remote work was started.', 'argentwolf-video-processor'),
            default => __('The migration planning request was refused. No remote work was started.', 'argentwolf-video-processor'),
        };
        echo '<div class="notice ' . (in_array($notice, array('refused','indeterminate','execution_refused','execution_indeterminate','busy'), true) ? 'notice-error' : 'notice-success') . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        $more = isset($_GET['awvp_migration_more']) && is_string($_GET['awvp_migration_more'])
            ? sanitize_key(wp_unslash($_GET['awvp_migration_more']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ('1' === $more) {
            echo '<div class="notice notice-info"><p>' . esc_html__('More than one bounded select-all batch is eligible. Repeat planning after this batch is reviewed.', 'argentwolf-video-processor') . '</p></div>';
        }
    }
}

// EOF: includes/PeerTube_Migration_Admin.php
