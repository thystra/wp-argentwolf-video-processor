<?php
/**
 * One restart-isolated real-WordPress assertion for the R37 grant service.
 */

declare(strict_types=1);

use ArgentVideo\Atomic_Option_Result;
use ArgentVideo\Backend_Registry;
use ArgentVideo\Managed_Backend_Secret_Store;
use ArgentVideo\PeerTube_Connection_Coordinator;
use ArgentVideo\PeerTube_Connection_Operation_Store;
use ArgentVideo\PeerTube_Connection_State_Machine;
use ArgentVideo\PeerTube_Password_Grant_Service;

if (! defined('ABSPATH')) {
    throw new RuntimeException('The password-grant fixture requires a loaded WordPress runtime.');
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$step = getenv('AWVP_R37_STEP');
$steps = array(
    'success-start',
    'success-manifest',
    'success-secret-plan',
    'success-secret-apply',
    'success-secret-confirm',
    'success-link-plan',
    'success-link-apply',
    'success-link-confirm',
    'success-ready',
    'otp-start',
    'otp-secret-plan',
    'otp-secret-apply',
    'otp-secret-confirm',
    'otp-link-plan',
    'otp-link-apply',
    'otp-link-confirm',
    'otp-ready',
    'transport-start',
    'transport-secret-plan',
    'transport-secret-apply',
    'transport-secret-confirm',
    'transport-link-plan',
    'transport-link-apply',
    'transport-link-confirm',
    'transport-ready',
    'success-submit',
    'success-reconcile',
    'otp-submit-required',
    'otp-reconcile-required',
    'otp-submit-success',
    'otp-reconcile-success',
    'transport-submit',
    'transport-reconcile',
    'transport-resubmit',
    'final',
);
$assert(is_string($step) && in_array($step, $steps, true), 'The R37 fixture step is not allowlisted.');
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
    defined('DISABLE_WP_CRON') && true === DISABLE_WP_CRON,
    'Ambient WordPress cron must be disabled.'
);
$assert(
    defined('ARGENT_VIDEO_PEERTUBE_DEV_ORIGINS')
        && array('http://peertube.test:9000') === ARGENT_VIDEO_PEERTUBE_DEV_ORIGINS,
    'The PeerTube development-origin allowlist differs from the isolated fixture.'
);
$assert(
    isset($_SERVER['HTTP_HOST']) && 'wp' === $_SERVER['HTTP_HOST'],
    'WP-CLI must use the isolated WordPress request host.'
);
$assert(
    class_exists(PeerTube_Password_Grant_Service::class),
    'The explicit password-grant service is unavailable.'
);

global $wpdb;
$assert($wpdb instanceof wpdb, 'The WordPress database connection is unavailable.');

$scenarios = array(
    'success' => array(
        'backend_id' => 'r37-success',
        'label'      => 'R37 Success',
        'now'        => 1900000000,
    ),
    'otp' => array(
        'backend_id' => 'r37-otp',
        'label'      => 'R37 OTP',
        'now'        => 1900010000,
    ),
    'transport' => array(
        'backend_id' => 'r37-transport',
        'label'      => 'R37 Transport',
        'now'        => 1900020000,
    ),
);
$origin = 'http://peertube.test:9000';

$tracked_options = static function () use ($wpdb): array {
    $query = $wpdb->prepare(
        'SELECT option_name, option_value, autoload FROM %i'
            . ' WHERE option_name = %s OR option_name = %s OR option_name LIKE %s'
            . ' ORDER BY option_name ASC',
        $wpdb->options,
        Backend_Registry::OPTION,
        PeerTube_Connection_Operation_Store::OPTION,
        $wpdb->esc_like(Managed_Backend_Secret_Store::OPTION) . '%'
    );
    $rows = $wpdb->get_results($query, ARRAY_A);
    if ('' !== (string) $wpdb->last_error || ! is_array($rows)) {
        throw new RuntimeException('The authoritative R37 option snapshot failed.');
    }

    $snapshot = array();
    foreach ($rows as $row) {
        if (
            ! is_array($row)
            || ! is_string($row['option_name'] ?? null)
            || ! is_string($row['option_value'] ?? null)
            || ! is_string($row['autoload'] ?? null)
        ) {
            throw new RuntimeException('An R37 option snapshot row was malformed.');
        }
        $snapshot[$row['option_name']] = array(
            'option_value' => $row['option_value'],
            'autoload'     => $row['autoload'],
        );
    }

    return $snapshot;
};

