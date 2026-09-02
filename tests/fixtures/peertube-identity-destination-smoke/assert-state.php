<?php
/**
 * Authoritative postconditions for the R39 identity/destination smoke.
 */

declare(strict_types=1);

use ArgentVideo\PeerTube_Connection_State_Machine;

define('AWVP_ADMIN_SMOKE_EXPECTED_PHASE', PeerTube_Connection_State_Machine::PHASE_ACTIVATION_READY);
define('AWVP_ADMIN_SMOKE_EXPECTED_REVISION', 13);
define('AWVP_ADMIN_SMOKE_EXPECTED_DESTINATION', '101');

require dirname(__DIR__) . '/peertube-admin-authorization-smoke/assert-state.php';

global $wpdb;
$journal_row = $wpdb->get_row(
    $wpdb->prepare(
        'SELECT option_value FROM %i WHERE option_name = %s',
        $wpdb->options,
        \ArgentVideo\PeerTube_Connection_Operation_Store::OPTION
    ),
    ARRAY_A
);
if (! is_array($journal_row) || ! is_string($journal_row['option_value'] ?? null)) {
    throw new RuntimeException('The R39 operation journal row was unavailable.');
}
foreach (array('Owned Channel 1', 'Owned Channel 101', 'channel_001', 'channel_101') as $ephemeral) {
    if (str_contains($journal_row['option_value'], $ephemeral)) {
        throw new RuntimeException('An ephemeral destination-list value entered the durable journal.');
    }
}

$option_rows = $wpdb->get_results(
    $wpdb->prepare('SELECT option_name, option_value FROM %i', $wpdb->options),
    ARRAY_A
);
if (! is_array($option_rows) || '' !== (string) $wpdb->last_error) {
    throw new RuntimeException('The R39 option-cache exclusion scan failed.');
}
foreach ($option_rows as $option_row) {
    if (! is_array($option_row) || ! is_string($option_row['option_value'] ?? null)) {
        throw new RuntimeException('The R39 option-cache exclusion scan returned a malformed row.');
    }
    foreach (array('Owned Channel 1', 'Owned Channel 101', 'channel_001', 'channel_101') as $ephemeral) {
        if (str_contains($option_row['option_value'], $ephemeral)) {
            throw new RuntimeException('An ephemeral destination-list value entered a WordPress option.');
        }
    }
}

echo "PEERTUBE_IDENTITY_DESTINATION_STATE_ASSERTIONS=PASS\n";

// EOF: tests/fixtures/peertube-identity-destination-smoke/assert-state.php
