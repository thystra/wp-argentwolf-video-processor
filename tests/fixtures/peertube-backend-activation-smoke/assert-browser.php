<?php
/**
 * Browser continuation for the R40 local backend-activation checkpoint.
 *
 * R39 first reaches a freshly verified activation-ready operation. R40 then
 * exercises only local registry/journal transitions. No additional PeerTube
 * request is authorized or expected.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/peertube-identity-destination-smoke/assert-browser.php';

set_exception_handler(
    static function (Throwable $error): void {
        $message = $error instanceof RuntimeException
            ? $error->getMessage()
            : 'The R40 browser fixture encountered an unexpected local failure.';
        fwrite(STDERR, 'PEERTUBE_BACKEND_ACTIVATION_BROWSER_ASSERTION_FAILED: ' . $message . "\n");
        exit(1);
    }
);

const AWVP_R40_ACTION_ACTIVATE = 'argentwolf_video_processor_peertube_connection_activate';

/** @param array<string,string> $cookies */
function awvp_r40_submit_activation(
    string $base_url,
    array &$cookies,
    string $operation_id,
    string $expected_notice
): void {
    $page = awvp_r38_settings_get($base_url, $cookies, $operation_id);
    awvp_r38_assert_no_grant_canaries($page->body);
    $form = awvp_r38_action_form($page->body, AWVP_R40_ACTION_ACTIVATE, $base_url);
    awvp_r38_assert(
        $operation_id === awvp_r38_form_value($form, 'operation_id'),
        'An activation form targeted the wrong operation.'
    );
    $response = awvp_r38_request(
        $base_url,
        'POST',
        '/wp-admin/admin-post.php',
        $cookies,
        array(
            'action'             => AWVP_R40_ACTION_ACTIVATE,
            AWVP_R38_NONCE_FIELD => $form['nonce'],
            'operation_id'       => $operation_id,
        )
    );
    awvp_r38_assert_no_grant_canaries(implode("\n", $response->headers));
    awvp_r38_assert_no_grant_canaries($response->body);
    awvp_r38_redirect($response, $base_url, $expected_notice, $operation_id);
}

// 1. Journal the exact registry mutation plan only.
awvp_r40_submit_activation($base_url, $admin_cookies, $operation_id, 'activation_advanced');
$planned_page = awvp_r38_settings_get($base_url, $admin_cookies, $operation_id);
awvp_r38_assert(
    str_contains($planned_page->body, 'Backend activation planned')
        && str_contains($planned_page->body, 'exact disabled-to-active registry mutation is journaled')
        && str_contains($planned_page->body, 'no PeerTube HTTP or media action occurs'),
    'The browser did not expose the exact activation-planned boundary.'
);

// 2. Apply the exact shared-registry CAS and stop before journal confirmation.
awvp_r40_submit_activation($base_url, $admin_cookies, $operation_id, 'activation_advanced');
$written_page = awvp_r38_settings_get($base_url, $admin_cookies, $operation_id);
awvp_r38_assert(
    str_contains($written_page->body, 'Backend activation planned'),
    'The registry-write boundary unexpectedly advanced the operation journal.'
);

// 3. Reconcile the already-active descriptor and journal confirmation.
awvp_r40_submit_activation($base_url, $admin_cookies, $operation_id, 'activation_advanced');
$pending_close_page = awvp_r38_settings_get($base_url, $admin_cookies, $operation_id);
awvp_r38_assert(
    str_contains($pending_close_page->body, 'Active backend pending final eligibility check')
        && str_contains($pending_close_page->body, 'Finalization re-proves the managed credential generation'),
    'The browser did not expose the exact active-pending-close boundary.'
);

// 4. Independently prove descriptor/secret/adapter health and close the journal.
awvp_r40_submit_activation($base_url, $admin_cookies, $operation_id, 'backend_activated');
$complete_page = awvp_r38_settings_get($base_url, $admin_cookies, $operation_id);
awvp_r38_assert_no_grant_canaries($complete_page->body);
awvp_r38_assert(
    str_contains($complete_page->body, 'No open PeerTube connection operations.')
        && str_contains(
            $complete_page->body,
            'Activation changes local AWVP registry state only; it does not upload media'
        ),
    'The completed activation did not close the operation without preserving the no-media-work boundary.'
);
awvp_r38_assert_no_form($complete_page->body, AWVP_R40_ACTION_ACTIVATE);
awvp_r38_assert_no_form($complete_page->body, AWVP_R38_ACTION_GRANT);

// Any additional network traffic would make the generic expected-request-log
// comparison fail. R40 itself never owns a PeerTube HTTP client.
echo "PEERTUBE_BACKEND_ACTIVATION_HTTP_BOUNDARY=PASS\n";
echo "PEERTUBE_BACKEND_ACTIVATION_BROWSER_FLOW=PASS\n";

// EOF: tests/fixtures/peertube-backend-activation-smoke/assert-browser.php
