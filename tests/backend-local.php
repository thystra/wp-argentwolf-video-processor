<?php
/**
 * Focused dependency-free tests for AWVP 2.0 backend registry/local adapter.
 */

declare(strict_types=1);

namespace {
    define('ABSPATH', '/tmp/wordpress/');

    $GLOBALS['awvp_backend_option_exists'] = false;
    $GLOBALS['awvp_backend_option_value'] = null;
    $GLOBALS['awvp_backend_option_writes'] = array();
    $GLOBALS['awvp_backend_autoload_writes'] = array();
    $GLOBALS['awvp_backend_option_autoload'] = false;
    $GLOBALS['awvp_backend_autoload_set_fails'] = false;
    $GLOBALS['awvp_backend_post_types'] = array(101 => 'argent_video_asset');
    $GLOBALS['awvp_backend_post_meta'] = array(
        101 => array('_argent_video_attachment_id' => 202),
    );

    function sanitize_text_field(mixed $value): string
    {
        return trim(strip_tags((string) $value));
    }

    function get_option(string $option, mixed $default = false): mixed
    {
        if ('argent_video_processor_backends' !== $option || ! $GLOBALS['awvp_backend_option_exists']) {
            return $default;
        }

        return $GLOBALS['awvp_backend_option_value'];
    }

    function add_option(string $option, mixed $value = '', string $deprecated = '', mixed $autoload = null): bool
    {
        unset($deprecated);
        if ($GLOBALS['awvp_backend_option_exists']) {
            return false;
        }

        $GLOBALS['awvp_backend_option_exists'] = true;
        $GLOBALS['awvp_backend_option_value'] = $value;
        $GLOBALS['awvp_backend_option_autoload'] = true === $autoload;
        $GLOBALS['awvp_backend_option_writes'][] = array('add', $option, $value, $autoload);
        return true;
    }

    function update_option(string $option, mixed $value, mixed $autoload = null): bool
    {
        $changed = ! $GLOBALS['awvp_backend_option_exists'] || $GLOBALS['awvp_backend_option_value'] !== $value;
        $GLOBALS['awvp_backend_option_exists'] = true;
        $GLOBALS['awvp_backend_option_value'] = $value;
        if ($changed && is_bool($autoload)) {
            $GLOBALS['awvp_backend_option_autoload'] = $autoload;
        }
        $GLOBALS['awvp_backend_option_writes'][] = array('update', $option, $value, $autoload);
        return $changed;
    }

    function wp_set_option_autoload(string $option, bool $autoload): bool
    {
        $GLOBALS['awvp_backend_autoload_writes'][] = array($option, $autoload);

        if ($GLOBALS['awvp_backend_autoload_set_fails']) {
            return false;
        }

        $changed = $GLOBALS['awvp_backend_option_autoload'] !== $autoload;
        $GLOBALS['awvp_backend_option_autoload'] = $autoload;
        return $changed;
    }

    function wp_load_alloptions(bool $force_cache = false): array
    {
        unset($force_cache);

        if ($GLOBALS['awvp_backend_option_exists'] && $GLOBALS['awvp_backend_option_autoload']) {
            return array(
                'argent_video_processor_backends' => $GLOBALS['awvp_backend_option_value'],
            );
        }

        return array();
    }

    function get_post_type(int $post_id): string|false
    {
        return $GLOBALS['awvp_backend_post_types'][$post_id] ?? false;
    }

    function get_post_meta(int $post_id, string $key, bool $single = false): mixed
    {
        unset($single);
        return $GLOBALS['awvp_backend_post_meta'][$post_id][$key] ?? '';
    }
}

namespace ArgentVideo {
    final class Queue
    {
        /** @var list<array{attachment_id:int,force:bool,profile:?string}> */
        public array $calls = array();

        public function enqueue(int $attachment_id, bool $force = false, ?string $profile = null): int
        {
            $this->calls[] = compact('attachment_id', 'force', 'profile');
            return 77;
        }
    }

