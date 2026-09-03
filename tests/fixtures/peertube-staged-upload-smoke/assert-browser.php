<?php
/**
 * R43 browser prerequisite: establish the exact active PeerTube backend through
 * the already-qualified R39/R40 administrator flow. R43 intentionally exposes
 * no upload form or WordPress-triggerable upload action; the isolated upload
 * mutation is exercised later by the WP-CLI state fixture against the mock.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/peertube-identity-destination-smoke/assert-browser.php';

set_exception_handler(
    static function (Throwable $error): void {
        $message = $error instanceof RuntimeException
            ? $error->getMessage()
            : 'The R43 upload-prerequisite browser fixture encountered an unexpected local failure.';
        fwrite(STDERR, 'PEERTUBE_STAGED_UPLOAD_BROWSER_ASSERTION_FAILED: ' . $message . "\n");
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

// Reuse the already-qualified four-step R40 activation flow as R43 prerequisite.
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
        && str_contains($complete_page->body, '<code>r38-admin</code>')
        && str_contains($complete_page->body, '<td>active</td>'),
    'The completed activation did not close the operation with the expected active managed backend.'
);
awvp_r38_assert_no_form($complete_page->body, AWVP_R40_ACTION_ACTIVATE);
awvp_r38_assert_no_form($complete_page->body, AWVP_R38_ACTION_GRANT);

// R43 still exposes no upload action on this page. The later state fixture is
// the only reviewed caller of the upload service in this isolated matrix.
$complete_page = awvp_r38_settings_get($base_url, $admin_cookies, $operation_id);
awvp_r38_assert(
    ! str_contains($complete_page->body, 'peertube_staged_upload')
        && ! str_contains($complete_page->body, 'upload-resumable'),
    'R43 prematurely exposed a browser-triggerable upload action.'
);

echo "PEERTUBE_BACKEND_ACTIVATION_HTTP_BOUNDARY=PASS\n";
echo "PEERTUBE_BACKEND_ACTIVATION_BROWSER_FLOW=PASS\n";
echo "PEERTUBE_STAGED_UPLOAD_BROWSER_PREREQUISITE=PASS\n";

// EOF: tests/fixtures/peertube-staged-upload-smoke/assert-browser.php
