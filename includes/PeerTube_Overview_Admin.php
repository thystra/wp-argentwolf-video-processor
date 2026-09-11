<?php
/**
 * File: includes/PeerTube_Overview_Admin.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Human-oriented operational overview for remote publication work and serving health. */
final class PeerTube_Overview_Admin
{
    public const ACTION_RESUME = 'argentwolf_video_processor_resume_incomplete';
    public const ACTION_REVIEW = 'argentwolf_video_processor_overview_review';
    public const ACTION_UNREVIEW = 'argentwolf_video_processor_overview_unreview';
    public const ACTION_DISMISS = 'argentwolf_video_processor_overview_dismiss';
    public const ACTION_REPUBLISH = 'argentwolf_video_processor_overview_republish';
    private const MAX_ROWS = 100;

    public function __construct(
        private readonly PeerTube_Staged_Upload_Operation_Store $operations,
        private readonly PeerTube_Incomplete_Work_Reconciler $recovery,
        private readonly ?PeerTube_Event_Repository $events = null,
        private readonly ?Remote_Publication_Health_Repository $health = null,
        private readonly ?Video_Serving_Service $serving = null,
        private readonly ?Overview_Disposition_Store $dispositions = null,
        private readonly ?Backend_Maintenance_Status_Store $backend_maintenance = null,
        private readonly ?Backend_Health_Incident_Store $backend_incidents = null,
        private readonly ?Remote_Republish_Service $republish = null
    ) {
    }

