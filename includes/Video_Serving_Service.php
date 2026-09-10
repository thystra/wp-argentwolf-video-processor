<?php
/**
 * File: includes/Video_Serving_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Fail-closed frontend resolver. Performs durable local reads only. */
final class Video_Serving_Service implements Video_Serving_Resolver
{
    public function __construct(
        private readonly PeerTube_Publication_Asset_Store $assets,
        private readonly ?Remote_Publication_Health_Repository $health = null,
        private readonly ?Backend_Serving_Priority_Store $priorities = null
    ) {
    }

    /**
     * Backward-compatible remote-embed accessor used by the current block/legacy bridge.
     * The selected remote candidate is backend-agnostic even though the historical method name is not.
     */
    public function peertube_embed_url(int $video_id): string
    {
        $candidate = $this->serving_candidate($video_id);
        return 'remote' === ($candidate['kind'] ?? '') ? (string) ($candidate['url'] ?? '') : '';
    }

    /**
     * Resolve the highest-priority currently viable serving source using local state only.
     *
     * @return array{kind:string,backend_id:string,priority:int,url:string,remote_asset_id:int,health_status:string}|array{}
     */
    public function serving_candidate(int $video_id): array
    {
        if ($video_id < 1) {
            return array();
        }

        $current = $this->current_verified_remote($video_id);
        if (null === $this->health || null === $this->priorities) {
            return is_array($current)
                ? array(
                    'kind'            => 'remote',
                    'backend_id'      => (string) $current['backend_id'],
                    'priority'        => 1,
                    'url'             => (string) $current['embed_url'],
                    'remote_asset_id' => (int) $current['id'],
                    'health_status'   => Serving_Viability::HEALTHY,
                )
                : $this->local_candidate($video_id);
        }

        $health = $this->health->for_video($video_id);
        $remote_rows = method_exists($this->assets, 'serving_candidates_for_video')
            ? $this->assets->serving_candidates_for_video($video_id)
            : (is_array($current) ? array($current) : array());
        $candidates = array();
        $current_id = is_array($current) ? (int) ($current['id'] ?? 0) : 0;

        foreach ($remote_rows as $asset) {
            if (! is_array($asset) || ! self::published_asset_shape($asset, $video_id)) {
                continue;
            }
            $asset_id = (int) $asset['id'];
            $backend_id = Backend_Identity::sanitize((string) ($asset['backend_id'] ?? ''));
            if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id) {
                continue;
            }
            $observation = $health[$asset_id] ?? null;
            $eligible = is_array($observation)
                ? 1 === (int) ($observation['eligible'] ?? 0)
                : $asset_id === $current_id;
            if (! $eligible) {
                continue;
            }
            $status = is_array($observation)
                ? (string) ($observation['status'] ?? Serving_Viability::PROBE_INDETERMINATE)
                : Serving_Viability::HEALTHY;
            $candidates[] = array(
                'kind'            => 'remote',
                'backend_id'      => $backend_id,
                'priority'        => $this->priorities->priority($backend_id),
                'url'             => (string) $asset['embed_url'],
                'remote_asset_id' => $asset_id,
                'health_status'   => $status,
                '_current'        => $asset_id === $current_id ? 1 : 0,
            );
        }

        $local = $this->local_candidate($video_id);
        if (array() !== $local) {
            $local['_current'] = 0;
            $candidates[] = $local;
        }
        if (array() === $candidates) {
            return array();
        }

        usort(
            $candidates,
            static function (array $a, array $b): int {
                $priority = ((int) $b['priority']) <=> ((int) $a['priority']);
                if (0 !== $priority) {
                    return $priority;
                }
                $current = ((int) ($b['_current'] ?? 0)) <=> ((int) ($a['_current'] ?? 0));
                if (0 !== $current) {
                    return $current;
                }
                return ((int) ($a['remote_asset_id'] ?? 0)) <=> ((int) ($b['remote_asset_id'] ?? 0));
            }
        );
        $selected = $candidates[0];
        unset($selected['_current']);
        return $selected;
    }

    /** @return array<string,mixed>|null */
    private function current_verified_remote(int $video_id): ?array
    {
        if (! metadata_exists('post', $video_id, Video_Meta::SERVING_AUTHORITY)) {
            return null;
        }
        $authority = Video_Serving_Authority::sanitize(
            get_post_meta($video_id, Video_Meta::SERVING_AUTHORITY, true)
        );
        if (array() === $authority) {
            return null;
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
            return null;
        }
        $anchor = get_post((int) $authority['anchor_post_id']);
        if (! is_object($anchor) || 'publish' !== ($anchor->post_status ?? null)) {
            return null;
        }
        $asset = $this->assets->find((int) $authority['remote_asset_id']);
        if (! is_array($asset)) {
            return null;
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
            return null;
        }
        return $asset;
    }

    /** @return array{kind:string,backend_id:string,priority:int,url:string,remote_asset_id:int,health_status:string}|array{} */
    private function local_candidate(int $video_id): array
    {
        $attachment_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true));
        if ($attachment_id < 1 || array() === WordPress_Source_File::capture($attachment_id)) {
            return array();
        }
        $url = wp_get_attachment_url($attachment_id);
        if (! is_string($url) || '' === $url) {
            return array();
        }
        return array(
            'kind'            => 'local',
            'backend_id'      => Backend_Registry::LOCAL_ID,
            'priority'        => 0,
            'url'             => $url,
            'remote_asset_id' => 0,
            'health_status'   => Serving_Viability::HEALTHY,
        );
    }

    /** @param array<string,mixed> $asset */
    private static function published_asset_shape(array $asset, int $video_id): bool
    {
        $privacy = (string) ($asset['actual_privacy'] ?? '');
        return (int) ($asset['id'] ?? 0) > 0
            && $video_id === (int) ($asset['video_post_id'] ?? 0)
            && in_array((string) ($asset['role'] ?? ''), array('secondary','primary'), true)
            && 'ready' === (string) ($asset['state'] ?? '')
            && in_array($privacy, array('public','unlisted'), true)
            && $privacy === (string) ($asset['desired_privacy'] ?? '')
            && '1:published' === (string) ($asset['remote_processing_state'] ?? '')
            && is_string($asset['last_verified_at'] ?? null) && '' !== $asset['last_verified_at']
            && is_string($asset['embed_url'] ?? null) && '' !== $asset['embed_url'];
    }
}

// EOF: includes/Video_Serving_Service.php
