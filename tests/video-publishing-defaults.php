<?php
/** Focused R46.2 publishing-default/support-preset tests. */
declare(strict_types=1);

namespace ArgentVideo {
    final class Backend_Registry
    {
        public const LOCAL_ID = 'local';
        public const PEERTUBE_TYPE = 'peertube';
        /** @var array<string,array<string,mixed>> */
        public array $descriptors = array();
        /** @return array<string,mixed>|null */
        public function get(string $backend_id): ?array { return $this->descriptors[$backend_id] ?? null; }
        /** @return array<string,array<string,mixed>> */
        public function all(): array { return $this->descriptors; }
    }
}

namespace {
    $GLOBALS['awvp_publishing_options'] = array();
    $GLOBALS['awvp_publishing_option_writes'] = array();

    function get_option(string $option, mixed $default = false): mixed
    {
        return array_key_exists($option, $GLOBALS['awvp_publishing_options'])
            ? $GLOBALS['awvp_publishing_options'][$option]
            : $default;
    }

    function add_option(string $option, mixed $value, string $deprecated = '', bool $autoload = true): bool
    {
        unset($deprecated);
        if (array_key_exists($option, $GLOBALS['awvp_publishing_options'])) {
            return false;
        }
        $GLOBALS['awvp_publishing_options'][$option] = $value;
        $GLOBALS['awvp_publishing_option_writes'][] = array('add', $option, $autoload);
        return true;
    }

    function update_option(string $option, mixed $value, ?bool $autoload = null): bool
    {
        $changed = ! array_key_exists($option, $GLOBALS['awvp_publishing_options'])
            || $GLOBALS['awvp_publishing_options'][$option] !== $value;
        $GLOBALS['awvp_publishing_options'][$option] = $value;
        $GLOBALS['awvp_publishing_option_writes'][] = array('update', $option, $autoload);
        return $changed;
    }

    require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Connection_Input.php';
    require_once dirname(__DIR__) . '/includes/Video_Destination.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Plan.php';
    require_once dirname(__DIR__) . '/includes/Video_Publishing_Defaults.php';
    require_once dirname(__DIR__) . '/includes/Video_Publishing_Defaults_Store.php';

    use ArgentVideo\Backend_Registry;
    use ArgentVideo\PeerTube_Publication_Plan;
    use ArgentVideo\Video_Destination;
    use ArgentVideo\Video_Publishing_Defaults;
    use ArgentVideo\Video_Publishing_Defaults_Store;

    $assert = static function (bool $ok, string $message): void {
        if (! $ok) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };

    $registry = new Backend_Registry();
    $registry->descriptors = array(
        'local' => array('id'=>'local','type'=>'local','state'=>'active'),
        'pt-primary' => array(
            'id'=>'pt-primary', 'type'=>'peertube', 'label'=>'Primary PeerTube', 'state'=>'active',
            'default_destination'=>'101', 'config'=>array('origin'=>'https://video.example.test'),
        ),
        'pt-disabled' => array(
            'id'=>'pt-disabled', 'type'=>'peertube', 'label'=>'Disabled', 'state'=>'disabled',
            'default_destination'=>'202', 'config'=>array('origin'=>'https://disabled.example.test'),
        ),
    );

    $store = new Video_Publishing_Defaults_Store($registry);
    $defaults = $store->get();
    $assert(is_array($defaults), 'Absent publishing option must resolve to defaults.');
    $assert(Video_Destination::local() === $defaults['default_destination'], 'Upgrade-safe default destination must be WordPress/local.');
    $assert('1' === $defaults['site']['final_privacy_id'], 'Default final PeerTube privacy must be Public.');
$assert(PeerTube_Publication_Plan::PRE_PUBLISH_PRIVATE === $defaults['site']['pre_publish_privacy_id'], 'Default pre-publication visibility must be Private.');
    $assert(PeerTube_Publication_Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH === $defaults['site']['dispatch_policy'], 'Default dispatch timing drifted.');
    $assert(array() === $GLOBALS['awvp_publishing_option_writes'], 'Reading absent defaults must not materialize an option.');

    $settings = Video_Publishing_Defaults::defaults();
    $settings['default_destination'] = array('version'=>1,'backend_id'=>'pt-primary');
    $settings['site']['licence_id'] = '2';
    $settings['site']['category_id'] = '15';
    $settings['site']['language'] = 'en';
    $settings['site']['pre_publish_privacy_id'] = PeerTube_Publication_Plan::PRE_PUBLISH_UNLISTED;
    $settings['site']['support_preset_id'] = 'wolf-raven';
    $settings['support_presets'] = array(
        'wolf-raven' => array(
            'label' => 'Wolf & Raven',
            'markdown' => "Support our work:\n[Become a supporter](https://example.test/support)",
        ),
    );
    $settings['backend_overrides']['pt-primary'] = array(
        'channel_id' => '303',
        'final_privacy_id' => '2',
        'licence_id' => null,
        'category_id' => '',
        // Deliberately omit language here: existing RC10 records did not
        // carry this key and must continue to inherit the site language.
    );

