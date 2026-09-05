<?php
/**
 * File: includes/PeerTube_Api_Client.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Bounded PeerTube API primitives that persist no remote response data.
 *
 * OAuth-client credentials, bootstrap credentials, and returned tokens remain
 * ephemeral caller-owned values. This class performs no option writes and
 * never returns an unreviewed raw response object.
 */
final class PeerTube_Api_Client implements PeerTube_Password_Grant_Api, PeerTube_Identity_Destination_Api, PeerTube_Token_Lifecycle_Api, PeerTube_Staged_Upload_Api, PeerTube_Remote_Reconciliation_Api
{
    private const CONFIG_PATH = '/api/v1/config';
    private const MAX_VERSION_BYTES = 64;
    private const MAX_INSTANCE_NAME_CHARACTERS = 120;
    private const MAX_INSTANCE_NAME_BYTES = 1024;
    private const MAX_IDENTIFIER_CHARACTERS = 191;
    private const MAX_TEXT_BYTES = 1024;
    private const MAX_SECRET_BYTES = 16384;
    private const MAX_TOKEN_LIFETIME_SECONDS = 315576000;
    private const MIN_USABLE_TOKEN_LIFETIME_SECONDS = 60;
    private const CHANNEL_PAGE_SIZE = 100;
    private const MAX_CHANNELS = 500;
    private const MAX_CHANNEL_PAGES = 5;

    public function __construct(private readonly PeerTube_Http_Client $http)
    {
    }

    public function origin(): string
    {
        return $this->http->origin();
    }

    /**
     * Perform one public, non-retrying PeerTube instance-detection request.
     *
     * @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null}
     */
    public function detect_instance(): array
    {
        $decoded = self::success_object(
            $this->http->get(self::CONFIG_PATH),
            200,
            'instance'
        );
        if (! $decoded['ok']) {
            return $decoded;
        }

        $server_version = self::server_version($decoded['data']['serverVersion'] ?? null);
        if ('' === $server_version) {
            return self::failure(PeerTube_Api_Error::invalid_response('instance_version_invalid', 200));
        }

        $instance = self::object($decoded['data']['instance'] ?? null);
        $transcoding = self::object($decoded['data']['transcoding'] ?? null);

        return self::success(
            array(
                'origin'                => $this->http->origin(),
                'server_version'        => $server_version,
                'instance_name'         => self::instance_name($instance['name'] ?? null),
                'transcoding_hls'       => self::nested_boolean($transcoding, 'hls', 'enabled'),
                'transcoding_web_video' => self::nested_boolean($transcoding, 'web_videos', 'enabled'),
            )
        );
    }

    /**
     * Fetch the instance-local OAuth client for immediate in-memory use.
     *
     * The returned client secret must not be logged, cached, or persisted.
     *
     * @return array{ok:bool,data:array<string,string>|null,error:array<string,mixed>|null}
     */
    public function local_oauth_client(): array
    {
        $decoded = self::success_object($this->http->get_local_oauth_client(), 200, 'oauth_client');
        if (! $decoded['ok']) {
            return $decoded;
        }

        $client_id = self::opaque_secret($decoded['data']['client_id'] ?? null, 1024);
        $client_secret = self::opaque_secret($decoded['data']['client_secret'] ?? null, self::MAX_SECRET_BYTES);
        if ('' === $client_id || '' === $client_secret) {
            return self::failure(PeerTube_Api_Error::invalid_response('oauth_client_shape_invalid', 200));
        }

        return self::success(
            array(
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
            )
        );
    }

