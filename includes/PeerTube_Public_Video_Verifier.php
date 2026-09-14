<?php
/** File: includes/PeerTube_Public_Video_Verifier.php */
declare(strict_types=1);
namespace ArgentVideo;

/** Verifies a PeerTube-shaped URL against the instance's reviewed public video API. */
final class PeerTube_Public_Video_Verifier
{
    public const VERIFIED = 'verified';
    public const REFUSED = 'refused';
    public const INDETERMINATE = 'indeterminate';

    public function __construct(private readonly \Closure $http_factory)
    {
    }

    /** @return array{status:string,identity:array<string,mixed>,title:string} */
    public function verify(array $recognized): array
    {
        $recognized = Video_Embed_Identity::sanitize($recognized);
        if (array() === $recognized || Video_Embed_Identity::PEERTUBE !== ($recognized['provider'] ?? null)) {
            return self::result(self::REFUSED);
        }
        $origin = (string) $recognized['provider_origin'];
        $candidate = (string) $recognized['video_id'];
        try {
            $http = ($this->http_factory)($origin);
            if (! is_object($http) || ! method_exists($http, 'get_public_video')) {
                return self::result(self::INDETERMINATE);
            }
            $response = $http->get_public_video($candidate);
        } catch (\Throwable) {
            return self::result(self::INDETERMINATE);
        }
        if (! is_array($response)) {
            return self::result(self::INDETERMINATE);
        }
        $status = (int) ($response['http_status'] ?? 0);
        if (404 === $status || 401 === $status || 403 === $status) {
            return self::result(self::REFUSED);
        }
        if (true !== ($response['ok'] ?? false) || 200 !== $status || ! is_string($response['body'] ?? null)) {
            return self::result(self::INDETERMINATE);
        }
        try {
            $body = json_decode((string) $response['body'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::result(self::INDETERMINATE);
        }
        if (! is_array($body)) {
            return self::result(self::INDETERMINATE);
        }
        $uuid = Video_Embed_Identity::sanitize_peertube_id($body['uuid'] ?? null);
        if ('' === $uuid || 1 !== preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $uuid)) {
            return self::result(self::INDETERMINATE);
        }
        $privacy = $body['privacy'] ?? null;
        $privacy_id = is_array($privacy) ? (int) ($privacy['id'] ?? 0) : (int) $privacy;
        if (1 !== $privacy_id) {
            return self::result(self::REFUSED);
        }
        $identity = Video_Embed_Identity::create(Video_Embed_Identity::PEERTUBE, $origin, $uuid);
        if (! is_array($identity)) {
            return self::result(self::INDETERMINATE);
        }
        $title = sanitize_text_field(is_string($body['name'] ?? null) ? $body['name'] : '');
        return self::result(self::VERIFIED, $identity, $title);
    }

    /** @return array{status:string,identity:array<string,mixed>,title:string} */
    private static function result(string $status, array $identity = array(), string $title = ''): array
    {
        return array('status' => $status, 'identity' => $identity, 'title' => $title);
    }
}
// EOF
