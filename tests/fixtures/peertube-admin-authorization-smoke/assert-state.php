<?php
/**
 * Authoritative postcondition checks for the R38 administrator boundary smoke.
 */

declare(strict_types=1);

use ArgentVideo\Backend_Registry;
use ArgentVideo\Managed_Backend_Secret_Store;
use ArgentVideo\PeerTube_Connection_Operation_Store;
use ArgentVideo\PeerTube_Connection_State_Machine;

if (! defined('ABSPATH')) {
    throw new RuntimeException('The R38 state fixture requires a loaded WordPress runtime.');
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    is_plugin_active('argentwolf-video-processor/argentwolf-video-processor.php'),
    'The AWVP plugin is not active.'
);
$assert(defined('WP_DEBUG') && true === WP_DEBUG, 'WP_DEBUG must be enabled.');
$assert(
    defined('WP_DEBUG_LOG') && true === WP_DEBUG_LOG,
    'WP_DEBUG_LOG must be enabled.'
);
$assert(
    defined('WP_DEBUG_DISPLAY') && false === WP_DEBUG_DISPLAY,
    'WP_DEBUG_DISPLAY must be disabled.'
);
$assert(
    defined('WP_HTTP_BLOCK_EXTERNAL') && true === WP_HTTP_BLOCK_EXTERNAL,
    'External WordPress HTTP must be blocked.'
);
$assert(
    defined('WP_ACCESSIBLE_HOSTS') && 'peertube.test' === WP_ACCESSIBLE_HOSTS,
    'Only the isolated PeerTube fixture may bypass the external HTTP block.'
);
$assert(
    defined('ARGENT_VIDEO_PEERTUBE_DEV_ORIGINS')
        && array('http://peertube.test:9000') === ARGENT_VIDEO_PEERTUBE_DEV_ORIGINS,
    'The development-origin allowlist differs from the isolated fixture.'
);
$assert(
    defined('DISABLE_WP_CRON') && true === DISABLE_WP_CRON,
    'Ambient WordPress cron must be disabled.'
);
$assert(
    isset($_SERVER['HTTP_HOST']) && 'wp' === $_SERVER['HTTP_HOST'],
    'WP-CLI must use the isolated WordPress request host.'
);

global $wpdb;
$assert($wpdb instanceof wpdb, 'The WordPress database connection is unavailable.');

$admin = get_user_by('login', 'awvpadmin');
$assert($admin instanceof WP_User, 'The disposable administrator is unavailable.');
$assert(user_can($admin, 'manage_options'), 'The disposable administrator lacks manage_options.');

$records = (new PeerTube_Connection_Operation_Store())->open_operations();
$assert(is_array($records), 'The operation journal was unreadable.');
$expect_complete = defined('AWVP_ADMIN_SMOKE_EXPECT_COMPLETE')
    && true === AWVP_ADMIN_SMOKE_EXPECT_COMPLETE;
if ($expect_complete) {
    $assert(array() === $records, 'A completed connection operation remained open.');
    $journal = get_option(PeerTube_Connection_Operation_Store::OPTION, null);
    $assert(
        is_array($journal)
            && 1 === ($journal['version'] ?? null)
            && is_array($journal['operations'] ?? null),
        'The completed operation journal was unavailable.'
    );
    $matches = array_values(
        array_filter(
            $journal['operations'],
            static fn (array $record): bool => 'r38-admin' === ($record['backend_id'] ?? null)
        )
    );
    $assert(1 === count($matches), 'The completed operation was not uniquely present in the journal.');
} else {
    $matches = array_values(
        array_filter(
            $records,
            static fn (array $record): bool => 'r38-admin' === ($record['backend_id'] ?? null)
        )
    );
    $assert(1 === count($matches), 'The R38 operation was not uniquely present.');
    $assert(1 === count($records), 'An unexpected open connection operation was present.');
}