    /**
     * Exchange ephemeral bootstrap credentials for an ephemeral token record.
     *
     * @param array<string, mixed> $oauth_client Exact output of local_oauth_client().
     * @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null}
     */
    public function password_token(
        array $oauth_client,
        string $username,
        string $password,
        string $otp,
        int $received_at
    ): array {
        if (array('client_id', 'client_secret') !== array_keys($oauth_client)) {
            return self::failure(PeerTube_Api_Error::invalid_response('oauth_client_input_invalid'));
        }

        $client_id = self::opaque_secret($oauth_client['client_id'] ?? null, 1024);
        $client_secret = self::opaque_secret($oauth_client['client_secret'] ?? null, self::MAX_SECRET_BYTES);
        if (
            '' === $client_id
            || '' === $client_secret
            || ! self::bounded_request_text($username, 1024, false)
            || ! self::bounded_request_text($password, self::MAX_SECRET_BYTES, true)
            || ('' !== $otp && 1 !== preg_match('/^[0-9]{6}$/D', $otp))
            || $received_at < 1
        ) {
            return self::failure(PeerTube_Api_Error::invalid_response('password_token_input_invalid'));
        }

        $response = $this->http->post_password_token(
            array(
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'username'      => $username,
                'password'      => $password,
                'response_type' => 'code',
                'grant_type'    => 'password',
                'scope'         => 'upload',
            ),
            $otp
        );
        $decoded = self::success_object($response, 200, 'token');
        if (! $decoded['ok']) {
            return $decoded;
        }

        $access_token = self::opaque_secret($decoded['data']['access_token'] ?? null, self::MAX_SECRET_BYTES);
        $refresh_token = self::opaque_secret($decoded['data']['refresh_token'] ?? null, self::MAX_SECRET_BYTES);
        $access_expires_at = self::absolute_expiry($decoded['data']['expires_in'] ?? null, $received_at);
        $refresh_expires_at = self::absolute_expiry(
            $decoded['data']['refresh_token_expires_in'] ?? null,
            $received_at
        );

        if (
            'Bearer' !== ($decoded['data']['token_type'] ?? null)
            || '' === $access_token
            || '' === $refresh_token
            || $access_token === $refresh_token
            || $access_expires_at < 1
            || $refresh_expires_at < 1
        ) {
            return self::failure(PeerTube_Api_Error::invalid_response('token_shape_invalid', 200));
        }

        return self::success(
            array(
                'access_token'       => $access_token,
                'refresh_token'      => $refresh_token,
                'access_expires_at'  => $access_expires_at,
                'refresh_expires_at' => $refresh_expires_at,
            )
        );
    }

    /**
     * Exchange one stored refresh token for a fresh bounded token pair.
     *
     * @param array<string, mixed> $oauth_client Exact output of local_oauth_client().
     * @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null}
     */
    public function refresh_token(
        array $oauth_client,
        string $refresh_token,
        int $received_at
    ): array {
        if (array('client_id', 'client_secret') !== array_keys($oauth_client)) {
            return self::failure(PeerTube_Api_Error::invalid_response('oauth_client_input_invalid'));
        }

        $client_id = self::opaque_secret($oauth_client['client_id'] ?? null, 1024);
        $client_secret = self::opaque_secret($oauth_client['client_secret'] ?? null, self::MAX_SECRET_BYTES);
        $refresh_token = self::opaque_secret($refresh_token, self::MAX_SECRET_BYTES);
        if ('' === $client_id || '' === $client_secret || '' === $refresh_token || $received_at < 1) {
            return self::failure(PeerTube_Api_Error::invalid_response('refresh_token_input_invalid'));
        }

        $decoded = self::success_object(
            $this->http->post_refresh_token(
                array(
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refresh_token,
                )
            ),
            200,
            'token'
        );
        if (! $decoded['ok']) {
            return $decoded;
        }

        $access_token = self::opaque_secret($decoded['data']['access_token'] ?? null, self::MAX_SECRET_BYTES);
        $new_refresh_token = self::opaque_secret($decoded['data']['refresh_token'] ?? null, self::MAX_SECRET_BYTES);
        $access_expires_at = self::absolute_expiry($decoded['data']['expires_in'] ?? null, $received_at);
        $refresh_expires_at = self::absolute_expiry(
            $decoded['data']['refresh_token_expires_in'] ?? null,
            $received_at
        );
        if (
            'Bearer' !== ($decoded['data']['token_type'] ?? null)
            || '' === $access_token
            || '' === $new_refresh_token
            || $access_token === $new_refresh_token
            || $access_expires_at < 1
            || $refresh_expires_at < 1
        ) {
            return self::failure(PeerTube_Api_Error::invalid_response('refresh_token_shape_invalid', 200));
        }

        return self::success(
            array(
                'access_token' => $access_token,
                'refresh_token' => $new_refresh_token,
                'access_expires_at' => $access_expires_at,
                'refresh_expires_at' => $refresh_expires_at,
            )
        );
    }

