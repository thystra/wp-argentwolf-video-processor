<?php
/**
 * Browser-boundary assertions for the R38 PeerTube administrator smoke.
 *
 * This fixture is intentionally independent of WordPress and third-party HTTP
 * clients. It exercises the real WordPress login, capability, nonce, form,
 * redirect, and administrator-page boundaries over the isolated Docker
 * network. Response bodies, cookies, nonces, and credentials are never
 * printed.
 */

declare(strict_types=1);

ini_set('zend.exception_ignore_args', '1');
set_exception_handler(
    static function (Throwable $error): void {
        $message = $error instanceof RuntimeException
            ? $error->getMessage()
            : 'The browser fixture encountered an unexpected local failure.';
        fwrite(
            STDERR,
            'PEERTUBE_ADMIN_AUTHORIZATION_BROWSER_ASSERTION_FAILED: ' . $message . "\n"
        );
        exit(1);
    }
);

const AWVP_R38_PAGE_SLUG = 'argentwolf-video-processor-peertube';
const AWVP_R38_ACTION_START = 'argentwolf_video_processor_peertube_connection_start';
const AWVP_R38_ACTION_RESUME = 'argentwolf_video_processor_peertube_connection_resume';
const AWVP_R38_ACTION_GRANT = 'argentwolf_video_processor_peertube_connection_grant';
const AWVP_R38_ACTION_RECONCILE = 'argentwolf_video_processor_peertube_connection_reconcile';
const AWVP_R38_ACTION_VERIFY_IDENTITY = 'argentwolf_video_processor_peertube_connection_verify_identity';
const AWVP_R38_NONCE_FIELD = 'argentwolf_video_processor_peertube_nonce';
const AWVP_R38_NOTICE_QUERY = 'argentwolf_peertube_notice';
const AWVP_R38_OPERATION_QUERY = 'argentwolf_peertube_operation';

const AWVP_R38_BACKEND_ID = 'r38-admin';
const AWVP_R38_ORIGIN = 'http://peertube.test:9000';
const AWVP_R38_LABEL = 'R38 Admin Authorization';

const AWVP_R38_ADMIN_LOGIN = 'awvpadmin';
const AWVP_R38_ADMIN_PASSWORD = 'AWVP-disposable-test-only-123!';
const AWVP_R38_SUBSCRIBER_LOGIN = 'awvpsubscriber';
const AWVP_R38_SUBSCRIBER_PASSWORD = 'AWVP-subscriber-test-only-123!';

const AWVP_R38_GRANT_USERNAME = 'r37-success-user-canary';
const AWVP_R38_GRANT_PASSWORD = 'r37-success-password-canary';
const AWVP_R38_GRANT_OTP = '';

final class Awvp_R38_Http_Response
{
    /** @param list<string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body
    ) {
    }

    /** @return list<string> */
    public function header_values(string $name): array
    {
        $values = array();
        $pattern = '/^' . preg_quote($name, '/') . '\\s*:\\s*(.*)$/iD';
        foreach ($this->headers as $header) {
            if (1 === preg_match($pattern, $header, $matches)) {
                $values[] = trim($matches[1]);
            }
        }
        return $values;
    }
}

/** @throws RuntimeException */
function awvp_r38_assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array<string, string> $cookies
 * @param array<string, string>|null $fields
 */
