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

/** @param array<string,string> $cookies */
function awvp_r41_submit_lifecycle(
    string $base_url,
    array &$cookies,
    string $action,
    string $expected_notice
): void {
    $page = awvp_r38_settings_get($base_url, $cookies);
    awvp_r38_assert_no_grant_canaries($page->body);
    $form = awvp_r38_action_form($page->body, $action, $base_url);
    awvp_r38_assert(
        AWVP_R41_BACKEND_ID === awvp_r38_form_value($form, 'backend_id'),
        'A lifecycle action form targeted the wrong backend.'
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
