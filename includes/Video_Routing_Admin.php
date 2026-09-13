<?php
/**
 * File: includes/Video_Routing_Admin.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Read-only administrator inventory of video/post/backend routing state. */
final class Video_Routing_Admin
{
    public function __construct(
        private readonly Backend_Registry $registry,
        private readonly Video_Publishing_Defaults_Store $defaults,
        private readonly PeerTube_Publication_Catalog_Store $catalogs,
        private readonly Video_Serving_Service $serving,
        private readonly Video_Reference_Index $references,
        private readonly ?Remote_Asset_Repository $remote_assets = null,
        private readonly ?Remote_Publication_Health_Repository $health = null
    ) {
    }

    public function render_tab(): void
    {
        $this->require_admin();
        $rows = $this->rows();
        $default = $this->site_default_target();
        ?>
        <h2><?php esc_html_e('Videos & Routing', 'argentwolf-video-processor'); ?></h2>
        <p><?php esc_html_e('This read-only matrix shows each AWVP Video, the posts that reference it, and the ordered backends that can currently serve it. The list distinguishes configured routing from verified artifact availability, so an intentionally removed local source is not reported as an error while a viable remote backend is serving.', 'argentwolf-video-processor'); ?></p>

        <p><strong><?php esc_html_e('Default primary destination for new videos:', 'argentwolf-video-processor'); ?></strong>
            <?php if (null === $default) : ?>
                <span><?php esc_html_e('Unavailable or invalid; review Publishing settings.', 'argentwolf-video-processor'); ?></span>
            <?php else : ?>
                <span><?php echo esc_html($this->target_label($default)); ?></span>
            <?php endif; ?>
        </p>
        <?php if ($this->references->truncated()) : ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html(sprintf(
                /* translators: %d: maximum number of WordPress posts scanned for references. */
                __('Post-reference discovery reached its safety limit of %d posts. Stored origin-post links are still shown, but additional content references may exist beyond the limited scan.', 'argentwolf-video-processor'),
                Video_Reference_Index::MAX_POSTS
            )); ?></p></div>
        <?php endif; ?>

        <?php if (array() === $rows) : ?>
            <p><?php esc_html_e('No AWVP Videos exist yet.', 'argentwolf-video-processor'); ?></p>
            <?php return; ?>
        <?php endif; ?>

        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e('Video', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Referenced by', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Serving backends', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Status / recovery', 'argentwolf-video-processor'); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php $this->render_row($row); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        $posts = get_posts(array(
            'post_type'      => Video_Post_Type::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ));
        if (! is_array($posts)) {
            return array();
        }

