<?php
/**
 * File: includes/Publication_History_Admin.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Read-only administrator view over the retained bounded publication/event history. */
final class Publication_History_Admin
{
    private const MAX_ROWS = 100;

    /** @var array<int,array<string,mixed>> */
    private array $serving_cache = array();

    public function __construct(
        private readonly PeerTube_Event_Repository $events,
        private readonly PeerTube_Remote_Asset_Store $assets,
        private readonly Video_Serving_Service $serving,
        private readonly Backend_Registry $registry
    ) {
    }

    public function render_tab(): void
    {
        $rows = $this->filtered_rows($this->group_rows($this->events->recent_global(self::MAX_ROWS)));
        $search = self::query_text('history_search', 120);
        $backend = self::query_token('history_backend', 191);
        $outcome = self::query_token('history_outcome', 32);
        $days = self::query_token('history_days', 3);
        ?>
        <h2><?php esc_html_e('Publication History & Logs', 'argentwolf-video-processor'); ?></h2>
        <p><?php esc_html_e('This read-only view shows the newest retained AWVP publication events, including completed, failed, and recovered work. It does not resume tasks or change remote media. Event retention remains limited by AWVP diagnostics policy.', 'argentwolf-video-processor'); ?></p>
        <form method="get" action="<?php echo esc_url(admin_url('options-general.php')); ?>" style="margin:1em 0">
            <input type="hidden" name="page" value="<?php echo esc_attr(Settings_Hub::PAGE_SLUG); ?>">
            <input type="hidden" name="tab" value="<?php echo esc_attr(Settings_Hub::TAB_HISTORY); ?>">
            <label>
                <span class="screen-reader-text"><?php esc_html_e('Search publication history', 'argentwolf-video-processor'); ?></span>
                <input type="search" name="history_search" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Video, post, backend, event…', 'argentwolf-video-processor'); ?>">
            </label>
            <label>
                <span class="screen-reader-text"><?php esc_html_e('Filter by backend', 'argentwolf-video-processor'); ?></span>
                <select name="history_backend">
                    <option value=""><?php esc_html_e('All backends', 'argentwolf-video-processor'); ?></option>
                    <?php foreach ($this->backend_choices() as $backend_id => $label) : ?>
                        <option value="<?php echo esc_attr($backend_id); ?>" <?php selected($backend, $backend_id); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="screen-reader-text"><?php esc_html_e('Filter by outcome', 'argentwolf-video-processor'); ?></span>
                <select name="history_outcome">
                    <?php foreach (self::outcome_choices() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($outcome, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="screen-reader-text"><?php esc_html_e('Filter by event date', 'argentwolf-video-processor'); ?></span>
                <select name="history_days">
                    <?php foreach (self::date_choices() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($days, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php submit_button(__('Filter history', 'argentwolf-video-processor'), 'secondary', 'submit', false); ?>
            <?php if ('' !== $search || '' !== $backend || '' !== $outcome || '' !== $days) : ?>
                <a class="button" href="<?php echo esc_url(Settings_Hub::tab_url(Settings_Hub::TAB_HISTORY)); ?>"><?php esc_html_e('Clear filters', 'argentwolf-video-processor'); ?></a>
            <?php endif; ?>
        </form>

        <?php if (array() === $rows) : ?>
            <p><em><?php esc_html_e('No retained publication events match these filters.', 'argentwolf-video-processor'); ?></em></p>
            <?php return; ?>
        <?php endif; ?>

        <table class="widefat striped" style="max-width:1400px">
            <thead><tr>
                <th><?php esc_html_e('Video / WordPress post', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Backend', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Outcome', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Current serving', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Last event', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Details & log', 'argentwolf-video-processor'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <?php $identity = $this->video_identity((int) $row['video_post_id']); ?>
                <?php $remote = $this->remote_identity($row); ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($identity['label']); ?></strong>
                        <?php if ('' !== $identity['filename']) : ?><br><code><?php echo esc_html($identity['filename']); ?></code><?php endif; ?>
                        <?php if ($identity['origin_post_id'] > 0) : ?>
                            <br><?php echo esc_html($identity['origin_title']); ?>
                            <?php if ('' !== $identity['origin_edit_url']) : ?>
                                — <a href="<?php echo esc_url($identity['origin_edit_url']); ?>"><?php esc_html_e('Edit post', 'argentwolf-video-processor'); ?></a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($this->backend_label((string) $row['backend_id'])); ?></td>
                    <td><strong><?php echo esc_html(self::outcome_label(self::outcome($row))); ?></strong><br><code><?php echo esc_html((string) $row['event_code']); ?></code></td>
                    <td><?php echo esc_html($this->serving_label((int) $row['video_post_id'])); ?></td>
                    <td><?php echo esc_html(Settings_Hub::format_mysql_utc((string) $row['created_at'])); ?></td>
                    <td>
                        <details>
                            <summary><?php esc_html_e('View event', 'argentwolf-video-processor'); ?></summary>
                            <?php foreach (($row['_events'] ?? array($row)) as $event) : ?>
                                <div style="margin:.75em 0;padding-bottom:.75em;border-bottom:1px solid #ddd">
                                    <p><strong><?php echo esc_html(Settings_Hub::format_mysql_utc((string) $event['created_at'])); ?> — <?php echo esc_html(self::pipeline_step_label((int) $event['pipeline_step'])); ?></strong><br><code><?php echo esc_html((string) $event['event_code']); ?></code></p>
                                    <p><?php echo esc_html((string) $event['message']); ?></p>
                                    <?php if ('' !== (string) $event['automatic_action']) : ?><p><strong><?php esc_html_e('AWVP action:', 'argentwolf-video-processor'); ?></strong> <?php echo esc_html((string) $event['automatic_action']); ?></p><?php endif; ?>
                                    <?php if ('' !== (string) $event['operator_action']) : ?><p><strong><?php esc_html_e('Operator action:', 'argentwolf-video-processor'); ?></strong> <?php echo esc_html((string) $event['operator_action']); ?></p><?php endif; ?>
                                    <?php if ((int) $event['http_status'] > 0) : ?><p><strong>HTTP:</strong> <?php echo esc_html((string) $event['http_status']); ?></p><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if ((int) $row['task_id'] > 0) : ?><p><strong><?php esc_html_e('Task:', 'argentwolf-video-processor'); ?></strong> <code><?php echo esc_html((string) $row['task_id']); ?></code></p><?php endif; ?>
                            <?php if ('' !== (string) $row['operation_id']) : ?><p><strong><?php esc_html_e('Upload operation:', 'argentwolf-video-processor'); ?></strong> <code><?php echo esc_html((string) $row['operation_id']); ?></code></p><?php endif; ?>
                            <?php if ((int) $row['remote_asset_id'] > 0) : ?><p><strong><?php esc_html_e('Remote asset:', 'argentwolf-video-processor'); ?></strong> <code><?php echo esc_html((string) $row['remote_asset_id']); ?></code><?php if ('' !== $remote['url']) : ?> — <a href="<?php echo esc_url($remote['url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open remote video', 'argentwolf-video-processor'); ?></a><?php endif; ?></p><?php endif; ?>
                            <?php if ('' !== $remote['remote_id']) : ?><p><strong><?php esc_html_e('Remote ID:', 'argentwolf-video-processor'); ?></strong> <code><?php echo esc_html($remote['remote_id']); ?></code></p><?php endif; ?>
                            <?php if (isset($row['context']['source_bytes']) && (int) $row['context']['source_bytes'] > 0) : ?><p><strong><?php esc_html_e('Source size:', 'argentwolf-video-processor'); ?></strong> <?php echo esc_html(size_format((int) $row['context']['source_bytes'])); ?></p><?php endif; ?>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /** @param list<array<string,mixed>> $events @return list<array<string,mixed>> */
    private function group_rows(array $events): array
    {
        $groups = array();
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $video_id = (int) ($event['video_post_id'] ?? 0);
            $operation_id = (string) ($event['operation_id'] ?? '');
            $task_id = (int) ($event['task_id'] ?? 0);
            $key = $video_id . ':' . ('' !== $operation_id ? 'op:' . $operation_id : ($task_id > 0 ? 'task:' . $task_id : 'event:' . (string) ($event['id'] ?? 0)));
            if (! isset($groups[$key])) {
                $event['_events'] = array();
                $groups[$key] = $event;
            }
            $groups[$key]['_events'][] = $event;
        }
        return array_values($groups);
    }

    /** @param list<array<string,mixed>> $events @return list<array<string,mixed>> */
    private function filtered_rows(array $events): array
    {
        $search = strtolower(self::query_text('history_search', 120));
        $backend = self::query_token('history_backend', 191);
        $outcome = self::query_token('history_outcome', 32);
        $days = self::query_token('history_days', 3);
        $window_days = in_array($days, array('7','30','90'), true) ? (int) $days : 0;
        $cutoff = $window_days > 0 ? time() - ($window_days * DAY_IN_SECONDS) : 0;
        $result = array();
        foreach ($events as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ('' !== $backend && $backend !== (string) ($row['backend_id'] ?? '')) {
                continue;
            }
            if ('' !== $outcome && $outcome !== self::outcome($row)) {
                continue;
            }
            if ($cutoff > 0) {
                $created = strtotime((string) ($row['created_at'] ?? '') . ' UTC');
                if (false === $created || $created < $cutoff) {
                    continue;
                }
            }
            if ('' !== $search) {
                $identity = $this->video_identity((int) ($row['video_post_id'] ?? 0));
                $event_text = array();
                foreach (($row['_events'] ?? array($row)) as $event) {
                    if (is_array($event)) {
                        $event_text[] = (string) ($event['event_code'] ?? '');
                        $event_text[] = (string) ($event['message'] ?? '');
                    }
                }
                $haystack = strtolower(implode(' ', array_merge(array(
                    $identity['label'], $identity['filename'], $identity['origin_title'],
                    (string) ($row['backend_id'] ?? ''), (string) ($row['operation_id'] ?? ''),
                ), $event_text)));
                if (! str_contains($haystack, $search)) {
                    continue;
                }
            }
            $result[] = $row;
        }
        return $result;
    }

    /** @return array<string,string> */
    private function backend_choices(): array
    {
        $choices = array();
        foreach ($this->registry->all() as $backend_id => $descriptor) {
            if (! is_string($backend_id) || ! is_array($descriptor)) {
                continue;
            }
            $clean = Backend_Identity::sanitize($backend_id);
            if ('' === $clean) {
                continue;
            }
            $choices[$clean] = $this->backend_label($clean);
        }
        ksort($choices, SORT_NATURAL | SORT_FLAG_CASE);
        return $choices;
    }

    private function backend_label(string $backend_id): string
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if ('' === $backend_id) {
            return '—';
        }
        $descriptor = $this->registry->get($backend_id);
        if (is_array($descriptor) && is_string($descriptor['label'] ?? null) && '' !== trim($descriptor['label'])) {
            return trim($descriptor['label']);
        }
        return Backend_Registry::LOCAL_ID === $backend_id ? __('Local AWVP', 'argentwolf-video-processor') : $backend_id;
    }

    /** @return array{label:string,filename:string,origin_post_id:int,origin_title:string,origin_edit_url:string} */
    private function video_identity(int $video_id): array
    {
        $video = $video_id > 0 ? get_post($video_id) : null;
        if (is_object($video) && is_string($video->post_title ?? null) && '' !== trim($video->post_title)) {
            $label = trim($video->post_title);
        } else {
            /* translators: %d: AWVP Video post ID. */
            $label = sprintf(__('Video #%d', 'argentwolf-video-processor'), $video_id);
        }
        $attachment_id = $video_id > 0 ? Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true)) : 0;
        $filename = '';
        if ($attachment_id > 0) {
            $path = get_attached_file($attachment_id);
            if (is_string($path) && '' !== $path) {
                $filename = basename($path);
            }
        }
        $origin_id = $video_id > 0 ? Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, true)) : 0;
        $origin = $origin_id > 0 ? get_post($origin_id) : null;
        if (is_object($origin) && is_string($origin->post_title ?? null) && '' !== trim($origin->post_title)) {
            $origin_title = trim($origin->post_title);
        } elseif ($origin_id > 0) {
            /* translators: %d: WordPress post ID. */
            $origin_title = sprintf(__('Post #%d', 'argentwolf-video-processor'), $origin_id);
        } else {
            $origin_title = '';
        }
        $edit = $origin_id > 0 ? get_edit_post_link($origin_id, 'raw') : '';
        return array(
            'label'=>$label,
            'filename'=>$filename,
            'origin_post_id'=>$origin_id,
            'origin_title'=>$origin_title,
            'origin_edit_url'=>is_string($edit) ? $edit : '',
        );
    }