    final class Diagnostics
    {
        /** @param list<array{check:string,status:string,detail:string}> $checks */
        public function __construct(private array $checks)
        {
        }

        /** @return list<array{check:string,status:string,detail:string}> */
        public function checks(): array
        {
            return $this->checks;
        }
    }

    final class Video_Post_Type
    {
        public const POST_TYPE = 'argent_video_asset';
    }

    final class Video_Meta
    {
        public const ATTACHMENT_ID = '_argent_video_attachment_id';

        public static function sanitize_positive_id(mixed $value): int
        {
            if (is_int($value)) {
                return $value > 0 ? $value : 0;
            }

            if (! is_string($value) || 1 !== preg_match('/^[1-9][0-9]*$/D', $value)) {
                return 0;
            }

            $integer = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
            return false !== $integer ? (int) $integer : 0;
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
    require_once dirname(__DIR__) . '/includes/Backend_Capabilities.php';
    require_once dirname(__DIR__) . '/includes/Backend_Health.php';
    require_once dirname(__DIR__) . '/includes/Backend_Adapter.php';
    require_once dirname(__DIR__) . '/includes/Backend_Adapter_Factory.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
    require_once dirname(__DIR__) . '/includes/Backend_Registry.php';
    require_once dirname(__DIR__) . '/includes/Local_Backend_Adapter.php';

    use ArgentVideo\Backend_Adapter_Factory;
    use ArgentVideo\Backend_Capabilities;
    use ArgentVideo\Backend_Health;
    use ArgentVideo\Backend_Identity;
    use ArgentVideo\Backend_Registry;
    use ArgentVideo\Diagnostics;
    use ArgentVideo\Local_Backend_Adapter;
    use ArgentVideo\Queue;

    $assert = static function (bool $condition, string $message): void {
        if (! $condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };

    $assert('home-pt' === Backend_Identity::sanitize('home-pt'), 'Canonical backend ID should be preserved.');
    $assert('' === Backend_Identity::sanitize('Home-PT'), 'Backend ID case must not be rewritten.');
    $assert('' === Backend_Identity::sanitize('home pt'), 'Malformed backend ID must fail closed.');
    $assert('Channel: A' === Backend_Identity::sanitize_opaque('Channel: A', 191), 'Opaque ID should be preserved.');
    $assert('' === Backend_Identity::sanitize_opaque(" Channel: A ", 191), 'Rewritten opaque ID must fail closed.');

    $local_caps = Backend_Capabilities::local();
    $assert(12 === count($local_caps), 'Unexpected local capability count.');
    $assert(true === $local_caps[Backend_Capabilities::PROCESSING_VIDEO], 'Local processing capability missing.');
    $assert(false === $local_caps[Backend_Capabilities::INGEST_SERVER_PUSH], 'Local server-push capability must be false.');
    $assert(false === $local_caps[Backend_Capabilities::INGEST_DIRECT_BROWSER], 'Local direct-browser capability must be false.');

    $registry = new Backend_Registry();
    $local = $registry->get('local');
    $assert(is_array($local), 'Absent registry must synthesize local.');
    $assert('active' === ($local['state'] ?? ''), 'Absent registry must synthesize active local.');
    $assert([] === $registry->diagnostics(), 'Absent registry should not be diagnosed as malformed.');
    $assert([] === $GLOBALS['awvp_backend_option_writes'], 'Registry read must not write defaults.');

    $GLOBALS['awvp_backend_option_exists'] = true;
    $GLOBALS['awvp_backend_option_value'] = 'malformed';
    $local = $registry->get('local');
    $assert('disabled' === ($local['state'] ?? ''), 'Malformed registry must disable local for new work.');
    $assert(in_array('registry_malformed', $registry->diagnostics(), true), 'Malformed registry diagnostic missing.');

    $future_descriptor = array(
        'id' => 'future',
        'type' => 'future',
        'label' => 'Future Backend',
        'state' => 'active',
        'default_destination' => 'chan-A',
        'secret_ref' => 'secret-record-1',
        'config_version' => 2,
        'config' => array('mode' => 'future'),
    );
    $GLOBALS['awvp_backend_option_value'] = array(
        'version' => 1,
        'backends' => array(
            'future' => $future_descriptor,
        ),
    );
    $assert(null !== $registry->get('future'), 'Unknown stored descriptor should remain inspectable.');
    $assert('disabled' === (($registry->get('local')['state'] ?? '')), 'Missing local must synthesize disabled local.');
    $assert(in_array('registry_missing_local', $registry->diagnostics(), true), 'Missing-local diagnostic missing.');

    $future_with_secret = $future_descriptor;
    $future_with_secret['id'] = 'future-secret';
    $future_with_secret['type'] = 'future-secret';
    $future_with_secret['config'] = array('access_token' => 'must-not-be-read-as-ordinary-config');
    $GLOBALS['awvp_backend_option_value'] = array(
        'version' => 1,
        'backends' => array(
            'future-secret' => $future_with_secret,
        ),
    );
    $assert(null === $registry->get('future-secret'), 'Secret-like stored descriptor must not be treated as valid ordinary configuration.');
    $assert(in_array('registry_descriptor_malformed', $registry->diagnostics(), true), 'Secret-like stored descriptor diagnostic missing.');

    $GLOBALS['awvp_backend_option_exists'] = false;
    $GLOBALS['awvp_backend_option_value'] = null;
    $GLOBALS['awvp_backend_option_writes'] = array();
    $GLOBALS['awvp_backend_autoload_writes'] = array();

    $valid = array(
        'version' => 1,
        'backends' => array(
            'local' => array(
                'id' => 'local',
                'type' => 'local',
                'label' => 'Local AWVP',
                'state' => 'active',
                'default_destination' => '',
                'secret_ref' => '',
                'config_version' => 1,
                'config' => array(),
            ),
        ),
    );

    $stored_with_future = $valid;
    $stored_with_future['backends']['future'] = $future_descriptor;
    $GLOBALS['awvp_backend_option_exists'] = true;
    $GLOBALS['awvp_backend_option_value'] = $stored_with_future;
    $before_future_save = $GLOBALS['awvp_backend_option_value'];

    $assert(null === $registry->sanitize($valid), 'Older writer must fail closed when stored registry contains an unknown/future descriptor.');
    $assert(! $registry->save($valid), 'Saving local-only input must not erase an existing unknown/future descriptor.');
    $assert($before_future_save === $GLOBALS['awvp_backend_option_value'], 'Failed local-only save must preserve the entire stored future registry exactly.');

    $future_registry = $valid;
    $future_registry['version'] = 2;
    $GLOBALS['awvp_backend_option_value'] = $future_registry;
    $before_future_registry_save = $GLOBALS['awvp_backend_option_value'];
    $assert(null === $registry->sanitize($valid), 'Older writer must fail closed for a future registry version.');
    $assert(! $registry->save($valid), 'Older writer must not downgrade a future registry version.');
    $assert($before_future_registry_save === $GLOBALS['awvp_backend_option_value'], 'Future registry version must remain unchanged after refused save.');

    $future_local = $valid;
    $future_local['backends']['local']['config_version'] = 2;
    $future_local['backends']['local']['config'] = array('future_mode' => 'enabled');
    $GLOBALS['awvp_backend_option_value'] = $future_local;
    $before_future_local_save = $GLOBALS['awvp_backend_option_value'];
    $assert(null === $registry->sanitize($valid), 'Older writer must fail closed for a future local config version.');
    $assert(! $registry->save($valid), 'Older writer must not downgrade future local config.');
    $assert($before_future_local_save === $GLOBALS['awvp_backend_option_value'], 'Future local config must remain unchanged after refused save.');

    $future_field = $valid;
    $future_field['backends']['local']['future_field'] = 'preserve-me';
    $GLOBALS['awvp_backend_option_value'] = $future_field;
    $before_future_field_save = $GLOBALS['awvp_backend_option_value'];
    $assert(null === $registry->sanitize($valid), 'Older writer must fail closed when a stored descriptor contains an unknown field.');
    $assert(! $registry->save($valid), 'Older writer must not erase an unknown future descriptor field.');
    $assert($before_future_field_save === $GLOBALS['awvp_backend_option_value'], 'Unknown future descriptor field must remain unchanged after refused save.');

    $GLOBALS['awvp_backend_option_exists'] = false;
    $GLOBALS['awvp_backend_option_value'] = null;
    $GLOBALS['awvp_backend_option_writes'] = array();
    $GLOBALS['awvp_backend_autoload_writes'] = array();
    $GLOBALS['awvp_backend_option_autoload'] = false;
    $GLOBALS['awvp_backend_autoload_set_fails'] = false;

    $assert($registry->save($valid), 'Valid local registry save failed.');
    $assert('add' === ($GLOBALS['awvp_backend_option_writes'][0][0] ?? ''), 'First registry save must use add_option.');
    $assert(false === ($GLOBALS['awvp_backend_option_writes'][0][3] ?? null), 'Registry add_option must disable autoload.');
    $assert(false === ($GLOBALS['awvp_backend_autoload_writes'][0][1] ?? null), 'Registry autoload enforcement must be false.');
    $assert(false === $GLOBALS['awvp_backend_option_autoload'], 'Successful registry save must leave the option non-autoloaded.');

    $GLOBALS['awvp_backend_option_autoload'] = true;
    $GLOBALS['awvp_backend_option_writes'] = array();
    $GLOBALS['awvp_backend_autoload_writes'] = array();
    $assert($registry->save($valid), 'Unchanged registry value must still repair an incorrect autoload=true state.');
    $assert(false === $GLOBALS['awvp_backend_option_autoload'], 'Autoload repair must leave registry non-autoloaded.');

    $GLOBALS['awvp_backend_option_autoload'] = true;
    $GLOBALS['awvp_backend_autoload_set_fails'] = true;
    $assert(! $registry->save($valid), 'Registry save must fail if non-autoload postcondition cannot be established.');
    $assert(true === $GLOBALS['awvp_backend_option_autoload'], 'Failed autoload correction fixture must remain autoloaded.');
    $GLOBALS['awvp_backend_autoload_set_fails'] = false;
    $GLOBALS['awvp_backend_option_autoload'] = false;

    $before = $GLOBALS['awvp_backend_option_value'];
    $invalid_unknown = $valid;
    $invalid_unknown['backends']['remote'] = array(
        'id' => 'remote',
        'type' => 'peertube',
        'label' => 'Unimplemented PeerTube',
        'state' => 'active',
        'config_version' => 1,
        'config' => array(),
    );
    $assert(! $registry->save($invalid_unknown), 'Unknown descriptor writer must fail closed in this tranche.');
    $assert($before === $GLOBALS['awvp_backend_option_value'], 'Failed unknown-type save must preserve stored registry.');

    $invalid_secret = $valid;
    $invalid_secret['backends']['local']['config'] = array('access_token' => 'do-not-store');
    $assert(! $registry->save($invalid_secret), 'Secret-like ordinary descriptor material must be rejected.');

    $peertube = array(
        'id' => 'peertube-primary',
        'type' => 'peertube',
        'label' => 'Primary PeerTube',
        'state' => 'disabled',
        'default_destination' => 'Channel: A',
        'secret_ref' => 'managed_0123456789abcdef0123456789abcdef',
        'config_version' => 1,
        'config' => array('origin' => 'https://video.example.org'),
    );

    $GLOBALS['awvp_backend_option_exists'] = false;
    $GLOBALS['awvp_backend_option_value'] = null;
    $GLOBALS['awvp_backend_option_writes'] = array();
    $GLOBALS['awvp_backend_autoload_writes'] = array();
    $GLOBALS['awvp_backend_option_autoload'] = false;

    $assert($registry->can_add_peertube($peertube), 'Known PeerTube v1 descriptor should pass read-only preflight.');
    $assert([] === $GLOBALS['awvp_backend_option_writes'], 'PeerTube preflight must not write the registry.');
    $assert([] === $GLOBALS['awvp_backend_autoload_writes'], 'PeerTube preflight must not mutate registry autoload state.');
    $assert(! $GLOBALS['awvp_backend_option_exists'], 'PeerTube preflight must not materialize the absent registry.');

    $stored_known_peertube = $valid;
    $stored_known_peertube['backends']['peertube-primary'] = $peertube;
    $GLOBALS['awvp_backend_option_exists'] = true;
    $GLOBALS['awvp_backend_option_value'] = $stored_known_peertube;
    $assert($peertube === $registry->get('peertube-primary'), 'Stored known PeerTube descriptor normalization mismatch.');
    $assert('active' === (($registry->get('local')['state'] ?? '')), 'Stored PeerTube state must not change canonical local behavior.');

    $second_peertube = $peertube;
    $second_peertube['id'] = 'peertube-secondary';
    $GLOBALS['awvp_backend_option_writes'] = array();
    $GLOBALS['awvp_backend_autoload_writes'] = array();
    $before_known_preflight = $GLOBALS['awvp_backend_option_value'];
    $assert($registry->can_add_peertube($second_peertube), 'Preflight should accept a second known PeerTube descriptor prospectively.');
    $assert($before_known_preflight === $GLOBALS['awvp_backend_option_value'], 'Known-state PeerTube preflight must preserve the option exactly.');
    $assert([] === $GLOBALS['awvp_backend_option_writes'], 'Known-state PeerTube preflight must perform no option write.');
    $assert([] === $GLOBALS['awvp_backend_autoload_writes'], 'Known-state PeerTube preflight must perform no autoload write.');

    $active_peertube = $peertube;
    $active_peertube['id'] = 'peertube-active-too-early';
    $active_peertube['state'] = 'active';
    $assert(
        ! $registry->can_add_peertube($active_peertube),
        'R34 preflight must reject an active PeerTube descriptor before verified activation exists.'
    );

    $secret_peertube = $peertube;
    $secret_peertube['id'] = 'peertube-secret';
    $secret_peertube['config']['access_token'] = 'must-never-enter-the-registry';
    $assert(! $registry->can_add_peertube($secret_peertube), 'Secret-like PeerTube config must fail closed.');

    $missing_secret_ref = $peertube;
    $missing_secret_ref['id'] = 'peertube-no-secret-ref';
    $missing_secret_ref['secret_ref'] = '';
    $assert(! $registry->can_add_peertube($missing_secret_ref), 'PeerTube descriptor must carry a nonempty opaque secret-store reference.');

    $future_peertube = $peertube;
    $future_peertube['id'] = 'peertube-future';
    $future_peertube['config_version'] = 2;
    $future_peertube['config']['future_mode'] = 'preserve-only';
    $assert(! $registry->can_add_peertube($future_peertube), 'Future PeerTube config must fail closed in the v1 preflight.');

    $preserved_future_descriptor = $future_descriptor;
    $preserved_future_descriptor['future_field'] = array('nested' => 'preserve-me-exactly');
    $preserved_future_peertube = $future_peertube;
    $preserved_future_peertube['id'] = 'peertube-future-stored';
    $preserved_future_peertube['future_descriptor_field'] = 'also-preserve-me';
    $stored_with_preserved_future = $valid;
    $stored_with_preserved_future['future_registry_field'] = 'preserve-this-too';
    $stored_with_preserved_future['backends']['future'] = $preserved_future_descriptor;
    $stored_with_preserved_future['backends']['peertube-future-stored'] = $preserved_future_peertube;
    $GLOBALS['awvp_backend_option_exists'] = true;
    $GLOBALS['awvp_backend_option_value'] = $stored_with_preserved_future;
    $GLOBALS['awvp_backend_option_writes'] = array();
    $GLOBALS['awvp_backend_autoload_writes'] = array();
    $GLOBALS['awvp_backend_option_autoload'] = false;

    $preserving_peertube = $peertube;
    $preserving_peertube['id'] = 'peertube-preserving';
    $preserving_peertube['default_destination'] = '';
    $before_preserving_preflight = $GLOBALS['awvp_backend_option_value'];
    $assert($registry->can_add_peertube($preserving_peertube), 'PeerTube preflight should accept a valid registry containing future state.');
    $assert($before_preserving_preflight === $GLOBALS['awvp_backend_option_value'], 'PeerTube preflight must preserve future state without writes.');
    $assert([] === $GLOBALS['awvp_backend_option_writes'], 'PeerTube future-state preflight must remain read-only.');
    $assert([] === $GLOBALS['awvp_backend_autoload_writes'], 'PeerTube future-state preflight must not touch autoload state.');
    $assert(null === $registry->get('peertube-future-stored'), 'Stored future PeerTube config must fail closed during normalization.');
    $assert(in_array('registry_descriptor_malformed', $registry->diagnostics(), true), 'Stored future PeerTube config must emit the malformed-descriptor diagnostic.');

    $stored_with_existing_target = $stored_with_preserved_future;
    $stored_with_existing_target['backends']['peertube-preserving'] = $preserving_peertube;
    $GLOBALS['awvp_backend_option_value'] = $stored_with_existing_target;
    $GLOBALS['awvp_backend_option_writes'] = array();
    $GLOBALS['awvp_backend_autoload_writes'] = array();
    $before_existing_target = $GLOBALS['awvp_backend_option_value'];
    $assert(! $registry->can_add_peertube($preserving_peertube), 'Create-only PeerTube preflight must refuse an existing target ID.');
    $assert($before_existing_target === $GLOBALS['awvp_backend_option_value'], 'Refused PeerTube target preflight must preserve the registry exactly.');
    $assert([] === $GLOBALS['awvp_backend_option_writes'], 'Refused PeerTube target preflight must perform no option write.');
    $assert([] === $GLOBALS['awvp_backend_autoload_writes'], 'Refused PeerTube target preflight must perform no autoload write.');

    $future_registry_for_append = $stored_with_preserved_future;
    $future_registry_for_append['version'] = 2;
    $GLOBALS['awvp_backend_option_value'] = $future_registry_for_append;
    $GLOBALS['awvp_backend_option_writes'] = array();
    $future_registry_append = $peertube;
    $future_registry_append['id'] = 'peertube-on-future-registry';
    $assert(! $registry->can_add_peertube($future_registry_append), 'PeerTube v1 preflight must refuse a future top-level registry version.');
    $assert($future_registry_for_append === $GLOBALS['awvp_backend_option_value'], 'Refused future-registry preflight must leave it unchanged.');
    $assert([] === $GLOBALS['awvp_backend_option_writes'], 'Refused future-registry preflight must perform no option write.');

    $missing_local_registry_for_append = array(
        'version' => 1,
        'backends' => array('future' => $preserved_future_descriptor),
    );
    $GLOBALS['awvp_backend_option_value'] = $missing_local_registry_for_append;
    $GLOBALS['awvp_backend_option_writes'] = array();
    $missing_local_append = $peertube;
    $missing_local_append['id'] = 'peertube-on-missing-local';
    $assert(! $registry->can_add_peertube($missing_local_append), 'PeerTube preflight must refuse an existing registry missing local.');
    $assert($missing_local_registry_for_append === $GLOBALS['awvp_backend_option_value'], 'Missing-local registry must remain unchanged after refused preflight.');
    $assert([] === $GLOBALS['awvp_backend_option_writes'], 'Missing-local refusal must perform no option write.');

    $malformed_registry_for_append = $stored_with_preserved_future;
    $malformed_registry_for_append['backends']['broken'] = 'not-a-descriptor';
    $GLOBALS['awvp_backend_option_value'] = $malformed_registry_for_append;
    $GLOBALS['awvp_backend_option_writes'] = array();
    $malformed_registry_append = $peertube;
    $malformed_registry_append['id'] = 'peertube-on-malformed-registry';
    $assert(! $registry->can_add_peertube($malformed_registry_append), 'PeerTube preflight must reject a malformed current registry.');
    $assert($malformed_registry_for_append === $GLOBALS['awvp_backend_option_value'], 'Malformed registry must remain unchanged after refused preflight.');
    $assert([] === $GLOBALS['awvp_backend_option_writes'], 'Malformed registry refusal must perform no option write.');

    $malformed_known_peertube = $valid;
    $malformed_known_peertube['backends']['peertube-bad-origin'] = $peertube;
    $malformed_known_peertube['backends']['peertube-bad-origin']['id'] = 'peertube-bad-origin';
    $malformed_known_peertube['backends']['peertube-bad-origin']['config']['origin'] = 'https://video.example.org/path';
    $GLOBALS['awvp_backend_option_value'] = $malformed_known_peertube;
    $assert(null === $registry->get('peertube-bad-origin'), 'Malformed stored PeerTube v1 config must not normalize as known state.');
    $assert(in_array('registry_descriptor_malformed', $registry->diagnostics(), true), 'Malformed stored PeerTube diagnostic missing.');

    $queue = new Queue();
    $diagnostics = new Diagnostics(
        array(
            array('check' => 'FFmpeg', 'status' => 'ok', 'detail' => '/usr/bin/ffmpeg'),
            array('check' => 'Uploads writable', 'status' => 'ok', 'detail' => 'Writable'),
        )
    );
    $adapter = new Local_Backend_Adapter($queue, $diagnostics);
    $factory = new Backend_Adapter_Factory($adapter);

    $assert('local' === $adapter->type(), 'Local adapter type mismatch.');
    $assert(Backend_Health::OK === $adapter->health($valid['backends']['local'])->status(), 'Healthy local diagnostics should be OK.');

    $GLOBALS['awvp_backend_option_value'] = $valid;
    $assert(
        $registry->eligible('local', Backend_Capabilities::PROCESSING_VIDEO, $factory),
        'Active healthy local processing should be eligible.'
    );

    $disabled = $valid;
    $disabled['backends']['local']['state'] = 'disabled';
    $GLOBALS['awvp_backend_option_value'] = $disabled;
    $assert(null !== $registry->get('local'), 'Disabled local must remain resolvable.');
    $assert(
        ! $registry->eligible('local', Backend_Capabilities::PROCESSING_VIDEO, $factory),
        'Disabled local must not be eligible for new work.'
    );

    $blocking_adapter = new Local_Backend_Adapter(
        new Queue(),
        new Diagnostics(array(array('check' => 'FFmpeg', 'status' => 'error', 'detail' => 'Unavailable')))
    );
    $blocking_factory = new Backend_Adapter_Factory($blocking_adapter);
    $GLOBALS['awvp_backend_option_value'] = $valid;
    $assert(
        ! $registry->eligible('local', Backend_Capabilities::PROCESSING_VIDEO, $blocking_factory),
        'Blocking local health must fail eligibility.'
    );

    $job_id = $adapter->queue_video(101, 'dual+hls', true);
    $assert(77 === $job_id, 'Local adapter did not return delegated job ID.');
    $assert(
        array('attachment_id' => 202, 'force' => true, 'profile' => 'dual+hls') === ($queue->calls[0] ?? null),
        'Local adapter did not delegate exact attachment/profile to Queue.'
    );

    $threw = false;
    try {
        $adapter->queue_video(101, 'made-up-profile', false);
    } catch (\RuntimeException) {
        $threw = true;
    }
    $assert($threw, 'Invalid local profile must fail closed.');

    echo "AWVP 2.0 backend registry/local adapter tests passed.\n";
}