    public function render_tab(): void
    {
        $rows = $this->rows(time());
        $current = array();
        $reviewed = array();
        $active_fingerprints = array();
        foreach ($rows as $row) {
            $fingerprint = (string) ($row['issue_fingerprint'] ?? '');
            if ('' !== $fingerprint) {
                $active_fingerprints[] = $fingerprint;
            }
            $state = null !== $this->dispositions && '' !== $fingerprint
                ? $this->dispositions->state($fingerprint)
                : '';
            if (Overview_Disposition_Store::DISMISSED === $state) {
                continue;
            }
            if (Overview_Disposition_Store::REVIEWED === $state && true === ($row['needs_attention'] ?? false)) {
                $reviewed[] = $row;
            } else {
                $current[] = $row;
            }
        }
        if (null !== $this->dispositions) {
            $this->dispositions->prune($active_fingerprints);
        }
        ?>
        <h2><?php esc_html_e('Status & Needs Attention', 'argentwolf-video-processor'); ?></h2>
        <p><?php esc_html_e('Active publication work and current remote-serving problems appear here. Resolved conditions disappear automatically; reviewing or removing an item changes only this presentation and never deletes its task or diagnostic history.', 'argentwolf-video-processor'); ?></p>
        <?php
        if (isset($_GET['awvp_republish'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect status notice; no state change is performed from this query parameter.
            $republish_notice = sanitize_key((string) wp_unslash($_GET['awvp_republish'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice; the republish mutation is separately nonce-protected.
            ?>
            <div class="notice <?php echo 'queued' === $republish_notice ? 'notice-success' : 'notice-error'; ?>" style="margin:1em 0;padding:.5em 1em"><p>
                <?php echo esc_html('queued' === $republish_notice
                    ? __('Republish was queued from the retained WordPress source.', 'argentwolf-video-processor')
                    : __('Republish could not be queued. Review the current source/backend state and try again.', 'argentwolf-video-processor')); ?>
            </p></div>
        <?php endif; ?>
        <?php $this->render_backend_maintenance_issues(); ?>
        <?php $this->render_backend_health_incidents(); ?>
        <?php if (array() === $current) : ?>
            <p><?php esc_html_e('No publication work currently needs operational attention.', 'argentwolf-video-processor'); ?></p>
        <?php else : ?>
            <?php $this->render_table($current, false); ?>
        <?php endif; ?>

        <?php if (array() !== $reviewed) : ?>
            <details style="margin-top:1.5em">
                <summary><strong><?php echo esc_html(sprintf(
                    /* translators: %d: number of currently reviewed Overview items */
                    _n('Reviewed item (%d)', 'Reviewed items (%d)', count($reviewed), 'argentwolf-video-processor'),
                    count($reviewed)
                )); ?></strong></summary>
                <p><?php esc_html_e('These conditions are still current but have been marked reviewed. A changed or new condition will return to Needs Attention automatically.', 'argentwolf-video-processor'); ?></p>
                <?php $this->render_table($reviewed, true); ?>
            </details>
        <?php endif;
    }

    private function render_backend_health_incidents(): void
    {
        if (null === $this->backend_incidents) {
            return;
        }
        $issues = $this->backend_incidents->all();
        if (array() === $issues) {
            return;
        }
        ?>
        <div class="notice notice-error" style="margin:1em 0;padding:.75em 1em">
            <p><strong><?php esc_html_e('Remote backend serving outage', 'argentwolf-video-processor'); ?></strong></p>
            <ul style="margin-bottom:0">
                <?php foreach ($issues as $backend_id => $issue) : ?>
                    <li><code><?php echo esc_html((string) $backend_id); ?></code> — <?php echo esc_html((string) $issue['message']); ?> <?php esc_html_e('Affected videos automatically use their next viable serving source by priority.', 'argentwolf-video-processor'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    private function render_backend_maintenance_issues(): void
    {
        if (null === $this->backend_maintenance) {
            return;
        }
        $issues = array_filter(
            $this->backend_maintenance->all(),
            static fn (array $row): bool => Backend_Maintenance_Status_Store::HEALTHY !== ($row['status'] ?? '')
        );
        if (array() === $issues) {
            return;
        }
        ?>
        <div class="notice notice-error" style="margin:1em 0;padding:.75em 1em">
            <p><strong><?php esc_html_e('Backend maintenance needs attention', 'argentwolf-video-processor'); ?></strong></p>
            <ul style="margin-bottom:0">
                <?php foreach ($issues as $backend_id => $issue) : ?>
                    <li>
                        <code><?php echo esc_html((string) $backend_id); ?></code> — <?php echo esc_html((string) $issue['message']); ?>
                        <?php if ((int) $issue['checked_at'] > 0) : ?>
                            <?php echo esc_html(sprintf(
                                /* translators: %s: date/time of the latest backend maintenance check. */
                                __('Last checked %s.', 'argentwolf-video-processor'),
                                Settings_Hub::format_datetime((int) $issue['checked_at'])
                            )); ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /** @param list<array<string,mixed>> $rows */
    private function render_table(array $rows, bool $reviewed): void
    {
        ?>
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
                        <?php if ('' !== (string) ($row['serving_label'] ?? '')) : ?><br><?php echo esc_html((string) $row['serving_label']); ?><?php endif; ?>
                        <details style="margin-top:.5em"><summary><?php esc_html_e('Details & log', 'argentwolf-video-processor'); ?></summary>
                            <?php if (is_array($row['health_issue'] ?? null)) : $health = $row['health_issue']; ?>
                                <p><strong><?php esc_html_e('Remote serving health:', 'argentwolf-video-processor'); ?></strong> <?php echo esc_html((string) ($health['status'] ?? '')); ?></p>
                                <?php if ('' !== (string) ($health['backend_id'] ?? '')) : ?><p><strong><?php esc_html_e('Backend ID (internal identifier):', 'argentwolf-video-processor'); ?></strong> <code><?php echo esc_html((string) $health['backend_id']); ?></code></p><?php endif; ?>
                                <?php if ((int) ($health['http_status'] ?? 0) > 0) : ?><p><strong><?php esc_html_e('HTTP status:', 'argentwolf-video-processor'); ?></strong> <?php echo esc_html((string) $health['http_status']); ?></p><?php endif; ?>
                                <?php if ('' !== (string) ($health['message'] ?? '')) : ?><p><?php echo esc_html((string) $health['message']); ?></p><?php endif; ?>
                            <?php endif; ?>
                            <?php if (is_array($row['latest_event'])) : $event = $row['latest_event']; ?>
                                <?php if ('' !== (string) $event['created_at']) : ?><p><strong><?php esc_html_e('Latest event:', 'argentwolf-video-processor'); ?></strong> <?php echo esc_html((string) $event['created_at']); ?></p><?php endif; ?>
                                <p><strong><?php echo esc_html(sprintf(
                                    /* translators: 1: pipeline step number, 2: pipeline step name */
                                    __('Step %1$d of 7 — %2$s', 'argentwolf-video-processor'),
                                    (int) $event['pipeline_step'],
                                    self::pipeline_step_label((int) $event['pipeline_step'])
                                )); ?></strong></p>
                                <?php if ('' !== (string) $event['backend_id']) : ?><p><strong><?php esc_html_e('Backend ID (internal identifier):', 'argentwolf-video-processor'); ?></strong> <code><?php echo esc_html((string) $event['backend_id']); ?></code></p><?php endif; ?>
                                <?php if ((int) $event['http_status'] > 0) : ?><p><strong><?php esc_html_e('HTTP status:', 'argentwolf-video-processor'); ?></strong> <?php echo esc_html((string) $event['http_status']); ?></p><?php endif; ?>
                                <p><?php echo esc_html((string) $event['message']); ?></p>
                                <?php if ('' !== (string) $event['automatic_action']) : ?><p><strong><?php esc_html_e('Automatic action:', 'argentwolf-video-processor'); ?></strong> <?php echo esc_html((string) $event['automatic_action']); ?></p><?php endif; ?>
                                <?php if ('' !== (string) $event['operator_action']) : ?><p><strong><?php esc_html_e('Suggested operator action:', 'argentwolf-video-processor'); ?></strong> <?php echo esc_html((string) $event['operator_action']); ?></p><?php endif; ?>
                                <?php if (array() !== $row['events']) : ?>
                                    <p><strong><?php esc_html_e('Recent activity', 'argentwolf-video-processor'); ?></strong></p>
                                    <ul>
                                        <?php foreach ($row['events'] as $history) : ?>
                                            <li><?php echo esc_html(sprintf(
                                                /* translators: 1: timestamp, 2: pipeline step, 3: event message */
                                                __('%1$s — Step %2$d of 7 — %3$s', 'argentwolf-video-processor'),
                                                (string) $history['created_at'],
                                                (int) $history['pipeline_step'],
                                                (string) $history['message']
                                            )); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            <?php endif; ?>
                            <p><strong><?php esc_html_e('Technical identifiers', 'argentwolf-video-processor'); ?></strong><br>
                            <code><?php echo esc_html('video_id=' . (string) $row['video_id']); ?></code><br>
                            <?php if (is_array($row['latest_event']) && (int) $row['latest_event']['task_id'] > 0) : ?><code><?php echo esc_html('task_id=' . (string) $row['latest_event']['task_id']); ?></code><br><?php endif; ?>
                            <?php if ('' !== $row['operation_id']) : ?><code><?php echo esc_html('operation_id=' . (string) $row['operation_id']); ?></code><br><?php endif; ?>
                            <?php if ('' !== $row['remote_uuid']) : ?><code><?php echo esc_html('remote_uuid=' . (string) $row['remote_uuid']); ?></code><?php endif; ?>
                            </p>
                        </details>
                    </td>
                    <td>
                        <?php if (true === $row['resumable']) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:.5em">
                                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_RESUME); ?>">
                                <input type="hidden" name="video_id" value="<?php echo esc_attr((string) $row['video_id']); ?>">
                                <?php wp_nonce_field(self::ACTION_RESUME . ':' . (string) $row['video_id']); ?>
                                <?php submit_button(__('Resume', 'argentwolf-video-processor'), 'secondary small', 'submit', false); ?>
                            </form>
                        <?php elseif (true === $row['recovering']) : ?>
                            <p><?php esc_html_e('Automatic recovery active', 'argentwolf-video-processor'); ?></p>
                        <?php elseif ('' !== (string) ($row['action_url'] ?? '')) : ?>
                            <p><a href="<?php echo esc_url((string) $row['action_url']); ?>"><?php echo esc_html((string) $row['action_label']); ?></a></p>
                        <?php endif; ?>

                        <?php if (true === ($row['republish_available'] ?? false) && is_array($row['republish_targets'] ?? null) && array() !== $row['republish_targets']) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:.35em 0 .6em">
                                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_REPUBLISH); ?>">
                                <input type="hidden" name="video_id" value="<?php echo esc_attr((string) $row['video_id']); ?>">
                                <label>
                                    <span class="screen-reader-text"><?php esc_html_e('Republish backend', 'argentwolf-video-processor'); ?></span>
                                    <select name="backend_id">
                                        <?php foreach ($row['republish_targets'] as $target) : ?>
                                            <option value="<?php echo esc_attr((string) $target['id']); ?>"><?php echo esc_html((string) $target['label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <?php wp_nonce_field(self::ACTION_REPUBLISH . ':' . (string) $row['video_id']); ?>
                                <?php submit_button(__('Republish', 'argentwolf-video-processor'), 'secondary small', 'submit', false); ?>
                            </form>
                        <?php elseif (true === ($row['republish_source_missing'] ?? false)) : ?>
                            <p><em><?php esc_html_e('Republish unavailable: the original WordPress source is not retained.', 'argentwolf-video-processor'); ?></em></p>
                        <?php endif; ?>

                        <?php if (true === ($row['needs_attention'] ?? false) && '' !== (string) ($row['issue_fingerprint'] ?? '')) : ?>
                            <?php if ($reviewed) : ?>
                                <?php $this->render_disposition_button($row, self::ACTION_UNREVIEW, __('Move back to Needs Attention', 'argentwolf-video-processor')); ?>
                            <?php else : ?>
                                <?php $this->render_disposition_button($row, self::ACTION_REVIEW, __('Mark reviewed', 'argentwolf-video-processor')); ?>
                            <?php endif; ?>
                            <?php $this->render_disposition_button($row, self::ACTION_DISMISS, __('Remove from list', 'argentwolf-video-processor')); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /** @param array<string,mixed> $row */
    private function render_disposition_button(array $row, string $action, string $label): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:.15em .25em .15em 0">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="video_id" value="<?php echo esc_attr((string) $row['video_id']); ?>">
            <input type="hidden" name="fingerprint" value="<?php echo esc_attr((string) $row['issue_fingerprint']); ?>">
            <?php wp_nonce_field($action . ':' . (string) $row['video_id'] . ':' . (string) $row['issue_fingerprint']); ?>
            <?php submit_button($label, 'secondary small', 'submit', false); ?>
        </form>
        <?php
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

    public function republish_action(): void
    {
        if (! current_user_can('manage_options') || null === $this->republish) {
            wp_die(esc_html__('You are not allowed to republish ArgentWolf Video Processor media.', 'argentwolf-video-processor'));
        }
        $video_id = isset($_POST['video_id'])
            ? Video_Meta::sanitize_positive_id(sanitize_text_field(wp_unslash($_POST['video_id'])))
            : 0;
        $backend_id = isset($_POST['backend_id'])
            ? Backend_Identity::sanitize(sanitize_text_field(wp_unslash($_POST['backend_id'])))
            : '';
        check_admin_referer(self::ACTION_REPUBLISH . ':' . (string) $video_id);
        $result = $video_id > 0 && '' !== $backend_id
            ? $this->republish->request($video_id, $backend_id, get_current_user_id(), time())
            : array('status'=>Remote_Republish_Service::REFUSED);
        $queued = in_array((string) ($result['status'] ?? ''), array(Remote_Republish_Service::APPLIED, Remote_Republish_Service::PRESENT), true);
        wp_safe_redirect(Settings_Hub::tab_url(Settings_Hub::TAB_OVERVIEW, array('awvp_republish' => $queued ? 'queued' : 'refused')));
        exit;
    }

    public function disposition_action(string $state): void
    {
        if (! current_user_can('manage_options') || null === $this->dispositions) {
            wp_die(esc_html__('You are not allowed to change ArgentWolf Video Processor Overview items.', 'argentwolf-video-processor'));
        }
        $video_id = isset($_POST['video_id'])
            ? Video_Meta::sanitize_positive_id(sanitize_text_field(wp_unslash($_POST['video_id'])))
            : 0;
        $fingerprint = isset($_POST['fingerprint']) && is_string($_POST['fingerprint'])
            ? strtolower(sanitize_text_field(wp_unslash($_POST['fingerprint'])))
            : '';
        $action = match ($state) {
            Overview_Disposition_Store::REVIEWED => self::ACTION_REVIEW,
            Overview_Disposition_Store::DISMISSED => self::ACTION_DISMISS,
            default => self::ACTION_UNREVIEW,
        };
        check_admin_referer($action . ':' . (string) $video_id . ':' . $fingerprint);
        $current = false;
        foreach ($this->rows(time()) as $row) {
            if ($video_id === (int) ($row['video_id'] ?? 0) && hash_equals((string) ($row['issue_fingerprint'] ?? ''), $fingerprint)) {
                $current = true;
                break;
            }
        }
        if ($current) {
            if ('clear' === $state) {
                $this->dispositions->clear($fingerprint);
            } else {
                $this->dispositions->set($fingerprint, $state, $video_id, time());
            }
        }
        wp_safe_redirect(Settings_Hub::tab_url(Settings_Hub::TAB_OVERVIEW));
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
            $health_issue = $this->health_issue($video_id);
            $operation_phase = is_array($operation) ? (string) ($operation['phase'] ?? '') : '';
            $operation_issue = in_array($operation_phase, array(
                PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE,
                PeerTube_Staged_Upload_State_Machine::PHASE_FAILED,
            ), true);
            $attention = is_array($health_issue) || true === ($recovery['pending'] ?? false) || $operation_issue;
            $active = is_array($operation) && ! in_array($operation_phase, array(
                PeerTube_Staged_Upload_State_Machine::PHASE_COMPLETE,
                PeerTube_Staged_Upload_State_Machine::PHASE_FAILED,
                PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED,
            ), true);
            if (! $attention && ! $active) {
                continue;
            }
            $rows[] = $this->row($video_id, $execution, $operation, $recovery, $health_issue, $attention);
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    private function health_issue(int $video_id): ?array
    {
        if (null === $this->health) {
            return null;
        }
        $rows = $this->health->for_video($video_id);
        $problems = array_values(array_filter($rows, static function (array $row): bool {
            $status = (string) ($row['status'] ?? '');
            return Serving_Viability::PROCESSING !== $status
                && (Serving_Viability::HEALTHY !== $status || 1 !== (int) ($row['eligible'] ?? 0));
        }));
        if (null !== $this->backend_incidents) {
            $problems = array_values(array_filter($problems, function (array $row): bool {
                return ! (Serving_Viability::TEMPORARILY_UNAVAILABLE === (string) ($row['status'] ?? '')
                    && null !== $this->backend_incidents->get((string) ($row['backend_id'] ?? '')));
            }));
        }
        if (array() === $problems) {
            return null;
        }
        $rank = static function (array $row): int {
            return match ((string) ($row['status'] ?? '')) {
                Serving_Viability::MISSING => 60,
                Serving_Viability::PRIVATE_OR_RESTRICTED => 50,
                Serving_Viability::EMBED_DISALLOWED => 45,
                Serving_Viability::TEMPORARILY_UNAVAILABLE => 40,
                Serving_Viability::PROBE_INDETERMINATE => 30,
                Serving_Viability::HEALTHY => 10,
                default => 20,
            };
        };
        usort($problems, static fn (array $a, array $b): int => $rank($b) <=> $rank($a));
        return $problems[0];
    }

    /** @param array<string,mixed> $execution @param array<string,mixed>|null $operation @param array<string,mixed> $recovery @param array<string,mixed>|null $health_issue @return array<string,mixed> */
    private function row(int $video_id, array $execution, ?array $operation, array $recovery, ?array $health_issue, bool $attention): array
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
        $status_label = $this->status_label($phase, $recovery, $health_issue);
        $progress = '';
        if (is_array($operation)) {
            $total = (int) ($operation['source']['bytes'] ?? 0);
            $confirmed = (int) ($operation['confirmed_bytes'] ?? 0);
            if ($total > 0 && ! in_array($phase, array(PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED, PeerTube_Staged_Upload_State_Machine::PHASE_COMPLETE), true)) {
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
        $events = null === $this->events ? array() : $this->events->recent($video_id, 10);
        $latest_event = is_array($events[0] ?? null) ? $events[0] : null;
        $serving = null !== $this->serving ? $this->serving->serving_candidate($video_id) : array();
        $serving_label = '';
        if (is_array($health_issue) && array() !== $serving) {
            $serving_label = Backend_Registry::LOCAL_ID === (string) ($serving['backend_id'] ?? '')
                ? __('Currently serving the WordPress original.', 'argentwolf-video-processor')
                : sprintf(
                    /* translators: %s: internal backend identifier currently serving the video */
                    __('Currently serving backend %s.', 'argentwolf-video-processor'),
                    (string) ($serving['backend_id'] ?? '')
                );
        }
        $fingerprint = $attention ? $this->issue_fingerprint($video_id, $phase, $recovery, $health_issue, $operation) : '';
        $republish_statuses = array(Serving_Viability::MISSING, Serving_Viability::PRIVATE_OR_RESTRICTED, Serving_Viability::EMBED_DISALLOWED);
        $republish_problem = is_array($health_issue) && in_array((string) ($health_issue['status'] ?? ''), $republish_statuses, true);
        $republish_source = $republish_problem && null !== $this->republish ? $this->republish->source_available($video_id) : false;
        $republish_targets = $republish_source && null !== $this->republish ? $this->republish->targets() : array();

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
            'latest_event' => $latest_event,
            'events' => $events,
            'health_issue' => $health_issue,
            'serving_label' => $serving_label,
            'needs_attention' => $attention,
            'republish_available' => $republish_problem && $republish_source && array() !== $republish_targets,
            'republish_source_missing' => $republish_problem && ! $republish_source,
            'republish_targets' => $republish_targets,
            'issue_fingerprint' => $fingerprint,
            'action_url' => '',
            'action_label' => '',
        );
    }

    /** @param array<string,mixed> $recovery @param array<string,mixed>|null $health @param array<string,mixed>|null $operation */
    private function issue_fingerprint(int $video_id, string $phase, array $recovery, ?array $health, ?array $operation): string
    {
        if (is_array($health)) {
            $basis = array('health', $video_id, (int) ($health['remote_asset_id'] ?? 0), (string) ($health['backend_id'] ?? ''), (string) ($health['status'] ?? ''), (string) ($health['failure_since'] ?? ''), (int) ($health['eligible'] ?? 0));
        } elseif (true === ($recovery['pending'] ?? false)) {
            $basis = array('recovery', $video_id, (string) ($recovery['status'] ?? ''), (int) ($recovery['origin_at'] ?? 0), (int) ($recovery['expires_at'] ?? 0));
        } else {
            $basis = array('operation', $video_id, $phase, (string) ($operation['operation_id'] ?? ''), (int) ($operation['record_revision'] ?? 0), (string) ($operation['last_error']['code'] ?? ''));
        }
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($basis, JSON_UNESCAPED_SLASHES) : json_encode($basis, JSON_UNESCAPED_SLASHES);
        return hash('sha256', is_string($encoded) && '' !== $encoded ? $encoded : implode('|', array_map('strval', $basis)));
    }

    private static function pipeline_step_label(int $step): string
    {
        return match ($step) {
            1 => __('Prepare source', 'argentwolf-video-processor'),
            2 => __('Connect to backend', 'argentwolf-video-processor'),
            3 => __('Start upload', 'argentwolf-video-processor'),
            4 => __('Transfer video', 'argentwolf-video-processor'),
            5 => __('Remote processing', 'argentwolf-video-processor'),
            6 => __('Finalize publication', 'argentwolf-video-processor'),
            7 => __('Verify serving and finish', 'argentwolf-video-processor'),
            default => __('Unknown step', 'argentwolf-video-processor'),
        };
    }

    /** @param array<string,mixed> $recovery @param array<string,mixed>|null $health */
    private function status_label(string $phase, array $recovery, ?array $health): string
    {
        if (is_array($health)) {
            $status = (string) ($health['status'] ?? '');
            if (Serving_Viability::HEALTHY === $status && 1 !== (int) ($health['eligible'] ?? 0)) {
                return __('Remote publication recovering; fallback remains active', 'argentwolf-video-processor');
            }
            return match ($status) {
                Serving_Viability::MISSING => __('Remote publication is missing; fallback is active', 'argentwolf-video-processor'),
                Serving_Viability::PRIVATE_OR_RESTRICTED => __('Remote publication is private or restricted; fallback is active', 'argentwolf-video-processor'),
                Serving_Viability::EMBED_DISALLOWED => __('Remote publication cannot be embedded; fallback is active', 'argentwolf-video-processor'),
                Serving_Viability::TEMPORARILY_UNAVAILABLE => __('Remote publication is temporarily unavailable; fallback is active', 'argentwolf-video-processor'),
                default => __('Remote publication health is uncertain; fallback is active', 'argentwolf-video-processor'),
            };
        }
        if (true === ($recovery['pending'] ?? false)) {
            return true === ($recovery['eligible'] ?? false)
                ? __('Recovering incomplete publication', 'argentwolf-video-processor')
                : __('Publication needs attention', 'argentwolf-video-processor');
        }
        return match ($phase) {
            PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE => __('Upload outcome needs attention', 'argentwolf-video-processor'),
            PeerTube_Staged_Upload_State_Machine::PHASE_FAILED => __('Remote upload failed', 'argentwolf-video-processor'),
            PeerTube_Staged_Upload_State_Machine::PHASE_PROCESSING => __('Remote backend is processing the video', 'argentwolf-video-processor'),
            default => __('Remote publication in progress', 'argentwolf-video-processor'),
        };
    }
}

// EOF: includes/PeerTube_Overview_Admin.php