$changed_options = static function (array $before, array $after): array {
    $names = array_unique(array_merge(array_keys($before), array_keys($after)));
    $changed = array();
    foreach ($names as $name) {
        if (
            ! array_key_exists($name, $before)
            || ! array_key_exists($name, $after)
            || $before[$name] !== $after[$name]
        ) {
            $changed[] = $name;
        }
    }
    sort($changed, SORT_STRING);
    return $changed;
};

$tree_snapshot = static function (string $root): array {
    if (! file_exists($root) && ! is_link($root)) {
        return array('exists' => false, 'entries' => array());
    }
    if (! is_dir($root) || is_link($root)) {
        throw new RuntimeException('The managed upload root is not a normal directory.');
    }

    $entries = array();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $path => $info) {
        $relative = substr((string) $path, strlen($root) + 1);
        if (! is_string($relative) || '' === $relative) {
            throw new RuntimeException('A managed upload path could not be normalized.');
        }
        $entries[$relative] = array(
            'type' => $info->isLink() ? 'link' : ($info->isDir() ? 'directory' : 'file'),
            'size' => $info->isFile() ? $info->getSize() : 0,
        );
    }
    ksort($entries, SORT_STRING);
    return array('exists' => true, 'entries' => $entries);
};

$uploads = wp_get_upload_dir();
$assert(
    is_array($uploads)
        && empty($uploads['error'])
        && is_string($uploads['basedir'] ?? null),
    'The WordPress upload base is unavailable.'
);
$managed_upload_root = trailingslashit($uploads['basedir']) . 'argentwolf-video-processor';
$options_before = $tracked_options();
$uploads_before = $tree_snapshot($managed_upload_root);
$attachments_before = (int) $wpdb->get_var(
    $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE post_type = %s', $wpdb->posts, 'attachment')
);
$assert('' === (string) $wpdb->last_error, 'The attachment baseline query failed.');

$assert_unregistered = static function () use ($assert): void {
    $filters = $GLOBALS['wp_filter'] ?? array();
    $assert(is_array($filters), 'The WordPress hook registry was malformed.');
    foreach ($filters as $hook) {
        if (! $hook instanceof WP_Hook) {
            continue;
        }
        foreach ($hook->callbacks as $priorities) {
            if (! is_array($priorities)) {
                continue;
            }
            foreach ($priorities as $registration) {
                $callback = is_array($registration) ? ($registration['function'] ?? null) : null;
                if ($callback instanceof PeerTube_Password_Grant_Service) {
                    $assert(false, 'The password-grant service was registered as a runtime callback.');
                }
                if (is_array($callback)) {
                    $owner = $callback[0] ?? null;
                    $assert(
                        ! $owner instanceof PeerTube_Password_Grant_Service
                            && PeerTube_Password_Grant_Service::class !== $owner,
                        'The password-grant service was registered as a runtime callback.'
                    );
                }
            }
        }
    }
};
$assert_unregistered();

$operation_record = static function (string $backend_id) use ($assert): array {
    $records = (new PeerTube_Connection_Operation_Store())->open_operations();
    $assert(is_array($records), 'The R37 operation journal was unreadable.');
    $matches = array_values(
        array_filter(
            $records,
            static fn (array $record): bool => $backend_id === ($record['backend_id'] ?? null)
        )
    );
    $assert(1 === count($matches), 'The expected R37 operation was not uniquely present.');
    $record = $matches[0];
    $assert(PeerTube_Connection_State_Machine::valid($record), 'An R37 operation record was invalid.');
    return $record;
};

