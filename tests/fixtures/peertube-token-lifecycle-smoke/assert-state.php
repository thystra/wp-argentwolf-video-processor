<?php
/** Authoritative postconditions for the R41 PeerTube token lifecycle smoke. */

declare(strict_types=1);

use ArgentVideo\Backend_Adapter_Factory;
use ArgentVideo\Backend_Capabilities;
use ArgentVideo\Backend_Registry;
use ArgentVideo\Managed_Backend_Secret_Store;
use ArgentVideo\PeerTube_Backend_Adapter;
use ArgentVideo\PeerTube_Connection_Operation_Store;
use ArgentVideo\PeerTube_Connection_State_Machine;
use ArgentVideo\PeerTube_Token_Lifecycle_Store;

if (! defined('ABSPATH')) {
    throw new RuntimeException('The R41 state fixture requires a loaded WordPress runtime.');
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$assert(is_plugin_active('argentwolf-video-processor/argentwolf-video-processor.php'), 'AWVP is not active.');
$assert(defined('WP_DEBUG') && true === WP_DEBUG, 'WP_DEBUG must be enabled.');
$assert(defined('WP_DEBUG_LOG') && true === WP_DEBUG_LOG, 'WP_DEBUG_LOG must be enabled.');
$assert(defined('WP_DEBUG_DISPLAY') && false === WP_DEBUG_DISPLAY, 'WP_DEBUG_DISPLAY must be disabled.');
$assert(defined('WP_HTTP_BLOCK_EXTERNAL') && true === WP_HTTP_BLOCK_EXTERNAL, 'External WordPress HTTP must be blocked.');
$assert(defined('WP_ACCESSIBLE_HOSTS') && 'peertube.test' === WP_ACCESSIBLE_HOSTS, 'The isolated host allowlist differed.');
$assert(defined('DISABLE_WP_CRON') && true === DISABLE_WP_CRON, 'Ambient WordPress cron must be disabled.');

global $wpdb;
$assert($wpdb instanceof wpdb, 'The WordPress database connection is unavailable.');

$journal = get_option(PeerTube_Connection_Operation_Store::OPTION, null);
$assert(is_array($journal) && is_array($journal['operations'] ?? null), 'The historical connection journal was unavailable.');
$matches = array_values(array_filter(
    $journal['operations'],
    static fn (array $record): bool => 'r38-admin' === ($record['backend_id'] ?? null)
));
$assert(1 === count($matches), 'The historical connection operation was not unique.');
$record = $matches[0];
$assert(PeerTube_Connection_State_Machine::valid($record), 'The historical connection operation was invalid.');
$assert(PeerTube_Connection_State_Machine::PHASE_COMPLETE === $record['phase'], 'R41 changed the completed connection phase.');
$assert(16 === $record['record_revision'], 'R41 changed the historical connection revision.');
$assert('101' === $record['selected_destination'], 'R41 changed the selected destination.');
$assert(1 === $record['secret_generation'], 'R41 rewrote historical connection generation evidence.');

$registry = new Backend_Registry();
$descriptor = $registry->get('r38-admin');
$assert(is_array($descriptor), 'The PeerTube descriptor was unavailable.');
$assert('retired' === ($descriptor['state'] ?? null), 'Disconnect did not retire the PeerTube descriptor.');
$assert('101' === ($descriptor['default_destination'] ?? null), 'Disconnect changed the verified destination.');
$assert('http://peertube.test:9000' === ($descriptor['config']['origin'] ?? null), 'Disconnect changed the PeerTube origin.');

$lifecycle = (new PeerTube_Token_Lifecycle_Store())->read('r38-admin');
$assert(is_array($lifecycle), 'The R41 lifecycle journal was unavailable.');
$assert('disconnect' === $lifecycle['action'], 'The lifecycle did not finish as disconnect.');
$assert('disconnect_complete' === $lifecycle['phase'], 'The disconnect lifecycle was incomplete.');
$assert(2 === $lifecycle['expected_generation'], 'Disconnect was not fenced to the refreshed secret generation.');
$assert(8 === $lifecycle['revision'], 'The lifecycle crossed an unexpected revision count.');
$assert(array() === $lifecycle['last_error'] && array() === $lifecycle['last_mutation'], 'Completed lifecycle retained transient error/mutation state.');

$secrets = new Managed_Backend_Secret_Store();
$assert(null === $secrets->read($record['secret_ref'], 'r38-admin'), 'The managed token record remained after disconnect.');
$factory = new Backend_Adapter_Factory(new PeerTube_Backend_Adapter($secrets));
$assert(! $registry->eligible('r38-admin', Backend_Capabilities::DELIVERY_EMBED, $factory), 'A retired PeerTube backend remained eligible.');
$assert(! $registry->eligible('r38-admin', Backend_Capabilities::PROCESSING_VIDEO, $factory), 'R41 exposed processing/upload capability.');

$forbidden = array(
    'r37-oauth-client-id',
    'r37-oauth-client-secret-canary',
    'r37-success-user-canary',
    'r37-success-password-canary',
    'r37-success-access-token-canary',
    'r37-success-refresh-token-canary',
    'r41-refreshed-access-token-canary',
    'r41-refreshed-refresh-token-canary',
);
$rows = $wpdb->get_results($wpdb->prepare('SELECT option_name, option_value, autoload FROM %i', $wpdb->options), ARRAY_A);
$assert(is_array($rows) && '' === (string) $wpdb->last_error, 'The option scan failed.');
$record_option = Managed_Backend_Secret_Store::OPTION . '_' . $record['secret_ref'];
$lifecycle_option = PeerTube_Token_Lifecycle_Store::OPTION_PREFIX . 'r38-admin';
$expected_autoload = function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no';
$seen_lifecycle = false;
foreach ($rows as $row) {
    foreach ($forbidden as $value) {
        $assert(! str_contains((string) $row['option_value'], $value), 'A plaintext R41 credential canary entered a WordPress option.');
    }
    $assert($record_option !== $row['option_name'], 'The deleted managed-secret record option remained.');
    if ($lifecycle_option === $row['option_name']) {
        $assert($expected_autoload === $row['autoload'], 'The lifecycle journal was autoloaded.');
        $seen_lifecycle = true;
    }
}
$assert($seen_lifecycle, 'The durable non-secret lifecycle journal was absent.');
$assert(! array_key_exists($lifecycle_option, wp_load_alloptions(true)), 'The lifecycle journal entered alloptions.');

$uploads = wp_get_upload_dir();
$assert(is_array($uploads) && empty($uploads['error']) && is_string($uploads['basedir'] ?? null), 'The WordPress upload base was unavailable.');
$managed_root = trailingslashit($uploads['basedir']) . 'argentwolf-video-processor';
$assert(! file_exists($managed_root) && ! is_link($managed_root), 'The token lifecycle changed managed upload storage.');
$attachment_count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE post_type = %s', $wpdb->posts, 'attachment'));
$assert('' === (string) $wpdb->last_error && 0 === $attachment_count, 'The token lifecycle changed attachment rows.');

echo "PEERTUBE_TOKEN_LIFECYCLE_STATE_ASSERTIONS=PASS\n";

// EOF
