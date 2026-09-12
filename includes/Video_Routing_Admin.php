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
        private readonly Video_Reference_Index $references
    ) {
    }

    public function render_tab(): void
    {
        $this->require_admin();
        $rows = $this->rows();
        $default = $this->site_default_target();
        ?>
        <h2><?php esc_html_e('Videos & Routing', 'argentwolf-video-processor'); ?></h2>
        <p><?php esc_html_e('This read-only matrix shows each AWVP Video, the WordPress posts that reference it, its configured primary destination, and the source that is actually serving now. New videos start from the site publishing default, then keep a concrete per-video destination so later default changes do not silently reroute existing videos.', 'argentwolf-video-processor'); ?></p>

        <p><strong><?php esc_html_e('Default primary destination for new videos:', 'argentwolf-video-processor'); ?></strong>
            <?php if (null === $default) : ?>
                <span><?php esc_html_e('Unavailable or invalid; review Publishing settings.', 'argentwolf-video-processor'); ?></span>
            <?php else : ?>
                <span><?php echo esc_html($this->target_label($default)); ?></span>
            <?php endif; ?>
        </p>
        <p class="description"><?php esc_html_e('This routing view does not enable multi-backend publication. Each video currently has one configured primary target; the Backups column is reserved for future ordered PeerTube/other-backend targets.', 'argentwolf-video-processor'); ?></p>

        <?php if ($this->references->truncated()) : ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html(sprintf(
                /* translators: %d: maximum number of WordPress posts scanned for references. */
                __('Post-reference discovery reached its safety limit of %d posts. Stored origin-post links are still shown, but additional content references may exist beyond the bounded scan.', 'argentwolf-video-processor'),
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
                <th><?php esc_html_e('Primary', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Backups', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Serving now', 'argentwolf-video-processor'); ?></th>
                <th><?php esc_html_e('Local source / retention', 'argentwolf-video-processor'); ?></th>
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
            $rows[] = array(
                'video_id'          => $video_id,
                'video_title'       => sanitize_text_field((string) ($post->post_title ?? '')),
                'attachment_id'     => $attachment_id,
                'attachment_name'   => $this->attachment_name($attachment_id),
                'origin_post_id'    => $origin_post_id,
                'references'        => $this->references->posts_for($video_id, $attachment_id, $origin_post_id),
                'destination'       => $destination,
                'destination_saved' => $destination_exists,
                'serving'           => $serving,
                'source_state'      => Video_Meta::sanitize_source_state(get_post_meta($video_id, Video_Meta::SOURCE_STATE, true)),
                'retention'         => $policy,
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
        $primary = array() === $destination
            ? __('Invalid / needs repair', 'argentwolf-video-processor')
            : $this->target_label($destination);
        $serving = is_array($row['serving']) ? $row['serving'] : array();
        $serving_label = $this->serving_label($serving);
        $configured_backend = Backend_Identity::sanitize((string) ($destination['backend_id'] ?? ''));
        $serving_backend = Backend_Identity::sanitize((string) ($serving['backend_id'] ?? ''));
        $fallback = '' !== $configured_backend && '' !== $serving_backend && $configured_backend !== $serving_backend;
        $retention = is_array($row['retention']) ? $row['retention'] : array();
        $retention_label = $this->retention_label($retention);
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
                <?php endif; ?>
            </td>
            <td><?php $this->render_references(is_array($row['references']) ? $row['references'] : array()); ?></td>
            <td>
                <strong><?php echo esc_html($primary); ?></strong>
                <br><span class="description"><?php echo esc_html(true === $row['destination_saved']
                    ? __('Stored per-video assignment', 'argentwolf-video-processor')
                    : __('Legacy Local default; no destination metadata stored', 'argentwolf-video-processor')); ?></span>
            </td>
            <td><span aria-label="<?php echo esc_attr__('No configured backup targets', 'argentwolf-video-processor'); ?>">—</span></td>
            <td>
                <strong><?php echo esc_html($serving_label); ?></strong>
                <?php if ($fallback) : ?><br><span class="description"><strong><?php esc_html_e('Fallback serving is active.', 'argentwolf-video-processor'); ?></strong></span><?php endif; ?>
            </td>
            <td>
                <?php echo esc_html(sprintf(
                    /* translators: %s: bounded source-state name. */
                    __('Source: %s', 'argentwolf-video-processor'),
                    (string) $row['source_state']
                )); ?>
                <br><span class="description"><?php echo esc_html($retention_label); ?></span>
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

    /** @param array<string,mixed> $serving */
    private function serving_label(array $serving): string
    {
        if (array() === $serving) {
            return __('No verified serving source', 'argentwolf-video-processor');
        }
        $backend_id = Backend_Identity::sanitize((string) ($serving['backend_id'] ?? ''));
        if (Backend_Registry::LOCAL_ID === $backend_id || 'local' === ($serving['kind'] ?? null)) {
            return __('Local AWVP / WordPress', 'argentwolf-video-processor');
        }
        return '' !== $backend_id ? $this->backend_label($backend_id) : __('Unknown source', 'argentwolf-video-processor');
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

    /** @param array<string,mixed> $policy */
    private function retention_label(array $policy): string
    {
        if (array() === $policy) {
            return __('Retention: site/default Keep behavior', 'argentwolf-video-processor');
        }
        return match ((string) $policy['mode']) {
            Local_Retention_Policy::MODE_KEEP => __('Retention: Keep all local copies', 'argentwolf-video-processor'),
            Local_Retention_Policy::MODE_DELETE_MANAGED => __('Retention: remove generated local copies', 'argentwolf-video-processor'),
            Local_Retention_Policy::MODE_DELETE_ALL => __('Retention: remove generated copies and original', 'argentwolf-video-processor'),
            default => __('Retention: invalid / needs repair', 'argentwolf-video-processor'),
        };
    }

    private function require_admin(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view ArgentWolf Video Processor routing.', 'argentwolf-video-processor'));
        }
    }
}

// EOF: includes/Video_Routing_Admin.php
