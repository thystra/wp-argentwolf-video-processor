<?php
/**
 * File: includes/PeerTube_Serving_Cutover_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** R46.6 local-only cutover writer. No provider HTTP is permitted here. */
final class PeerTube_Serving_Cutover_Service
{
    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const LOCAL = 'local';
    public const REFUSED = 'refused';
    public const INDETERMINATE = 'indeterminate';

    public function __construct(
        private readonly PeerTube_Publication_Asset_Store $assets,
        private readonly ?Remote_Publication_Health_Repository $health = null
    ) {
    }

    public function reconcile(int $video_id, int $now): string
    {
        if ($video_id < 1 || $now < 1) {
            return self::REFUSED;
        }
        self::refresh_post_cache($video_id);
        self::refresh_post_meta_cache($video_id);
        $video = get_post($video_id);
        if (! is_object($video) || Video_Post_Type::POST_TYPE !== ($video->post_type ?? null)
            || 'trash' === ($video->post_status ?? null)) {
            return self::REFUSED;
        }
        $lifecycle = PeerTube_Publication_Lifecycle::sanitize(
            get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE, true)
        );
        $destination = Video_Destination::sanitize(get_post_meta($video_id, Video_Meta::DESTINATION, true));
        $plan = PeerTube_Publication_Plan::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN, true));
        $execution = PeerTube_Publication_Execution::sanitize(
            get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_EXECUTION, true)
        );
        if (array() === $lifecycle || array() === $destination || array() === $plan || array() === $execution
            || Backend_Registry::LOCAL_ID === ($destination['backend_id'] ?? null)
            || $lifecycle['backend_id'] !== ($destination['backend_id'] ?? null)
            || $lifecycle['backend_id'] !== ($plan['backend_id'] ?? null)
            || $lifecycle['backend_id'] !== ($execution['backend_id'] ?? null)
            || $plan['channel_id'] !== ($destination['channel_id'] ?? null)
            || $plan['channel_id'] !== ($execution['channel_id'] ?? null)
            || $lifecycle['anchor_post_id'] !== ($plan['anchor_post_id'] ?? null)
            || $lifecycle['anchor_post_id'] !== ($execution['anchor_post_id'] ?? null)
            || ! hash_equals((string) $lifecycle['plan_sha256'], PeerTube_Publication_Lifecycle::plan_sha256($plan))
            || ! hash_equals((string) $lifecycle['plan_sha256'], (string) ($execution['manifest']['plan_sha256'] ?? ''))) {
            return self::clear_or_refuse($video_id, true);
        }

        self::refresh_post_cache((int) $lifecycle['anchor_post_id']);
        $anchor = get_post((int) $lifecycle['anchor_post_id']);
        $privacy = (string) $lifecycle['target_privacy_id'];
        if (true !== $lifecycle['reveal_authorized'] || ! is_object($anchor) || 'publish' !== ($anchor->post_status ?? null)
            || ! in_array($privacy, array('1','2'), true)) {
            return self::clear_or_refuse($video_id, false);
        }

        $asset_id = (int) $execution['remote_asset_id'];
        if ($asset_id < 1 || '' === (string) $execution['remote_uuid']) {
            return self::clear_or_refuse($video_id, true);
        }
        $asset = $this->assets->find($asset_id);
        if (! self::asset_ready($asset, $video_id, $execution, $privacy)) {
            return self::clear_or_refuse($video_id, true);
        }
        if ('' === (string) $execution['applied_manifest_sha256']
            || ! hash_equals((string) $execution['manifest_sha256'], (string) $execution['applied_manifest_sha256'])) {
            return $this->operator_authority_still_valid($video_id, $lifecycle, $execution, $asset)
                ? self::PRESENT
                : self::clear_or_refuse($video_id, true);
        }
        $record = Video_Serving_Authority::create($video_id, $lifecycle, $execution, $asset, $now);
        if (array() === $record) {
            return self::clear_or_refuse($video_id, true);
        }
        if (null !== $this->health) {
            // Serving authority may only be created after an actual unauthenticated
            // public/embed URL probe has passed and been durably recorded. The
            // cutover service remains local-only and must never manufacture a
            // healthy observation from provider API state or remote-asset metadata.
            $health = $this->health->find($asset_id);
            if (! is_array($health)
                || $video_id !== (int) ($health['video_post_id'] ?? 0)
                || (string) $asset['backend_id'] !== (string) ($health['backend_id'] ?? '')
                || Serving_Viability::HEALTHY !== (string) ($health['status'] ?? '')
                || 1 !== (int) ($health['eligible'] ?? 0)) {
                return self::REFUSED;
            }
        }
        // Re-read the lifecycle/post immediately before the serving-authority
        // write. This service is local-only, but a separate WordPress request can
        // still advance the publication while this reconciliation is running.
        // Never let an older generation install authority after that transition.
        self::refresh_post_meta_cache($video_id);
        $latest_lifecycle = PeerTube_Publication_Lifecycle::sanitize(
            get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE, true)
        );
        self::refresh_post_cache((int) $lifecycle['anchor_post_id']);
        $latest_anchor = get_post((int) $lifecycle['anchor_post_id']);
        if (array() === $latest_lifecycle
            || (int) $latest_lifecycle['generation'] !== (int) $lifecycle['generation']
            || ! hash_equals((string) $latest_lifecycle['plan_sha256'], (string) $lifecycle['plan_sha256'])
            || (string) $latest_lifecycle['target_privacy_id'] !== $privacy
            || true !== $latest_lifecycle['reveal_authorized']
            || 'publish' !== (string) $latest_lifecycle['wordpress_status']
            || ! is_object($latest_anchor)
            || 'publish' !== ($latest_anchor->post_status ?? null)) {
            return self::clear_or_refuse($video_id, false);
        }

        $before = metadata_exists('post', $video_id, Video_Meta::SERVING_AUTHORITY)
            ? Video_Serving_Authority::sanitize(get_post_meta($video_id, Video_Meta::SERVING_AUTHORITY, true))
            : null;
        if ($record === $before) {
            return self::PRESENT;
        }
        update_post_meta($video_id, Video_Meta::SERVING_AUTHORITY, $record);
        $after = Video_Serving_Authority::sanitize(get_post_meta($video_id, Video_Meta::SERVING_AUTHORITY, true));
        return $record === $after ? self::APPLIED : self::INDETERMINATE;
    }

    /**
     * Establish serving authority for an already-known publication from a fresh,
     * durable visitor-facing operator check. Historical finalizer state is read
     * but never rewritten by this path.
     */
    public function adopt_verified_remote(
        int $video_id,
        int $remote_asset_id,
        string $expected_health_checked_at,
        int $now,
        bool $allow_ineligible_health = false
    ): string {
        if ($video_id < 1 || $remote_asset_id < 1 || $now < 1
            || 1 !== preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/D', $expected_health_checked_at)
            || null === $this->health) {
            return self::REFUSED;
        }
        self::refresh_post_cache($video_id);
        self::refresh_post_meta_cache($video_id);
        $video = get_post($video_id);
        if (! is_object($video) || Video_Post_Type::POST_TYPE !== ($video->post_type ?? null)
            || 'trash' === ($video->post_status ?? null)) {
            return self::REFUSED;
        }
        $lifecycle = PeerTube_Publication_Lifecycle::sanitize(
            get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE, true)
        );
        $destination = Video_Destination::sanitize(get_post_meta($video_id, Video_Meta::DESTINATION, true));
        $plan = PeerTube_Publication_Plan::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN, true));
        $execution = PeerTube_Publication_Execution::sanitize(
            get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_EXECUTION, true)
        );
        if (array() === $lifecycle || array() === $destination || array() === $plan || array() === $execution
            || Backend_Registry::LOCAL_ID === ($destination['backend_id'] ?? null)
            || $lifecycle['backend_id'] !== ($destination['backend_id'] ?? null)
            || $lifecycle['backend_id'] !== ($plan['backend_id'] ?? null)
            || $lifecycle['backend_id'] !== ($execution['backend_id'] ?? null)
            || $plan['channel_id'] !== ($destination['channel_id'] ?? null)
            || $plan['channel_id'] !== ($execution['channel_id'] ?? null)
            || $lifecycle['anchor_post_id'] !== ($plan['anchor_post_id'] ?? null)
            || $lifecycle['anchor_post_id'] !== ($execution['anchor_post_id'] ?? null)
            || ! hash_equals((string) $lifecycle['plan_sha256'], PeerTube_Publication_Lifecycle::plan_sha256($plan))
            || ! hash_equals((string) $lifecycle['plan_sha256'], (string) ($execution['manifest']['plan_sha256'] ?? ''))
            || $remote_asset_id !== (int) ($execution['remote_asset_id'] ?? 0)) {
            return self::REFUSED;
        }
        self::refresh_post_cache((int) $lifecycle['anchor_post_id']);
        $anchor = get_post((int) $lifecycle['anchor_post_id']);
        $privacy = (string) $lifecycle['target_privacy_id'];
        if (true !== $lifecycle['reveal_authorized'] || 'publish' !== (string) $lifecycle['wordpress_status']
            || ! is_object($anchor) || 'publish' !== ($anchor->post_status ?? null)
            || ! in_array($privacy, array('1','2'), true)) {
            return self::REFUSED;
        }
        $asset = $this->assets->find($remote_asset_id);
        if (! self::asset_ready($asset, $video_id, $execution, $privacy)) {
            return self::REFUSED;
        }
        $health = $this->health->find($remote_asset_id);
        if (! is_array($health)
            || $video_id !== (int) ($health['video_post_id'] ?? 0)
            || (string) $asset['backend_id'] !== (string) ($health['backend_id'] ?? '')
            || Serving_Viability::HEALTHY !== (string) ($health['status'] ?? '')
            || (! $allow_ineligible_health && 1 !== (int) ($health['eligible'] ?? 0))
            || ! in_array((int) ($health['eligible'] ?? -1), array(0, 1), true)
            || (int) ($health['success_streak'] ?? 0) < 1
            || $expected_health_checked_at !== (string) ($health['last_checked_at'] ?? '')) {
            return self::REFUSED;
        }
        $record = Video_Serving_Authority::create_operator_adopted($video_id, $lifecycle, $execution, $asset, $now);
        if (array() === $record) {
            return self::REFUSED;
        }

        // Bind the write to the same lifecycle and same fresh health observation.
        self::refresh_post_meta_cache($video_id);
        self::refresh_post_cache((int) $lifecycle['anchor_post_id']);
        $latest_lifecycle = PeerTube_Publication_Lifecycle::sanitize(
            get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE, true)
        );
        $latest_anchor = get_post((int) $lifecycle['anchor_post_id']);
        $latest_health = $this->health->find($remote_asset_id);
        if (array() === $latest_lifecycle
            || (int) $latest_lifecycle['generation'] !== (int) $lifecycle['generation']
            || ! hash_equals((string) $latest_lifecycle['plan_sha256'], (string) $lifecycle['plan_sha256'])
            || (string) $latest_lifecycle['target_privacy_id'] !== $privacy
            || true !== $latest_lifecycle['reveal_authorized']
            || 'publish' !== (string) $latest_lifecycle['wordpress_status']
            || ! is_object($latest_anchor) || 'publish' !== ($latest_anchor->post_status ?? null)
            || ! is_array($latest_health)
            || $video_id !== (int) ($latest_health['video_post_id'] ?? 0)
            || (string) $asset['backend_id'] !== (string) ($latest_health['backend_id'] ?? '')
            || Serving_Viability::HEALTHY !== (string) ($latest_health['status'] ?? '')
            || (! $allow_ineligible_health && 1 !== (int) ($latest_health['eligible'] ?? 0))
            || ! in_array((int) ($latest_health['eligible'] ?? -1), array(0, 1), true)
            || (int) ($latest_health['success_streak'] ?? 0) < 1
            || $expected_health_checked_at !== (string) ($latest_health['last_checked_at'] ?? '')) {
            return self::INDETERMINATE;
        }

        $before = metadata_exists('post', $video_id, Video_Meta::SERVING_AUTHORITY)
            ? Video_Serving_Authority::sanitize(get_post_meta($video_id, Video_Meta::SERVING_AUTHORITY, true))
            : null;
        if ($record === $before) {
            return self::PRESENT;
        }
        update_post_meta($video_id, Video_Meta::SERVING_AUTHORITY, $record);
        $after = Video_Serving_Authority::sanitize(get_post_meta($video_id, Video_Meta::SERVING_AUTHORITY, true));
        return $record === $after ? self::APPLIED : self::INDETERMINATE;
    }

    /** @param array<string,mixed> $lifecycle @param array<string,mixed> $execution @param array<string,mixed> $asset */
    private function operator_authority_still_valid(int $video_id, array $lifecycle, array $execution, array $asset): bool
    {
        if (null === $this->health || ! metadata_exists('post', $video_id, Video_Meta::SERVING_AUTHORITY)) {
            return false;
        }
        $authority = Video_Serving_Authority::sanitize(get_post_meta($video_id, Video_Meta::SERVING_AUTHORITY, true));
        if (Video_Serving_Authority::BASIS_OPERATOR_VERIFIED !== Video_Serving_Authority::basis($authority)) {
            return false;
        }
        $expected = Video_Serving_Authority::create_operator_adopted(
            $video_id,
            $lifecycle,
            $execution,
            $asset,
            (int) ($authority['verified_at'] ?? 0)
        );
        if ($expected !== $authority) {
            return false;
        }
        $health = $this->health->find((int) $authority['remote_asset_id']);
        return is_array($health)
            && $video_id === (int) ($health['video_post_id'] ?? 0)
            && (string) $asset['backend_id'] === (string) ($health['backend_id'] ?? '')
            && Serving_Viability::HEALTHY === (string) ($health['status'] ?? '')
            && 1 === (int) ($health['eligible'] ?? 0);
    }

    private static function refresh_post_cache(int $post_id): void
    {
        if ($post_id > 0 && function_exists('wp_cache_delete')) {
            wp_cache_delete($post_id, 'posts');
        }
    }

    private static function refresh_post_meta_cache(int $post_id): void
    {
        if ($post_id > 0 && function_exists('wp_cache_delete')) {
            wp_cache_delete($post_id, 'post_meta');
        }
    }

    /** @param array<string,mixed>|null $asset @param array<string,mixed> $execution */
    private static function asset_ready(?array $asset, int $video_id, array $execution, string $privacy_id): bool
    {
        if (! is_array($asset)) {
            return false;
        }
        $privacy = Video_Serving_Authority::privacy_name($privacy_id);
        return '' !== $privacy
            && $video_id === (int) ($asset['video_post_id'] ?? 0)
            && (int) $execution['remote_asset_id'] === (int) ($asset['id'] ?? 0)
            && $execution['backend_id'] === ($asset['backend_id'] ?? null)
            && $execution['channel_id'] === ($asset['channel_id'] ?? null)
            && $execution['remote_uuid'] === strtolower((string) ($asset['remote_id'] ?? ''))
            && in_array((string) ($asset['role'] ?? ''), array('secondary','primary'), true)
            && 'ready' === ($asset['state'] ?? null)
            && $privacy === ($asset['desired_privacy'] ?? null)
            && $privacy === ($asset['actual_privacy'] ?? null)
            && '1:published' === ($asset['remote_processing_state'] ?? null)
            && is_string($asset['last_verified_at'] ?? null) && '' !== $asset['last_verified_at']
            && is_string($asset['embed_url'] ?? null) && '' !== $asset['embed_url'];
    }

    private static function clear_or_refuse(int $video_id, bool $refused): string
    {
        if (! metadata_exists('post', $video_id, Video_Meta::SERVING_AUTHORITY)) {
            return $refused ? self::REFUSED : self::LOCAL;
        }
        delete_post_meta($video_id, Video_Meta::SERVING_AUTHORITY);
        return metadata_exists('post', $video_id, Video_Meta::SERVING_AUTHORITY)
            ? self::INDETERMINATE
            : self::LOCAL;
    }
}

// EOF: includes/PeerTube_Serving_Cutover_Service.php