    /** @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null} */
    public function revoke_token(string $access_token): array
    {
        if ('' === self::opaque_secret($access_token, self::MAX_SECRET_BYTES)) {
            return self::failure(PeerTube_Api_Error::invalid_response('access_token_input_invalid'));
        }

        $response = $this->http->post_revoke_token($access_token);
        if (! is_array($response) || true !== ($response['ok'] ?? null)) {
            return self::failure(is_array($response['error'] ?? null) ? $response['error'] : null);
        }
        $http_status = is_int($response['http_status'] ?? null) ? $response['http_status'] : 0;
        if (200 !== $http_status) {
            return self::failure(PeerTube_Api_Error::invalid_response('revoke_status_invalid', $http_status));
        }

        return self::success(array('revoked' => true));
    }

    /** @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null} */
    public function begin_resumable_upload(
        string $access_token,
        string $destination_id,
        string $name,
        string $filename,
        string $content_type,
        int $total_bytes
    ): array {
        $access_token = self::opaque_secret($access_token, self::MAX_SECRET_BYTES);
        $destination_id = self::canonical_decimal_id($destination_id);
        $channel_id = '' === $destination_id ? false : filter_var(
            $destination_id,
            FILTER_VALIDATE_INT,
            array('options' => array('min_range' => 1))
        );
        if ('' === $access_token || false === $channel_id || ! self::valid_upload_name($name)
            || ! self::valid_upload_filename($filename) || 'video/mp4' !== $content_type || $total_bytes < 1) {
            return self::failure(PeerTube_Api_Error::invalid_response('upload_init_input_invalid'));
        }

        $response = $this->http->post_resumable_upload_init(
            $access_token,
            array(
                'channelId' => (int) $channel_id,
                'name'      => $name,
                'privacy'   => PeerTube_Staged_Upload_State_Machine::PRIVATE_PRIVACY,
                'filename'  => $filename,
            ),
            $total_bytes,
            $content_type
        );
        if (! ($response['ok'] ?? false)) {
            return self::failure(is_array($response['error'] ?? null) ? $response['error'] : null);
        }
        $status = is_int($response['http_status'] ?? null) ? $response['http_status'] : 0;
        if (! in_array($status, array(200, 201), true)) {
            return self::failure(PeerTube_Api_Error::invalid_response('upload_init_status_invalid', $status));
        }
        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : array();
        $session_id = self::upload_session_id_from_location($headers['location'] ?? '', $this->origin());
        if ('' === $session_id) {
            return self::failure(PeerTube_Api_Error::invalid_response('upload_init_location_invalid', $status));
        }
        return self::success(array('session_id' => $session_id));
    }

    /** @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null} */
    public function upload_resumable_chunk(
        string $access_token,
        string $session_id,
        int $start,
        int $total_bytes,
        string $content_type,
        string $chunk
    ): array {
        $access_token = self::opaque_secret($access_token, self::MAX_SECRET_BYTES);
        if ('' === $access_token || ! self::valid_upload_session_id($session_id)
            || $start < 0 || $total_bytes < 1 || $start >= $total_bytes
            || '' === $chunk || strlen($chunk) > PeerTube_Http_Client::MAX_UPLOAD_REQUEST_BYTES
            || $start > PHP_INT_MAX - strlen($chunk) || $start + strlen($chunk) > $total_bytes
            || 'video/mp4' !== $content_type) {
            return self::failure(PeerTube_Api_Error::invalid_response('upload_chunk_input_invalid'));
        }

        return self::upload_progress_projection(
            $this->http->put_resumable_upload_chunk(
                $access_token,
                $session_id,
                $start,
                $total_bytes,
                $content_type,
                $chunk
            ),
            $total_bytes,
            'upload_chunk'
        );
    }

    /** @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null} */
    public function upload_resumable_slice(
        string $access_token,
        string $session_id,
        int $start,
        int $total_bytes,
        string $content_type,
        PeerTube_Upload_Slice $slice
    ): array {
        $access_token = self::opaque_secret($access_token, self::MAX_SECRET_BYTES);
        if ('' === $access_token || ! self::valid_upload_session_id($session_id)
            || $start < 0 || $total_bytes < 1 || $start >= $total_bytes
            || $slice->start() !== $start || $slice->bytes() < 1
            || $start > PHP_INT_MAX - $slice->bytes() || $start + $slice->bytes() > $total_bytes
            || 'video/mp4' !== $content_type) {
            return self::failure(PeerTube_Api_Error::invalid_response('upload_slice_input_invalid'));
        }

        return self::upload_progress_projection(
            $this->http->put_resumable_upload_slice(
                $access_token,
                $session_id,
                $total_bytes,
                $content_type,
                $slice
            ),
            $total_bytes,
            'upload_slice'
        );
    }

