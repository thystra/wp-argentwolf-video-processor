<?php
/**
 * File: includes/Video_Serving_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** R46.6 fail-closed frontend resolver. Performs local reads only. */
final class Video_Serving_Service implements Video_Serving_Resolver
{
    public function __construct(private readonly PeerTube_Publication_Asset_Store $assets)
    {
    }

    public function peertube_embed_url(int $video_id): string
    {
        if ($video_id < 1 || ! metadata_exists('post', $video_id, Video_Meta::SERVING_AUTHORITY)) {
            return '';
        }
        $authority = Video_Serving_Authority::sanitize(
            get_post_meta($video_id, Video_Meta::SERVING_AUTHORITY, true)
        );
        if (array() === $authority) {
            return '';
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
            || true !== $lifecycle['reveal_authorized']
            || (int) $authority['generation'] !== (int) $lifecycle['generation']
            || ! hash_equals((string) $authority['plan_sha256'], (string) $lifecycle['plan_sha256'])
            || ! hash_equals((string) $lifecycle['plan_sha256'], PeerTube_Publication_Lifecycle::plan_sha256($plan))
            || ! hash_equals((string) $lifecycle['plan_sha256'], (string) ($execution['manifest']['plan_sha256'] ?? ''))
            || ! hash_equals((string) $authority['manifest_sha256'], (string) $execution['manifest_sha256'])
            || ! hash_equals((string) $execution['manifest_sha256'], (string) $execution['applied_manifest_sha256'])
            || $authority['backend_id'] !== ($destination['backend_id'] ?? null)
            || $authority['backend_id'] !== ($plan['backend_id'] ?? null)
            || $authority['backend_id'] !== ($execution['backend_id'] ?? null)
            || $authority['channel_id'] !== ($destination['channel_id'] ?? null)
            || $authority['channel_id'] !== ($plan['channel_id'] ?? null)
            || $authority['channel_id'] !== ($execution['channel_id'] ?? null)
            || (int) $authority['anchor_post_id'] !== (int) $lifecycle['anchor_post_id']
            || (int) $authority['anchor_post_id'] !== (int) $plan['anchor_post_id']
            || (int) $authority['anchor_post_id'] !== (int) $execution['anchor_post_id']
            || (int) $authority['remote_asset_id'] !== (int) $execution['remote_asset_id']
            || $authority['remote_uuid'] !== $execution['remote_uuid']
            || $authority['privacy_id'] !== $lifecycle['target_privacy_id']) {
            return '';
        }
        $anchor = get_post((int) $authority['anchor_post_id']);
        if (! is_object($anchor) || 'publish' !== ($anchor->post_status ?? null)) {
            return '';
        }
        $asset = $this->assets->find((int) $authority['remote_asset_id']);
        if (! is_array($asset)) {
            return '';
        }
        $privacy = Video_Serving_Authority::privacy_name((string) $authority['privacy_id']);
        if ('' === $privacy
            || $video_id !== (int) ($asset['video_post_id'] ?? 0)
            || $authority['backend_id'] !== ($asset['backend_id'] ?? null)
            || $authority['channel_id'] !== ($asset['channel_id'] ?? null)
            || $authority['remote_uuid'] !== strtolower((string) ($asset['remote_id'] ?? ''))
            || ! in_array((string) ($asset['role'] ?? ''), array('secondary','primary'), true)
            || 'ready' !== ($asset['state'] ?? null)
            || $privacy !== ($asset['desired_privacy'] ?? null)
            || $privacy !== ($asset['actual_privacy'] ?? null)
            || '1:published' !== ($asset['remote_processing_state'] ?? null)
            || ! is_string($asset['last_verified_at'] ?? null) || '' === $asset['last_verified_at']
            || $authority['embed_url'] !== ($asset['embed_url'] ?? null)) {
            return '';
        }
        return (string) $authority['embed_url'];
    }
}

// EOF: includes/Video_Serving_Service.php