$record = $matches[0];
$assert(PeerTube_Connection_State_Machine::valid($record), 'The R38 operation record was invalid.');
$assert(
    1 === preg_match('/^connection_[a-f0-9]{32}$/D', $record['operation_id']),
    'The operation identifier was malformed.'
);
$assert(
    1 === preg_match('/^managed_[a-f0-9]{32}$/D', $record['secret_ref']),
    'The managed-secret reference was malformed.'
);
$assert('r38-admin' === $record['backend_id'], 'The backend identity changed.');
$assert(
    'http://peertube.test:9000' === $record['origin'],
    'The authoritative PeerTube origin changed.'
);
$assert('R38 Admin Authorization' === $record['label'], 'The connection label changed.');
$expected_phase = defined('AWVP_ADMIN_SMOKE_EXPECTED_PHASE')
    ? AWVP_ADMIN_SMOKE_EXPECTED_PHASE
    : PeerTube_Connection_State_Machine::PHASE_SECRET_STORED;
$expected_revision = defined('AWVP_ADMIN_SMOKE_EXPECTED_REVISION')
    ? AWVP_ADMIN_SMOKE_EXPECTED_REVISION
    : 9;
$assert(
    $expected_phase === $record['phase'],
    'The browser sequence did not reach its exact expected connection phase.'
);
$assert(
    $expected_revision === $record['record_revision'],
    'The browser sequence crossed an unexpected revision count.'
);
$assert(1 === $record['grant_attempt_no'], 'The browser sequence did not perform exactly one grant attempt.');
$assert(1 === $record['secret_generation'], 'The encrypted secret generation was not confirmed.');
$expected_destination = defined('AWVP_ADMIN_SMOKE_EXPECTED_DESTINATION')
    ? AWVP_ADMIN_SMOKE_EXPECTED_DESTINATION
    : '';
$assert(
    $expected_destination === $record['selected_destination'],
    'The durable selected destination differed.'
);
if ('' !== $expected_destination) {
    $assert(
        array(
            'user_id'      => '17',
            'username'     => 'awvp_service',
            'account_id'   => '23',
            'account_name' => 'awvp_service',
        ) === $record['verified_identity'],
        'The bounded authenticated identity projection differed.'
    );
    $assert(
        1 === $record['verified_secret_generation']
            && $record['verified_at'] >= $record['activation_requested_at']
            && $record['activation_requested_at'] > 0
            && (int) $admin->ID === $record['activation_requested_by'],
        'Destination activation intent was not bound to fresh identity evidence.'
    );
}
$assert((int) $admin->ID === $record['created_by'], 'The administrator actor was not recorded exactly.');
$assert(
    $record['created_at'] > 0
        && $record['updated_at'] >= $record['created_at']
        && $record['grant_started_at'] >= $record['created_at']
        && $record['grant_started_at'] <= $record['updated_at'],
    'The operation timestamps were not ordered.'
);

$descriptor = (new Backend_Registry())->get('r38-admin');
$expected_descriptor_state = defined('AWVP_ADMIN_SMOKE_EXPECT_DESCRIPTOR_STATE')
    ? AWVP_ADMIN_SMOKE_EXPECT_DESCRIPTOR_STATE
    : 'disabled';
$expected_descriptor_destination = defined('AWVP_ADMIN_SMOKE_EXPECT_DESCRIPTOR_DESTINATION')
    ? AWVP_ADMIN_SMOKE_EXPECT_DESCRIPTOR_DESTINATION
    : '';
$expected_descriptor = array(
    'id'                  => 'r38-admin',
    'type'                => 'peertube',
    'label'               => 'R38 Admin Authorization',
    'state'               => $expected_descriptor_state,
    'default_destination' => $expected_descriptor_destination,
    'secret_ref'          => $record['secret_ref'],
    'config_version'      => 1,
    'config'              => array('origin' => 'http://peertube.test:9000'),
);
$assert($expected_descriptor === $descriptor, 'The backend descriptor differed from the expected checkpoint state.');

