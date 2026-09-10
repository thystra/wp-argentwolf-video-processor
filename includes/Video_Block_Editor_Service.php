<?php
/**
 * File: includes/Video_Block_Editor_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * R46.3b editor application service.
 *
 * This service owns only WordPress-local AWVP Video binding and destination
 * selection. It performs no PeerTube HTTP, upload, publication, migration, or
 * serving-authority mutation.
 */
final class Video_Block_Editor_Service
{
    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const REFUSED = 'refused';
    public const BUSY = 'busy';
    public const INDETERMINATE = 'indeterminate';

    public const ATTACHMENT_ASSET_META = '_argent_video_asset_id';

    private const LOCK_PREFIX = 'argentwolf_video_processor_attachment_bind_lock_';
    private const LOCK_VERSION = 1;
    private const LOCK_TTL_SECONDS = 120;

    public function __construct(
        private readonly Backend_Registry $registry,
        private readonly Video_Publishing_Defaults_Store $defaults,
        private readonly ?Job_Repository $jobs = null
    ) {
    }

    /**
     * Adopt/bind one ordinary WordPress video attachment to exactly one AWVP
     * Video object. A per-attachment option mutex uses the options table's
     * unique option_name constraint to serialize plugin-owned adoption.
     *
     * @return array{status:string,video_id:int}
     */
    public function bind_local_attachment(
        int $attachment_id,
        int $origin_post_id,
        int $user_id,
        ?int $now = null
    ): array {
        if ($attachment_id < 1 || $origin_post_id < 1 || $user_id < 1) {
            return self::result(self::REFUSED);
        }
        if (! $this->valid_video_attachment($attachment_id) || ! $this->valid_origin_post($origin_post_id)) {
            return self::result(self::REFUSED);
        }

        $now = null === $now ? time() : $now;
        if ($now < 1) {
            return self::result(self::REFUSED);
        }

        $lock = $this->acquire_attachment_lock($attachment_id, $now);
        if (null === $lock) {
            return self::result(self::BUSY);
        }

        try {
            $existing = $this->existing_binding($attachment_id);
            if ($existing > 0) {
                return self::result(self::PRESENT, $existing);
            }
            if (metadata_exists('post', $attachment_id, self::ATTACHMENT_ASSET_META)) {
                // A present malformed/stale reverse pointer is ambiguous. Do
                // not overwrite it as an implicit repair during editor bind.
                return self::result(self::REFUSED);
            }

            // Site defaults are consulted only when creating a new AWVP Video.
            // An attachment that is already bound must remain usable even if
            // administrators later make the defaults malformed/unavailable,
            // and an existing video's destination is never re-resolved here.
            $initial_destination = $this->resolved_site_default();
            if (null === $initial_destination) {
                return self::result(self::REFUSED);
            }

            $attachment = get_post($attachment_id);
            if (! is_object($attachment)) {
                return self::result(self::REFUSED);
            }

            $title = sanitize_text_field((string) ($attachment->post_title ?? ''));
            if ('' === $title) {
                $title = __('Video', 'argentwolf-video-processor');
            }

            $candidate = wp_insert_post(
                array(
                    'post_type'    => Video_Post_Type::POST_TYPE,
                    'post_status'  => 'publish',
                    'post_title'   => $title,
                    'post_content' => '',
                    'post_author'  => $user_id,
                ),
                true
            );
            if (is_wp_error($candidate) || ! is_int($candidate) || $candidate < 1) {
                return self::result(self::INDETERMINATE);
            }

            if (! $this->initialize_candidate($candidate, $attachment_id, $origin_post_id, $initial_destination)) {
                $this->discard_candidate($candidate);
                return self::result(self::INDETERMINATE);
            }

            if (add_post_meta($attachment_id, self::ATTACHMENT_ASSET_META, $candidate, true)) {
                $claimed = Video_Meta::sanitize_positive_id(
                    get_post_meta($attachment_id, self::ATTACHMENT_ASSET_META, true)
                );
                return $candidate === $claimed
                    ? self::result(self::APPLIED, $candidate)
                    : self::result(self::INDETERMINATE, $candidate);
            }

            // Defensive convergence if another plugin-owned writer somehow
            // won the reverse pointer despite the mutex (for example after a
            // stale-lock recovery boundary). Keep only the established winner.
            $winner = $this->existing_binding($attachment_id);
            if (! $this->discard_candidate($candidate)) {
                return self::result(self::INDETERMINATE);
            }

            return $winner > 0
                ? self::result(self::PRESENT, $winner)
                : self::result(self::REFUSED);
        } finally {
            $this->release_attachment_lock($attachment_id, $lock);
        }
    }