    /** @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null} */
    public function probe_resumable_upload(string $access_token, string $session_id, int $total_bytes): array
    {
        $access_token = self::opaque_secret($access_token, self::MAX_SECRET_BYTES);
        if ('' === $access_token || ! self::valid_upload_session_id($session_id) || $total_bytes < 1) {
            return self::failure(PeerTube_Api_Error::invalid_response('upload_probe_input_invalid'));
        }
        return self::upload_progress_projection(
            $this->http->put_resumable_upload_probe($access_token, $session_id, $total_bytes),
            $total_bytes,
            'upload_probe'
        );
    }

    /** @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null} */
    public function video_status(string $access_token, string $video_uuid): array
    {
        $access_token = self::opaque_secret($access_token, self::MAX_SECRET_BYTES);
        $video_uuid = strtolower($video_uuid);
        if ('' === $access_token || 1 !== preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $video_uuid)) {
            return self::failure(PeerTube_Api_Error::invalid_response('remote_video_input_invalid'));
        }

        $decoded = self::success_object(
            $this->http->get_video_status($access_token, $video_uuid),
            200,
            'remote_video'
        );
        if (! $decoded['ok']) {
            return $decoded;
        }

        $data = $decoded['data'];
        $state = self::object($data['state'] ?? null);
        $privacy = self::object($data['privacy'] ?? null);
        $channel = self::object($data['channel'] ?? null);
        $id = self::canonical_decimal_id($data['id'] ?? null);
        $uuid = is_string($data['uuid'] ?? null) ? strtolower($data['uuid']) : '';
        $state_id = self::bounded_positive_integer($state['id'] ?? null, 9);
        $privacy_id = self::bounded_positive_integer($privacy['id'] ?? null, 255);
        $channel_id = self::canonical_decimal_id($channel['id'] ?? null);
        $embed_path = self::embed_path($data['embedPath'] ?? null);
        $is_live = $data['isLive'] ?? null;
        $is_local = $data['isLocal'] ?? null;

        if ('' === $id || ! hash_equals($video_uuid, $uuid) || $state_id < 1 || $privacy_id < 1
            || '' === $channel_id || '' === $embed_path || false !== $is_live || true !== $is_local) {
            return self::failure(PeerTube_Api_Error::invalid_response('remote_video_shape_invalid', 200));
        }

