<?php
/**
 * Browser continuation for the R39 identity/destination checkpoint.
 *
 * The reviewed R38 fixture first establishes one exact encrypted credential.
 * This continuation then exercises only the new explicit identity, discovery,
 * selection, and re-verification boundaries.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/peertube-admin-authorization-smoke/assert-browser.php';

set_exception_handler(
    static function (Throwable $error): void {
        $message = $error instanceof RuntimeException
            ? $error->getMessage()
            : 'The R39 browser fixture encountered an unexpected local failure.';
        fwrite(STDERR, 'PEERTUBE_IDENTITY_DESTINATION_BROWSER_ASSERTION_FAILED: ' . $message . "\n");
        exit(1);
    }
);

const AWVP_R39_ACTION_SELECT_DESTINATION =
    'argentwolf_video_processor_peertube_connection_select_destination';
const AWVP_R39_DISCOVER_QUERY = 'argentwolf_peertube_discover';
const AWVP_R39_DESTINATION_ID = '101';

/**
 * @return array{attributes:array<string,string>,inputs:list<array<string,string>>,html:string,nonce:string}
 */
function awvp_r39_discovery_form(string $body, string $base_url, string $operation_id): array
{
    $matches = array();
    foreach (awvp_r38_forms($body) as $form) {
        $discover = awvp_r38_named_inputs($form['inputs'], AWVP_R39_DISCOVER_QUERY);
        if (1 === count($discover) && '1' === ($discover[0]['value'] ?? null)) {
            $matches[] = $form;
        }
    }
    awvp_r38_assert(1 === count($matches), 'The explicit destination-read form was not unique.');
    $form = $matches[0];
    awvp_r38_assert(
        'get' === strtolower($form['attributes']['method'] ?? '')
            && $base_url . '/wp-admin/options-general.php' === ($form['attributes']['action'] ?? ''),
        'The destination-read form target differed.'
    );
    awvp_r38_assert(
        AWVP_R38_PAGE_SLUG === awvp_r38_form_value($form, 'page')
            && $operation_id === awvp_r38_form_value($form, AWVP_R38_OPERATION_QUERY),
        'The destination-read form was not bound to the exact operation.'
    );
    $nonce = awvp_r38_form_value($form, AWVP_R38_NONCE_FIELD);
    awvp_r38_assert(1 === preg_match('/^[0-9A-Za-z]{10}$/D', $nonce), 'The destination-read nonce was malformed.');
    $form['nonce'] = $nonce;
    return $form;
}

/** @param array<string, string> $cookies */
function awvp_r39_submit_verify(
    string $base_url,
    array &$cookies,
    string $operation_id,
    string $expected_notice
): void {
    $page = awvp_r38_settings_get($base_url, $cookies, $operation_id);
    awvp_r38_assert_no_grant_canaries($page->body);
    $form = awvp_r38_action_form(
        $page->body,
        AWVP_R38_ACTION_VERIFY_IDENTITY,
        $base_url
    );
    awvp_r38_assert(
        $operation_id === awvp_r38_form_value($form, 'operation_id'),
        'An identity-verification form targeted the wrong operation.'
    );
    $response = awvp_r38_request(
        $base_url,
        'POST',
        '/wp-admin/admin-post.php',
        $cookies,
        array(
            'action'             => AWVP_R38_ACTION_VERIFY_IDENTITY,
            AWVP_R38_NONCE_FIELD => $form['nonce'],
            'operation_id'       => $operation_id,
        )
    );
    awvp_r38_assert_no_grant_canaries(implode("\n", $response->headers));
    awvp_r38_assert_no_grant_canaries($response->body);
    awvp_r38_redirect($response, $base_url, $expected_notice, $operation_id);
}

// First request journals intent only. The second performs the exact
// authenticated /users/me plus deterministic public channel reads.
awvp_r39_submit_verify($base_url, $admin_cookies, $operation_id, 'verification_advanced');
$in_flight_page = awvp_r38_settings_get($base_url, $admin_cookies, $operation_id);
awvp_r38_assert(
    str_contains($in_flight_page->body, 'Identity verification in progress')
        && str_contains($in_flight_page->body, '/users/me')
        && str_contains($in_flight_page->body, 'No upload or remote mutation occurs.'),
    'The explicit authenticated verification disclosure differed.'
);
awvp_r39_submit_verify($base_url, $admin_cookies, $operation_id, 'identity_verified');

