<?php
/**
 * File: includes/PeerTube_Publication_Health_Probe.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/** PeerTube implementation of the backend-agnostic public serving-viability contract. */
final class PeerTube_Publication_Health_Probe implements Serving_Health_Adapter
{
    /** @var callable(string):PeerTube_Api_Client */
    private $api_factory;
    /** @var callable(string):PeerTube_Http_Client */
    private $http_factory;

    public function __construct(
        private readonly Managed_Backend_Secret_Store $secrets,
        ?callable $api_factory = null,
        ?callable $http_factory = null
    ) {
        $this->api_factory = $api_factory ?? static fn (string $origin): PeerTube_Api_Client =>
            new PeerTube_Api_Client(new PeerTube_Http_Client($origin));
        $this->http_factory = $http_factory ?? static fn (string $origin): PeerTube_Http_Client =>
            new PeerTube_Http_Client($origin);
    }

    public function type(): string
    {
        return Backend_Registry::PEERTUBE_TYPE;
    }

    /** @param array<string,mixed> $descriptor @param array<string,mixed> $asset */
    public function probe_publication(array $descriptor, array $asset): Serving_Viability
    {
        $backend_id = Backend_Identity::sanitize((string) ($asset['backend_id'] ?? ''));
        $remote_uuid = is_string($asset['remote_id'] ?? null) ? strtolower($asset['remote_id']) : '';
        $embed_url = is_string($asset['embed_url'] ?? null) ? $asset['embed_url'] : '';
        if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id
            || 1 !== preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $remote_uuid)
            || '' === $embed_url) {
            return self::result(Serving_Viability::PROBE_INDETERMINATE, 'peertube.health.asset_invalid', 'Stored PeerTube publication identity is incomplete.');
        }

        $origin = is_array($descriptor['config'] ?? null)
            ? (string) ($descriptor['config']['origin'] ?? '')
            : '';
        if (! is_array($descriptor) || Backend_Registry::PEERTUBE_TYPE !== ($descriptor['type'] ?? null)
            || 'active' !== ($descriptor['state'] ?? null) || '' === PeerTube_Origin::sanitize($origin)) {
            return self::result(Serving_Viability::TEMPORARILY_UNAVAILABLE, 'peertube.health.backend_unavailable', 'The configured PeerTube server is not currently available to AWVP.');
        }

        try {
            $http = ($this->http_factory)($origin);
            $public = $http->get_public_embed($embed_url);
        } catch (Throwable) {
            return self::result(Serving_Viability::PROBE_INDETERMINATE, 'peertube.health.probe_invalid', 'The PeerTube serving URL could not be probed safely.');
        }

        if (true !== ($public['ok'] ?? false)) {
            $status = is_int($public['http_status'] ?? null) ? $public['http_status'] : 0;
            $error = is_array($public['error'] ?? null) ? $public['error'] : array();
            $machine = is_string($error['status'] ?? null) ? $error['status'] : '';
            return match (true) {
                404 === $status || 'not_found' === $machine => self::result(Serving_Viability::MISSING, 'peertube.health.public_missing', 'The PeerTube publication is no longer available at its verified serving URL.', $status),
                in_array($status, array(401,403), true) => self::result(Serving_Viability::PRIVATE_OR_RESTRICTED, 'peertube.health.public_restricted', 'The PeerTube publication is no longer publicly available.', $status),
                429 === $status || $status >= 500 || in_array($machine, array('transport_timeout','dns_error','connection_refused','connection_reset','tls_error','transport_error','remote_error','rate_limited'), true) => self::result(Serving_Viability::TEMPORARILY_UNAVAILABLE, 'peertube.health.public_unavailable', 'The PeerTube serving URL is temporarily unavailable.', $status),
                default => self::result(Serving_Viability::PROBE_INDETERMINATE, 'peertube.health.public_indeterminate', 'AWVP could not determine whether the PeerTube serving URL is currently usable.', $status),
            };
        }

        $status = is_int($public['http_status'] ?? null) ? $public['http_status'] : 0;
        $headers = is_array($public['headers'] ?? null) ? $public['headers'] : array();
        $body = is_string($public['body'] ?? null) ? $public['body'] : '';
        $content_type = strtolower((string) ($headers['content-type'] ?? ''));
        if ($status < 200 || $status >= 300 || '' === $body || (! str_contains($content_type, 'text/html') && ! str_contains($content_type, 'application/xhtml+xml'))) {
            return self::result(Serving_Viability::PROBE_INDETERMINATE, 'peertube.health.public_shape_invalid', 'The PeerTube serving URL returned an unexpected response.', $status);
        }

        $body_lower = strtolower(substr($body, 0, 1048576));
        if (str_contains($body_lower, 'video is unavailable') || str_contains($body_lower, 'video unavailable')) {
            return self::result(Serving_Viability::PRIVATE_OR_RESTRICTED, 'peertube.health.public_unavailable_page', 'PeerTube reports that this video is unavailable.', $status);
        }
        foreach (array(
            'video is being transcoded',
            'video is currently being transcoded',
            'this video is being transcoded',
            'video is being processed',
        ) as $processing_marker) {
            if (str_contains($body_lower, $processing_marker)) {
                return self::result(
                    Serving_Viability::PROCESSING,
                    'peertube.health.public_processing',
                    'PeerTube is still processing this publication; AWVP will keep the existing serving source until the public player is ready.',
                    $status
                );
            }
        }

        // PeerTube's embed route is a client application shell, so a 2xx HTML
        // response alone cannot distinguish every processing placeholder. An
        // authenticated API read may therefore *disqualify* an otherwise
        // reachable shell as still processing/failed. It may never promote an
        // unreachable public URL to healthy; the public URL check above remains
        // mandatory for positive serving qualification.
        $supplementary = $this->supplementary_provider_state($descriptor, $backend_id, $remote_uuid, $status);
        if ($supplementary instanceof Serving_Viability) {
            return $supplementary;
        }

        return Serving_Viability::healthy();
    }

    /** @param array<string,mixed> $descriptor */
    private function supplementary_provider_state(array $descriptor, string $backend_id, string $remote_uuid, int $public_http_status): ?Serving_Viability
    {
        $secret_ref = is_string($descriptor['secret_ref'] ?? null) ? $descriptor['secret_ref'] : '';
        if ('' === $secret_ref) {
            return null;
        }
        $secret = $this->secrets->read($secret_ref, $backend_id);
        $access_token = is_array($secret) && is_string($secret['access_token'] ?? null)
            ? $secret['access_token']
            : '';
        if ('' === $access_token) {
            return null;
        }
        try {
            $api = ($this->api_factory)((string) ($descriptor['config']['origin'] ?? ''));
            $status = $api->video_status($access_token, $remote_uuid);
        } catch (Throwable) {
            return null;
        }
        if (true !== ($status['ok'] ?? false) || ! is_array($status['data'] ?? null)) {
            return null;
        }
        $state_id = (int) ($status['data']['state_id'] ?? 0);
        $privacy_id = (int) ($status['data']['privacy_id'] ?? 0);
        if ($privacy_id > 0 && ! in_array($privacy_id, array(1, 2), true)) {
            return self::result(
                Serving_Viability::PRIVATE_OR_RESTRICTED,
                'peertube.health.provider_restricted',
                'PeerTube reports that this publication is no longer public or unlisted; AWVP will use another viable serving source.',
                $public_http_status
            );
        }
        if (in_array($state_id, array(2, 6, 9), true)) {
            return self::result(
                Serving_Viability::PROCESSING,
                'peertube.health.provider_processing',
                'PeerTube has accepted the publication and is still processing it; AWVP will keep the existing serving source until the public player is ready.',
                $public_http_status
            );
        }
        if (in_array($state_id, array(7, 8), true)) {
            return self::result(
                Serving_Viability::TEMPORARILY_UNAVAILABLE,
                'peertube.health.provider_processing_failed',
                'PeerTube reports that processing of this publication failed.',
                $public_http_status
            );
        }
        return null;
    }

    private static function result(string $status, string $reason, string $message, int $http_status = 0): Serving_Viability
    {
        return Serving_Viability::create($status, $reason, $message, $http_status)
            ?? Serving_Viability::create(Serving_Viability::PROBE_INDETERMINATE, 'peertube.health.internal_result_invalid', 'AWVP could not normalize the PeerTube health result.')
            ?? throw new \RuntimeException('Serving viability result construction failed.');
    }
}

// EOF: includes/PeerTube_Publication_Health_Probe.php
