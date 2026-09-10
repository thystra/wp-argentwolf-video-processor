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

        $anchor = get_post((int) $lifecycle['anchor_post_id']);
        $privacy = (string) $lifecycle['target_privacy_id'];
        if (true !== $lifecycle['reveal_authorized'] || ! is_object($anchor) || 'publish' !== ($anchor->post_status ?? null)
            || ! in_array($privacy, array('1','2'), true)) {
            return self::clear_or_refuse($video_id, false);
        }

        $asset_id = (int) $execution['remote_asset_id'];
        if ($asset_id < 1 || '' === (string) $execution['remote_uuid']
            || '' === (string) $execution['applied_manifest_sha256']
            || ! hash_equals((string) $execution['manifest_sha256'], (string) $execution['applied_manifest_sha256'])) {
            return self::clear_or_refuse($video_id, true);
        }
        $asset = $this->assets->find($asset_id);
        if (! self::asset_ready($asset, $video_id, $execution, $privacy)) {
            return self::clear_or_refuse($video_id, true);
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
