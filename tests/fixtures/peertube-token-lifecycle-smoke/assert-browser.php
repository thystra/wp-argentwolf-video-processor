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
 * Locate one exact lifecycle form inside the expected managed-backend row.
 *
 * R41 renders lifecycle controls per managed backend.  The durable UI
 * invariant is therefore one row for the backend identity and one requested
 * action inside that row, not global uniqueness across every form on the
 * settings page.
 *
 * @return array{attributes:array<string,string>,inputs:list<array<string,string>>,html:string,nonce:string}
 */
function awvp_r41_backend_action_form(
    string $body,
    string $action,
    string $backend_id,
    string $base_url
): array {
    $matched = preg_match_all('~<tr\\b[^>]*>(.*?)</tr>~is', $body, $row_matches, PREG_SET_ORDER);
    awvp_r38_assert(false !== $matched, 'The managed-backend rows could not be parsed.');

    $rows = array_values(
        array_filter(
            $row_matches,
            static fn (array $match): bool => str_contains(
                $match[0],
                '<code>' . $backend_id . '</code>'
            )
        )
    );
    awvp_r38_assert(
        1 === count($rows),
        'The expected managed-backend row was not unique.'
    );

    $form = awvp_r38_action_form($rows[0][0], $action, $base_url);
    $backend_inputs = awvp_r38_named_inputs($form['inputs'], 'backend_id');
    awvp_r38_assert(
        1 === count($backend_inputs)
            && $backend_id === ($backend_inputs[0]['value'] ?? null),
        'The lifecycle form backend identity differed.'
    );
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
