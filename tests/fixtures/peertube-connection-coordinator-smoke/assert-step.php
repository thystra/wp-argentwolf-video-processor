<?php
/**
 * One restart-isolated real-WordPress assertion for the R36 coordinator.
 */

declare(strict_types=1);

use ArgentVideo\Atomic_Option_Result;
use ArgentVideo\Backend_Registry;
use ArgentVideo\Managed_Backend_Secret_Store;
use ArgentVideo\PeerTube_Connection_Coordinator;
use ArgentVideo\PeerTube_Connection_Operation_Store;
use ArgentVideo\PeerTube_Connection_State_Machine;

if (! defined('ABSPATH')) {
    throw new RuntimeException('The coordinator fixture requires a loaded WordPress runtime.');
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$step = getenv('AWVP_R36_STEP');
$steps = array(
    'start',
    'manifest',
    'secret-plan',
    'secret-apply',
    'secret-confirm',
    'link-plan',
    'link-apply',
    'link-confirm',
    'registry-append',
    'ready',
    'occupied-refusal',
);
$assert(is_string($step) && in_array($step, $steps, true), 'The coordinator step is not allowlisted.');
$assert(
    is_plugin_active('argentwolf-video-processor/argentwolf-video-processor.php'),
    'The AWVP plugin is not active.'
);
$assert(defined('WP_DEBUG') && true === WP_DEBUG, 'WP_DEBUG must be enabled.');
$assert(
    defined('WP_HTTP_BLOCK_EXTERNAL') && true === WP_HTTP_BLOCK_EXTERNAL,
    'External WordPress HTTP must be blocked.'
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
$assert(is_object($wpdb) && isset($wpdb->options), 'The WordPress options table is unavailable.');

$raw_row = static function (string $option) use ($wpdb): ?array {
    $query = $wpdb->prepare(
        'SELECT option_value, autoload FROM %i WHERE option_name = %s LIMIT 1',
        $wpdb->options,
        $option
    );
    $row = $wpdb->get_row($query, ARRAY_A);
    if ('' !== (string) $wpdb->last_error) {
        throw new RuntimeException('An authoritative option read failed.');
    }
    return is_array($row) ? $row : null;
};

$decode_row = static function (?array $row): ?array {
    if (! is_array($row) || ! is_string($row['option_value'] ?? null)) {
        return null;
    }
    $value = unserialize($row['option_value'], array('allowed_classes' => false));
    return is_array($value) ? $value : null;
};

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
        throw new RuntimeException('The authoritative coordinator option snapshot failed.');
    }

    $snapshot = array();
    foreach ($rows as $row) {
        if (
            ! is_array($row)
            || ! is_string($row['option_name'] ?? null)
            || ! is_string($row['option_value'] ?? null)
            || ! is_string($row['autoload'] ?? null)
        ) {
            throw new RuntimeException('A coordinator option snapshot row was malformed.');
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

$assert_option_changes = static function (
    array $before,
    array $after,
    array $expected
) use ($assert, $changed_options, $step): void {
    sort($expected, SORT_STRING);
    $assert(
        $expected === $changed_options($before, $after),
        'The coordinator step changed an unexpected persistence target: ' . $step
    );
};

$assert_nonautoload = static function (string $option) use ($assert, $raw_row): void {
    $row = $raw_row($option);
    $assert(is_array($row), 'Expected a persisted non-autoload coordinator option.');
    $autoload = (string) ($row['autoload'] ?? '');
    $expected = function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no';
    $assert($expected === $autoload, 'A coordinator option was not stored non-autoload.');
    $assert(
        ! array_key_exists($option, wp_load_alloptions(true)),
        'A coordinator option appeared in wp_load_alloptions().'
    );
};

$one_record = static function () use ($assert, $decode_row, $raw_row): array {
    $journal = $decode_row($raw_row(PeerTube_Connection_Operation_Store::OPTION));
    $assert(
        is_array($journal)
            && 1 === ($journal['version'] ?? null)
            && is_array($journal['operations'] ?? null)
            && 1 === count($journal['operations']),
        'The coordinator journal did not contain exactly one operation.'
    );
    $record = array_values($journal['operations'])[0] ?? null;
    $assert(
        is_array($record) && PeerTube_Connection_State_Machine::valid($record),
        'The coordinator journal record was invalid.'
    );
    return $record;
};

$descriptor = static function (array $record): array {
    return array(
        'id'                  => $record['backend_id'],
        'type'                => 'peertube',
        'label'               => $record['label'],
        'state'               => 'disabled',
        'default_destination' => '',
        'secret_ref'          => $record['secret_ref'],
        'config_version'      => 1,
        'config'              => array('origin' => $record['origin']),
    );
};

$assert_record = static function (
    array $record,
    string $phase,
    int $revision
) use ($assert): void {
    $assert($phase === $record['phase'], 'The coordinator phase was out of order.');
    $assert($revision === $record['record_revision'], 'The coordinator revision was out of order.');
    $assert('coordinator-primary' === $record['backend_id'], 'The backend identity changed.');
    $assert(
        'https://coordinator.example.invalid' === $record['origin'],
        'The normalized PeerTube origin changed.'
    );
    $assert('Coordinator Primary' === $record['label'], 'The coordinator label changed.');
    $assert(
        1 === preg_match('/^connection_[a-f0-9]{32}$/D', $record['operation_id']),
        'The operation identifier was invalid.'
    );
    $assert(
        1 === preg_match('/^managed_[a-f0-9]{32}$/D', $record['secret_ref']),
        'The managed secret reference was invalid.'
    );
    $assert(
        1 === preg_match('/^provision_[a-f0-9]{32}$/D', $record['provisioning_id']),
        'The provisioning identifier was invalid.'
    );
};

$assert_projection = static function (
    array $result,
    string $status,
    string $mutation,
    string $operation_id,
    string $phase,
    int $revision
) use ($assert): void {
    $assert(
        array(
            'status',
            'mutation',
            'operation_id',
            'backend_id',
            'phase',
            'record_revision',
        ) === array_keys($result),
        'The coordinator exposed fields outside its bounded result projection.'
    );
    $assert($status === $result['status'], 'The coordinator result status differed.');
    $assert($mutation === $result['mutation'], 'The coordinator mutation classification differed.');
    $assert($operation_id === $result['operation_id'], 'The coordinator operation ID differed.');
    $assert('coordinator-primary' === $result['backend_id'], 'The coordinator result backend differed.');
    $assert($phase === $result['phase'], 'The coordinator result phase differed.');
    $assert($revision === $result['record_revision'], 'The coordinator result revision differed.');
    $encoded = serialize($result);
    foreach (
        array(
            'https://coordinator.example.invalid',
            'Coordinator Primary',
            'managed_',
            'provision_',
            'password',
            'otp',
            'access_token',
            'refresh_token',
        ) as $private_value
    ) {
        $assert(
            false === strpos($encoded, $private_value),
            'The bounded coordinator result exposed private connection state.'
        );
    }
};

$assert_refused_start = static function (array $result) use ($assert): void {
    $assert(
        array(
            'status',
            'mutation',
            'operation_id',
            'backend_id',
            'phase',
            'record_revision',
        ) === array_keys($result),
        'A refused start exposed fields outside the bounded result projection.'
    );
    $assert(
        PeerTube_Connection_Coordinator::STATUS_REFUSED === $result['status']
            && Atomic_Option_Result::MUTATION_NONE === $result['mutation']
            && '' === $result['operation_id']
            && '' === $result['backend_id']
            && '' === $result['phase']
            && 0 === $result['record_revision'],
        'A refused start did not return the empty bounded projection.'
    );
};

$upload_directory = wp_get_upload_dir();
$assert(
    is_array($upload_directory)
        && false === ($upload_directory['error'] ?? null)
        && is_string($upload_directory['basedir'] ?? null)
        && '' !== $upload_directory['basedir'],
    'WordPress did not resolve a usable upload directory.'
);
$upload_root = (string) $upload_directory['basedir'];
$uploads_snapshot = static function (string $root): array {
    $snapshot = array('root' => 'absent', 'entries' => array());
    if (is_link($root)) {
        $target = readlink($root);
        $snapshot['root'] = 'symlink:' . (is_string($target) ? $target : 'unreadable');
        return $snapshot;
    }
    if (! file_exists($root)) {
        return $snapshot;
    }
    if (is_file($root)) {
        $hash = hash_file('sha256', $root);
        $size = filesize($root);
        $snapshot['root'] = 'file:' . (is_int($size) ? (string) $size : 'unreadable') . ':'
            . (is_string($hash) ? $hash : 'unreadable');
        return $snapshot;
    }
    if (! is_dir($root)) {
        $snapshot['root'] = 'other';
        return $snapshot;
    }

    $snapshot['root'] = 'directory';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        $relative = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
        if ($entry->isLink()) {
            $target = $entry->getLinkTarget();
            $snapshot['entries'][$relative] = 'symlink:'
                . (is_string($target) ? $target : 'unreadable');
        } elseif ($entry->isDir()) {
            $snapshot['entries'][$relative] = 'directory';
        } elseif ($entry->isFile()) {
            $hash = hash_file('sha256', $path);
            if (! is_string($hash)) {
                throw new RuntimeException('An uploads snapshot could not hash a file.');
            }
            $snapshot['entries'][$relative] = 'file:' . $entry->getSize() . ':' . $hash;
        } else {
            $snapshot['entries'][$relative] = 'other';
        }
    }
    ksort($snapshot['entries'], SORT_STRING);
    return $snapshot;
};

$uploads_before = $uploads_snapshot($upload_root);
$http_requests = 0;
$http_guard = static function (mixed $preempt, array $arguments, string $url) use (&$http_requests): WP_Error {
    unset($preempt, $arguments, $url);
    ++$http_requests;
    return new WP_Error('awvp_r36_http_refused', 'Unexpected HTTP in coordinator smoke.');
};
add_filter('pre_http_request', $http_guard, 1, 3);

$options_before = $tracked_options();
$coordinator = new PeerTube_Connection_Coordinator();
$secret_store = new Managed_Backend_Secret_Store();
$registry = new Backend_Registry();
$now = 2100000000 + (int) array_search($step, $steps, true);
$expected_options = array();

if ('start' === $step) {
    $assert(
        null === $raw_row(PeerTube_Connection_Operation_Store::OPTION),
        'The fresh coordinator journal was not absent.'
    );
    $assert(null === $raw_row(Managed_Backend_Secret_Store::OPTION), 'The fresh secret manifest was not absent.');
    $assert(null === $raw_row(Backend_Registry::OPTION), 'The fresh backend registry was not absent.');

    $result = $coordinator->start(
        array(
            'backend_id' => 'coordinator-primary',
            'origin'     => 'https://coordinator.example.invalid',
            'label'      => 'Coordinator Primary',
        ),
        7,
        $now
    );
    $record = $one_record();
    $assert_record($record, PeerTube_Connection_State_Machine::PHASE_PREPARED, 1);
    $assert_projection(
        $result,
        PeerTube_Connection_Coordinator::STATUS_ADVANCED,
        Atomic_Option_Result::MUTATION_APPLIED,
        $record['operation_id'],
        PeerTube_Connection_State_Machine::PHASE_PREPARED,
        1
    );
    $assert(7 === $record['created_by'], 'The operation actor was not preserved.');
    $assert($now === $record['created_at'] && $now === $record['updated_at'], 'The start timestamps differed.');
    $expected_options = array(PeerTube_Connection_Operation_Store::OPTION);
} else {
    $record = $one_record();
    $operation_id = $record['operation_id'];
    $secret_option = Managed_Backend_Secret_Store::OPTION . '_' . $record['secret_ref'];

    if ('manifest' === $step) {
        $assert_record($record, PeerTube_Connection_State_Machine::PHASE_PREPARED, 1);
        $assert(null === $raw_row(Managed_Backend_Secret_Store::OPTION), 'The manifest step was already applied.');
        $assert(null === $raw_row($secret_option), 'The manifest step found an unexpected reserved slot.');
        $result = $coordinator->resume($operation_id, $now);
        $assert_projection(
            $result,
            PeerTube_Connection_Coordinator::STATUS_ADVANCED,
            Atomic_Option_Result::MUTATION_APPLIED,
            $operation_id,
            PeerTube_Connection_State_Machine::PHASE_PREPARED,
            1
        );
        $assert(array('version' => 1) === $decode_row($raw_row(Managed_Backend_Secret_Store::OPTION)), 'The manifest value differed.');
        $assert(null === $raw_row($secret_option), 'Manifest initialization also reserved a secret slot.');
        $expected_options = array(Managed_Backend_Secret_Store::OPTION);
    } elseif ('secret-plan' === $step) {
        $assert_record($record, PeerTube_Connection_State_Machine::PHASE_PREPARED, 1);
        $assert(array('version' => 1) === $decode_row($raw_row(Managed_Backend_Secret_Store::OPTION)), 'The secret manifest was not ready.');
        $assert(null === $raw_row($secret_option), 'Secret planning did not begin from an absent slot.');
        $result = $coordinator->resume($operation_id, $now);
        $record = $one_record();
        $assert_record($record, PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVE_PLANNED, 2);
        $assert_projection(
            $result,
            PeerTube_Connection_Coordinator::STATUS_ADVANCED,
            Atomic_Option_Result::MUTATION_APPLIED,
            $operation_id,
            PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVE_PLANNED,
            2
        );
        $assert('secret_reserve' === $record['last_mutation']['kind'], 'The secret plan kind differed.');
        $assert(false === $record['last_mutation']['before_exists'], 'Secret planning did not record absence.');
        $assert(true === $record['last_mutation']['after_exists'], 'Secret planning did not record a target.');
        $assert(null === $raw_row($secret_option), 'Secret planning mutated the reserved slot.');
        $expected_options = array(PeerTube_Connection_Operation_Store::OPTION);
    } elseif ('secret-apply' === $step) {
        $assert_record($record, PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVE_PLANNED, 2);
        $assert('secret_reserve' === $record['last_mutation']['kind'], 'The reservation plan was absent.');
        $assert(null === $raw_row($secret_option), 'The reservation target was not still absent.');
        $journal_before = $raw_row(PeerTube_Connection_Operation_Store::OPTION);
        $result = $coordinator->resume($operation_id, $now);
        $assert_projection(
            $result,
            PeerTube_Connection_Coordinator::STATUS_ADVANCED,
            Atomic_Option_Result::MUTATION_APPLIED,
            $operation_id,
            PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVE_PLANNED,
            2
        );
        $assert($journal_before === $raw_row(PeerTube_Connection_Operation_Store::OPTION), 'Secret application also changed the journal.');
        $assert(
            array(
                'version'         => 2,
                'state'           => 'pending',
                'backend_id'      => $record['backend_id'],
                'provisioning_id' => $record['provisioning_id'],
                'generation'      => 0,
                'envelope'        => array(),
            ) === $decode_row($raw_row($secret_option)),
            'Secret application did not create the exact pending slot.'
        );
        $expected_options = array($secret_option);
    } elseif ('secret-confirm' === $step) {
        $assert_record($record, PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVE_PLANNED, 2);
        $state_before = $secret_store->provisioning_state(
            $record['secret_ref'],
            $record['backend_id'],
            $record['provisioning_id']
        );
        $assert(
            array('state' => Managed_Backend_Secret_Store::PROVISION_PENDING, 'generation' => 0)
                === $state_before,
            'The secret confirmation step did not observe the exact pending slot.'
        );
        $secret_before = $raw_row($secret_option);
        $result = $coordinator->resume($operation_id, $now);
        $record = $one_record();
        $assert_record($record, PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVED, 3);
        $assert_projection(
            $result,
            PeerTube_Connection_Coordinator::STATUS_ADVANCED,
            Atomic_Option_Result::MUTATION_APPLIED,
            $operation_id,
            PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVED,
            3
        );
        $assert($secret_before === $raw_row($secret_option), 'Secret confirmation rewrote the pending slot.');
        $expected_options = array(PeerTube_Connection_Operation_Store::OPTION);
    } elseif ('link-plan' === $step) {
        $assert_record($record, PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVED, 3);
        $assert(null === $raw_row(Backend_Registry::OPTION), 'Link planning did not begin from an absent registry.');
        $result = $coordinator->resume($operation_id, $now);
        $record = $one_record();
        $assert_record($record, PeerTube_Connection_State_Machine::PHASE_LINK_PLANNED, 4);
        $assert_projection(
            $result,
            PeerTube_Connection_Coordinator::STATUS_ADVANCED,
            Atomic_Option_Result::MUTATION_APPLIED,
            $operation_id,
            PeerTube_Connection_State_Machine::PHASE_LINK_PLANNED,
            4
        );
        $assert('registry_link' === $record['last_mutation']['kind'], 'The registry plan kind differed.');
        $assert(false === $record['last_mutation']['before_exists'], 'Registry planning did not record absence.');
        $assert(true === $record['last_mutation']['after_exists'], 'Registry planning did not record a target.');
        $assert(null === $raw_row(Backend_Registry::OPTION), 'Registry planning mutated the registry.');
        $expected_options = array(PeerTube_Connection_Operation_Store::OPTION);
    } elseif ('link-apply' === $step) {
        $assert_record($record, PeerTube_Connection_State_Machine::PHASE_LINK_PLANNED, 4);
        $assert('registry_link' === $record['last_mutation']['kind'], 'The registry link plan was absent.');
        $assert(null === $raw_row(Backend_Registry::OPTION), 'The registry target was not still absent.');
        $journal_before = $raw_row(PeerTube_Connection_Operation_Store::OPTION);
        $result = $coordinator->resume($operation_id, $now);
        $assert_projection(
            $result,
            PeerTube_Connection_Coordinator::STATUS_ADVANCED,
            Atomic_Option_Result::MUTATION_APPLIED,
            $operation_id,
            PeerTube_Connection_State_Machine::PHASE_LINK_PLANNED,
            4
        );
        $assert($journal_before === $raw_row(PeerTube_Connection_Operation_Store::OPTION), 'Link application also changed the journal.');
        $assert($descriptor($record) === $registry->get($record['backend_id']), 'The exact disabled descriptor was not linked.');
        $local = $registry->get(Backend_Registry::LOCAL_ID);
        $assert(is_array($local) && 'active' === ($local['state'] ?? null), 'The initial registry did not retain active local.');
        $expected_options = array(Backend_Registry::OPTION);
    } elseif ('link-confirm' === $step) {
        $assert_record($record, PeerTube_Connection_State_Machine::PHASE_LINK_PLANNED, 4);
        $assert($descriptor($record) === $registry->get($record['backend_id']), 'The link was not present for confirmation.');
        $registry_before = $raw_row(Backend_Registry::OPTION);
        $result = $coordinator->resume($operation_id, $now);
        $record = $one_record();
        $assert_record($record, PeerTube_Connection_State_Machine::PHASE_DISABLED, 5);
        $assert_projection(
            $result,
            PeerTube_Connection_Coordinator::STATUS_ADVANCED,
            Atomic_Option_Result::MUTATION_APPLIED,
            $operation_id,
            PeerTube_Connection_State_Machine::PHASE_DISABLED,
            5
        );
        $assert($registry_before === $raw_row(Backend_Registry::OPTION), 'Link confirmation rewrote the registry.');
        $expected_options = array(PeerTube_Connection_Operation_Store::OPTION);
    } elseif ('registry-append' === $step) {
        $assert_record($record, PeerTube_Connection_State_Machine::PHASE_DISABLED, 5);
        $assert(null === $registry->get('coordinator-unrelated'), 'The unrelated descriptor was already present.');
        $unrelated = array(
            'id'                  => 'coordinator-unrelated',
            'type'                => 'peertube',
            'label'               => 'Coordinator Unrelated',
            'state'               => 'disabled',
            'default_destination' => '',
            'secret_ref'          => 'managed_' . str_repeat('e', 32),
            'config_version'      => 1,
            'config'              => array('origin' => 'https://unrelated.example.invalid'),
        );
        $journal_before = $raw_row(PeerTube_Connection_Operation_Store::OPTION);
        $append = $registry->create_disabled_peertube($unrelated);
        $assert(Atomic_Option_Result::APPLIED === $append->status(), 'The unrelated registry append failed.');
        $assert(Atomic_Option_Result::MUTATION_APPLIED === $append->mutation(), 'The unrelated append was not classified applied.');
        $assert($unrelated === $registry->get('coordinator-unrelated'), 'The unrelated descriptor was not stored exactly.');
        $assert($descriptor($record) === $registry->get($record['backend_id']), 'The unrelated append changed the primary descriptor.');
        $assert($journal_before === $raw_row(PeerTube_Connection_Operation_Store::OPTION), 'The unrelated append changed the journal.');
        $expected_options = array(Backend_Registry::OPTION);
    } elseif ('ready' === $step) {
        $assert_record($record, PeerTube_Connection_State_Machine::PHASE_DISABLED, 5);
        $assert(is_array($registry->get('coordinator-unrelated')), 'Semantic-ready was not tested after an unrelated append.');
        $result = $coordinator->resume($operation_id, $now);
        $assert_projection(
            $result,
            PeerTube_Connection_Coordinator::STATUS_READY_FOR_GRANT,
            Atomic_Option_Result::MUTATION_NONE,
            $operation_id,
            PeerTube_Connection_State_Machine::PHASE_DISABLED,
            5
        );
        $expected_options = array();
    } elseif ('occupied-refusal' === $step) {
        $assert_record($record, PeerTube_Connection_State_Machine::PHASE_DISABLED, 5);
        $occupied = $coordinator->start(
            array(
                'backend_id' => 'coordinator-primary',
                'origin'     => 'https://replacement.example.invalid',
                'label'      => 'Must Not Replace',
            ),
            9,
            $now
        );
        $assert_refused_start($occupied);

        $credential_intents = array(
            array('password' => 'r36-password-canary'),
            array('otp' => 'r36-otp-canary'),
            array('access_token' => 'r36-access-token-canary'),
            array('refresh_token' => 'r36-refresh-token-canary'),
        );
        foreach ($credential_intents as $credential_field) {
            $invalid = $coordinator->start(
                array_merge(
                    array(
                        'backend_id' => 'coordinator-credential-refusal',
                        'origin'     => 'https://credentials.example.invalid',
                        'label'      => 'Must Refuse Credentials',
                    ),
                    $credential_field
                ),
                9,
                $now
            );
            $assert_refused_start($invalid);
        }
        $expected_options = array();
    }
}

$options_after = $tracked_options();
$assert_option_changes($options_before, $options_after, $expected_options);

foreach (array_keys($options_after) as $option_name) {
    $assert_nonautoload($option_name);
}

$credential_canaries = array(
    'password',
    'otp',
    'access_token',
    'refresh_token',
    'client_secret',
    'r36-password-canary',
    'r36-otp-canary',
    'r36-access-token-canary',
    'r36-refresh-token-canary',
);
$contains_credential_key = static function (mixed $value) use (&$contains_credential_key): bool {
    if (! is_array($value)) {
        return false;
    }

    foreach ($value as $key => $nested) {
        if (
            is_string($key)
            && in_array(
                $key,
                array('password', 'otp', 'access_token', 'refresh_token', 'client_secret'),
                true
            )
        ) {
            return true;
        }
        if ($contains_credential_key($nested)) {
            return true;
        }
    }

    return false;
};
foreach ($options_after as $stored) {
    foreach ($credential_canaries as $canary) {
        $assert(
            false === strpos((string) $stored['option_value'], $canary),
            'Credential material appeared in coordinator-owned persistence.'
        );
    }
    $decoded = unserialize((string) $stored['option_value'], array('allowed_classes' => false));
    $assert(
        ! $contains_credential_key($decoded),
        'A credential field appeared in coordinator-owned persistence.'
    );
}

$final_record = $one_record();
$assert(
    ! array_key_exists('password', $final_record)
        && ! array_key_exists('otp', $final_record)
        && ! array_key_exists('access_token', $final_record)
        && ! array_key_exists('refresh_token', $final_record),
    'The connection journal contained credential fields.'
);

remove_filter('pre_http_request', $http_guard, 1);
$assert(0 === $http_requests, 'The local coordinator attempted WordPress HTTP.');
$assert(
    $uploads_before === $uploads_snapshot($upload_root),
    'The local coordinator changed the WordPress-resolved uploads tree.'
);

$target_label = [] === $expected_options ? 'NONE' : implode(',', $expected_options);
echo 'COORDINATOR_STEP_PHASE=' . $step . ':' . $final_record['phase'] . "\n";
echo 'COORDINATOR_STEP_REVISION=' . $step . ':' . $final_record['record_revision'] . "\n";
echo 'COORDINATOR_STEP_PERSISTENCE_TARGET=' . $step . ':' . $target_label . "\n";
echo 'COORDINATOR_STEP_HTTP_REQUESTS=' . $step . ":0\n";
echo 'COORDINATOR_STEP_UPLOAD_MUTATIONS=' . $step . ":0\n";
echo 'PEERTUBE_CONNECTION_COORDINATOR_STEP=' . $step . ":PASS\n";

// EOF: tests/fixtures/peertube-connection-coordinator-smoke/assert-step.php
