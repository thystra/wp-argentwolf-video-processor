<?php
/** File: includes/PeerTube_Verified_Remote_Asset_Refresher.php */
declare(strict_types=1);
namespace ArgentVideo;

use Throwable;

/**
 * Read-only PeerTube verification plus local catalog refresh for operator adoption.
 *
 * This path never mutates PeerTube or historical finalizer state. It only updates
 * the local remote-asset observation after the current provider state proves the
 * exact publication identity, channel, final privacy, and published state.
 */
final class PeerTube_Verified_Remote_Asset_Refresher implements Verified_Remote_Asset_Refresher
{
    /** @var callable(string):PeerTube_Api_Client */
    private $api_factory;

    public function __construct(
        private readonly PeerTube_Publication_Asset_Store $assets,
        private readonly Backend_Registry $backends,
        private readonly Managed_Backend_Secret_Store $secrets,
        ?callable $api_factory = null
    ) {
        $this->api_factory = $api_factory ?? static fn (string $origin): PeerTube_Api_Client =>
            new PeerTube_Api_Client(new PeerTube_Http_Client($origin));
    }

    /** @return array{status:string,reason_code:string,message:string} */
    public function refresh(int $video_id, int $remote_asset_id, int $now): array
    {
        if ($video_id < 1 || $remote_asset_id < 1 || $now < 1) {
            return self::result(self::REFUSED, 'operator.remote_refresh.invalid_request', 'The verified remote state could not be refreshed because the request was invalid.');
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
            return self::result(self::REFUSED, 'operator.remote_refresh.publication_state_mismatch', 'The current WordPress publication state no longer matches this remote publication.');
        }

        $privacy_id = (string) ($lifecycle['target_privacy_id'] ?? '');
        if (true !== ($lifecycle['reveal_authorized'] ?? false)
            || 'publish' !== (string) ($lifecycle['wordpress_status'] ?? '')
            || ! in_array($privacy_id, array('1', '2'), true)) {
            return self::result(self::REFUSED, 'operator.remote_refresh.visibility_not_serving', 'The current publication state does not authorize public or unlisted remote serving.');
        }

        $asset = $this->assets->find($remote_asset_id);
        if (! self::asset_identity_matches($asset, $video_id, $execution)) {
            return self::result(self::REFUSED, 'operator.remote_refresh.asset_identity_mismatch', 'The stored remote publication identity no longer matches the current publication execution.');
        }

        $backend_id = (string) $execution['backend_id'];
        $descriptor = $this->backends->get_fresh($backend_id);
        $origin = PeerTube_Origin::sanitize(
            is_array($descriptor['config'] ?? null) ? (string) ($descriptor['config']['origin'] ?? '') : ''
        );
        $secret_ref = is_array($descriptor) && is_string($descriptor['secret_ref'] ?? null) ? (string) $descriptor['secret_ref'] : '';
        if (! is_array($descriptor)
            || Backend_Registry::PEERTUBE_TYPE !== ($descriptor['type'] ?? null)
            || 'active' !== ($descriptor['state'] ?? null)
            || '' === $origin
            || '' === $secret_ref) {
            return self::result(self::INDETERMINATE, 'operator.remote_refresh.backend_unavailable', 'The PeerTube backend is not currently available for a fresh provider-state check.');
        }

        try {
            $secret = $this->secrets->read($secret_ref, $backend_id);
        } catch (Throwable) {
            $secret = null;
        }
        $access_token = is_array($secret) && is_string($secret['access_token'] ?? null)
            ? (string) $secret['access_token']
            : '';
        if ('' === $access_token) {
            return self::result(self::INDETERMINATE, 'operator.remote_refresh.credentials_unavailable', 'AWVP could not read current PeerTube credentials for the provider-state check.');
        }

        try {
            $api = ($this->api_factory)($origin);
            $status = $api->video_status($access_token, (string) $execution['remote_uuid']);
        } catch (Throwable) {
            return self::result(self::INDETERMINATE, 'operator.remote_refresh.provider_read_failed', 'PeerTube could not be read safely while refreshing the verified remote state.');
        }
        if (true !== ($status['ok'] ?? false) || ! is_array($status['data'] ?? null)) {
            $error = is_array($status['error'] ?? null) ? $status['error'] : array();
            $http = is_int($error['http_status'] ?? null) ? (int) $error['http_status'] : 0;
            return self::result(
                404 === $http ? self::REFUSED : self::INDETERMINATE,
                404 === $http ? 'operator.remote_refresh.remote_missing' : 'operator.remote_refresh.provider_read_failed',
                404 === $http ? 'PeerTube no longer reports this exact remote publication.' : 'PeerTube did not return a definitive current state for this remote publication.'
            );
        }

        $remote = $status['data'];
        if ((string) ($remote['uuid'] ?? '') !== (string) $execution['remote_uuid']) {
            return self::result(self::REFUSED, 'operator.remote_refresh.remote_identity_mismatch', 'PeerTube returned a different remote publication identity.');
        }
        if ((string) ($remote['channel_id'] ?? '') !== (string) $execution['channel_id']) {
            return self::result(self::REFUSED, 'operator.remote_refresh.channel_mismatch', 'PeerTube reports that this publication belongs to a different channel.');
        }
        if ((int) ($remote['privacy_id'] ?? 0) !== (int) $privacy_id) {
            return self::result(self::REFUSED, 'operator.remote_refresh.privacy_mismatch', 'PeerTube reports a different visibility than the current reviewed WordPress publication state.');
        }
        if (1 !== (int) ($remote['state_id'] ?? 0)) {
            return self::result(self::REFUSED, 'operator.remote_refresh.not_published', 'PeerTube does not currently report this publication in the published state.');
        }

        $embed = is_string($asset['embed_url'] ?? null) ? (string) $asset['embed_url'] : '';
        $provider_embed = $origin . (string) ($remote['embed_path'] ?? '');
        if ('' === $embed || ! hash_equals($provider_embed, $embed)) {
            return self::result(self::REFUSED, 'operator.remote_refresh.embed_identity_mismatch', 'The current PeerTube embed identity no longer matches the stored remote publication.');
        }

        $written = $this->assets->record_publication_observation(
            $remote_asset_id,
            $video_id,
            $backend_id,
            (string) $execution['remote_uuid'],
            (string) $execution['channel_id'],
            $privacy_id,
            $now
        );
        if (PeerTube_Remote_Asset_Store::APPLIED === $written) {
            return self::result(self::APPLIED, '', 'PeerTube confirmed the current remote publication state and AWVP refreshed its local serving facts.');
        }
        if (PeerTube_Remote_Asset_Store::PRESENT === $written) {
            return self::result(self::PRESENT, '', 'The local remote-publication facts already match the current verified PeerTube state.');
        }
        return self::result(
            PeerTube_Remote_Asset_Store::CONFLICT === $written ? self::REFUSED : self::INDETERMINATE,
            PeerTube_Remote_Asset_Store::CONFLICT === $written ? 'operator.remote_refresh.catalog_conflict' : 'operator.remote_refresh.catalog_write_indeterminate',
            PeerTube_Remote_Asset_Store::CONFLICT === $written
                ? 'The stored remote publication changed while AWVP was refreshing its verified state.'
                : 'PeerTube was verified, but AWVP could not durably refresh the local remote-publication facts.'
        );
    }

    /** @param array<string,mixed>|null $asset @param array<string,mixed> $execution */
    private static function asset_identity_matches(?array $asset, int $video_id, array $execution): bool
    {
        return is_array($asset)
            && $video_id === (int) ($asset['video_post_id'] ?? 0)
            && (int) $execution['remote_asset_id'] === (int) ($asset['id'] ?? 0)
            && $execution['backend_id'] === ($asset['backend_id'] ?? null)
            && $execution['channel_id'] === ($asset['channel_id'] ?? null)
            && $execution['remote_uuid'] === strtolower((string) ($asset['remote_id'] ?? ''))
            && in_array((string) ($asset['role'] ?? ''), array('secondary', 'primary'), true)
            && 'ready' === ($asset['state'] ?? null)
            && '1:published' === ($asset['remote_processing_state'] ?? null)
            && is_string($asset['embed_url'] ?? null)
            && '' !== (string) $asset['embed_url'];
    }

    /** @return array{status:string,reason_code:string,message:string} */
    private static function result(string $status, string $reason, string $message): array
    {
        return array('status' => $status, 'reason_code' => $reason, 'message' => $message);
    }
}
// EOF