    private function serving_label(int $video_id): string
    {
        if ($video_id < 1) {
            return '—';
        }
        if (! isset($this->serving_cache[$video_id])) {
            $candidate = $this->serving->serving_candidate($video_id);
            $this->serving_cache[$video_id] = is_array($candidate) ? $candidate : array();
        }
        $candidate = $this->serving_cache[$video_id];
        $backend_id = Backend_Identity::sanitize((string) ($candidate['backend_id'] ?? ''));
        return '' === $backend_id ? __('No verified serving source', 'argentwolf-video-processor') : $this->backend_label($backend_id);
    }

    /** @param array<string,mixed> $event @return array{remote_id:string,url:string} */
    private function remote_identity(array $event): array
    {
        $asset_id = (int) ($event['remote_asset_id'] ?? 0);
        $asset = $asset_id > 0 ? $this->assets->find($asset_id) : null;
        if (! is_array($asset)) {
            return array('remote_id'=>'','url'=>'');
        }
        $url = '';
        foreach (array('remote_url','embed_url') as $field) {
            $candidate = is_string($asset[$field] ?? null) ? trim($asset[$field]) : '';
            if ('' !== $candidate && self::safe_https_url($candidate)) {
                $url = $candidate;
                break;
            }
        }
        return array(
            'remote_id'=>is_string($asset['remote_id'] ?? null) ? $asset['remote_id'] : '',
            'url'=>$url,
        );
    }