        return self::success(array(
            'id'          => $id,
            'uuid'        => $uuid,
            'state_id'    => $state_id,
            'privacy_id'  => $privacy_id,
            'channel_id'  => $channel_id,
            'embed_path'  => $embed_path,
            'is_live'     => false,
        ));
    }

    /**
     * Verify and minimally project the authenticated PeerTube identity.
     *
     * @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null}
     */
    public function current_identity(string $access_token): array
    {
        if ('' === self::opaque_secret($access_token, self::MAX_SECRET_BYTES)) {
            return self::failure(PeerTube_Api_Error::invalid_response('access_token_input_invalid'));
        }

        $decoded = self::success_object($this->http->get_current_user($access_token), 200, 'identity');
        if (! $decoded['ok']) {
            return $decoded;
        }

        $account = self::object($decoded['data']['account'] ?? null);
        $user_id = self::canonical_decimal_id($decoded['data']['id'] ?? null);
        $username = self::strict_text($decoded['data']['username'] ?? null, self::MAX_IDENTIFIER_CHARACTERS);
        $account_id = self::canonical_decimal_id($account['id'] ?? null);
        $account_name = self::strict_text($account['name'] ?? null, self::MAX_IDENTIFIER_CHARACTERS);
        $blocked = $decoded['data']['blocked'] ?? null;

        if (
            '' === $user_id
            || '' === $username
            || '' === $account_id
            || '' === $account_name
            || ! self::safe_machine_name($username)
            || ! self::safe_machine_name($account_name)
            || ! is_bool($blocked)
        ) {
            return self::failure(PeerTube_Api_Error::invalid_response('identity_shape_invalid', 200));
        }

        if ($blocked) {
            return self::failure(PeerTube_Api_Error::invalid_response('identity_blocked', 200));
        }

        return self::success(
            array(
                'user_id'      => $user_id,
                'username'     => $username,
                'account_id'   => $account_id,
                'account_name' => $account_name,
            )
        );
    }

    /**
     * Verify the bearer identity and discover only its local owned channels.
     *
     * The account-channel endpoint is public; the bearer token is deliberately
     * sent only to /users/me, never to that public listing. Keeping the
     * identity lookup inside this method prevents a caller from fabricating an
     * account-shaped array and minting management authority from public data.
     *
     * @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null}
     */
    public function owned_channels(string $access_token): array
    {
        $identity = $this->current_identity($access_token);
        if (! $identity['ok']) {
            return $identity;
        }

        $channels = $this->channels_for_identity($identity['data']);
        if (! $channels['ok']) {
            return $channels;
        }

        return self::success(
            array(
                'identity' => $identity['data'],
                'channels' => $channels['data']['channels'],
            )
        );
    }

    /**
     * @param array<string, mixed> $identity Verified current identity.
     * @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null}
     */
    private function channels_for_identity(array $identity): array
    {
        $account_id = self::canonical_decimal_id($identity['account_id'] ?? null);
        $account_name = self::strict_text($identity['account_name'] ?? null, self::MAX_IDENTIFIER_CHARACTERS);
        if (
            '' === $account_id
            || '' === $account_name
            || ! self::safe_machine_name($account_name)
        ) {
            return self::failure(PeerTube_Api_Error::invalid_response('channel_identity_input_invalid'));
        }

        $channels = array();
        $seen = array();
        $expected_total = null;
        $start = 0;
        $last_numeric_id = 0;
        $page_requests = 0;

        while (true) {
            if ($page_requests >= self::MAX_CHANNEL_PAGES) {
                return self::failure(PeerTube_Api_Error::invalid_response('channel_list_incomplete', 200));
            }
            ++$page_requests;

            $decoded = self::success_object(
                $this->http->get_account_channels($account_name, $start, self::CHANNEL_PAGE_SIZE),
                200,
                'channels'
            );
            if (! $decoded['ok']) {
                return $decoded;
            }

            $total = self::nonnegative_int($decoded['data']['total'] ?? null);
            $page = $decoded['data']['data'] ?? null;
            if (
                null === $total
                || $total > self::MAX_CHANNELS
                || ! is_array($page)
                || ! array_is_list($page)
                || count($page) > self::CHANNEL_PAGE_SIZE
                || (null !== $expected_total && $total !== $expected_total)
            ) {
                return self::failure(PeerTube_Api_Error::invalid_response('channel_list_shape_invalid', 200));
            }

            $expected_total ??= $total;
            $remaining = $expected_total - $start;
            $expected_page_length = min(self::CHANNEL_PAGE_SIZE, max(0, $remaining));
            if ($remaining < 0 || count($page) !== $expected_page_length) {
                return self::failure(PeerTube_Api_Error::invalid_response('channel_list_incomplete', 200));
            }

            foreach ($page as $candidate) {
                if (! is_array($candidate) || array_is_list($candidate)) {
                    return self::failure(PeerTube_Api_Error::invalid_response('channel_shape_invalid', 200));
                }

                $id = self::canonical_decimal_id($candidate['id'] ?? null);
                $name = self::strict_text($candidate['name'] ?? null, self::MAX_IDENTIFIER_CHARACTERS);
                $display_name = self::strict_text($candidate['displayName'] ?? null, 240);
                $owner = self::object($candidate['ownerAccount'] ?? null);
                $owner_id = self::canonical_decimal_id($owner['id'] ?? null);
                $is_local = $candidate['isLocal'] ?? null;
                $numeric_id = '' !== $id ? (int) $id : 0;

                if (
                    '' === $id
                    || '' === $name
                    || '' === $display_name
                    || ! self::safe_machine_name($name)
                    || $owner_id !== $account_id
                    || true !== $is_local
                    || isset($seen[$id])
                    || $numeric_id <= $last_numeric_id
                ) {
                    return self::failure(PeerTube_Api_Error::invalid_response('channel_authority_invalid', 200));
                }

                $seen[$id] = true;
                $last_numeric_id = $numeric_id;
                $channels[] = array(
                    'id'           => $id,
                    'name'         => $name,
                    'display_name' => $display_name,
                    'authority'    => 'owned',
                );
            }

            $start = count($channels);
            if ($start === $expected_total) {
                break;
            }

            if ($start > $expected_total || $start >= self::MAX_CHANNELS) {
                return self::failure(PeerTube_Api_Error::invalid_response('channel_list_incomplete', 200));
            }
        }

        return self::success(array('channels' => $channels));
    }

    /**
     * @param array<string, mixed> $response
     * @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null}
     */
    private static function success_object(array $response, int $expected_status, string $prefix): array
    {
        if (! ($response['ok'] ?? false)) {
            return self::failure(is_array($response['error'] ?? null) ? $response['error'] : null);
        }

        $http_status = is_int($response['http_status'] ?? null) ? $response['http_status'] : 0;
        if ($expected_status !== $http_status) {
            return self::failure(
                PeerTube_Api_Error::invalid_response($prefix . '_status_invalid', $http_status)
            );
        }

        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : array();
        if (! self::is_json_content_type($headers['content-type'] ?? '')) {
            return self::failure(
                PeerTube_Api_Error::invalid_response($prefix . '_content_type_invalid', $http_status)
            );
        }

        try {
            $decoded = json_decode((string) ($response['body'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::failure(PeerTube_Api_Error::invalid_response($prefix . '_json_invalid', $http_status));
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return self::failure(PeerTube_Api_Error::invalid_response($prefix . '_shape_invalid', $http_status));
        }

        return self::success($decoded);
    }

    private static function is_json_content_type(mixed $value): bool
    {
        if (! is_string($value) || '' === $value || strlen($value) > 255) {
            return false;
        }

        $parts = explode(';', strtolower($value), 2);
        return 'application/json' === trim($parts[0]);
    }

    /** @param array<string,mixed> $response @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null} */
    private static function upload_progress_projection(array $response, int $total_bytes, string $prefix): array
    {
        if (! ($response['ok'] ?? false)) {
            return self::failure(is_array($response['error'] ?? null) ? $response['error'] : null);
        }
        $status = is_int($response['http_status'] ?? null) ? $response['http_status'] : 0;
        if (308 === $status) {
            $headers = is_array($response['headers'] ?? null) ? $response['headers'] : array();
            $confirmed = self::confirmed_bytes_from_range($headers['range'] ?? '', $total_bytes);
            if (null === $confirmed) {
                return self::failure(PeerTube_Api_Error::invalid_response('upload_range_invalid', 308));
            }
            return self::success(array('state' => 'incomplete', 'confirmed_bytes' => $confirmed));
        }
        if (200 !== $status) {
            return self::failure(PeerTube_Api_Error::invalid_response($prefix . '_status_invalid', $status));
        }
        $decoded = self::success_object($response, 200, 'upload_response');
        if (! $decoded['ok']) {
            return $decoded;
        }
        $video = self::object($decoded['data']['video'] ?? null);
        $id = self::canonical_decimal_id($video['id'] ?? null);
        $uuid = is_string($video['uuid'] ?? null) ? strtolower($video['uuid']) : '';
        if ('' === $id || 1 !== preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $uuid)) {
            return self::failure(PeerTube_Api_Error::invalid_response('upload_response_shape_invalid', 200));
        }
        return self::success(array(
            'state' => 'created',
            'remote_identity' => array('id' => $id, 'uuid' => $uuid),
        ));
    }

    private static function upload_session_id_from_location(mixed $location, string $origin): string
    {
        if (! is_string($location) || '' === $location || strlen($location) > 1024) {
            return '';
        }
        $absolute = str_starts_with($location, '/') ? $origin . $location : $location;
        $parts = function_exists('wp_parse_url') ? wp_parse_url($absolute) : parse_url($absolute);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $host_output = false !== filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '[' . trim($host, '[]') . ']'
            : $host;
        $port = $parts['port'] ?? null;
        $candidate_origin = $scheme . '://' . $host_output . (is_int($port) ? ':' . $port : '');
        if (! hash_equals($origin, $candidate_origin) || '/api/v1/videos/upload-resumable' !== ($parts['path'] ?? '')) {
            return '';
        }
        $query = array();
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (array('upload_id') !== array_keys($query) || ! is_string($query['upload_id'])) {
            return '';
        }
        return self::valid_upload_session_id($query['upload_id']) ? $query['upload_id'] : '';
    }

    private static function confirmed_bytes_from_range(mixed $range, int $total_bytes): ?int
    {
        if ('' === $range) {
            return 0;
        }
        if (! is_string($range) || strlen($range) > 128
            || 1 !== preg_match('/^bytes=0-([0-9]+)$/D', trim($range), $matches)) {
            return null;
        }
        $end = filter_var($matches[1], FILTER_VALIDATE_INT, array('options' => array('min_range' => 0)));
        if (false === $end || $end >= $total_bytes || $end === PHP_INT_MAX) {
            return null;
        }
        return (int) $end + 1;
    }

    private static function valid_upload_session_id(string $value): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9._~-]{1,191}$/D', $value);
    }

    private static function bounded_positive_integer(mixed $value, int $maximum): int
    {
        if (! is_int($value) || $value < 1 || $value > $maximum) {
            return 0;
        }
        return $value;
    }

    private static function embed_path(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 255
            || 1 !== preg_match('#^/videos/embed/[A-Za-z0-9_-]{1,191}$#D', $value)) {
            return '';
        }
        return $value;
    }

    private static function valid_upload_filename(string $value): bool
    {
        return '' !== $value && strlen($value) <= 255 && basename($value) === $value
            && ! str_contains($value, '/') && ! str_contains($value, '\\') && 1 !== preg_match('/[\x00-\x1F\x7F]/', $value);
    }

    private static function valid_upload_name(string $value): bool
    {
        return '' !== self::strict_text($value, 120);
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
        return self::strict_text($value, self::MAX_INSTANCE_NAME_CHARACTERS);
    }

    /** @param array<string, mixed> $source */
    private static function nested_boolean(array $source, string $section, string $field): ?bool
    {
        $nested = self::object($source[$section] ?? null);
        $value = $nested[$field] ?? null;
        return is_bool($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        return is_array($value) && ! array_is_list($value) ? $value : array();
    }

    private static function strict_text(mixed $value, int $maximum_characters): string
    {
        if (! is_string($value) || strlen($value) > self::MAX_TEXT_BYTES || 1 !== preg_match('//u', $value)) {
            return '';
        }

        if ('' === $value) {
            return '';
        }

        if (
            trim($value) !== $value
            || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)
        ) {
            return '';
        }

        $characters = array();
        $length = preg_match_all('/./us', $value, $characters);
        return is_int($length) && $length <= $maximum_characters ? $value : '';
    }

    private static function bounded_request_text(string $value, int $maximum_bytes, bool $allow_whitespace): bool
    {
        if ('' === $value || strlen($value) > $maximum_bytes || 1 !== preg_match('//u', $value)) {
            return false;
        }

        if (1 === preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return false;
        }

        return $allow_whitespace || 1 !== preg_match('/\s/u', $value);
    }

    private static function safe_machine_name(string $value): bool
    {
        return strlen($value) <= 50
            && 1 === preg_match('/^[a-z0-9_]+(?:[a-z0-9_.-]+[a-z0-9_]+)?$/D', $value);
    }

    private static function opaque_secret(mixed $value, int $maximum_bytes): string
    {
        return is_string($value)
            && self::bounded_request_text($value, $maximum_bytes, false)
                ? $value
                : '';
    }

    private static function canonical_decimal_id(mixed $value): string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : '';
        }

        if (! is_string($value) || 1 !== preg_match('/^[1-9][0-9]*$/D', $value)) {
            return '';
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        return false !== $parsed && (string) $parsed === $value ? $value : '';
    }

    private static function nonnegative_int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || 1 !== preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 0)));
        return false !== $parsed && (string) $parsed === $value ? (int) $parsed : null;
    }

    private static function absolute_expiry(mixed $lifetime, int $received_at): int
    {
        $seconds = self::nonnegative_int($lifetime);
        if (
            null === $seconds
            || $seconds <= self::MIN_USABLE_TOKEN_LIFETIME_SECONDS
            || $seconds > self::MAX_TOKEN_LIFETIME_SECONDS
            || $received_at > PHP_INT_MAX - $seconds
        ) {
            return 0;
        }

        return $received_at + $seconds;
    }

    /** @param array<string, mixed> $data */
    private static function success(array $data): array
    {
        return array('ok' => true, 'data' => $data, 'error' => null);
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
