<?php
/** File: includes/External_Video_Source_Service.php */
declare(strict_types=1);
namespace ArgentVideo;

/** Creates/reuses durable AWVP Video objects for external embed identities. */
final class External_Video_Source_Service
{
    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const BUSY = 'busy';
    public const REFUSED = 'refused';
    public const INDETERMINATE = 'indeterminate';
    private const LOCK_PREFIX = 'argentwolf_video_processor_external_bind_lock_';
    private const LOCK_TTL_SECONDS = 120;

    public function __construct(
        private readonly Video_Embed_Resolver $resolver,
        private readonly PeerTube_Public_Video_Verifier $peertube_verifier,
        private readonly Backend_Registry $registry,
        private readonly Remote_Asset_Repository $remote_assets
    ) {}

    /** @return array{status:string,video_id:int} */
    public function bind_url(string $url, int $origin_post_id, int $user_id, ?int $now = null): array
    {
        if ($origin_post_id < 1 || $user_id < 1 || ! $this->valid_origin_post($origin_post_id)) {
            return self::result(self::REFUSED);
        }
        $identity = $this->resolver->recognize($url);
        if (! is_array($identity)) {
            return self::result(self::REFUSED);
        }
        $now = null === $now ? time() : $now;
        if ($now < 1) {
            return self::result(self::REFUSED);
        }
        $title = '';
        $verified_at = 0;
        if (Video_Embed_Identity::PEERTUBE === ($identity['provider'] ?? null)) {
            $verified = $this->peertube_verifier->verify($identity);
            if (PeerTube_Public_Video_Verifier::REFUSED === $verified['status']) {
                return self::result(self::REFUSED);
            }
            if (PeerTube_Public_Video_Verifier::VERIFIED !== $verified['status']) {
                return self::result(self::INDETERMINATE);
            }
            $identity = $verified['identity'];
            $title = $verified['title'];
            $verified_at = $now;
            $managed = $this->managed_peertube_video($identity);
            if (self::INDETERMINATE === $managed['status']) {
                return self::result(self::INDETERMINATE);
            }
            if ($managed['video_id'] > 0) {
                return self::result(self::PRESENT, $managed['video_id']);
            }
        }
        $key = (string) ($identity['canonical_key'] ?? '');
        if ('' === $key) {
            return self::result(self::REFUSED);
        }
        $lock = $this->acquire_lock($key, $now);
        if (null === $lock) {
            return self::result(self::BUSY);
        }
        try {
            $existing = $this->find_external($key);
            if ($existing < 0) {
                return self::result(self::INDETERMINATE);
            }
            if ($existing > 0) {
                return self::result(self::PRESENT, $existing);
            }
            $record = External_Video_Source::create($identity, $title, $verified_at);
            if (array() === $record) {
                return self::result(self::REFUSED);
            }
            $post_title = '' !== $title ? $title : ucfirst((string) $identity['provider']) . ' video';
            $video_id = wp_insert_post(array(
                'post_type' => Video_Post_Type::POST_TYPE,
                'post_status' => 'publish',
                'post_title' => $post_title,
                'post_name' => self::post_name_for_key($key),
                'post_content' => '',
                'post_author' => $user_id,
            ), true);
            if (is_wp_error($video_id) || ! is_int($video_id) || $video_id < 1) {
                return self::result(self::INDETERMINATE);
            }
            update_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, $origin_post_id);
            update_post_meta($video_id, Video_Meta::INGEST_KIND, 'existing_remote');
            update_post_meta($video_id, Video_Meta::MASTER_AUTHORITY, 'external_archive');
            update_post_meta($video_id, Video_Meta::SOURCE_STATE, 'external');
            update_post_meta($video_id, Video_Meta::EXTERNAL_SOURCE, $record);
            update_post_meta($video_id, Video_Meta::EXTERNAL_CANONICAL_KEY, $key);
            if (! $this->candidate_matches($video_id, $origin_post_id, $record, $key)) {
                wp_delete_post($video_id, true);
                return self::result(self::INDETERMINATE);
            }
            return self::result(self::APPLIED, $video_id);
        } finally {
            $this->release_lock($key, $lock);
        }
    }

    /** @return array{status:string,video_id:int} */
    private function managed_peertube_video(array $identity): array
    {
        $origin = (string) ($identity['provider_origin'] ?? '');
        $uuid = (string) ($identity['video_id'] ?? '');
        foreach ($this->registry->all() as $backend_id => $descriptor) {
            if (! is_array($descriptor) || Backend_Registry::PEERTUBE_TYPE !== ($descriptor['type'] ?? null)) {
                continue;
            }
            $configured_origin = PeerTube_Origin::sanitize($descriptor['config']['origin'] ?? null);
            if ('' === $configured_origin || ! hash_equals($configured_origin, $origin)) {
                continue;
            }
            $lookup = $this->remote_assets->lookup_by_backend_remote((string) $backend_id, $uuid);
            if ('indeterminate' === ($lookup['status'] ?? null)) {
                return array('status'=>self::INDETERMINATE,'video_id'=>0);
            }
            $row = is_array($lookup['row'] ?? null) ? $lookup['row'] : null;
            $video_id = is_array($row) ? (int) ($row['video_post_id'] ?? 0) : 0;
            if ($video_id > 0) {
                $post = get_post($video_id);
                if (is_object($post) && Video_Post_Type::POST_TYPE === ($post->post_type ?? null) && 'trash' !== ($post->post_status ?? null)) {
                    return array('status'=>self::PRESENT,'video_id'=>$video_id);
                }
                return array('status'=>self::INDETERMINATE,'video_id'=>0);
            }
        }
        return array('status'=>'absent','video_id'=>0);
    }

    private function find_external(string $key): int
    {
        $post = get_page_by_path(
            self::post_name_for_key($key),
            OBJECT,
            Video_Post_Type::POST_TYPE
        );
        if (null === $post) {
            return 0;
        }
        if (! is_object($post) || 'trash' === ($post->post_status ?? null)) {
            return -1;
        }
        $id = Video_Meta::sanitize_positive_id($post->ID ?? 0);
        if ($id < 1) {
            return -1;
        }
        $record = External_Video_Source::sanitize(get_post_meta($id, Video_Meta::EXTERNAL_SOURCE, true));
        return array() !== $record && $key === ($record['identity']['canonical_key'] ?? '') ? $id : -1;
    }

    private static function post_name_for_key(string $key): string
    {
        return 'external-' . hash('sha256', $key);
    }

    private function candidate_matches(int $video_id, int $origin_post_id, array $record, string $key): bool
    {
        $post = get_post($video_id);
        return is_object($post)
            && self::post_name_for_key($key) === (string) ($post->post_name ?? '')
            && $origin_post_id === Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, true))
            && 'existing_remote' === Video_Meta::sanitize_ingest_kind(get_post_meta($video_id, Video_Meta::INGEST_KIND, true))
            && 'external_archive' === Video_Meta::sanitize_master_authority(get_post_meta($video_id, Video_Meta::MASTER_AUTHORITY, true))
            && 'external' === Video_Meta::sanitize_source_state(get_post_meta($video_id, Video_Meta::SOURCE_STATE, true))
            && $record === External_Video_Source::sanitize(get_post_meta($video_id, Video_Meta::EXTERNAL_SOURCE, true))
            && $key === (string) get_post_meta($video_id, Video_Meta::EXTERNAL_CANONICAL_KEY, true);
    }

    private function valid_origin_post(int $id): bool
    {
        $post = get_post($id);
        return is_object($post) && ! in_array($post->post_type ?? null, array('revision', 'attachment'), true) && 'trash' !== ($post->post_status ?? null);
    }

    /** @return array{token:string,created_at:int}|null */
    private function acquire_lock(string $key, int $now): ?array
    {
        $name = self::LOCK_PREFIX . hash('sha256', $key);
        $existing = get_option($name, null);
        if (is_array($existing) && is_int($existing['created_at'] ?? null) && $existing['created_at'] > $now - self::LOCK_TTL_SECONDS) {
            return null;
        }
        if (null !== $existing) {
            delete_option($name);
        }
        try { $token = bin2hex(random_bytes(16)); } catch (\Throwable) { return null; }
        $lock = array('token' => $token, 'created_at' => $now);
        return add_option($name, $lock, '', false) ? $lock : null;
    }

    private function release_lock(string $key, array $lock): void
    {
        $name = self::LOCK_PREFIX . hash('sha256', $key);
        if (get_option($name, null) === $lock) {
            delete_option($name);
        }
    }

    /** @return array{status:string,video_id:int} */
    private static function result(string $status, int $video_id = 0): array
    {
        return array('status' => $status, 'video_id' => max(0, $video_id));
    }
}
// EOF