    /**
     * Return the bounded non-secret editor state for one AWVP Video.
     *
     * @return array<string,mixed>|null
     */
    public function editor_state(int $video_id): ?array
    {
        $video = $this->video_post($video_id);
        if (null === $video) {
            return null;
        }

        $attachment_id = Video_Meta::sanitize_positive_id(
            get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true)
        );
        if ($attachment_id < 1 || ! $this->valid_video_attachment($attachment_id)) {
            return null;
        }

        $url = wp_get_attachment_url($attachment_id);
        if (! is_string($url) || '' === $url) {
            return null;
        }

        $has_destination = metadata_exists('post', $video_id, Video_Meta::DESTINATION);
        $stored_destination = $has_destination
            ? get_post_meta($video_id, Video_Meta::DESTINATION, true)
            : null;
        $destination = Video_Destination::resolve($stored_destination, $has_destination);
        $destination_valid = array() !== $destination && null !== $this->destination_descriptor($destination);

        $site_default = $this->resolved_site_default();

        $origin_post_id = Video_Meta::sanitize_positive_id(
            get_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, true)
        );

        return array(
            'video' => array(
                'id'                => $video_id,
                'origin_post_id'    => $origin_post_id,
                'attachment_id'     => $attachment_id,
                'attachment_url'    => esc_url_raw($url),
                'attachment_title'  => sanitize_text_field((string) get_the_title($attachment_id)),
                'destination_valid' => $destination_valid,
                'destination'       => $destination_valid
                    ? array(
                        'backend_id' => (string) $destination['backend_id'],
                        'label'      => $this->destination_label($destination),
                    )
                    : null,
            ),
            'site_default' => null === $site_default
                ? null
                : array(
                    'backend_id' => (string) $site_default['backend_id'],
                    'label'      => $this->destination_label($site_default),
                ),
            'destinations' => $this->destination_options(),
        );
    }

    /**
     * Persist one concrete destination selection. `site_default` is resolved
     * now and stored as concrete backend/channel state; it is never a live
     * pointer that can reroute the existing video later.
     *
     * @return array{status:string}
     */
    public function set_destination(int $video_id, string $mode, string $backend_id = ''): array
    {
        if (null === $this->video_post($video_id)) {
            return array('status' => self::REFUSED);
        }

        $destination = null;
        if ('site_default' === $mode) {
            $destination = $this->resolved_site_default();
        } elseif ('local' === $mode) {
            $destination = Video_Destination::local();
        } elseif ('backend' === $mode) {
            $destination = $this->resolved_backend_destination($backend_id);
        }

        if (null === $destination || array() === Video_Destination::sanitize($destination)) {
            return array('status' => self::REFUSED);
        }

        if (metadata_exists('post', $video_id, Video_Meta::PEERTUBE_MIGRATION_EXECUTION)) {
            $migration_execution = PeerTube_Migration_Execution::sanitize(
                get_post_meta($video_id, Video_Meta::PEERTUBE_MIGRATION_EXECUTION, true)
            );
            if (array() === $migration_execution) {
                return array('status' => self::REFUSED);
            }
            $current_exists = metadata_exists('post', $video_id, Video_Meta::DESTINATION);
            $current = Video_Destination::resolve(
                get_post_meta($video_id, Video_Meta::DESTINATION, true),
                $current_exists
            );
            if (array() === $current || $current !== $destination) {
                return array('status' => self::REFUSED);
            }
        }

        $before_exists = metadata_exists('post', $video_id, Video_Meta::DESTINATION);
        $before = $before_exists
            ? Video_Destination::sanitize(get_post_meta($video_id, Video_Meta::DESTINATION, true))
            : array();
        if ($before_exists && $before === $destination) {
            return array('status' => self::PRESENT);
        }

        update_post_meta($video_id, Video_Meta::DESTINATION, $destination);
        $after = Video_Destination::sanitize(
            get_post_meta($video_id, Video_Meta::DESTINATION, true)
        );

        if ($after !== $destination) {
            return array('status' => self::INDETERMINATE);
        }
        if (! Video_Destination::is_local($destination)) {
            $this->discard_unstarted_local_job($video_id);
        }
        return array('status' => self::APPLIED);
    }

    private function discard_unstarted_local_job(int $video_id): void
    {
        if (null === $this->jobs) {
            return;
        }
        $attachment_id = Video_Meta::sanitize_positive_id(
            get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true)
        );
        if ($attachment_id < 1 || ! $this->jobs->discard_unstarted($attachment_id)) {
            return;
        }
        delete_post_meta($attachment_id, '_argent_video_job_id');
        if ('queued' === (string) get_post_meta($attachment_id, '_argent_video_status', true)) {
            delete_post_meta($attachment_id, '_argent_video_status');
        }
        delete_post_meta($attachment_id, '_argent_video_last_error');
    }

    /** @return list<array{backend_id:string,type:string,label:string}> */
    private function destination_options(): array
    {
        $options = array(
            array(
                'backend_id' => Backend_Registry::LOCAL_ID,
                'type'       => 'local',
                'label'      => __('WordPress / local', 'argentwolf-video-processor'),
            ),
        );

        $backends = $this->registry->all();
        ksort($backends, SORT_STRING);
        foreach ($backends as $backend_id => $descriptor) {
            if (! is_string($backend_id) || ! is_array($descriptor) || null === $this->active_peertube($backend_id)) {
                continue;
            }
            $options[] = array(
                'backend_id' => $backend_id,
                'type'       => Backend_Registry::PEERTUBE_TYPE,
                'label'      => $this->backend_label($descriptor),
            );
        }

        return $options;
    }

    /** @return array<string,mixed>|null */
    private function resolved_site_default(): ?array
    {
        $settings = $this->defaults->get();
        if (! is_array($settings)) {
            return null;
        }
        $configured = Video_Destination::sanitize($settings['default_destination'] ?? null);
        if (array() === $configured) {
            return null;
        }
        if (Video_Destination::is_local($configured)) {
            return Video_Destination::local();
        }

        $backend_id = (string) ($configured['backend_id'] ?? '');
        $descriptor = $this->active_peertube($backend_id);
        if (null === $descriptor) {
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

    /** @return array<string,mixed>|null */
    private function resolved_backend_destination(string $backend_id): ?array
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        $descriptor = $this->active_peertube($backend_id);
        if (null === $descriptor) {
            return null;
        }

        $channel_id = PeerTube_Connection_Input::destination_id($descriptor['default_destination'] ?? null);
        $settings = $this->defaults->get();
        if (is_array($settings)) {
            $effective = Video_Publishing_Defaults::effective_for_backend($settings, $descriptor);
            $effective_channel = PeerTube_Connection_Input::destination_id($effective['channel_id'] ?? null);
            if ('' !== $effective_channel) {
                $channel_id = $effective_channel;
            }
        }
        if ('' === $channel_id) {
            return null;
        }

        return array(
            'version'    => Video_Destination::VERSION,
            'backend_id' => $backend_id,
            'channel_id' => $channel_id,
        );
    }

    /** @param array<string,mixed> $destination @return array<string,mixed>|null */
    private function destination_descriptor(array $destination): ?array
    {
        $destination = Video_Destination::sanitize($destination);
        if (array() === $destination) {
            return null;
        }
        if (Video_Destination::is_local($destination)) {
            return array(
                'id'    => Backend_Registry::LOCAL_ID,
                'type'  => 'local',
                'label' => __('WordPress / local', 'argentwolf-video-processor'),
            );
        }

        return $this->active_peertube((string) ($destination['backend_id'] ?? ''));
    }

    /** @param array<string,mixed> $destination */
    private function destination_label(array $destination): string
    {
        if (Video_Destination::is_local($destination)) {
            return __('WordPress / local', 'argentwolf-video-processor');
        }
        $descriptor = $this->active_peertube((string) ($destination['backend_id'] ?? ''));
        return null === $descriptor
            ? __('Unavailable destination', 'argentwolf-video-processor')
            : $this->backend_label($descriptor);
    }

    /** @return array<string,mixed>|null */
    private function active_peertube(string $backend_id): ?array
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id) {
            return null;
        }
        $descriptor = $this->registry->get($backend_id);
        if (! is_array($descriptor)
            || Backend_Registry::PEERTUBE_TYPE !== ($descriptor['type'] ?? null)
            || 'active' !== ($descriptor['state'] ?? null)
            || '' === PeerTube_Connection_Input::destination_id($descriptor['default_destination'] ?? null)) {
            return null;
        }
        return $descriptor;
    }

    /** @param array<string,mixed> $descriptor */
    private function backend_label(array $descriptor): string
    {
        $label = sanitize_text_field((string) ($descriptor['label'] ?? ''));
        $id = Backend_Identity::sanitize($descriptor['id'] ?? null);
        return '' !== $label ? $label : $id;
    }

    private function valid_video_attachment(int $attachment_id): bool
    {
        $post = get_post($attachment_id);
        if (! is_object($post) || 'attachment' !== ($post->post_type ?? null) || 'trash' === ($post->post_status ?? null)) {
            return false;
        }
        $mime = (string) get_post_mime_type($attachment_id);
        return str_starts_with($mime, 'video/');
    }

    private function valid_origin_post(int $post_id): bool
    {
        $post = get_post($post_id);
        return is_object($post)
            && 'revision' !== ($post->post_type ?? null)
            && 'attachment' !== ($post->post_type ?? null)
            && 'trash' !== ($post->post_status ?? null);
    }

    private function video_post(int $video_id): ?object
    {
        if ($video_id < 1) {
            return null;
        }
        $post = get_post($video_id);
        if (! is_object($post)
            || Video_Post_Type::POST_TYPE !== ($post->post_type ?? null)
            || 'trash' === ($post->post_status ?? null)) {
            return null;
        }
        return $post;
    }

    private function existing_binding(int $attachment_id): int
    {
        if (! metadata_exists('post', $attachment_id, self::ATTACHMENT_ASSET_META)) {
            return 0;
        }
        $video_id = Video_Meta::sanitize_positive_id(
            get_post_meta($attachment_id, self::ATTACHMENT_ASSET_META, true)
        );
        if ($video_id < 1 || null === $this->video_post($video_id)) {
            return 0;
        }
        $linked_attachment = Video_Meta::sanitize_positive_id(
            get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true)
        );
        return $attachment_id === $linked_attachment ? $video_id : 0;
    }

    /** @param array<string,mixed> $destination */
    private function initialize_candidate(
        int $video_id,
        int $attachment_id,
        int $origin_post_id,
        array $destination
    ): bool {
        update_post_meta($video_id, Video_Meta::ATTACHMENT_ID, $attachment_id);
        update_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, $origin_post_id);
        update_post_meta($video_id, Video_Meta::INGEST_KIND, 'wordpress_attachment');
        update_post_meta($video_id, Video_Meta::MASTER_AUTHORITY, 'wordpress_source');
        update_post_meta($video_id, Video_Meta::SOURCE_STATE, 'present');
        update_post_meta($video_id, Video_Meta::DESTINATION, $destination);

        return $attachment_id === Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true))
            && $origin_post_id === Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, true))
            && 'wordpress_attachment' === Video_Meta::sanitize_ingest_kind(get_post_meta($video_id, Video_Meta::INGEST_KIND, true))
            && 'wordpress_source' === Video_Meta::sanitize_master_authority(get_post_meta($video_id, Video_Meta::MASTER_AUTHORITY, true))
            && 'present' === Video_Meta::sanitize_source_state(get_post_meta($video_id, Video_Meta::SOURCE_STATE, true))
            && $destination === Video_Destination::sanitize(get_post_meta($video_id, Video_Meta::DESTINATION, true));
    }

    private function discard_candidate(int $video_id): bool
    {
        $deleted = wp_delete_post($video_id, true);
        return is_object($deleted) || false === get_post($video_id);
    }

    /** @return array{version:int,token:string,created_at:int}|null */
    private function acquire_attachment_lock(int $attachment_id, int $now): ?array
    {
        $option = self::lock_option($attachment_id);
        $existing = get_option($option, null);
        if (null !== $existing) {
            $valid = self::valid_lock($existing);
            if (null === $valid) {
                return null;
            }
            if ($valid['created_at'] > $now - self::LOCK_TTL_SECONDS) {
                return null;
            }
            // Stale lock recovery. Delete only the exact record observed; no
            // replacement can exist before this delete because option_name is
            // unique. The later add_option() is still the authoritative claim.
            if (get_option($option, null) !== $existing || ! delete_option($option)) {
                return null;
            }
        }

        try {
            $token = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return null;
        }
        $lock = array(
            'version'    => self::LOCK_VERSION,
            'token'      => $token,
            'created_at' => $now,
        );
        return add_option($option, $lock, '', false) ? $lock : null;
    }

    /** @param array{version:int,token:string,created_at:int} $lock */
    private function release_attachment_lock(int $attachment_id, array $lock): void
    {
        $option = self::lock_option($attachment_id);
        if (get_option($option, null) === $lock) {
            delete_option($option);
        }
    }

    private static function lock_option(int $attachment_id): string
    {
        return self::LOCK_PREFIX . hash('sha256', (string) $attachment_id);
    }

    /** @return array{version:int,token:string,created_at:int}|null */
    private static function valid_lock(mixed $value): ?array
    {
        if (! is_array($value)
            || array('version', 'token', 'created_at') !== array_keys($value)
            || self::LOCK_VERSION !== ($value['version'] ?? null)
            || ! is_string($value['token'] ?? null)
            || 1 !== preg_match('/^[0-9a-f]{32}$/D', $value['token'])
            || ! is_int($value['created_at'] ?? null)
            || $value['created_at'] < 1) {
            return null;
        }
        return $value;
    }

    /** @return array{status:string,video_id:int} */
    private static function result(string $status, int $video_id = 0): array
    {
        return array('status' => $status, 'video_id' => max(0, $video_id));
    }
}

// EOF: includes/Video_Block_Editor_Service.php
