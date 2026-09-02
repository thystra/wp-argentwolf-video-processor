<?php
/**
 * Browser continuation for the R41 explicit PeerTube token lifecycle.
 *
 * R40 first activates the backend. R41 then performs exactly one bounded
 * refresh-token grant and one bounded revoke before retiring local authority.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/peertube-backend-activation-smoke/assert-browser.php';

set_exception_handler(
    static function (Throwable $error): void {
        $message = $error instanceof RuntimeException
            ? $error->getMessage()
            : 'The R41 browser fixture encountered an unexpected lifecycle failure.';
        fwrite(STDERR, 'PEERTUBE_TOKEN_LIFECYCLE_BROWSER_ASSERTION_FAILED: ' . $message . "\n");
        exit(1);
    }
);

const AWVP_R41_ACTION_REFRESH = 'argentwolf_video_processor_peertube_token_refresh';
const AWVP_R41_ACTION_DISCONNECT = 'argentwolf_video_processor_peertube_disconnect';
const AWVP_R41_BACKEND_ID = 'r38-admin';

/**
 * Locate one exact lifecycle form scoped to the expected managed backend.
 *
 * The settings page may legitimately render the same lifecycle action for
 * more than one active PeerTube backend, so action-name uniqueness is not a
 * valid R41 invariant.
 *
 * @return array{attributes:array<string,string>,inputs:list<array<string,string>>,html:string,nonce:string}
 */
function awvp_r41_backend_action_form(
    string $body,
    string $action,
    string $backend_id,
    string $base_url
): array {
    $matches = array();
    foreach (awvp_r38_forms($body) as $form) {
        $action_inputs = awvp_r38_named_inputs($form['inputs'], 'action');
        $backend_inputs = awvp_r38_named_inputs($form['inputs'], 'backend_id');
        if (
            1 === count($action_inputs)
            && $action === ($action_inputs[0]['value'] ?? null)
            && 1 === count($backend_inputs)
            && $backend_id === ($backend_inputs[0]['value'] ?? null)
        ) {
            $matches[] = $form;
        }
    }
    awvp_r38_assert(
        1 === count($matches),
        'The expected backend-scoped administrator action form was not unique.'
    );

    $form = $matches[0];
    awvp_r38_assert(
        'post' === strtolower($form['attributes']['method'] ?? '')
            && $base_url . '/wp-admin/admin-post.php' === ($form['attributes']['action'] ?? ''),
        'The backend-scoped administrator action form target differed.'
    );

    $nonce_inputs = awvp_r38_named_inputs($form['inputs'], AWVP_R38_NONCE_FIELD);
    awvp_r38_assert(1 === count($nonce_inputs), 'The backend-scoped nonce field was not unique.');
    $nonce = $nonce_inputs[0]['value'] ?? '';
    awvp_r38_assert(
        1 === preg_match('/^[0-9A-Za-z]{10}$/D', $nonce),
        'The backend-scoped nonce was malformed.'
    );
    $form['nonce'] = $nonce;
    return $form;
}

/** @param array<string,string> $cookies */
function awvp_r41_submit_lifecycle(
    string $base_url,
    array &$cookies,
    string $action,
    string $expected_notice
): void {
    $page = awvp_r38_settings_get($base_url, $cookies);
    awvp_r38_assert_no_grant_canaries($page->body);
    $form = awvp_r41_backend_action_form(
        $page->body,
        $action,
        AWVP_R41_BACKEND_ID,
        $base_url
    );
    $response = awvp_r38_request(
        $base_url,
        'POST',
        '/wp-admin/admin-post.php',
        $cookies,
        array(
            'action'             => $action,
            AWVP_R38_NONCE_FIELD => $form['nonce'],
            'backend_id'         => AWVP_R41_BACKEND_ID,
        )
    );
    awvp_r38_assert_no_grant_canaries(implode("\n", $response->headers));
    awvp_r38_assert_no_grant_canaries($response->body);
    awvp_r38_redirect($response, $base_url, $expected_notice, '');
}

// Refresh is an explicit three-request local lifecycle: initialize; perform one
// reviewed token grant after a durable in-flight claim; independently confirm
// the new encrypted generation.
awvp_r41_submit_lifecycle($base_url, $admin_cookies, AWVP_R41_ACTION_REFRESH, 'lifecycle_advanced');
awvp_r41_submit_lifecycle($base_url, $admin_cookies, AWVP_R41_ACTION_REFRESH, 'lifecycle_advanced');
awvp_r41_submit_lifecycle($base_url, $admin_cookies, AWVP_R41_ACTION_REFRESH, 'token_refreshed');

$refreshed_page = awvp_r38_settings_get($base_url, $admin_cookies);
awvp_r38_assert(
    str_contains($refreshed_page->body, 'refresh_complete')
        && str_contains($refreshed_page->body, 'Disconnect PeerTube'),
    'The completed refresh did not preserve the explicitly managed active backend.'
);

// Disconnect owns one revoke only. Each later local retirement/delete boundary
// requires another explicit administrator request.
awvp_r41_submit_lifecycle($base_url, $admin_cookies, AWVP_R41_ACTION_DISCONNECT, 'lifecycle_advanced');
awvp_r41_submit_lifecycle($base_url, $admin_cookies, AWVP_R41_ACTION_DISCONNECT, 'lifecycle_advanced');
awvp_r41_submit_lifecycle($base_url, $admin_cookies, AWVP_R41_ACTION_DISCONNECT, 'lifecycle_advanced');
awvp_r41_submit_lifecycle($base_url, $admin_cookies, AWVP_R41_ACTION_DISCONNECT, 'lifecycle_advanced');
awvp_r41_submit_lifecycle($base_url, $admin_cookies, AWVP_R41_ACTION_DISCONNECT, 'lifecycle_advanced');
awvp_r41_submit_lifecycle($base_url, $admin_cookies, AWVP_R41_ACTION_DISCONNECT, 'backend_disconnected');

$complete_page = awvp_r38_settings_get($base_url, $admin_cookies);
awvp_r38_assert_no_grant_canaries($complete_page->body);
awvp_r38_assert(
    str_contains($complete_page->body, 'disconnect_complete')
        && str_contains($complete_page->body, 'retired')
        && str_contains($complete_page->body, 'No active remote credential action.')
        && str_contains($complete_page->body, 'No media upload, processing, publication'),
    'The completed disconnect did not expose the durable retired/no-media-work boundary.'
);
awvp_r38_assert_no_form($complete_page->body, AWVP_R41_ACTION_REFRESH);
awvp_r38_assert_no_form($complete_page->body, AWVP_R41_ACTION_DISCONNECT);

echo "PEERTUBE_TOKEN_LIFECYCLE_HTTP_BOUNDARY=PASS\n";
echo "PEERTUBE_TOKEN_LIFECYCLE_BROWSER_FLOW=PASS\n";

// EOF