$secret = (new Managed_Backend_Secret_Store())->read(
    $record['secret_ref'],
    $record['backend_id']
);
$assert(is_array($secret), 'The authenticated-encrypted secret could not be read.');
$assert(
    array(
        'access_token',
        'refresh_token',
        'access_expires_at',
        'refresh_expires_at',
        'generation',
    ) === array_keys($secret),
    'The encrypted secret projection contained an unexpected field.'
);
$assert(
    'r37-success-access-token-canary' === $secret['access_token']
        && 'r37-success-refresh-token-canary' === $secret['refresh_token'],
    'The exact fixture tokens did not round-trip through encrypted storage.'
);
$assert(
    is_int($secret['access_expires_at'])
        && is_int($secret['refresh_expires_at'])
        && $secret['access_expires_at'] > $record['updated_at']
        && $secret['refresh_expires_at'] > $secret['access_expires_at']
        && 3600 === $secret['refresh_expires_at'] - $secret['access_expires_at']
        && 1 === $secret['generation'],
    'The stored token expiry or generation metadata differed.'
);
unset($secret);

$forbidden = array(
    'r37-oauth-client-id',
    'r37-oauth-client-secret-canary',
    'r37-success-user-canary',
    'r37-success-password-canary',
    'r37-success-access-token-canary',
    'r37-success-refresh-token-canary',
);
$rows = $wpdb->get_results(
    $wpdb->prepare('SELECT option_name, option_value, autoload FROM %i', $wpdb->options),
    ARRAY_A
);
$assert(is_array($rows) && '' === (string) $wpdb->last_error, 'The option scan failed.');

$private_prefix = Managed_Backend_Secret_Store::OPTION;
$private_names = array(
    Backend_Registry::OPTION,
    PeerTube_Connection_Operation_Store::OPTION,
    Managed_Backend_Secret_Store::OPTION,
    Managed_Backend_Secret_Store::OPTION . '_' . $record['secret_ref'],
);
$observed_private = array();
$expected_autoload = function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no';

foreach ($rows as $row) {
    $assert(
        is_array($row)
            && is_string($row['option_name'] ?? null)
            && is_string($row['option_value'] ?? null)
            && is_string($row['autoload'] ?? null),
        'An option row was malformed.'
    );
    foreach ($forbidden as $value) {
        $assert(
            ! str_contains($row['option_value'], $value),
            'A plaintext authorization canary entered a WordPress option.'
        );
    }

    if (
        in_array($row['option_name'], $private_names, true)
        || str_starts_with($row['option_name'], $private_prefix . '_')
    ) {
        $assert(
            in_array($row['option_name'], $private_names, true),
            'An unexpected managed-secret option was present.'
        );
        $assert(
            $expected_autoload === $row['autoload'],
            'A private R38 option was not stored non-autoload.'
        );
        $observed_private[] = $row['option_name'];
    }
}
sort($observed_private, SORT_STRING);
sort($private_names, SORT_STRING);
$assert($private_names === $observed_private, 'The exact private option set differed.');

$alloptions = wp_load_alloptions(true);
foreach ($private_names as $name) {
    $assert(! array_key_exists($name, $alloptions), 'A private R38 option was autoloaded.');
}
unset($rows, $alloptions);

$uploads = wp_get_upload_dir();
$assert(
    is_array($uploads)
        && empty($uploads['error'])
        && is_string($uploads['basedir'] ?? null),
    'The WordPress upload base was unavailable.'
);
$managed_root = trailingslashit($uploads['basedir']) . 'argentwolf-video-processor';
$assert(
    ! file_exists($managed_root) && ! is_link($managed_root),
    'The administrator connection flow changed managed upload storage.'
);
$attachment_count = (int) $wpdb->get_var(
    $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE post_type = %s', $wpdb->posts, 'attachment')
);
$assert('' === (string) $wpdb->last_error, 'The attachment postcondition query failed.');
$assert(0 === $attachment_count, 'The administrator connection flow changed attachment rows.');

echo "PEERTUBE_ADMIN_AUTHORIZATION_STATE_ASSERTIONS=PASS\n";

// EOF: tests/fixtures/peertube-admin-authorization-smoke/assert-state.php