$assert_record = static function (
    array $record,
    array $scenario,
    string $phase,
    int $revision
) use ($assert, $origin): void {
    $assert($scenario['backend_id'] === $record['backend_id'], 'The backend identity changed.');
    $assert($scenario['label'] === $record['label'], 'The backend label changed.');
    $assert($origin === $record['origin'], 'The authoritative PeerTube origin changed.');
    $assert($phase === $record['phase'], 'The operation phase was out of order.');
    $assert($revision === $record['record_revision'], 'The operation revision was out of order.');
    $assert(
        1 === preg_match('/^connection_[a-f0-9]{32}$/D', $record['operation_id']),
        'The operation identifier was malformed.'
    );
    $assert(
        1 === preg_match('/^managed_[a-f0-9]{32}$/D', $record['secret_ref']),
        'The managed-secret reference was malformed.'
    );
};

$assert_projection = static function (
    array $result,
    string $status,
    string $phase,
    int $revision,
    string $mutation,
    string $backend_id
) use ($assert): void {
    $assert(
        array(
            'status',
            'mutation',
            'operation_id',
            'backend_id',
            'phase',
            'record_revision',
            'retry_after',
        ) === array_keys($result),
        'The grant service returned an unreviewed public field.'
    );
    $assert($status === $result['status'], 'The grant service status differed.');
    $assert($mutation === $result['mutation'], 'The grant service mutation classification differed.');
    $assert($backend_id === $result['backend_id'], 'The projected backend identity differed.');
    $assert($phase === $result['phase'], 'The projected grant phase differed.');
    $assert($revision === $result['record_revision'], 'The projected record revision differed.');
    $assert(0 === $result['retry_after'], 'An unexpected retry interval escaped the grant service.');
    $assert(
        1 === preg_match('/^connection_[a-f0-9]{32}$/D', $result['operation_id']),
        'The projected operation identifier was malformed.'
    );

    $encoded = json_encode($result, JSON_THROW_ON_ERROR);
    foreach (array('password', 'token', 'oauth', '731946', 'user-canary') as $forbidden) {
        $assert(! str_contains(strtolower($encoded), $forbidden), 'A secret marker escaped in a projection.');
    }
};

$coordinator = new PeerTube_Connection_Coordinator();
$service = new PeerTube_Password_Grant_Service(
    null,
    null,
    null,
    null,
    static fn (int $minimum): int => $minimum
);
$expected_changes = array();

$preparation = array(
    'success-start'          => array('success', 'start', '', 0, 'prepared', 1),
    'success-manifest'       => array('success', 'resume', 'prepared', 1, 'prepared', 1),
    'success-secret-plan'    => array('success', 'resume', 'prepared', 1, 'secret_reserve_planned', 2),
    'success-secret-apply'   => array('success', 'resume', 'secret_reserve_planned', 2, 'secret_reserve_planned', 2),
    'success-secret-confirm' => array('success', 'resume', 'secret_reserve_planned', 2, 'secret_reserved', 3),
    'success-link-plan'      => array('success', 'resume', 'secret_reserved', 3, 'link_planned', 4),
    'success-link-apply'     => array('success', 'resume', 'link_planned', 4, 'link_planned', 4),
    'success-link-confirm'   => array('success', 'resume', 'link_planned', 4, 'disabled', 5),
    'success-ready'          => array('success', 'ready', 'disabled', 5, 'disabled', 5),
    'otp-start'              => array('otp', 'start', '', 0, 'prepared', 1),
    'otp-secret-plan'        => array('otp', 'resume', 'prepared', 1, 'secret_reserve_planned', 2),
    'otp-secret-apply'       => array('otp', 'resume', 'secret_reserve_planned', 2, 'secret_reserve_planned', 2),
    'otp-secret-confirm'     => array('otp', 'resume', 'secret_reserve_planned', 2, 'secret_reserved', 3),
    'otp-link-plan'          => array('otp', 'resume', 'secret_reserved', 3, 'link_planned', 4),
    'otp-link-apply'         => array('otp', 'resume', 'link_planned', 4, 'link_planned', 4),
    'otp-link-confirm'       => array('otp', 'resume', 'link_planned', 4, 'disabled', 5),
    'otp-ready'              => array('otp', 'ready', 'disabled', 5, 'disabled', 5),
    'transport-start'          => array('transport', 'start', '', 0, 'prepared', 1),
    'transport-secret-plan'    => array('transport', 'resume', 'prepared', 1, 'secret_reserve_planned', 2),
    'transport-secret-apply'   => array('transport', 'resume', 'secret_reserve_planned', 2, 'secret_reserve_planned', 2),
    'transport-secret-confirm' => array('transport', 'resume', 'secret_reserve_planned', 2, 'secret_reserved', 3),
    'transport-link-plan'      => array('transport', 'resume', 'secret_reserved', 3, 'link_planned', 4),
    'transport-link-apply'     => array('transport', 'resume', 'link_planned', 4, 'link_planned', 4),
    'transport-link-confirm'   => array('transport', 'resume', 'link_planned', 4, 'disabled', 5),
    'transport-ready'          => array('transport', 'ready', 'disabled', 5, 'disabled', 5),
);