        $rows = array();
        foreach ($posts as $post) {
            if (! is_object($post) || 'trash' === ($post->post_status ?? null)) {
                continue;
            }
            $video_id = Video_Meta::sanitize_positive_id($post->ID ?? null);
            if ($video_id < 1) {
                continue;
            }
            $attachment_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true));
            $origin_post_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, true));
            $destination_exists = metadata_exists('post', $video_id, Video_Meta::DESTINATION);
            $destination = Video_Destination::resolve(
                $destination_exists ? get_post_meta($video_id, Video_Meta::DESTINATION, true) : null,
                $destination_exists
            );
            $serving = $this->serving->serving_candidate($video_id);
            $policy = Local_Retention_Policy::sanitize(get_post_meta($video_id, Video_Meta::LOCAL_RETENTION_POLICY, true));
            $remote_assets = null !== $this->remote_assets ? $this->remote_assets->serving_candidates_for_video($video_id) : array();
            $tombstone = Source_Retirement_Record::sanitize(get_post_meta($video_id, Video_Meta::SOURCE_TOMBSTONE, true));
            $health = null !== $this->health ? $this->health->for_video($video_id) : array();
            $original_present = $attachment_id > 0 && array() !== WordPress_Source_File::capture($attachment_id);
            $local_delivery_present = $attachment_id > 0 && '' !== Local_Delivery_Evidence::available_hls_url($attachment_id);
            $rows[] = array(
                'video_id'          => $video_id,
                'video_title'       => sanitize_text_field((string) ($post->post_title ?? '')),
                'attachment_id'     => $attachment_id,
                'attachment_name'   => $this->attachment_name($attachment_id),
                'source_tombstone'  => $tombstone,
                'origin_post_id'    => $origin_post_id,
                'references'        => $this->references->posts_for($video_id, $attachment_id, $origin_post_id),
                'destination'       => $destination,
                'destination_saved' => $destination_exists,
                'serving'           => $serving,
                'source_state'      => Video_Meta::sanitize_source_state(get_post_meta($video_id, Video_Meta::SOURCE_STATE, true)),
                'retention'         => $policy,
                'remote_assets'      => $remote_assets,
                'health'             => $health,
                'original_present'   => $original_present,
                'local_delivery_present' => $local_delivery_present,
            );
        }
        return $rows;
    }

    /** @param array<string,mixed> $row */
    private function render_row(array $row): void
    {
        $video_id = (int) $row['video_id'];
        $title = '' !== (string) $row['video_title'] ? (string) $row['video_title'] : __('Untitled video', 'argentwolf-video-processor');
        $destination = is_array($row['destination']) ? $row['destination'] : array();
        $backend_rows = $this->serving_backends($row);
        $status = $this->routing_status($row, $backend_rows);
        ?>
        <tr>
            <td>
                <strong>#<?php echo esc_html((string) $video_id); ?> — <?php echo esc_html($title); ?></strong>
                <?php if ((int) $row['attachment_id'] > 0) : ?>
                    <br><span class="description"><?php echo esc_html(sprintf(
                        /* translators: 1: WordPress attachment ID, 2: attachment filename/title. */
                        __('Attachment #%1$d — %2$s', 'argentwolf-video-processor'),
                        (int) $row['attachment_id'],
                        (string) $row['attachment_name']
                    )); ?></span>
                <?php elseif (is_array($row['source_tombstone']) && array() !== $row['source_tombstone']) : ?>
                    <br><span class="description"><?php echo esc_html(sprintf(
                        /* translators: 1: former WordPress attachment ID, 2: former filename. */
                        __('Local source retired — former attachment #%1$d (%2$s)', 'argentwolf-video-processor'),
                        (int) $row['source_tombstone']['former_attachment_id'],
                        (string) $row['source_tombstone']['filename']
                    )); ?></span>
                <?php endif; ?>
            </td>
            <td><?php $this->render_references(is_array($row['references']) ? $row['references'] : array()); ?></td>
            <td>
                <ol style="margin:0 0 0 1.4em">
                    <?php foreach ($backend_rows as $backend) : ?>
                        <li>
                            <strong><?php echo esc_html((string) $backend['label']); ?></strong>
                            <?php if (true === ($backend['primary'] ?? false)) : ?> <span class="description"><?php esc_html_e('Primary', 'argentwolf-video-processor'); ?></span><?php endif; ?>
                            <br><span class="description"><?php echo esc_html((string) $backend['detail']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </td>
            <td>
                <strong><?php echo esc_html((string) $status['label']); ?></strong>
                <?php if ('' !== (string) $status['detail']) : ?><br><span class="description"><?php echo esc_html((string) $status['detail']); ?></span><?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /** @param list<array{id:int,title:string,status:string,post_type:string,is_origin:bool}> $references */
    private function render_references(array $references): void
    {
        if (array() === $references) {
            echo '<em>' . esc_html__('No referencing posts found', 'argentwolf-video-processor') . '</em>';
            return;
        }
        foreach ($references as $index => $reference) {
            if ($index > 0) {
                echo '<br>';
            }
            $post_id = (int) $reference['id'];
            $edit = get_edit_post_link($post_id, '');
            if (is_string($edit) && '' !== $edit) {
                echo '<a href="' . esc_url($edit) . '">' . esc_html((string) $reference['title']) . '</a>';
            } else {
                echo esc_html((string) $reference['title']);
            }
            echo ' <span class="description">#' . esc_html((string) $post_id);
            if (true === $reference['is_origin']) {
                echo ' · ' . esc_html__('origin', 'argentwolf-video-processor');
            }
            if ('' !== (string) $reference['status']) {
                echo ' · ' . esc_html((string) $reference['status']);
            }
            echo '</span>';
        }
    }

    /** @return array<string,mixed>|null */
    private function site_default_target(): ?array
    {
        $settings = $this->defaults->get();
        if (! is_array($settings)) {
            return null;
        }
        $destination = Video_Destination::sanitize($settings['default_destination'] ?? null);
        if (array() === $destination) {
            return null;
        }
        if (Video_Destination::is_local($destination)) {
            return Video_Destination::local();
        }
        $backend_id = Backend_Identity::sanitize((string) ($destination['backend_id'] ?? ''));
        $descriptor = '' !== $backend_id ? $this->registry->get($backend_id) : null;
        if (! is_array($descriptor)) {
            return null;
        }
        $effective = Video_Publishing_Defaults::effective_for_backend($settings, $descriptor);
        $channel_id = PeerTube_Connection_Input::destination_id($effective['channel_id'] ?? null);
        if ('' === $channel_id) {
            return null;
        }
        return array(
            'version'    => Video_Destination::VERSION,
            'backend_id' => $backend_id,
            'channel_id' => $channel_id,
        );
    }

    /** @param array<string,mixed> $target */
    private function target_label(array $target): string
    {
        $target = Video_Destination::sanitize($target);
        if (array() === $target) {
            return __('Invalid / needs repair', 'argentwolf-video-processor');
        }
        $backend_id = (string) $target['backend_id'];
        if (Backend_Registry::LOCAL_ID === $backend_id) {
            return __('Local AWVP', 'argentwolf-video-processor');
        }
        $backend_label = $this->backend_label($backend_id);
        $channel_id = PeerTube_Connection_Input::destination_id($target['channel_id'] ?? null);
        if ('' === $channel_id) {
            return $backend_label;
        }
        return sprintf(
            /* translators: 1: backend/server label, 2: channel label. */
            __('%1$s → %2$s', 'argentwolf-video-processor'),
            $backend_label,
            $this->channel_label($backend_id, $channel_id)
        );
    }

    /** @param array<string,mixed> $row @return list<array{label:string,detail:string,primary:bool}> */
    private function serving_backends(array $row): array
    {
        $destination = is_array($row['destination'] ?? null) ? $row['destination'] : array();
        $configured = Backend_Identity::sanitize((string) ($destination['backend_id'] ?? ''));
        $serving = is_array($row['serving'] ?? null) ? $row['serving'] : array();
        $serving_backend = Backend_Identity::sanitize((string) ($serving['backend_id'] ?? ''));
        $serving_asset = (int) ($serving['remote_asset_id'] ?? 0);
        $health = is_array($row['health'] ?? null) ? $row['health'] : array();
        $out = array();
        $seen = array();

        foreach ((array) ($row['remote_assets'] ?? array()) as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            $asset_id = (int) ($asset['id'] ?? 0);
            $backend_id = Backend_Identity::sanitize((string) ($asset['backend_id'] ?? ''));
            if ($asset_id < 1 || '' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id) {
                continue;
            }
            $observation = is_array($health[$asset_id] ?? null) ? $health[$asset_id] : array();
            $status = (string) ($observation['status'] ?? '');
            $eligible = 1 === (int) ($observation['eligible'] ?? 0);
            $is_serving = $asset_id === $serving_asset && $backend_id === $serving_backend;
            if ($is_serving) {
                $detail = __('Serving now · remote verified', 'argentwolf-video-processor');
            } elseif (Serving_Viability::HEALTHY === $status && $eligible) {
                $detail = __('Available · remote verified', 'argentwolf-video-processor');
            } elseif ('' !== $status) {
                $detail = sprintf(
                    /* translators: %s: bounded remote serving-health status. */
                    __('Remote present · health: %s', 'argentwolf-video-processor'),
                    $status
                );
            } else {
                $detail = __('Remote present · serving health not yet qualified', 'argentwolf-video-processor');
            }
            $out[] = array(
                'label' => $this->backend_label($backend_id),
                'detail' => $detail,
                'primary' => $backend_id === $configured,
                '_serving' => $is_serving,
            );
            $seen[$backend_id] = true;
        }

        if ('' !== $configured && Backend_Registry::LOCAL_ID !== $configured && ! isset($seen[$configured])) {
            $out[] = array(
                'label' => $this->backend_label($configured),
                'detail' => __('Configured · no verified remote publication available', 'argentwolf-video-processor'),
                'primary' => true,
                '_serving' => false,
            );
        }

        $source_removed = 'removed' === (string) ($row['source_state'] ?? '');
        $original = true === ($row['original_present'] ?? false);
        $delivery = true === ($row['local_delivery_present'] ?? false);
        $local_serving = Backend_Registry::LOCAL_ID === $serving_backend || 'local' === (string) ($serving['kind'] ?? '');
        if ($local_serving) {
            $local_detail = __('Serving now', 'argentwolf-video-processor');
            if ($original) {
                $local_detail .= ' · ' . __('original present', 'argentwolf-video-processor');
            } elseif ($delivery) {
                $local_detail .= ' · ' . __('local delivery present', 'argentwolf-video-processor');
            }
        } elseif ($original || $delivery) {
            $parts = array();
            if ($original) {
                $parts[] = __('original present', 'argentwolf-video-processor');
            }
            if ($delivery) {
                $parts[] = __('local delivery present', 'argentwolf-video-processor');
            }
            $local_detail = __('Available', 'argentwolf-video-processor') . ' · ' . implode(' · ', $parts);
        } elseif ($source_removed) {
            $local_detail = __('Not available · local source intentionally removed', 'argentwolf-video-processor');
        } else {
            $local_detail = __('Not available · no local serving artifact found', 'argentwolf-video-processor');
        }
        $out[] = array(
            'label' => __('Local AWVP / WordPress', 'argentwolf-video-processor'),
            'detail' => $local_detail,
            'primary' => Backend_Registry::LOCAL_ID === $configured,
            '_serving' => $local_serving,
        );

        usort($out, static function (array $a, array $b): int {
            $as = true === ($a['_serving'] ?? false) ? 1 : 0;
            $bs = true === ($b['_serving'] ?? false) ? 1 : 0;
            if ($as !== $bs) {
                return $bs <=> $as;
            }
            if ((bool) $a['primary'] !== (bool) $b['primary']) {
                return ((int) $b['primary']) <=> ((int) $a['primary']);
            }
            return 0;
        });
        foreach ($out as &$entry) {
            unset($entry['_serving']);
        }
        unset($entry);
        return $out;
    }

    /** @param list<array{label:string,detail:string,primary:bool}> $backend_rows @return array{label:string,detail:string} */
    private function routing_status(array $row, array $backend_rows): array
    {
        $serving = is_array($row['serving'] ?? null) ? $row['serving'] : array();
        if (array() === $serving) {
            return array(
                'label'=>__('Needs attention', 'argentwolf-video-processor'),
                'detail'=>__('No verified serving backend is currently available. Use Overview for Check now, rebuild, or republish recovery actions.', 'argentwolf-video-processor'),
            );
        }
        $backend_id = Backend_Identity::sanitize((string) ($serving['backend_id'] ?? ''));
        $source_removed = 'removed' === (string) ($row['source_state'] ?? '');
        if (Backend_Registry::LOCAL_ID !== $backend_id && $source_removed) {
            return array(
                'label'=>__('Healthy', 'argentwolf-video-processor'),
                'detail'=>__('Verified remote serving is active; the local source was intentionally removed.', 'argentwolf-video-processor'),
            );
        }
        $destination = is_array($row['destination'] ?? null) ? $row['destination'] : array();
        $configured = Backend_Identity::sanitize((string) ($destination['backend_id'] ?? ''));
        if ('' !== $configured && $configured !== $backend_id) {
            return array(
                'label'=>__('Fallback serving active', 'argentwolf-video-processor'),
                'detail'=>__('The configured primary is not serving now. Use Overview if recovery is required.', 'argentwolf-video-processor'),
            );
        }
        return array('label'=>__('Healthy', 'argentwolf-video-processor'), 'detail'=>'');
    }

    private function backend_label(string $backend_id): string
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if (Backend_Registry::LOCAL_ID === $backend_id) {
            return __('Local AWVP', 'argentwolf-video-processor');
        }
        $descriptor = '' !== $backend_id ? $this->registry->get($backend_id) : null;
        $label = is_array($descriptor) ? sanitize_text_field((string) ($descriptor['label'] ?? '')) : '';
        return '' !== $label ? $label : $backend_id;
    }

    private function channel_label(string $backend_id, string $channel_id): string
    {
        $catalog = $this->catalogs->get($backend_id);
        if (is_array($catalog)) {
            foreach ($catalog['channels'] ?? array() as $channel) {
                if (is_array($channel) && $channel_id === ($channel['id'] ?? null)) {
                    $label = sanitize_text_field((string) ($channel['display_name'] ?? $channel['name'] ?? ''));
                    if ('' !== $label) {
                        return $label;
                    }
                }
            }
        }
        return sprintf(
            /* translators: %s: provider channel identifier. */
            __('Channel %s', 'argentwolf-video-processor'),
            $channel_id
        );
    }

    private function attachment_name(int $attachment_id): string
    {
        if ($attachment_id < 1) {
            return __('No attachment', 'argentwolf-video-processor');
        }
        $relative = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (is_string($relative) && '' !== trim($relative)) {
            return basename(str_replace('\\', '/', $relative));
        }
        $title = sanitize_text_field((string) get_the_title($attachment_id));
        return '' !== $title ? $title : __('Attachment', 'argentwolf-video-processor');
    }

    private function require_admin(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view ArgentWolf Video Processor routing.', 'argentwolf-video-processor'));
        }
    }
}

// EOF: includes/Video_Routing_Admin.php
