<?php
/**
 * File: includes/PeerTube_Api_Client.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Bounded PeerTube API primitives that persist no remote response data.
 */
final class PeerTube_Api_Client
{
    private const CONFIG_PATH = '/api/v1/config';
    private const MAX_VERSION_BYTES = 64;
    private const MAX_INSTANCE_NAME_CHARACTERS = 120;
    private const MAX_INSTANCE_NAME_BYTES = 1024;

    public function __construct(private readonly PeerTube_Http_Client $http)
    {
    }

    /**
     * Perform one public, non-retrying PeerTube instance-detection request.
     *
     * @return array{
     *   ok:bool,
     *   data:array<string,mixed>|null,
     *   error:array<string,mixed>|null
     * }
     */
    public function detect_instance(): array
    {
        $response = $this->http->get(self::CONFIG_PATH);
        if (! $response['ok']) {
            return self::failure($response['error']);
        }

        if (200 !== $response['http_status']) {
            return self::failure(
                PeerTube_Api_Error::invalid_response('instance_status_invalid', $response['http_status'])
            );
        }

        if (! self::is_json_content_type($response['headers']['content-type'] ?? '')) {
            return self::failure(
                PeerTube_Api_Error::invalid_response('instance_content_type_invalid', $response['http_status'])
            );
        }

        try {
            $decoded = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::failure(
                PeerTube_Api_Error::invalid_response('instance_json_invalid', $response['http_status'])
            );
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return self::failure(
                PeerTube_Api_Error::invalid_response('instance_shape_invalid', $response['http_status'])
            );
        }

        $server_version = self::server_version($decoded['serverVersion'] ?? null);
        if ('' === $server_version) {
            return self::failure(
                PeerTube_Api_Error::invalid_response('instance_version_invalid', $response['http_status'])
            );
        }

        $instance = is_array($decoded['instance'] ?? null) && ! array_is_list($decoded['instance'])
            ? $decoded['instance']
            : array();
        $transcoding = is_array($decoded['transcoding'] ?? null) && ! array_is_list($decoded['transcoding'])
            ? $decoded['transcoding']
            : array();

        return array(
            'ok'   => true,
            'data' => array(
                'origin'                => $this->http->origin(),
                'server_version'        => $server_version,
                'instance_name'         => self::instance_name($instance['name'] ?? null),
                'transcoding_hls'       => self::nested_boolean($transcoding, 'hls', 'enabled'),
                'transcoding_web_video' => self::nested_boolean($transcoding, 'web_videos', 'enabled'),
            ),
            'error' => null,
        );
    }

    private static function is_json_content_type(mixed $value): bool
    {
        if (! is_string($value) || '' === $value || strlen($value) > 255) {
            return false;
        }

        $parts = explode(';', strtolower($value), 2);
        return 'application/json' === trim($parts[0]);
    }

    private static function server_version(mixed $value): string
    {
        if (
            ! is_string($value)
            || '' === $value
            || strlen($value) > self::MAX_VERSION_BYTES
            || 1 !== preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/D', $value)
        ) {
            return '';
        }

        return $value;
    }

    private static function instance_name(mixed $value): string
    {
        if (
            ! is_string($value)
            || '' === $value
            || strlen($value) > self::MAX_INSTANCE_NAME_BYTES
            || preg_match('/[\x00-\x1F\x7F]/', $value)
        ) {
            return '';
        }

        $characters = array();
        $length = preg_match_all('/./us', $value, $characters);

        if (! is_int($length)) {
            return '';
        }

        return $length <= self::MAX_INSTANCE_NAME_CHARACTERS ? $value : '';
    }

    /** @param array<string, mixed> $source */
    private static function nested_boolean(array $source, string $section, string $field): ?bool
    {
        $nested = $source[$section] ?? null;
        if (! is_array($nested) || array_is_list($nested)) {
            return null;
        }

        $value = $nested[$field] ?? null;
        return is_bool($value) ? $value : null;
    }

    /**
     * @param array<string, mixed>|null $error
     * @return array{ok:false,data:null,error:array<string,mixed>}
     */
    private static function failure(?array $error): array
    {
        return array(
            'ok'    => false,
            'data'  => null,
            'error' => $error ?? PeerTube_Api_Error::invalid_response('api_error_missing'),
        );
    }
}

// EOF: includes/PeerTube_Api_Client.php