    $sanitized = Video_Publishing_Defaults::sanitize($settings);
    $assert(array() !== $sanitized, 'Valid publishing defaults were rejected.');
    $assert(Video_Publishing_Defaults_Store::APPLIED === $store->save($settings)['status'], 'Valid publishing defaults were not saved.');
    $assert(false === ($GLOBALS['awvp_publishing_option_writes'][0][2] ?? null), 'Publishing defaults must be non-autoloaded.');
    $assert(Video_Publishing_Defaults_Store::PRESENT === $store->save($settings)['status'], 'Idempotent save must classify as present.');

    $effective = Video_Publishing_Defaults::effective_for_backend($settings, $registry->descriptors['pt-primary']);
    $assert('303' === ($effective['channel_id'] ?? ''), 'Backend channel override was not applied.');
    $assert(PeerTube_Publication_Plan::PRE_PUBLISH_UNLISTED === ($effective['pre_publish_privacy_id'] ?? ''), 'Pre-publication visibility default was not inherited.');
    $assert('2' === ($effective['final_privacy_id'] ?? ''), 'Backend privacy override was not applied.');
    $assert('2' === ($effective['licence_id'] ?? ''), 'Null backend licence override must inherit site licence.');
    $assert('' === ($effective['category_id'] ?? 'x'), 'Empty backend category override must explicitly clear site category.');
    $assert('en' === ($effective['language'] ?? ''), 'Missing legacy backend language override must inherit site language.');
    $assert('preset' === ($effective['support']['mode'] ?? ''), 'Support preset was not resolved.');
    $assert(str_contains((string) ($effective['support']['markdown'] ?? ''), 'Become a supporter'), 'Support preset Markdown was not resolved.');
    $assert(! array_key_exists('reviewed', $effective['moderation'] ?? array()), 'Defaults must never satisfy per-video moderation review.');

    $snapshot = $effective;
    $changed = $settings;
    $changed['support_presets']['wolf-raven']['markdown'] = 'Later changed preset text';
    $changed_effective = Video_Publishing_Defaults::effective_for_backend($changed, $registry->descriptors['pt-primary']);
    $assert($snapshot['support']['markdown'] !== $changed_effective['support']['markdown'], 'Resolved support snapshot fixture did not change.');
    $assert(str_contains($snapshot['support']['markdown'], 'Become a supporter'), 'Previously resolved support snapshot was mutated by later defaults.');

    $language_override = $settings;
    $language_override['backend_overrides']['pt-primary']['language'] = 'fr';
    $language_effective = Video_Publishing_Defaults::effective_for_backend($language_override, $registry->descriptors['pt-primary']);
    $assert('fr' === ($language_effective['language'] ?? ''), 'Backend language override was not applied.');

    $bad_backend = $settings;
    $bad_backend['default_destination'] = array('version'=>1,'backend_id'=>'pt-disabled');
    $assert(Video_Publishing_Defaults_Store::REFUSED === $store->save($bad_backend)['status'], 'Disabled PeerTube backend must not become the site default.');

    $bad_preset = $settings;
    $bad_preset['site']['support_preset_id'] = 'missing-preset';
    $assert(array() === Video_Publishing_Defaults::sanitize($bad_preset), 'Missing support preset reference must fail closed.');

    $bad_sensitive = $settings;
    $bad_sensitive['site']['moderation'] = array('sensitive'=>false,'reason'=>'not allowed','violent'=>false,'sexually_explicit'=>false);
    $assert(array() === Video_Publishing_Defaults::sanitize($bad_sensitive), 'Non-sensitive prefill must not retain a sensitive summary.');

    $future = array('version'=>2,'default_destination'=>Video_Destination::local(),'future'=>true);
    $GLOBALS['awvp_publishing_options'][Video_Publishing_Defaults_Store::OPTION] = $future;
    $assert(null === $store->get(), 'Future/malformed publishing option must fail closed.');
    $assert(Video_Publishing_Defaults_Store::REFUSED === $store->save($settings)['status'], 'Future publishing option must not be overwritten.');
    $assert(2 === ($GLOBALS['awvp_publishing_options'][Video_Publishing_Defaults_Store::OPTION]['version'] ?? 0), 'Refused save mutated future publishing state.');

    fwrite(STDOUT, "R46 video publishing defaults/support-preset tests passed.\n");
}