// The ordinary awaiting-destination GET remains local. Only the nonce-bound
// discovery form causes a new read-only remote sequence.
$awaiting_page = awvp_r38_settings_get($base_url, $admin_cookies, $operation_id);
awvp_r38_assert(
    str_contains($awaiting_page->body, 'Awaiting owned destination selection'),
    'Identity verification did not reach the destination gate.'
);
$discovery_form = awvp_r39_discovery_form($awaiting_page->body, $base_url, $operation_id);
$discovery_query = array(
    'page' => AWVP_R38_PAGE_SLUG,
    AWVP_R38_OPERATION_QUERY => $operation_id,
    AWVP_R39_DISCOVER_QUERY => '1',
    AWVP_R38_NONCE_FIELD => $discovery_form['nonce'],
);
$discovery_page = awvp_r38_request(
    $base_url,
    'GET',
    '/wp-admin/options-general.php?'
        . http_build_query($discovery_query, '', '&', PHP_QUERY_RFC3986),
    $admin_cookies
);
awvp_r38_assert(200 === $discovery_page->status, 'The explicit destination read did not return the settings page.');
awvp_r38_assert_no_grant_canaries($discovery_page->body);
awvp_r38_assert(
    str_contains($discovery_page->body, 'awvp_service')
        && str_contains($discovery_page->body, 'Owned Channel 1')
        && str_contains($discovery_page->body, 'Owned Channel 101'),
    'The bounded two-page owned-channel projection differed.'
);

$select_form = awvp_r38_action_form(
    $discovery_page->body,
    AWVP_R39_ACTION_SELECT_DESTINATION,
    $base_url
);
$destination_inputs = awvp_r38_named_inputs($select_form['inputs'], 'destination_id');
awvp_r38_assert(101 === count($destination_inputs), 'The destination chooser did not contain exactly 101 owned channels.');
$destination_ids = array_map(
    static fn (array $input): string => (string) ($input['value'] ?? ''),
    $destination_inputs
);
awvp_r38_assert(
    array_map('strval', range(1, 101)) === $destination_ids,
    'The destination chooser order or exact IDs differed.'
);

$selection_response = awvp_r38_request(
    $base_url,
    'POST',
    '/wp-admin/admin-post.php',
    $admin_cookies,
    array(
        'action'             => AWVP_R39_ACTION_SELECT_DESTINATION,
        AWVP_R38_NONCE_FIELD => $select_form['nonce'],
        'operation_id'       => $operation_id,
        'destination_id'     => AWVP_R39_DESTINATION_ID,
    )
);
awvp_r38_assert_no_grant_canaries(implode("\n", $selection_response->headers));
awvp_r38_assert_no_grant_canaries($selection_response->body);
awvp_r38_redirect(
    $selection_response,
    $base_url,
    'verification_advanced',
    $operation_id
);

// Selection clears the earlier identity. A final explicit verification binds
// the same exact channel to fresh bearer identity evidence and stops before
// activation.
awvp_r39_submit_verify($base_url, $admin_cookies, $operation_id, 'destination_verified');
$ready_page = awvp_r38_settings_get($base_url, $admin_cookies, $operation_id);
awvp_r38_assert_no_grant_canaries($ready_page->body);
awvp_r38_assert(
    str_contains($ready_page->body, 'Identity and destination verified; activation pending')
        && str_contains($ready_page->body, 'Activation changes only local AWVP backend-registry state')
        && str_contains($ready_page->body, 'performs no PeerTube HTTP request or media upload'),
    'The browser did not reach the exact activation-ready boundary.'
);
awvp_r38_assert_no_form($ready_page->body, AWVP_R38_ACTION_GRANT);
awvp_r38_assert_no_form($ready_page->body, AWVP_R38_ACTION_VERIFY_IDENTITY);
awvp_r38_assert_no_form($ready_page->body, AWVP_R39_ACTION_SELECT_DESTINATION);

echo "PEERTUBE_IDENTITY_DESTINATION_HTTP_BOUNDARY=PASS\n";
echo "PEERTUBE_IDENTITY_DESTINATION_BROWSER_FLOW=PASS\n";

// EOF: tests/fixtures/peertube-identity-destination-smoke/assert-browser.php
