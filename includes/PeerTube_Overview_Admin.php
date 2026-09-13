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
    public const ACTION_RESOLVE_UPLOAD = 'argentwolf_video_processor_overview_resolve_upload';
    public const ACTION_RESET_READINESS = 'argentwolf_video_processor_overview_reset_readiness';
    public const ACTION_CHECK_HEALTH = 'argentwolf_video_processor_overview_check_health';
    public const ACTION_RESTORE_HEALTH = 'argentwolf_video_processor_overview_restore_health';
    public const ACTION_REBUILD_LOCAL = 'argentwolf_video_processor_overview_rebuild_local';
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
        private readonly ?Remote_Republish_Service $republish = null,
        private readonly ?Backend_Processing_Estimator $processing_estimator = null,
        private readonly ?Backend_Registry $backend_registry = null,
        private readonly ?Remote_Health_Operator_Service $health_operator = null,
        private readonly ?Local_Delivery_Rebuild_Service $local_rebuild = null
    ) {
    }

    public function render_tab(): void
    {
        $now = time();
        $rows = $this->rows($now);
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
        <?php $this->render_backend_summary($rows, $now); ?>
        <?php $this->render_readiness_summary($now); ?>
        <h2><?php esc_html_e('Status & Needs Attention', 'argentwolf-video-processor'); ?></h2>
        <p><?php esc_html_e('Active publication work and current remote-serving problems appear here. Fixed conditions disappear automatically; reviewing or removing an item changes only this presentation and never deletes its task or diagnostic history.', 'argentwolf-video-processor'); ?></p>
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
        <?php
        if (isset($_GET['awvp_upload_resolution'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect status notice; no state change is performed from this query parameter.
            $resolution_notice = sanitize_key((string) wp_unslash($_GET['awvp_upload_resolution'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice; the resolution mutation is separately nonce-protected.
            ?>
            <div class="notice <?php echo 'resolved' === $resolution_notice ? 'notice-success' : 'notice-error'; ?>" style="margin:1em 0;padding:.5em 1em"><p>
                <?php echo esc_html('resolved' === $resolution_notice
                    ? __('The uncertain upload was retired after administrator confirmation. No remote request was sent; Republish is now available if the retained source and backend are usable.', 'argentwolf-video-processor')
                    : __('The uncertain upload was not retired. No new publication was started.', 'argentwolf-video-processor')); ?>
            </p></div>
        <?php endif; ?>
        <?php if (isset($_GET['awvp_health_check'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice after nonce-protected POST. ?>
            <?php $health_notice = sanitize_key((string) wp_unslash($_GET['awvp_health_check'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <div class="notice <?php echo 'healthy' === $health_notice ? 'notice-success' : ('checked' === $health_notice ? 'notice-warning' : 'notice-error'); ?>" style="margin:1em 0;padding:.5em 1em"><p>
                <?php echo esc_html(match ($health_notice) {
                    'healthy' => __('The remote publication passed a fresh visitor-facing check. You can now use this verified remote publication immediately if AWVP still needs serving authority or recovery confirmation.', 'argentwolf-video-processor'),
                    'checked' => __('The remote publication was checked again and is still not ready for remote serving.', 'argentwolf-video-processor'),
                    default => __('The remote serving check could not be completed safely. No serving change was made.', 'argentwolf-video-processor'),
                }); ?>
            </p></div>
        <?php endif; ?>
        <?php if (isset($_GET['awvp_health_restore'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice after nonce-protected POST. ?>
            <?php $restore_notice = sanitize_key((string) wp_unslash($_GET['awvp_health_restore'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <div class="notice <?php echo in_array($restore_notice, array('restored','already'), true) ? 'notice-success' : 'notice-error'; ?>" style="margin:1em 0;padding:.5em 1em"><p>
                <?php echo esc_html(match ($restore_notice) {
                    'restored' => __('The fresh successful check was applied and the verified remote publication is available for serving.', 'argentwolf-video-processor'),
                    'already' => __('The verified remote publication is already available for serving; no additional change was needed.', 'argentwolf-video-processor'),
                    default => __('The verified remote could not be selected safely. Run Check now again; if it still cannot be adopted, use Republish only when a new remote publication is intended.', 'argentwolf-video-processor'),
                }); ?>
            </p></div>
        <?php endif; ?>
        <?php if (isset($_GET['awvp_local_rebuild'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice after nonce-protected POST. ?>
            <?php $rebuild_notice = sanitize_key((string) wp_unslash($_GET['awvp_local_rebuild'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <div class="notice <?php echo in_array($rebuild_notice, array('queued','present'), true) ? 'notice-success' : 'notice-error'; ?>" style="margin:1em 0;padding:.5em 1em"><p>
                <?php echo esc_html(match ($rebuild_notice) {
                    'queued' => __('Local AWVP delivery rebuilding was queued from the retained WordPress source.', 'argentwolf-video-processor'),
                    'present' => __('Local AWVP delivery rebuilding is already queued or running.', 'argentwolf-video-processor'),
                    default => __('Local AWVP delivery rebuilding could not be queued. The remote publication history was not changed.', 'argentwolf-video-processor'),
                }); ?>
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

    /** @param list<array<string,mixed>> $attention_rows */
    private function render_backend_summary(array $attention_rows, int $now): void
    {
        $rows = $this->backend_summary($attention_rows, $now);
        if (array() === $rows) {
            return;
        }
        ?>
        <h2><?php esc_html_e('Backend Summary', 'argentwolf-video-processor'); ?></h2>
        <p><?php esc_html_e('A compact view of where AWVP videos are serving now and where publication work or remote-serving attention remains.', 'argentwolf-video-processor'); ?></p>
        <table class="widefat striped" style="max-width:900px;margin-bottom:1.5em">
            <thead><tr>
                <th><?php esc_html_e('Backend', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Serving now', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Healthy remote copies', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Active publication work', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Needs attention', 'argentwolf-video-processor'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <tr>
                    <td><strong><?php echo esc_html((string) $row['label']); ?></strong><br><code><?php echo esc_html((string) $row['backend_id']); ?></code></td>
                    <td><?php echo esc_html((string) $row['serving_now']); ?></td>
                    <td><?php echo esc_html(Backend_Registry::LOCAL_ID === (string) $row['backend_id'] ? '—' : (string) $row['healthy']); ?></td>
                    <td><?php echo esc_html(Backend_Registry::LOCAL_ID === (string) $row['backend_id'] ? '—' : (string) $row['active']); ?></td>
                    <td><?php echo esc_html((string) $row['needs_attention']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_readiness_summary(int $now): void
    {
        $rows = $this->readiness_summary($now);
        if (array() === $rows) {
            return;
        }
        if (isset($_GET['awvp_readiness_reset'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; reset mutation is separately nonce-protected.
            $notice = sanitize_key((string) wp_unslash($_GET['awvp_readiness_reset'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.
            ?>
            <div class="notice <?php echo 'complete' === $notice ? 'notice-success' : 'notice-error'; ?>" style="margin:1em 0;padding:.5em 1em"><p>
                <?php echo esc_html('complete' === $notice
                    ? __('Readiness statistics were reset. Publication/task/event/upload history was not changed.', 'argentwolf-video-processor')
                    : __('Readiness statistics could not be reset.', 'argentwolf-video-processor')); ?>
            </p></div>
        <?php endif; ?>
        <h2><?php esc_html_e('PeerTube Estimated Readiness', 'argentwolf-video-processor'); ?></h2>
        <p><?php esc_html_e('Estimated readiness uses recent upload-accepted to remote-ready observations. It is advisory: source duration, codec complexity, runner load, and server load can change actual processing time.', 'argentwolf-video-processor'); ?></p>
        <table class="widefat striped" style="max-width:1050px">
            <thead><tr>
                <th><?php esc_html_e('Backend', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Source size', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Estimated readiness', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Samples in band', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Confidence', 'argentwolf-video-processor'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <tr>
                    <td><strong><?php echo esc_html((string) $row['backend_label']); ?></strong><br><code><?php echo esc_html((string) $row['backend_id']); ?></code></td>
                    <td><?php echo esc_html($this->size_band_label((int) $row['min_bytes'], (int) $row['max_bytes'])); ?></td>
                    <td><?php echo esc_html(self::format_duration((int) $row['seconds'])); ?></td>
                    <td><?php echo esc_html((string) $row['same_bucket_count']); ?></td>
                    <td><?php echo esc_html(self::confidence_label((string) $row['confidence'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div style="margin:.75em 0 1.5em">
            <?php foreach ($this->readiness_backends() as $backend) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:.15em .35em .15em 0">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_RESET_READINESS); ?>">
                    <input type="hidden" name="backend_id" value="<?php echo esc_attr((string) $backend['backend_id']); ?>">
                    <?php wp_nonce_field(self::ACTION_RESET_READINESS); ?>
                    <?php submit_button(sprintf(
                        /* translators: %s: backend label */
                        __('Reset %s readiness statistics', 'argentwolf-video-processor'),
                        (string) $backend['label']
                    ), 'secondary small', 'submit', false); ?>
                </form>
            <?php endforeach; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:.15em .35em .15em 0">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_RESET_READINESS); ?>">
                <input type="hidden" name="backend_id" value="all">
                <?php wp_nonce_field(self::ACTION_RESET_READINESS); ?>
                <?php submit_button(__('Reset all readiness statistics', 'argentwolf-video-processor'), 'secondary small', 'submit', false); ?>
            </form>
        </div>
        <p class="description"><?php esc_html_e('Resetting readiness statistics clears only advisory estimator samples. It does not delete publication events, task history, upload journals, remote assets, or serving-health records.', 'argentwolf-video-processor'); ?></p>
        <?php
    }

    /**
     * @param list<array<string,mixed>> $attention_rows
     * @return list<array{backend_id:string,label:string,serving_now:int,healthy:int,active:int,needs_attention:int}>
     */
    public function backend_summary(array $attention_rows, int $now): array
    {
        unset($now);
        if (null === $this->serving) {
            return array();
        }
        $descriptors = null !== $this->backend_registry ? $this->backend_registry->all() : array();
        $summary = array();
        $ensure = static function (string $backend_id) use (&$summary, $descriptors): void {
            if (isset($summary[$backend_id])) {
                return;
            }
            $descriptor = is_array($descriptors[$backend_id] ?? null) ? $descriptors[$backend_id] : array();
            $label = is_string($descriptor['label'] ?? null) && '' !== trim((string) $descriptor['label'])
                ? trim((string) $descriptor['label'])
                : (Backend_Registry::LOCAL_ID === $backend_id ? 'Local AWVP' : $backend_id);
            $summary[$backend_id] = array(
                'backend_id' => $backend_id,
                'label' => $label,
                'serving_now' => 0,
                'healthy' => 0,
                'active' => 0,
                'needs_attention' => 0,
            );
        };
        $ensure(Backend_Registry::LOCAL_ID);
        foreach ($descriptors as $backend_id => $descriptor) {
            if (! is_string($backend_id) || '' === Backend_Identity::sanitize($backend_id) || ! is_array($descriptor)) {
                continue;
            }
            $ensure($backend_id);
        }

        $ids = get_posts(array(
            'post_type' => Video_Post_Type::POST_TYPE,
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ));
        $healthy_seen = array();
        if (is_array($ids)) {
            foreach ($ids as $raw_id) {
                $video_id = Video_Meta::sanitize_positive_id($raw_id);
                if ($video_id < 1) {
                    continue;
                }
                $candidate = $this->serving->serving_candidate($video_id);
                $backend_id = Backend_Identity::sanitize((string) ($candidate['backend_id'] ?? ''));
                if ('' !== $backend_id) {
                    $ensure($backend_id);
                    ++$summary[$backend_id]['serving_now'];
                }
                if (null !== $this->health) {
                    foreach ($this->health->for_video($video_id) as $health) {
                        $remote_backend = Backend_Identity::sanitize((string) ($health['backend_id'] ?? ''));
                        if ('' === $remote_backend || Backend_Registry::LOCAL_ID === $remote_backend
                            || Serving_Viability::HEALTHY !== (string) ($health['status'] ?? '')
                            || 1 !== (int) ($health['eligible'] ?? 0)) {
                            continue;
                        }
                        $key = $remote_backend . ':' . (string) $video_id;
                        if (isset($healthy_seen[$key])) {
                            continue;
                        }
                        $healthy_seen[$key] = true;
                        $ensure($remote_backend);
                        ++$summary[$remote_backend]['healthy'];
                    }
                }
            }
        }

        $open = $this->operations->open_operations();
        $active_seen = array();
        if (is_array($open)) {
            foreach ($open as $operation) {
                if (! is_array($operation)) {
                    continue;
                }
                $phase = (string) ($operation['phase'] ?? '');
                if (in_array($phase, array(
                    PeerTube_Staged_Upload_State_Machine::PHASE_COMPLETE,
                    PeerTube_Staged_Upload_State_Machine::PHASE_OPERATOR_ABANDONED,
                    PeerTube_Staged_Upload_State_Machine::PHASE_FAILED,
                    PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED,
                ), true)) {
                    continue;
                }
                $backend_id = Backend_Identity::sanitize((string) ($operation['backend_id'] ?? ''));
                $video_id = (int) ($operation['video_post_id'] ?? 0);
                if ('' === $backend_id || $video_id < 1) {
                    continue;
                }
                $key = $backend_id . ':' . (string) $video_id;
                if (isset($active_seen[$key])) {
                    continue;
                }
                $active_seen[$key] = true;
                $ensure($backend_id);
                ++$summary[$backend_id]['active'];
            }
        }

        $attention_seen = array();
        foreach ($attention_rows as $row) {
            if (true !== ($row['needs_attention'] ?? false)) {
                continue;
            }
            $backend_id = Backend_Identity::sanitize((string) ($row['backend_id'] ?? ''));
            $video_id = (int) ($row['video_id'] ?? 0);
            if ('' === $backend_id || $video_id < 1) {
                continue;
            }
            $key = $backend_id . ':' . (string) $video_id;
            if (isset($attention_seen[$key])) {
                continue;
            }
            $attention_seen[$key] = true;
            $ensure($backend_id);
            ++$summary[$backend_id]['needs_attention'];
        }

        uasort($summary, static function (array $a, array $b): int {
            if (Backend_Registry::LOCAL_ID === $a['backend_id']) return -1;
            if (Backend_Registry::LOCAL_ID === $b['backend_id']) return 1;
            return strcasecmp((string) $a['label'], (string) $b['label']);
        });
        return array_values($summary);
    }

    /** @return list<array<string,mixed>> */
    public function readiness_summary(int $now): array
    {
        if (null === $this->processing_estimator || $now < 1) {
            return array();
        }
        $out = array();
        foreach ($this->readiness_backends() as $backend) {
            foreach ($this->processing_estimator->summaries((string) $backend['backend_id'], $now) as $summary) {
                $out[] = array_merge($summary, array(
                    'backend_id' => (string) $backend['backend_id'],
                    'backend_label' => (string) $backend['label'],
                ));
            }
        }
        return $out;
    }

    /** @return list<array{backend_id:string,label:string}> */
    private function readiness_backends(): array
    {
        if (null === $this->backend_registry) {
            return array();
        }
        $out = array();
        foreach ($this->backend_registry->all() as $backend_id => $descriptor) {
            if (! is_string($backend_id) || ! is_array($descriptor)
                || Backend_Registry::LOCAL_ID === $backend_id
                || Backend_Registry::PEERTUBE_TYPE !== (string) ($descriptor['type'] ?? '')
                || 'active' !== (string) ($descriptor['state'] ?? '')) {
                continue;
            }
            $label = is_string($descriptor['label'] ?? null) && '' !== trim((string) $descriptor['label'])
                ? trim((string) $descriptor['label'])
                : $backend_id;
            $out[] = array('backend_id' => $backend_id, 'label' => $label);
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp((string) $a['label'], (string) $b['label']));
        return $out;
    }

    private function size_band_label(int $min_bytes, int $max_bytes): string
    {
        if ($max_bytes >= PHP_INT_MAX) {
            return sprintf(
                /* translators: %s: formatted source size lower bound */
                __('Larger than %s', 'argentwolf-video-processor'),
                size_format(max(1, $min_bytes - 1), 0)
            );
        }
        if ($min_bytes <= 1) {
            return sprintf(
                /* translators: %s: formatted source size upper bound */
                __('Up to %s', 'argentwolf-video-processor'),
                size_format($max_bytes, 0)
            );
        }
        return sprintf(
            /* translators: 1: formatted source size lower bound, 2: formatted source size upper bound */
            __('%1$s – %2$s', 'argentwolf-video-processor'),
            size_format(max(1, $min_bytes - 1), 0),
            size_format($max_bytes, 0)
        );
    }

    private static function confidence_label(string $confidence): string
    {
        return match ($confidence) {
            'high' => __('High', 'argentwolf-video-processor'),
            'medium' => __('Medium', 'argentwolf-video-processor'),
            default => __('Low', 'argentwolf-video-processor'),
        };
    }

    private static function format_duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;
        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $remaining)
            : sprintf('%d:%02d', $minutes, $remaining);
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
                                <?php if ('' !== (string) $event['created_at']) : ?><p><strong><?php esc_html_e('Latest event:', 'argentwolf-video-processor'); ?></strong> <?php echo esc_html(Settings_Hub::format_mysql_utc((string) $event['created_at'])); ?></p><?php endif; ?>
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
                                <?php $operator_guidance = '' !== (string) ($row['operator_guidance'] ?? '') ? (string) $row['operator_guidance'] : (string) $event['operator_action']; ?>
                                <?php if ('' !== $operator_guidance) : ?><p><strong><?php esc_html_e('Suggested operator action:', 'argentwolf-video-processor'); ?></strong> <?php echo esc_html($operator_guidance); ?></p><?php endif; ?>
                                <?php if (array() !== $row['events']) : ?>
                                    <p><strong><?php esc_html_e('Recent activity', 'argentwolf-video-processor'); ?></strong></p>
                                    <ul>
                                        <?php foreach ($row['events'] as $history) : ?>
                                            <li><?php echo esc_html(sprintf(
                                                /* translators: 1: timestamp, 2: pipeline step, 3: event message */
                                                __('%1$s — Step %2$d of 7 — %3$s', 'argentwolf-video-processor'),
                                                Settings_Hub::format_mysql_utc((string) $history['created_at']),
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
                                <?php submit_button(
                                    'finalizer_retry' === (string) ($row['recovery_status'] ?? '')
                                        ? __('Retry publication', 'argentwolf-video-processor')
                                        : __('Resume', 'argentwolf-video-processor'),
                                    'secondary small',
                                    'submit',
                                    false
                                ); ?>
                            </form>
                        <?php elseif (true === $row['recovering']) : ?>
                            <p><?php esc_html_e('Automatic recovery active', 'argentwolf-video-processor'); ?></p>
                        <?php elseif ('' !== (string) ($row['action_url'] ?? '')) : ?>
                            <p><a href="<?php echo esc_url((string) $row['action_url']); ?>"><?php echo esc_html((string) $row['action_label']); ?></a></p>
                        <?php endif; ?>

                        <?php if (true === ($row['upload_resolution_available'] ?? false)) : ?>
                            <div class="notice notice-warning inline" style="margin:.35em 0 .6em;padding:.55em .75em">
                                <p><strong><?php esc_html_e('Uncertain upload initialization', 'argentwolf-video-processor'); ?></strong></p>
                                <p><?php esc_html_e('AWVP cannot safely determine whether PeerTube created a remote video when this upload start was interrupted. Check the selected PeerTube server for a matching video before retiring this attempt.', 'argentwolf-video-processor'); ?></p>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_RESOLVE_UPLOAD); ?>">
                                    <input type="hidden" name="video_id" value="<?php echo esc_attr((string) $row['video_id']); ?>">
                                    <input type="hidden" name="operation_id" value="<?php echo esc_attr((string) $row['operation_id']); ?>">
                                    <label>
                                        <input type="checkbox" name="confirmed_no_remote" value="1" required>
                                        <?php esc_html_e('I checked PeerTube and confirmed that no matching remote video exists. I understand that starting a new publication without this check could create a duplicate.', 'argentwolf-video-processor'); ?>
                                    </label>
                                    <?php wp_nonce_field(self::ACTION_RESOLVE_UPLOAD . ':' . (string) $row['video_id'] . ':' . (string) $row['operation_id']); ?>
                                    <p><?php submit_button(__('Retire uncertain upload', 'argentwolf-video-processor'), 'secondary small', 'submit', false); ?></p>
                                </form>
                            </div>
                        <?php endif; ?>

                        <?php if (true === ($row['health_check_available'] ?? false)) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:.35em 0 .4em">
                                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_CHECK_HEALTH); ?>">
                                <input type="hidden" name="video_id" value="<?php echo esc_attr((string) $row['video_id']); ?>">
                                <input type="hidden" name="remote_asset_id" value="<?php echo esc_attr((string) $row['remote_asset_id']); ?>">
                                <?php wp_nonce_field(self::ACTION_CHECK_HEALTH . ':' . (string) $row['video_id'] . ':' . (string) $row['remote_asset_id']); ?>
                                <?php submit_button(__('Check now', 'argentwolf-video-processor'), 'secondary small', 'submit', false); ?>
                            </form>
                        <?php endif; ?>
                        <?php if (true === ($row['health_restore_available'] ?? false)) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:.35em 0 .6em">
                                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_RESTORE_HEALTH); ?>">
                                <input type="hidden" name="video_id" value="<?php echo esc_attr((string) $row['video_id']); ?>">
                                <input type="hidden" name="remote_asset_id" value="<?php echo esc_attr((string) $row['remote_asset_id']); ?>">
                                <?php wp_nonce_field(self::ACTION_RESTORE_HEALTH . ':' . (string) $row['video_id'] . ':' . (string) $row['remote_asset_id']); ?>
                                <?php submit_button(__('Use verified remote now', 'argentwolf-video-processor'), 'primary small', 'submit', false); ?>
                            </form>
                        <?php endif; ?>
                        <?php if (true === ($row['local_rebuild_available'] ?? false)) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:.35em 0 .6em">
                                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_REBUILD_LOCAL); ?>">
                                <input type="hidden" name="video_id" value="<?php echo esc_attr((string) $row['video_id']); ?>">
                                <?php wp_nonce_field(self::ACTION_REBUILD_LOCAL . ':' . (string) $row['video_id']); ?>
                                <?php submit_button(__('Rebuild local delivery', 'argentwolf-video-processor'), 'secondary small', 'submit', false); ?>
                            </form>
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
                                <?php submit_button(__('Republish as new publication', 'argentwolf-video-processor'), 'secondary small', 'submit', false); ?>
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

    public function check_health_action(): void
    {
        if (! current_user_can('manage_options') || null === $this->health_operator) {
            wp_die(esc_html__('You are not allowed to run remote serving checks.', 'argentwolf-video-processor'));
        }
        $video_id = isset($_POST['video_id']) ? absint(wp_unslash($_POST['video_id'])) : 0;
        $remote_asset_id = isset($_POST['remote_asset_id']) ? absint(wp_unslash($_POST['remote_asset_id'])) : 0;
        if ($video_id < 1 || $remote_asset_id < 1 || ! current_user_can('edit_post', $video_id)) {
            wp_die(esc_html__('The selected remote publication cannot be checked.', 'argentwolf-video-processor'));
        }
        check_admin_referer(self::ACTION_CHECK_HEALTH . ':' . (string) $video_id . ':' . (string) $remote_asset_id);
        $result = $this->health_operator->check_now($video_id, $remote_asset_id, get_current_user_id(), time());
        $notice = Remote_Health_Operator_Service::APPLIED === ($result['status'] ?? '')
            ? (Serving_Viability::HEALTHY === ($result['viability_status'] ?? '') ? 'healthy' : 'checked')
            : 'refused';
        wp_safe_redirect(Settings_Hub::tab_url(Settings_Hub::TAB_OVERVIEW, array('awvp_health_check' => $notice)));
        exit;
    }

    public function restore_health_action(): void
    {
        if (! current_user_can('manage_options') || null === $this->health_operator) {
            wp_die(esc_html__('You are not allowed to restore remote serving.', 'argentwolf-video-processor'));
        }
        $video_id = isset($_POST['video_id']) ? absint(wp_unslash($_POST['video_id'])) : 0;
        $remote_asset_id = isset($_POST['remote_asset_id']) ? absint(wp_unslash($_POST['remote_asset_id'])) : 0;
        if ($video_id < 1 || $remote_asset_id < 1 || ! current_user_can('edit_post', $video_id)) {
            wp_die(esc_html__('The selected remote publication cannot be restored.', 'argentwolf-video-processor'));
        }
        check_admin_referer(self::ACTION_RESTORE_HEALTH . ':' . (string) $video_id . ':' . (string) $remote_asset_id);
        $result = $this->health_operator->restore_now($video_id, $remote_asset_id, get_current_user_id(), time());
        $notice = match ((string) ($result['status'] ?? '')) {
            Remote_Health_Operator_Service::APPLIED => 'restored',
            Remote_Health_Operator_Service::PRESENT => 'already',
            default => 'refused',
        };
        wp_safe_redirect(Settings_Hub::tab_url(Settings_Hub::TAB_OVERVIEW, array('awvp_health_restore' => $notice)));
        exit;
    }

    public function rebuild_local_action(): void
    {
        if (! current_user_can('manage_options') || null === $this->local_rebuild) {
            wp_die(esc_html__('You are not allowed to rebuild local video delivery.', 'argentwolf-video-processor'));
        }
        $video_id = isset($_POST['video_id']) ? absint(wp_unslash($_POST['video_id'])) : 0;
        if ($video_id < 1 || ! current_user_can('edit_post', $video_id)) {
            wp_die(esc_html__('The selected AWVP Video cannot be rebuilt locally.', 'argentwolf-video-processor'));
        }
        check_admin_referer(self::ACTION_REBUILD_LOCAL . ':' . (string) $video_id);
        $result = $this->local_rebuild->request($video_id, get_current_user_id(), time());
        $notice = match ((string) ($result['status'] ?? '')) {
            Local_Delivery_Rebuild_Service::APPLIED => 'queued',
            Local_Delivery_Rebuild_Service::PRESENT => 'present',
            default => 'refused',
        };
        wp_safe_redirect(Settings_Hub::tab_url(Settings_Hub::TAB_OVERVIEW, array('awvp_local_rebuild' => $notice)));
        exit;
    }

    public function resolve_indeterminate_upload_action(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to resolve ArgentWolf Video Processor uploads.', 'argentwolf-video-processor'));
        }
        $video_id = isset($_POST['video_id'])
            ? Video_Meta::sanitize_positive_id(sanitize_text_field(wp_unslash($_POST['video_id'])))
            : 0;
        $operation_id = isset($_POST['operation_id'])
            ? strtolower(sanitize_text_field(wp_unslash($_POST['operation_id'])))
            : '';
        if (1 !== preg_match('/^upload_[a-f0-9]{32}$/D', $operation_id)) {
            $operation_id = '';
        }
        check_admin_referer(self::ACTION_RESOLVE_UPLOAD . ':' . (string) $video_id . ':' . $operation_id);

        $confirmed = isset($_POST['confirmed_no_remote'])
            && '1' === sanitize_text_field(wp_unslash($_POST['confirmed_no_remote']));
        $resolved = false;
        $actor_id = get_current_user_id();
        $now = time();
        if ($video_id > 0 && '' !== $operation_id && $confirmed && $actor_id > 0) {
            $execution = PeerTube_Publication_Execution::sanitize(
                get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_EXECUTION, true)
            );
            $operation = $this->operations->get($operation_id);
            if ($operation_id === (string) ($execution['operation_id'] ?? '')
                && self::operator_resolution_available($operation)) {
                $result = $this->operations->apply_event(
                    $operation_id,
                    (int) $operation['record_revision'],
                    PeerTube_Staged_Upload_State_Machine::EVENT_OPERATOR_ABANDON,
                    array('confirmed_no_remote' => true),
                    $now
                );
                $after = $this->operations->get($operation_id);
                $resolved = Atomic_Option_Result::APPLIED === $result->status()
                    && is_array($after)
                    && PeerTube_Staged_Upload_State_Machine::PHASE_OPERATOR_ABANDONED === ($after['phase'] ?? null);
                if ($resolved && null !== $this->events) {
                    $this->events->record(
                        $video_id,
                        3,
                        'upload_indeterminate_operator_abandoned',
                        'warning',
                        'Administrator confirmed no matching remote video exists and retired the uncertain upload attempt.',
                        $now,
                        0,
                        $operation_id,
                        0,
                        (string) ($operation['backend_id'] ?? ''),
                        (int) ($operation['last_error']['http_status'] ?? 0),
                        'AWVP preserved the old upload journal and unlocked explicit Republish; no remote request was sent.',
                        'Republish only if a new remote publication is intended.',
                        array('operator_user_id' => $actor_id, 'confirmed_no_remote' => true)
                    );
                }
            }
        }

        wp_safe_redirect(Settings_Hub::tab_url(
            Settings_Hub::TAB_OVERVIEW,
            array('awvp_upload_resolution' => $resolved ? 'resolved' : 'refused')
        ));
        exit;
    }

    public function reset_readiness_action(): void
    {
        if (! current_user_can('manage_options') || null === $this->processing_estimator) {
            wp_die(esc_html__('You are not allowed to reset ArgentWolf Video Processor readiness statistics.', 'argentwolf-video-processor'));
        }
        check_admin_referer(self::ACTION_RESET_READINESS);
        $backend_id = isset($_POST['backend_id'])
            ? sanitize_text_field(wp_unslash($_POST['backend_id']))
            : '';
        $backend_id = 'all' === $backend_id ? 'all' : Backend_Identity::sanitize($backend_id);
        $complete = false;
        if ('all' === $backend_id) {
            $complete = $this->processing_estimator->reset(null, time());
        } elseif ('' !== $backend_id && Backend_Registry::LOCAL_ID !== $backend_id) {
            $complete = $this->processing_estimator->reset($backend_id, time());
        }
        wp_safe_redirect(Settings_Hub::tab_url(
            Settings_Hub::TAB_OVERVIEW,
            array('awvp_readiness_reset' => $complete ? 'complete' : 'failed')
        ));
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
                PeerTube_Staged_Upload_State_Machine::PHASE_OPERATOR_ABANDONED,
                PeerTube_Staged_Upload_State_Machine::PHASE_FAILED,
            ), true);
            $attention = is_array($health_issue) || true === ($recovery['pending'] ?? false) || $operation_issue;
            $active = is_array($operation) && ! in_array($operation_phase, array(
                PeerTube_Staged_Upload_State_Machine::PHASE_COMPLETE,
                PeerTube_Staged_Upload_State_Machine::PHASE_OPERATOR_ABANDONED,
                PeerTube_Staged_Upload_State_Machine::PHASE_FAILED,
                PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED,
            ), true);
            if (! $attention && ! $active) {
                continue;
            }
            $rows[] = $this->row($video_id, $execution, $operation, $recovery, $health_issue, $attention, $now);
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
        $authority = Video_Serving_Authority::sanitize(
            get_post_meta($video_id, Video_Meta::SERVING_AUTHORITY, true)
        );
        $execution = PeerTube_Publication_Execution::sanitize(
            get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_EXECUTION, true)
        );
        $execution_asset_id = (int) ($execution['remote_asset_id'] ?? 0);
        $problems = array_values(array_filter($rows, static function (array $row) use ($video_id, $authority, $execution_asset_id): bool {
            $status = (string) ($row['status'] ?? '');
            $backend_id = Backend_Identity::sanitize((string) ($row['backend_id'] ?? ''));
            $remote_asset_id = (int) ($row['remote_asset_id'] ?? 0);
            $last_healthy_at = is_string($row['last_healthy_at'] ?? null)
                ? (string) $row['last_healthy_at']
                : '';
            $previously_qualified = '' !== $last_healthy_at
                || (array() !== $authority
                    && $video_id === (int) ($row['video_post_id'] ?? 0)
                    && $remote_asset_id === (int) ($authority['remote_asset_id'] ?? 0)
                    && $backend_id === (string) ($authority['backend_id'] ?? ''));
            $authority_gap = Serving_Viability::HEALTHY === $status
                && 1 === (int) ($row['eligible'] ?? 0)
                && $execution_asset_id > 0
                && $execution_asset_id === $remote_asset_id
                && (array() === $authority || $remote_asset_id !== (int) ($authority['remote_asset_id'] ?? 0));
            return $previously_qualified
                && Serving_Viability::PROCESSING !== $status
                && (Serving_Viability::HEALTHY !== $status || 1 !== (int) ($row['eligible'] ?? 0) || $authority_gap);
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
    private function row(int $video_id, array $execution, ?array $operation, array $recovery, ?array $health_issue, bool $attention, int $now): array
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
        $upload_resolution_available = self::operator_resolution_available($operation);
        $upload_resolution_complete = PeerTube_Staged_Upload_State_Machine::PHASE_OPERATOR_ABANDONED === $phase;
        $authority_gap = is_array($health_issue)
            && Serving_Viability::HEALTHY === (string) ($health_issue['status'] ?? '')
            && 1 === (int) ($health_issue['eligible'] ?? 0);
        $republish_problem = (is_array($health_issue) && in_array((string) ($health_issue['status'] ?? ''), $republish_statuses, true))
            || $authority_gap
            || $upload_resolution_complete;
        $republish_source = $republish_problem && null !== $this->republish ? $this->republish->source_available($video_id) : false;
        $republish_targets = $republish_source && null !== $this->republish ? $this->republish->targets() : array();

        $backend_id = is_array($health_issue)
            ? Backend_Identity::sanitize((string) ($health_issue['backend_id'] ?? ''))
            : Backend_Identity::sanitize((string) ($operation['backend_id'] ?? ''));
        if ('' === $backend_id) {
            $destination = Video_Destination::resolve(
                get_post_meta($video_id, Video_Meta::DESTINATION, true),
                metadata_exists('post', $video_id, Video_Meta::DESTINATION)
            );
            $backend_id = Backend_Identity::sanitize((string) ($destination['backend_id'] ?? ''));
        }

        $execution_remote_asset_id = (int) ($execution['remote_asset_id'] ?? 0);
        $remote_asset_id = is_array($health_issue)
            ? (int) ($health_issue['remote_asset_id'] ?? 0)
            : $execution_remote_asset_id;
        $checkable_statuses = array(
            Serving_Viability::HEALTHY,
            Serving_Viability::MISSING,
            Serving_Viability::PRIVATE_OR_RESTRICTED,
            Serving_Viability::EMBED_DISALLOWED,
            Serving_Viability::TEMPORARILY_UNAVAILABLE,
            Serving_Viability::PROBE_INDETERMINATE,
        );
        $terminal_finalizer = 'finalizer_terminal' === (string) ($recovery['status'] ?? '');
        $terminal_authority_gap = false;
        if ($terminal_finalizer && $execution_remote_asset_id > 0) {
            $authority = Video_Serving_Authority::sanitize(
                get_post_meta($video_id, Video_Meta::SERVING_AUTHORITY, true)
            );
            $terminal_authority_gap = array() === $authority
                || $execution_remote_asset_id !== (int) ($authority['remote_asset_id'] ?? 0);
        }
        $health_check_available = null !== $this->health_operator && $remote_asset_id > 0
            && (
                (is_array($health_issue) && in_array((string) ($health_issue['status'] ?? ''), $checkable_statuses, true))
                || $terminal_authority_gap
            );
        $health_restore_available = null !== $this->health_operator && $remote_asset_id > 0
            && $this->health_operator->restore_available($video_id, $remote_asset_id, $now);
        $local_rebuild_available = null !== $this->local_rebuild && is_array($health_issue)
            && $this->local_rebuild->source_available($video_id);
        $operator_guidance = $terminal_authority_gap
            ? __('Run Check now to verify the existing PeerTube publication. If it passes, use Use verified remote now. Republish only when a new remote publication is intended.', 'argentwolf-video-processor')
            : '';

        return array(
            'video_id' => $video_id,
            'backend_id' => $backend_id,
            'remote_asset_id' => $remote_asset_id,
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
            'recovery_status' => (string) ($recovery['status'] ?? ''),
            'latest_event' => $latest_event,
            'events' => $events,
            'health_issue' => $health_issue,
            'serving_label' => $serving_label,
            'needs_attention' => $attention,
            'upload_resolution_available' => $upload_resolution_available,
            'republish_available' => $republish_problem && $republish_source && array() !== $republish_targets,
            'republish_source_missing' => $republish_problem && ! $republish_source,
            'republish_targets' => $republish_targets,
            'health_check_available' => $health_check_available,
            'health_restore_available' => $health_restore_available,
            'local_rebuild_available' => $local_rebuild_available,
            'operator_guidance' => $operator_guidance,
            'issue_fingerprint' => $fingerprint,
            'action_url' => '',
            'action_label' => '',
        );
    }

    /** @param array<string,mixed>|null $operation */
    private static function operator_resolution_available(?array $operation): bool
    {
        return is_array($operation)
            && PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE === ($operation['phase'] ?? null)
            && PeerTube_Staged_Upload_State_Machine::REQUEST_INIT === ($operation['request_kind'] ?? null)
            && '' === (string) ($operation['upload_session_id'] ?? '')
            && 0 === (int) ($operation['confirmed_bytes'] ?? -1)
            && 0 === (int) ($operation['remote_asset_id'] ?? -1)
            && array('id' => '', 'uuid' => '') === ($operation['remote_identity'] ?? null);
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
            if (Serving_Viability::HEALTHY === $status && 1 === (int) ($health['eligible'] ?? 0)) {
                return __('Verified remote publication needs serving authority', 'argentwolf-video-processor');
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
            if ('finalizer_retry' === (string) ($recovery['status'] ?? '')) {
                return __('Publication finalization is ready to retry', 'argentwolf-video-processor');
            }
            if ('finalizer_missing' === (string) ($recovery['status'] ?? '')) {
                return __('Remote video is ready; AWVP is restoring the missing finish step', 'argentwolf-video-processor');
            }
            if ('finalizer_terminal' === (string) ($recovery['status'] ?? '')) {
                return __('Remote video is ready, but publication stopped before serving', 'argentwolf-video-processor');
            }
            return true === ($recovery['eligible'] ?? false)
                ? __('Recovering incomplete publication', 'argentwolf-video-processor')
                : __('Publication needs attention', 'argentwolf-video-processor');
        }
        return match ($phase) {
            PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE => __('Upload outcome needs attention', 'argentwolf-video-processor'),
            PeerTube_Staged_Upload_State_Machine::PHASE_OPERATOR_ABANDONED => __('Uncertain upload retired; ready to republish', 'argentwolf-video-processor'),
            PeerTube_Staged_Upload_State_Machine::PHASE_FAILED => __('Remote upload failed', 'argentwolf-video-processor'),
            PeerTube_Staged_Upload_State_Machine::PHASE_PROCESSING => __('Remote backend is processing the video', 'argentwolf-video-processor'),
            default => __('Remote publication in progress', 'argentwolf-video-processor'),
        };
    }
}

// EOF: includes/PeerTube_Overview_Admin.php