function awvp_r38_request(
    string $base_url,
    string $method,
    string $path,
    array &$cookies,
    ?array $fields = null
): Awvp_R38_Http_Response {
    awvp_r38_assert(
        in_array($method, array('GET', 'POST'), true),
        'The browser fixture attempted an unreviewed HTTP method.'
    );
    awvp_r38_assert(
        str_starts_with($path, '/') && ! str_starts_with($path, '//'),
        'The browser fixture attempted an invalid local path.'
    );
    awvp_r38_assert(
        ('POST' === $method) === is_array($fields),
        'The browser fixture request body contract differed.'
    );

    $request_headers = array(
        'Accept: text/html, application/xhtml+xml',
        'Accept-Encoding: identity',
        'Connection: close',
        'User-Agent: ArgentWolf-Video-Processor-R38-Browser-Smoke',
    );

    if ([] !== $cookies) {
        ksort($cookies, SORT_STRING);
        $pairs = array();
        foreach ($cookies as $name => $value) {
            awvp_r38_assert(
                1 === preg_match('/^[!#$%&\'()*+\-.0-9A-Z^_`a-z|~]+$/D', $name)
                    && 0 === preg_match('/[\\x00-\\x20\\x7f;,]/D', $value),
                'The in-memory cookie jar contained an invalid cookie.'
            );
            $pairs[] = $name . '=' . $value;
        }
        $request_headers[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $content = '';
    if (is_array($fields)) {
        foreach ($fields as $name => $value) {
            awvp_r38_assert(
                is_string($name) && is_string($value),
                'The browser fixture form shape differed.'
            );
        }
        $content = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $request_headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $request_headers[] = 'Content-Length: ' . strlen($content);
    }

    $context = stream_context_create(
        array(
            'http' => array(
                'method'          => $method,
                'header'          => implode("\r\n", $request_headers),
                'content'         => $content,
                'ignore_errors'   => true,
                'follow_location' => 0,
                'max_redirects'   => 0,
                'protocol_version'=> 1.1,
                'timeout'         => 30,
            ),
        )
    );

    $http_response_header = array();
    $body = @file_get_contents($base_url . $path, false, $context, 0, 4 * 1024 * 1024);
    $headers = is_array($http_response_header) ? $http_response_header : array();
    awvp_r38_assert(
        false !== $body && [] !== $headers,
        'The isolated WordPress HTTP request failed.'
    );

    $status = 0;
    foreach ($headers as $header) {
        if (1 === preg_match('/^HTTP\\/[0-9.]+\\s+([0-9]{3})(?:\\s|$)/D', $header, $matches)) {
            $status = (int) $matches[1];
        }
    }
    awvp_r38_assert($status >= 100 && $status <= 599, 'The HTTP response status was malformed.');

    foreach ($headers as $header) {
        if (1 !== preg_match('/^Set-Cookie\\s*:\\s*([^;]*)(.*)$/iD', $header, $matches)) {
            continue;
        }
        $pair = explode('=', trim($matches[1]), 2);
        if (2 !== count($pair)) {
            continue;
        }
        [$name, $value] = $pair;
        if (1 !== preg_match('/^[!#$%&\'()*+\-.0-9A-Z^_`a-z|~]+$/D', $name)) {
            continue;
        }
        $attributes = strtolower($matches[2]);
        if ('' === $value || str_contains($attributes, 'max-age=0')) {
            unset($cookies[$name]);
            continue;
        }
        awvp_r38_assert(
            0 === preg_match('/[\\x00-\\x20\\x7f;,]/D', $value),
            'WordPress returned an invalid cookie value.'
        );
        $cookies[$name] = $value;
    }

    return new Awvp_R38_Http_Response($status, $headers, $body);
}

/** @param array<string, string> $cookies */
function awvp_r38_login(
    string $base_url,
    string $login,
    string $password,
    string $expected_landing_path,
    array &$cookies
): void {
    awvp_r38_assert(
        in_array(
            $expected_landing_path,
            array('/wp-admin/', '/wp-admin/profile.php'),
            true
        ),
        'The browser fixture attempted an unreviewed login landing path.'
    );

    $login_page = awvp_r38_request($base_url, 'GET', '/wp-login.php', $cookies);
    awvp_r38_assert(200 === $login_page->status, 'The WordPress login page was unavailable.');

    $response = awvp_r38_request(
        $base_url,
        'POST',
        '/wp-login.php',
        $cookies,
        array(
            'log'         => $login,
            'pwd'         => $password,
            'wp-submit'   => 'Log In',
            'redirect_to' => $base_url . $expected_landing_path,
            'testcookie'  => '1',
        )
    );
    awvp_r38_assert(302 === $response->status, 'The disposable WordPress login failed.');
    $locations = $response->header_values('Location');
    awvp_r38_assert(
        array($base_url . $expected_landing_path) === $locations,
        'WordPress returned an unexpected login redirect.'
    );

    $has_logged_in_cookie = false;
    foreach (array_keys($cookies) as $name) {
        if (str_starts_with($name, 'wordpress_logged_in_')) {
            $has_logged_in_cookie = true;
        }
    }
    awvp_r38_assert($has_logged_in_cookie, 'WordPress did not establish an authenticated session.');

    $landing = awvp_r38_request($base_url, 'GET', $expected_landing_path, $cookies);
    awvp_r38_assert(
        200 === $landing->status && [] === $landing->header_values('Location'),
        'The authenticated login landing page was unavailable.'
    );
}

/** @return array<string, string> */
function awvp_r38_attributes(string $source): array
{
    $attributes = array();
    $pattern = "~([A-Za-z_:][A-Za-z0-9_.:-]*)(?:\\s*=\\s*(?:\"([^\"]*)\"|'([^']*)'|([^\\s\"'=<>`]+)))?~";
    $matched = preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);
    awvp_r38_assert(false !== $matched, 'An administrator form attribute could not be parsed.');

    foreach ($matches as $match) {
        $name = strtolower($match[1]);
        awvp_r38_assert(
            ! array_key_exists($name, $attributes),
            'An administrator form contained a duplicate attribute.'
        );
        $value = '';
        if (isset($match[2]) && '' !== $match[2]) {
            $value = $match[2];
        } elseif (isset($match[3]) && '' !== $match[3]) {
            $value = $match[3];
        } elseif (isset($match[4]) && '' !== $match[4]) {
            $value = $match[4];
        }
        $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return $attributes;
}

/**
 * @return list<array{attributes:array<string,string>,inputs:list<array<string,string>>,html:string}>
 */
function awvp_r38_forms(string $body): array
{
    $matched = preg_match_all('~<form\\b([^>]*)>(.*?)</form>~is', $body, $matches, PREG_SET_ORDER);
    awvp_r38_assert(false !== $matched, 'The administrator forms could not be parsed.');

    $forms = array();
    foreach ($matches as $match) {
        $inputs = array();
        $input_match = preg_match_all('~<input\\b([^>]*)>~is', $match[2], $input_matches, PREG_SET_ORDER);
        awvp_r38_assert(false !== $input_match, 'An administrator form input could not be parsed.');
        foreach ($input_matches as $input) {
            $inputs[] = awvp_r38_attributes($input[1]);
        }
        $forms[] = array(
            'attributes' => awvp_r38_attributes($match[1]),
            'inputs'     => $inputs,
            'html'       => $match[0],
        );
    }
    return $forms;
}

/**
 * @param list<array<string, string>> $inputs
 * @return list<array<string, string>>
 */
function awvp_r38_named_inputs(array $inputs, string $name): array
{
    return array_values(
        array_filter(
            $inputs,
            static fn (array $input): bool => $name === ($input['name'] ?? null)
        )
    );
}

/**
 * Locate one exact action form, then parse its nonce only from that form.
 *
 * @return array{attributes:array<string,string>,inputs:list<array<string,string>>,html:string,nonce:string}
 */
function awvp_r38_action_form(string $body, string $action, string $base_url): array
{
    $matches = array();
    foreach (awvp_r38_forms($body) as $form) {
        $action_inputs = awvp_r38_named_inputs($form['inputs'], 'action');
        if (
            1 === count($action_inputs)
            && $action === ($action_inputs[0]['value'] ?? null)
        ) {
            $matches[] = $form;
        }
    }
    awvp_r38_assert(1 === count($matches), 'The expected administrator action form was not unique.');

    $form = $matches[0];
    awvp_r38_assert(
        'post' === strtolower($form['attributes']['method'] ?? '')
            && $base_url . '/wp-admin/admin-post.php' === ($form['attributes']['action'] ?? ''),
        'The administrator action form target differed.'
    );

    $nonce_inputs = awvp_r38_named_inputs($form['inputs'], AWVP_R38_NONCE_FIELD);
    awvp_r38_assert(1 === count($nonce_inputs), 'The action-scoped nonce field was not unique.');
    $nonce = $nonce_inputs[0]['value'] ?? '';
    awvp_r38_assert(
        1 === preg_match('/^[0-9A-Za-z]{10}$/D', $nonce),
        'The action-scoped nonce was malformed.'
    );
    $form['nonce'] = $nonce;
    return $form;
}

/**
 * @param array{inputs:list<array<string,string>>} $form
 */
function awvp_r38_form_value(array $form, string $name): string
{
    $inputs = awvp_r38_named_inputs($form['inputs'], $name);
    awvp_r38_assert(1 === count($inputs), 'An action form field was not unique.');
    awvp_r38_assert(
        array_key_exists('value', $inputs[0]),
        'An action form field lacked its required value.'
    );
    return $inputs[0]['value'];
}

function awvp_r38_assert_no_form(string $body, string $action): void
{
    foreach (awvp_r38_forms($body) as $form) {
        $action_inputs = awvp_r38_named_inputs($form['inputs'], 'action');
        awvp_r38_assert(
            ! (1 === count($action_inputs) && $action === ($action_inputs[0]['value'] ?? null)),
            'An unavailable administrator action form was rendered.'
        );
    }
}

/** @param array<string, string> $cookies */
function awvp_r38_settings_get(
    string $base_url,
    array &$cookies,
    string $operation_id = ''
): Awvp_R38_Http_Response {
    $query = array('page' => AWVP_R38_PAGE_SLUG);
    if ('' !== $operation_id) {
        $query[AWVP_R38_OPERATION_QUERY] = $operation_id;
    }
    $response = awvp_r38_request(
        $base_url,
        'GET',
        '/wp-admin/options-general.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
        $cookies
    );
    awvp_r38_assert(200 === $response->status, 'The PeerTube settings page was unavailable.');
    awvp_r38_assert(
        str_contains($response->body, 'PeerTube Connection — ArgentWolf Video Processor'),
        'The expected PeerTube settings page was not rendered.'
    );
    return $response;
}

/** @return array<string, string> */
function awvp_r38_query_pairs(string $query): array
{
    awvp_r38_assert('' !== $query, 'A redirect query was absent.');
    $pairs = array();
    foreach (explode('&', $query) as $component) {
        $parts = explode('=', $component, 2);
        awvp_r38_assert(2 === count($parts), 'A redirect query component was malformed.');
        $name = rawurldecode($parts[0]);
        $value = rawurldecode($parts[1]);
        awvp_r38_assert(
            '' !== $name && ! array_key_exists($name, $pairs),
            'A redirect query key was empty or repeated.'
        );
        $pairs[$name] = $value;
    }
    ksort($pairs, SORT_STRING);
    return $pairs;
}

/**
 * Require one exact fixed local 303 response. When $operation_id is null, the
 * redirect must supply and return the newly created operation identifier.
 */
function awvp_r38_redirect(
    Awvp_R38_Http_Response $response,
    string $base_url,
    string $notice,
    ?string $operation_id
): string {
    $allowlisted_notices = array(
        'connection_advanced',
        'ready_for_credentials',
        'otp_required',
        'credentials_required',
        'credentials_stored',
        'verification_advanced',
        'verification_failed',
        'identity_verified',
        'destination_verified',
        'destination_unavailable',
        'activation_advanced',
        'backend_activated',
        'lifecycle_advanced',
        'token_refreshed',
        'backend_disconnected',
        'refresh_rate_limited',
        'reauthentication_required',
        'lifecycle_indeterminate',
        'grant_indeterminate',
        'connection_conflict',
        'state_check_required',
        'state_may_have_changed',
        'request_refused',
        'invalid_request',
        'outside_checkpoint',
    );
    awvp_r38_assert(
        in_array($notice, $allowlisted_notices, true),
        'The fixture requested an unreviewed redirect notice.'
    );
    awvp_r38_assert(303 === $response->status, 'An administrator action did not return HTTP 303.');
    $locations = $response->header_values('Location');
    awvp_r38_assert(1 === count($locations), 'An administrator action returned an invalid Location header.');

    $parts = parse_url($locations[0]);
    awvp_r38_assert(is_array($parts), 'An administrator redirect URL was malformed.');
    awvp_r38_assert(
        'http' === ($parts['scheme'] ?? null)
            && 'wp' === ($parts['host'] ?? null)
            && ! array_key_exists('port', $parts)
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts)
            && ! array_key_exists('fragment', $parts)
            && '/wp-admin/options-general.php' === ($parts['path'] ?? null),
        'An administrator action did not redirect to the fixed local settings path.'
    );
    awvp_r38_assert(
        str_starts_with($locations[0], $base_url . '/wp-admin/options-general.php?'),
        'An administrator action redirected outside the isolated WordPress origin.'
    );

    $pairs = awvp_r38_query_pairs((string) ($parts['query'] ?? ''));
    $actual_operation_id = $pairs[AWVP_R38_OPERATION_QUERY] ?? '';
    if (null === $operation_id) {
        awvp_r38_assert(
            1 === preg_match('/^connection_[a-f0-9]{32}$/D', $actual_operation_id),
            'The start redirect did not contain one valid operation identifier.'
        );
        $operation_id = $actual_operation_id;
    }

    $expected = array(
        'page'                 => AWVP_R38_PAGE_SLUG,
        AWVP_R38_NOTICE_QUERY  => $notice,
    );
    if ('' !== $operation_id) {
        $expected[AWVP_R38_OPERATION_QUERY] = $operation_id;
    }
    ksort($expected, SORT_STRING);
    awvp_r38_assert($expected === $pairs, 'An administrator redirect query differed from its allowlist.');
    return $operation_id;
}

function awvp_r38_assert_rejection(
    Awvp_R38_Http_Response $response,
    string $required_message
): void {
    awvp_r38_assert(
        in_array($response->status, array(400, 403, 500), true),
        'A rejected administrator action returned an unexpected status.'
    );
    awvp_r38_assert(
        [] === $response->header_values('Location'),
        'A rejected administrator action unexpectedly redirected.'
    );
    awvp_r38_assert(
        str_contains($response->body, $required_message),
        'The administrator rejection boundary was evaluated out of order.'
    );
}

function awvp_r38_assert_no_grant_canaries(string $value): void
{
    $forbidden = array(
        AWVP_R38_GRANT_USERNAME,
        AWVP_R38_GRANT_PASSWORD,
        'r37-oauth-client-id',
        'r37-oauth-client-secret-canary',
        'r37-success-access-token-canary',
        'r37-success-refresh-token-canary',
    );
    foreach ($forbidden as $canary) {
        awvp_r38_assert(
            ! str_contains($value, $canary),
            'A credential or token canary escaped into a browser-visible response.'
        );
    }
}

/**
 * @return array{attributes:array<string,string>,inputs:list<array<string,string>>,html:string,nonce:string}
 */
function awvp_r38_assert_ready_grant_page(
    string $body,
    string $base_url,
    string $operation_id
): array {
    foreach (
        array(
            AWVP_R38_BACKEND_ID,
            AWVP_R38_ORIGIN,
            AWVP_R38_LABEL,
            $operation_id,
            'Ready for credentials (backend disabled)',
            '0 / 8',
            'PeerTube is an optional external service.',
            'Other installed server-side code attached to WordPress HTTP hooks can inspect requests transiently.',
            'Development-only warning: this allowlisted origin uses plaintext HTTP.',
            'The entered credentials and returned tokens are not protected by TLS in transit.',
        ) as $required
    ) {
        awvp_r38_assert(str_contains($body, $required), 'The ready-for-grant disclosure or state differed.');
    }
    awvp_r38_assert_no_grant_canaries($body);
    awvp_r38_assert_no_form($body, AWVP_R38_ACTION_RESUME);
    awvp_r38_assert_no_form($body, AWVP_R38_ACTION_RECONCILE);

    $form = awvp_r38_action_form($body, AWVP_R38_ACTION_GRANT, $base_url);
    awvp_r38_assert(
        $operation_id === awvp_r38_form_value($form, 'operation_id'),
        'The credential form operation identifier differed.'
    );

    foreach (array('username', 'password', 'otp') as $name) {
        $inputs = awvp_r38_named_inputs($form['inputs'], $name);
        awvp_r38_assert(1 === count($inputs), 'A credential input was not unique.');
        awvp_r38_assert(
            ! array_key_exists('value', $inputs[0]) || '' === $inputs[0]['value'],
            'A credential input was repopulated.'
        );
    }
    $passwords = awvp_r38_named_inputs($form['inputs'], 'password');
    awvp_r38_assert(
        'password' === ($passwords[0]['type'] ?? null),
        'The password input type differed.'
    );

    foreach (array('authorize_external_service', 'authorize_insecure_transport') as $name) {
        $consents = awvp_r38_named_inputs($form['inputs'], $name);
        awvp_r38_assert(1 === count($consents), 'A required authorization control was not unique.');
        awvp_r38_assert(
            'checkbox' === ($consents[0]['type'] ?? null)
                && '1' === ($consents[0]['value'] ?? null)
                && array_key_exists('required', $consents[0]),
            'A required authorization control differed.'
        );
    }
    return $form;
}

$base_url = getenv('AWVP_R38_WORDPRESS_URL');
awvp_r38_assert(
    'http://wp' === $base_url,
    'The R38 WordPress base URL must be the exact isolated origin.'
);

$admin_cookies = array();
$subscriber_cookies = array();
$unauthenticated_cookies = array();

$unauthenticated = awvp_r38_request(
    $base_url,
    'POST',
    '/wp-admin/admin-post.php',
    $unauthenticated_cookies,
    array('action' => AWVP_R38_ACTION_START)
);
awvp_r38_assert(
    400 === $unauthenticated->status
        && [] === $unauthenticated->header_values('Location'),
    'The unprivileged admin-post action was not rejected by WordPress.'
);

// Core maps the default admin destination to profile.php for a subscriber,
// which has read but not edit_posts. Supply and prove each exact landing path.
awvp_r38_login(
    $base_url,
    AWVP_R38_ADMIN_LOGIN,
    AWVP_R38_ADMIN_PASSWORD,
    '/wp-admin/',
    $admin_cookies
);
echo "WORDPRESS_ADMIN_LOGIN_BOUNDARY=PASS\n";
awvp_r38_login(
    $base_url,
    AWVP_R38_SUBSCRIBER_LOGIN,
    AWVP_R38_SUBSCRIBER_PASSWORD,
    '/wp-admin/profile.php',
    $subscriber_cookies
);
echo "WORDPRESS_SUBSCRIBER_LOGIN_BOUNDARY=PASS\n";

$initial_page = awvp_r38_settings_get($base_url, $admin_cookies);
$initial_start_form = awvp_r38_action_form(
    $initial_page->body,
    AWVP_R38_ACTION_START,
    $base_url
);
$admin_start_nonce = $initial_start_form['nonce'];

$method_rejection = awvp_r38_request(
    $base_url,
    'GET',
    '/wp-admin/admin-post.php?action=' . rawurlencode(AWVP_R38_ACTION_START),
    $admin_cookies
);
awvp_r38_assert_rejection(
    $method_rejection,
    'PeerTube connection actions require an explicit POST request.'
);

$start_fields = array(
    'action'              => AWVP_R38_ACTION_START,
    AWVP_R38_NONCE_FIELD  => $admin_start_nonce,
    'backend_id'          => AWVP_R38_BACKEND_ID,
    'origin'              => AWVP_R38_ORIGIN,
    'label'               => AWVP_R38_LABEL,
);
$subscriber_rejection = awvp_r38_request(
    $base_url,
    'POST',
    '/wp-admin/admin-post.php',
    $subscriber_cookies,
    $start_fields
);
awvp_r38_assert_rejection(
    $subscriber_rejection,
    'You are not allowed to administer PeerTube connections.'
);
awvp_r38_assert(
    ! str_contains($subscriber_rejection->body, 'could not be verified'),
    'The subscriber request reached nonce verification before authorization.'
);

$invalid_nonce_fields = $start_fields;
$invalid_nonce_fields[AWVP_R38_NONCE_FIELD] = 'invalid nonce';
$nonce_rejection = awvp_r38_request(
    $base_url,
    'POST',
    '/wp-admin/admin-post.php',
    $admin_cookies,
    $invalid_nonce_fields
);
awvp_r38_assert_rejection(
    $nonce_rejection,
    'The PeerTube connection request could not be verified.'
);

$pre_start_page = awvp_r38_settings_get($base_url, $admin_cookies);
$extra_fields = $start_fields;
$extra_fields[AWVP_R38_NONCE_FIELD] = awvp_r38_action_form(
    $pre_start_page->body,
    AWVP_R38_ACTION_START,
    $base_url
)['nonce'];
$extra_fields['unexpected'] = 'r38-extra-field-canary';
$extra_rejection = awvp_r38_request(
    $base_url,
    'POST',
    '/wp-admin/admin-post.php',
    $admin_cookies,
    $extra_fields
);
awvp_r38_redirect($extra_rejection, $base_url, 'invalid_request', '');

$unchanged_page = awvp_r38_settings_get($base_url, $admin_cookies);
awvp_r38_assert(
    ! str_contains($unchanged_page->body, AWVP_R38_BACKEND_ID)
        && ! str_contains($unchanged_page->body, AWVP_R38_LABEL)
        && str_contains($unchanged_page->body, 'No open PeerTube connection operations.'),
    'A rejected start request created a connection operation.'
);

$valid_start_fields = $start_fields;
$valid_start_fields[AWVP_R38_NONCE_FIELD] = awvp_r38_action_form(
    $unchanged_page->body,
    AWVP_R38_ACTION_START,
    $base_url
)['nonce'];
$start_response = awvp_r38_request(
    $base_url,
    'POST',
    '/wp-admin/admin-post.php',
    $admin_cookies,
    $valid_start_fields
);
$operation_id = awvp_r38_redirect(
    $start_response,
    $base_url,
    'connection_advanced',
    null
);

$resume_statuses = array(
    'Prepared',
    'Prepared',
    'Secret reservation planned',
    'Secret reservation planned',
    'Secret slot reserved',
    'Disabled backend link planned',
    'Disabled backend link planned',
);
$resume_posts = 0;
foreach ($resume_statuses as $status_label) {
    $operation_page = awvp_r38_settings_get($base_url, $admin_cookies, $operation_id);
    awvp_r38_assert(
        str_contains($operation_page->body, $status_label),
        'The local preparation phase was out of order.'
    );
    awvp_r38_assert_no_form($operation_page->body, AWVP_R38_ACTION_GRANT);
    $resume_form = awvp_r38_action_form(
        $operation_page->body,
        AWVP_R38_ACTION_RESUME,
        $base_url
    );
    awvp_r38_assert(
        $operation_id === awvp_r38_form_value($resume_form, 'operation_id'),
        'A resume form targeted the wrong operation.'
    );

    $resume_response = awvp_r38_request(
        $base_url,
        'POST',
        '/wp-admin/admin-post.php',
        $admin_cookies,
        array(
            'action'              => AWVP_R38_ACTION_RESUME,
            AWVP_R38_NONCE_FIELD  => $resume_form['nonce'],
            'operation_id'        => $operation_id,
        )
    );
    ++$resume_posts;
    awvp_r38_redirect(
        $resume_response,
        $base_url,
        'connection_advanced',
        $operation_id
    );
}
awvp_r38_assert(7 === $resume_posts, 'The browser fixture did not perform exactly seven resume posts.');

$ready_page = awvp_r38_settings_get($base_url, $admin_cookies, $operation_id);
$ready_form = awvp_r38_assert_ready_grant_page(
    $ready_page->body,
    $base_url,
    $operation_id
);
$read_only_page = awvp_r38_settings_get($base_url, $admin_cookies, $operation_id);
$ready_form = awvp_r38_assert_ready_grant_page(
    $read_only_page->body,
    $base_url,
    $operation_id
);

$grant_base = array(
    'action'                       => AWVP_R38_ACTION_GRANT,
    AWVP_R38_NONCE_FIELD           => $ready_form['nonce'],
    'operation_id'                 => $operation_id,
    'username'                     => AWVP_R38_GRANT_USERNAME,
    'password'                     => AWVP_R38_GRANT_PASSWORD,
    'otp'                          => AWVP_R38_GRANT_OTP,
    'authorize_external_service'   => '0',
    'authorize_insecure_transport' => '1',
);
$external_rejection = awvp_r38_request(
    $base_url,
    'POST',
    '/wp-admin/admin-post.php',
    $admin_cookies,
    $grant_base
);
awvp_r38_assert_no_grant_canaries(implode("\n", $external_rejection->headers));
awvp_r38_assert_no_grant_canaries($external_rejection->body);
awvp_r38_redirect(
    $external_rejection,
    $base_url,
    'invalid_request',
    $operation_id
);

$after_external = awvp_r38_settings_get($base_url, $admin_cookies, $operation_id);
$grant_form = awvp_r38_assert_ready_grant_page(
    $after_external->body,
    $base_url,
    $operation_id
);
$insecure_rejection_fields = $grant_base;
$insecure_rejection_fields[AWVP_R38_NONCE_FIELD] = $grant_form['nonce'];
$insecure_rejection_fields['authorize_external_service'] = '1';
$insecure_rejection_fields['authorize_insecure_transport'] = '0';
$insecure_rejection = awvp_r38_request(
    $base_url,
    'POST',
    '/wp-admin/admin-post.php',
    $admin_cookies,
    $insecure_rejection_fields
);
awvp_r38_assert_no_grant_canaries(implode("\n", $insecure_rejection->headers));
awvp_r38_assert_no_grant_canaries($insecure_rejection->body);
awvp_r38_redirect(
    $insecure_rejection,
    $base_url,
    'invalid_request',
    $operation_id
);

$after_insecure = awvp_r38_settings_get($base_url, $admin_cookies, $operation_id);
$grant_form = awvp_r38_assert_ready_grant_page(
    $after_insecure->body,
    $base_url,
    $operation_id
);
$valid_grant_fields = $grant_base;
$valid_grant_fields[AWVP_R38_NONCE_FIELD] = $grant_form['nonce'];
$valid_grant_fields['authorize_external_service'] = '1';
$valid_grant_fields['authorize_insecure_transport'] = '1';
$grant_response = awvp_r38_request(
    $base_url,
    'POST',
    '/wp-admin/admin-post.php',
    $admin_cookies,
    $valid_grant_fields
);
awvp_r38_assert_no_grant_canaries(implode("\n", $grant_response->headers));
awvp_r38_assert_no_grant_canaries($grant_response->body);
awvp_r38_redirect(
    $grant_response,
    $base_url,
    'connection_advanced',
    $operation_id
);

$pending_page = awvp_r38_settings_get($base_url, $admin_cookies, $operation_id);
awvp_r38_assert(
    str_contains($pending_page->body, 'Encrypted token write pending reconciliation'),
    'The successful grant did not expose its credential-free reconciliation state.'
);
awvp_r38_assert_no_grant_canaries($pending_page->body);
awvp_r38_assert_no_form($pending_page->body, AWVP_R38_ACTION_GRANT);
$reconcile_form = awvp_r38_action_form(
    $pending_page->body,
    AWVP_R38_ACTION_RECONCILE,
    $base_url
);
awvp_r38_assert(
    $operation_id === awvp_r38_form_value($reconcile_form, 'operation_id'),
    'The reconcile form targeted the wrong operation.'
);
$reconcile_response = awvp_r38_request(
    $base_url,
    'POST',
    '/wp-admin/admin-post.php',
    $admin_cookies,
    array(
        'action'              => AWVP_R38_ACTION_RECONCILE,
        AWVP_R38_NONCE_FIELD  => $reconcile_form['nonce'],
        'operation_id'        => $operation_id,
    )
);
awvp_r38_redirect(
    $reconcile_response,
    $base_url,
    'credentials_stored',
    $operation_id
);

$final_page = awvp_r38_settings_get($base_url, $admin_cookies, $operation_id);
awvp_r38_assert(
    str_contains($final_page->body, 'Encrypted tokens stored; verification pending'),
    'The final browser-visible operation state differed.'
);
awvp_r38_assert_no_grant_canaries($final_page->body);
awvp_r38_assert_no_form($final_page->body, AWVP_R38_ACTION_GRANT);
foreach (array('username', 'password', 'otp') as $credential_name) {
    awvp_r38_assert(
        [] === awvp_r38_named_inputs(
            array_merge(...array_column(awvp_r38_forms($final_page->body), 'inputs')),
            $credential_name
        ),
        'A credential input remained available after token storage.'
    );
}
awvp_r38_action_form($final_page->body, AWVP_R38_ACTION_VERIFY_IDENTITY, $base_url);

echo "PEERTUBE_ADMIN_AUTHORIZATION_HTTP_BOUNDARY=PASS\n";
echo "PEERTUBE_ADMIN_AUTHORIZATION_BROWSER_FLOW=PASS\n";

// EOF: tests/fixtures/peertube-admin-authorization-smoke/assert-browser.php