if (isset($preparation[$step])) {
    [$scenario_name, $action, $before_phase, $before_revision, $after_phase, $revision] = $preparation[$step];
    $scenario = $scenarios[$scenario_name];

    if ('start' === $action) {
        $result = $coordinator->start(
            array(
                'backend_id' => $scenario['backend_id'],
                'origin'     => $origin,
                'label'      => $scenario['label'],
            ),
            1,
            $scenario['now']
        );
        $expected_changes = array(PeerTube_Connection_Operation_Store::OPTION);
    } else {
        $record = $operation_record($scenario['backend_id']);
        $assert_record($record, $scenario, $before_phase, $before_revision);
        $result = $coordinator->resume($record['operation_id'], $scenario['now'] + $revision);

        $expected_changes = match (true) {
            'success-manifest' === $step => array(Managed_Backend_Secret_Store::OPTION),
            str_ends_with($step, 'secret-plan'),
            str_ends_with($step, 'secret-confirm'),
            str_ends_with($step, 'link-plan'),
            str_ends_with($step, 'link-confirm') => array(PeerTube_Connection_Operation_Store::OPTION),
            str_ends_with($step, 'secret-apply') => array(
                Managed_Backend_Secret_Store::OPTION . '_' . $record['secret_ref'],
            ),
            str_ends_with($step, 'link-apply') => array(Backend_Registry::OPTION),
            default => array(),
        };
    }

    $record = $operation_record($scenario['backend_id']);
    $assert_record($record, $scenario, $after_phase, $revision);
    $expected_status = 'ready' === $action
        ? PeerTube_Connection_Coordinator::STATUS_READY_FOR_GRANT
        : PeerTube_Connection_Coordinator::STATUS_ADVANCED;
    $assert($expected_status === $result['status'], 'The local coordinator returned an unexpected status.');
    $assert($after_phase === $result['phase'], 'The local coordinator projected the wrong phase.');
} elseif ('success-submit' === $step) {
    $record = $operation_record($scenarios['success']['backend_id']);
    $result = $service->submit(
        $record['operation_id'],
        'r37-success-user-canary',
        'r37-success-password-canary',
        '',
        1900000100
    );
    $assert_projection(
        $result,
        PeerTube_Password_Grant_Service::STATUS_ADVANCED,
        PeerTube_Connection_State_Machine::PHASE_SECRET_WRITE_PLANNED,
        8,
        Atomic_Option_Result::MUTATION_APPLIED,
        $scenarios['success']['backend_id']
    );
    $record = $operation_record($scenarios['success']['backend_id']);
    $expected_changes = array(
        PeerTube_Connection_Operation_Store::OPTION,
        Managed_Backend_Secret_Store::OPTION . '_' . $record['secret_ref'],
    );
} elseif ('success-reconcile' === $step) {
    $record = $operation_record($scenarios['success']['backend_id']);
    $result = $service->reconcile($record['operation_id'], 1900000101);
    $assert_projection(
        $result,
        PeerTube_Password_Grant_Service::STATUS_READY_FOR_VERIFICATION,
        PeerTube_Connection_State_Machine::PHASE_SECRET_STORED,
        9,
        Atomic_Option_Result::MUTATION_APPLIED,
        $scenarios['success']['backend_id']
    );
    $expected_changes = array(PeerTube_Connection_Operation_Store::OPTION);
} elseif ('otp-submit-required' === $step) {
    $record = $operation_record($scenarios['otp']['backend_id']);
    $result = $service->submit(
        $record['operation_id'],
        'r37-otp-user-canary',
        'r37-otp-password-canary',
        '',
        1900010100
    );
    $assert_projection(
        $result,
        PeerTube_Password_Grant_Service::STATUS_AWAITING_OTP,
        PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP,
        9,
        Atomic_Option_Result::MUTATION_APPLIED,
        $scenarios['otp']['backend_id']
    );
    $expected_changes = array(PeerTube_Connection_Operation_Store::OPTION);
} elseif ('otp-reconcile-required' === $step) {
    $record = $operation_record($scenarios['otp']['backend_id']);
    $result = $service->reconcile($record['operation_id'], 1900010101);
    $assert_projection(
        $result,
        PeerTube_Password_Grant_Service::STATUS_AWAITING_OTP,
        PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP,
        9,
        Atomic_Option_Result::MUTATION_NONE,
        $scenarios['otp']['backend_id']
    );
} elseif ('otp-submit-success' === $step) {
    $record = $operation_record($scenarios['otp']['backend_id']);
    $result = $service->submit(
        $record['operation_id'],
        'r37-otp-user-canary',
        'r37-otp-password-canary',
        '731946',
        1900010200
    );
    $assert_projection(
        $result,
        PeerTube_Password_Grant_Service::STATUS_ADVANCED,
        PeerTube_Connection_State_Machine::PHASE_SECRET_WRITE_PLANNED,
        12,
        Atomic_Option_Result::MUTATION_APPLIED,
        $scenarios['otp']['backend_id']
    );
    $record = $operation_record($scenarios['otp']['backend_id']);
    $expected_changes = array(
        PeerTube_Connection_Operation_Store::OPTION,
        Managed_Backend_Secret_Store::OPTION . '_' . $record['secret_ref'],
    );
} elseif ('otp-reconcile-success' === $step) {
    $record = $operation_record($scenarios['otp']['backend_id']);
    $result = $service->reconcile($record['operation_id'], 1900010201);
    $assert_projection(
        $result,
        PeerTube_Password_Grant_Service::STATUS_READY_FOR_VERIFICATION,
        PeerTube_Connection_State_Machine::PHASE_SECRET_STORED,
        13,
        Atomic_Option_Result::MUTATION_APPLIED,
        $scenarios['otp']['backend_id']
    );
    $expected_changes = array(PeerTube_Connection_Operation_Store::OPTION);
} elseif ('transport-submit' === $step) {
    $record = $operation_record($scenarios['transport']['backend_id']);
    $result = $service->submit(
        $record['operation_id'],
        'r37-transport-user-canary',
        'r37-transport-password-canary',
        '',
        1900020100
    );
    $assert_projection(
        $result,
        PeerTube_Password_Grant_Service::STATUS_GRANT_INDETERMINATE,
        PeerTube_Connection_State_Machine::PHASE_GRANT_INDETERMINATE,
        8,
        Atomic_Option_Result::MUTATION_APPLIED,
        $scenarios['transport']['backend_id']
    );
    $expected_changes = array(PeerTube_Connection_Operation_Store::OPTION);
} elseif ('transport-reconcile' === $step) {
    $record = $operation_record($scenarios['transport']['backend_id']);
    $result = $service->reconcile($record['operation_id'], 1900020200);
    $assert_projection(
        $result,
        PeerTube_Password_Grant_Service::STATUS_GRANT_INDETERMINATE,
        PeerTube_Connection_State_Machine::PHASE_GRANT_INDETERMINATE,
        8,
        Atomic_Option_Result::MUTATION_NONE,
        $scenarios['transport']['backend_id']
    );
} elseif ('transport-resubmit' === $step) {
    $record = $operation_record($scenarios['transport']['backend_id']);
    $result = $service->submit(
        $record['operation_id'],
        'r37-transport-user-canary',
        'r37-transport-password-canary',
        '',
        1900020300
    );
    $assert_projection(
        $result,
        PeerTube_Password_Grant_Service::STATUS_REFUSED,
        PeerTube_Connection_State_Machine::PHASE_GRANT_INDETERMINATE,
        8,
        Atomic_Option_Result::MUTATION_NONE,
        $scenarios['transport']['backend_id']
    );
} elseif ('final' === $step) {
    $secret_store = new Managed_Backend_Secret_Store();
    $expected_secrets = array(
        'success' => array(
            'access_token'       => 'r37-success-access-token-canary',
            'refresh_token'      => 'r37-success-refresh-token-canary',
            'access_expires_at'  => 1900003700,
            'refresh_expires_at' => 1900007300,
            'generation'         => 1,
        ),
        'otp' => array(
            'access_token'       => 'r37-otp-access-token-canary',
            'refresh_token'      => 'r37-otp-refresh-token-canary',
            'access_expires_at'  => 1900013800,
            'refresh_expires_at' => 1900017400,
            'generation'         => 1,
        ),
    );

    foreach ($expected_secrets as $scenario_name => $expected_secret) {
        $scenario = $scenarios[$scenario_name];
        $record = $operation_record($scenario['backend_id']);
        $assert_record(
            $record,
            $scenario,
            PeerTube_Connection_State_Machine::PHASE_SECRET_STORED,
            'success' === $scenario_name ? 9 : 13
        );
        $stored = $secret_store->read($record['secret_ref'], $record['backend_id']);
        $assert($expected_secret === $stored, 'The encrypted grant secret did not round-trip exactly.');
        unset($stored);
    }

    $transport = $operation_record($scenarios['transport']['backend_id']);
    $assert_record(
        $transport,
        $scenarios['transport'],
        PeerTube_Connection_State_Machine::PHASE_GRANT_INDETERMINATE,
        8
    );
    $assert(
        array('state' => Managed_Backend_Secret_Store::PROVISION_PENDING, 'generation' => 0)
            === $secret_store->provisioning_state(
                $transport['secret_ref'],
                $transport['backend_id'],
                $transport['provisioning_id']
            ),
        'The indeterminate grant changed its pending secret reservation.'
    );

    $forbidden = array(
        'r37-oauth-client-id',
        'r37-oauth-client-secret-canary',
        'r37-success-user-canary',
        'r37-success-password-canary',
        'r37-success-access-token-canary',
        'r37-success-refresh-token-canary',
        'r37-otp-user-canary',
        'r37-otp-password-canary',
        'r37-otp-access-token-canary',
        'r37-otp-refresh-token-canary',
        'r37-transport-user-canary',
        'r37-transport-password-canary',
        '731946',
    );
    $rows = $wpdb->get_results(
        $wpdb->prepare('SELECT option_name, option_value FROM %i', $wpdb->options),
        ARRAY_A
    );
    $assert(is_array($rows) && '' === (string) $wpdb->last_error, 'The secret-exclusion scan failed.');
    foreach ($rows as $row) {
        $raw = is_array($row) && is_string($row['option_value'] ?? null)
            ? $row['option_value']
            : '';
        foreach ($forbidden as $value) {
            $assert(! str_contains($raw, $value), 'A plaintext grant canary entered a WordPress option.');
        }
    }
    unset($rows, $expected_secrets);
} else {
    throw new RuntimeException('The R37 fixture step has no implementation.');
}

$options_after = $tracked_options();
$expected_changes = array_values(array_unique($expected_changes));
sort($expected_changes, SORT_STRING);
$assert(
    $expected_changes === $changed_options($options_before, $options_after),
    'The R37 step changed an unexpected persistence target: ' . $step
);

$expected_autoload = function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no';
foreach ($options_after as $name => $row) {
    $assert(
        $expected_autoload === $row['autoload'],
        'An R37 private option was not stored non-autoload: ' . $name
    );
    $assert(
        ! array_key_exists($name, wp_load_alloptions(true)),
        'An R37 private option appeared in wp_load_alloptions(): ' . $name
    );
}

$assert(
    $uploads_before === $tree_snapshot($managed_upload_root),
    'The R37 step changed managed upload storage: ' . $step
);
$attachments_after = (int) $wpdb->get_var(
    $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE post_type = %s', $wpdb->posts, 'attachment')
);
$assert('' === (string) $wpdb->last_error, 'The attachment postcondition query failed.');
$assert($attachments_before === $attachments_after, 'The R37 step changed attachment rows.');

echo 'PEERTUBE_PASSWORD_GRANT_STEP=' . $step . ":PASS\n";

// EOF: tests/fixtures/peertube-password-grant-smoke/assert-step.php