    /** @param array<string,mixed> $event */
    private static function outcome(array $event): string
    {
        $code = (string) ($event['event_code'] ?? '');
        $service = (string) ($event['context']['service_status'] ?? '');
        if ('error' === ($event['severity'] ?? null)) {
            return 'failed';
        }
        if (str_contains($code, 'recover') || str_contains($service, 'recover')) {
            return 'recovered';
        }
        if (in_array($code, array('verified','verified_existing','already_verified','ready_verified','serving_health_restored','serving_health_recovered','staged_prepublication','staged_private'), true)
            || str_starts_with($code, 'cutover_retry')
        ) {
            return 'completed';
        }
        return 'info';
    }

    /** @return array<string,string> */
    private static function outcome_choices(): array
    {
        return array(
            '' => __('All outcomes', 'argentwolf-video-processor'),
            'completed' => __('Completed', 'argentwolf-video-processor'),
            'recovered' => __('Recovered', 'argentwolf-video-processor'),
            'failed' => __('Failed', 'argentwolf-video-processor'),
            'info' => __('Other / in progress', 'argentwolf-video-processor'),
        );
    }

    /** @return array<string,string> */
    private static function date_choices(): array
    {
        return array(
            '' => __('Any retained date', 'argentwolf-video-processor'),
            '7' => __('Last 7 days', 'argentwolf-video-processor'),
            '30' => __('Last 30 days', 'argentwolf-video-processor'),
            '90' => __('Last 90 days', 'argentwolf-video-processor'),
        );
    }

    private static function outcome_label(string $outcome): string
    {
        return self::outcome_choices()[$outcome] ?? __('Other / in progress', 'argentwolf-video-processor');
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

    private static function query_text(string $key, int $max): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only history filter.
        $raw = isset($_GET[$key]) && is_string($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
        return strlen($raw) <= $max ? trim($raw) : substr(trim($raw), 0, $max);
    }

    private static function query_token(string $key, int $max): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only history filter.
        $raw = isset($_GET[$key]) && is_string($_GET[$key]) ? sanitize_key(wp_unslash($_GET[$key])) : '';
        return strlen($raw) <= $max ? $raw : '';
    }

    private static function safe_https_url(string $url): bool
    {
        $parts = wp_parse_url($url);
        return is_array($parts)
            && 'https' === strtolower((string) ($parts['scheme'] ?? ''))
            && is_string($parts['host'] ?? null)
            && '' !== $parts['host'];
    }
}

// EOF: includes/Publication_History_Admin.php
