<?php
/** Focused R46.3a publication-catalog cache/service tests. */
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
    }
}

namespace {
    $GLOBALS['awvp_catalog_options'] = array();
    $GLOBALS['awvp_catalog_autoload'] = array();

    function get_option(string $option, mixed $default = false): mixed
    {
        return array_key_exists($option, $GLOBALS['awvp_catalog_options']) ? $GLOBALS['awvp_catalog_options'][$option] : $default;
    }
    function add_option(string $option, mixed $value, string $deprecated = '', bool $autoload = true): bool
    {
        unset($deprecated);
        if (array_key_exists($option, $GLOBALS['awvp_catalog_options'])) return false;
        $GLOBALS['awvp_catalog_options'][$option] = $value;
        $GLOBALS['awvp_catalog_autoload'][$option] = $autoload;
        return true;
    }
    function update_option(string $option, mixed $value, ?bool $autoload = null): bool
    {
        $GLOBALS['awvp_catalog_options'][$option] = $value;
        if (null !== $autoload) $GLOBALS['awvp_catalog_autoload'][$option] = $autoload;
        return true;
    }
    function wp_set_option_autoload(string $option, bool $autoload): bool
    {
        $GLOBALS['awvp_catalog_autoload'][$option] = $autoload;
        return true;
    }

    require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
    require_once dirname(__DIR__) . '/includes/Backend_Secret_Store.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Catalog_Api.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Catalog.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Catalog_Store.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Catalog_Service.php';

    $assert = static function (bool $ok, string $message): void {
        if (! $ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    };

    final class FakeSecrets implements \ArgentVideo\Backend_Secret_Store
    {
        public ?array $secret = null;
        public bool $throw_on_read = false;
        public int $replace_calls = 0;
        public int $delete_calls = 0;
        public function available(): bool { return true; }
        public function read(string $secret_ref, string $backend_id): ?array
        {
            unset($secret_ref,$backend_id);
            if ($this->throw_on_read) throw new \RuntimeException('simulated secret read failure');
            return $this->secret;
        }
        public function replace(string $secret_ref, string $backend_id, array $secret, int $expected_generation): bool
        {
            unset($secret_ref,$backend_id,$secret,$expected_generation);
            ++$this->replace_calls;
            return false;
        }
        public function delete(string $secret_ref, string $backend_id, int $expected_generation): bool
        {
            unset($secret_ref,$backend_id,$expected_generation);
            ++$this->delete_calls;
            return false;
        }
    }

    final class FakeCatalogApi implements \ArgentVideo\PeerTube_Publication_Catalog_Api
    {
        public bool $throw = false;
        public function __construct(public array $result, public array &$tokens) {}
        public function publication_catalog(string $access_token): array
        {
            $this->tokens[] = $access_token;
            if ($this->throw) throw new \RuntimeException('simulated catalog transport exception');
            return $this->result;
        }
    }

    $registry = new \ArgentVideo\Backend_Registry();
    $registry->descriptors['pt-primary'] = array(
        'id'=>'pt-primary','type'=>'peertube','state'=>'active','secret_ref'=>'secret-pt-primary',
        'default_destination'=>'7','config'=>array('origin'=>'https://video.example.com'),
    );
    $store = new \ArgentVideo\PeerTube_Publication_Catalog_Store();
    $secrets = new FakeSecrets();
    $secrets->secret = array('access_token'=>'token-sentinel','refresh_token'=>'refresh','access_expires_at'=>2000001000,'refresh_expires_at'=>2000100000,'generation'=>1);
    $tokens = array();
    $remote_data = array(
        'server_version'=>'8.2.0',
        'channels'=>array(array('id'=>'7','name'=>'main','display_name'=>'Main','authority'=>'owned')),
        'privacies'=>array('1'=>'Public','2'=>'Unlisted','3'=>'Private','4'=>'Internal','5'=>'Password protected'),
        'licences'=>array('1'=>'Attribution','9'=>'All Rights Reserved'),
        'categories'=>array('15'=>'Science & Technology'),
        'languages'=>array('_unknown'=>'Unknown','en'=>'English'),
        'capabilities'=>array('sensitive_content'=>true,'sensitive_flags'=>true,'password_privacy'=>true),
    );
    $api = new FakeCatalogApi(array('ok'=>true,'data'=>$remote_data,'error'=>null), $tokens);
    $service = new \ArgentVideo\PeerTube_Publication_Catalog_Service($store,$secrets,$registry,static fn(string $origin) => $api);

    $result = $service->refresh('pt-primary', 2000000000);
    $assert(\ArgentVideo\PeerTube_Publication_Catalog_Service::COMPLETE === $result['status'], 'Valid publication catalog refresh failed.');
    $catalog = $store->get('pt-primary');
    $assert(is_array($catalog) && '8.2.0' === $catalog['server_version'], 'Last-known-good catalog was not persisted.');
    $assert(1 === $catalog['secret_generation'], 'Publication catalog did not bind the managed-secret generation.');
    $assert(false === $catalog['stale'] && null === $catalog['stale_since'] && '' === $catalog['stale_reason'], 'Successful refresh was not stored fresh.');
    $assert($catalog === $store->get_for_context('pt-primary', 'https://video.example.com', 1), 'Catalog context lookup rejected matching backend/origin/generation.');
    $assert(null === $store->get_for_context('pt-primary', 'https://video.example.com', 2), 'Catalog context lookup accepted a different credential generation.');
    $assert(false === ($GLOBALS['awvp_catalog_autoload'][\ArgentVideo\PeerTube_Publication_Catalog_Store::option_name('pt-primary')] ?? true), 'Catalog cache must be non-autoloaded.');
    $assert(array('token-sentinel') === $tokens, 'Catalog service did not confine managed bearer to one API call.');

    $provider_snapshot = array_intersect_key($catalog, array_flip(array('server_version','channels','privacies','licences','categories','languages','capabilities')));
    $api->result = array('ok'=>false,'data'=>null,'error'=>array('status'=>'transport_error'));
    $failed = $service->refresh('pt-primary', 2000000010);
    $assert(\ArgentVideo\PeerTube_Publication_Catalog_Service::REMOTE_FAILED === $failed['status'], 'Remote catalog failure classification mismatch.');
    $stale = $store->get('pt-primary');
    $assert(is_array($stale) && true === $stale['stale'], 'Remote refresh failure did not mark last-known-good catalog stale.');
    $assert(2000000010 === $stale['stale_since'] && 'remote_failed' === $stale['stale_reason'], 'Remote refresh stale metadata mismatch.');
    $assert($provider_snapshot === array_intersect_key($stale, $provider_snapshot), 'Remote refresh failure changed last-known-good provider data.');

    $api->throw = true;
    $thrown = $service->refresh('pt-primary', 2000000015);
    $api->throw = false;
    $assert(\ArgentVideo\PeerTube_Publication_Catalog_Service::REMOTE_FAILED === $thrown['status'], 'Thrown remote failure classification mismatch.');
    $stale = $store->get('pt-primary');
    $assert(2000000010 === $stale['stale_since'] && 'remote_failed' === $stale['stale_reason'], 'Repeated remote failure should preserve first stale timestamp.');

    $secrets->secret['access_expires_at'] = 2000000070;
    $refused = $service->refresh('pt-primary', 2000000020);
    $assert(\ArgentVideo\PeerTube_Publication_Catalog_Service::REFUSED === $refused['status'], 'Near-expiry bearer should refuse catalog refresh.');
    $stale = $store->get('pt-primary');
    $assert(true === $stale['stale'] && 'authentication_required' === $stale['stale_reason'], 'Authentication refusal did not retain a clearly stale catalog.');

    // Credential rotation invalidates the observational context even when the same
    // backend/origin remains configured. A failed refresh must not relabel old data
    // as having been observed under the new credential generation.
    $secrets->secret['generation'] = 2;
    $secrets->secret['access_expires_at'] = 2000003000;
    $api->result = array('ok'=>false,'data'=>null,'error'=>array('status'=>'transport_error'));
    $rotated_failed = $service->refresh('pt-primary', 2000000030);
    $assert(\ArgentVideo\PeerTube_Publication_Catalog_Service::REMOTE_FAILED === $rotated_failed['status'], 'Credential-rotation failure classification mismatch.');
    $stale = $store->get('pt-primary');
    $assert(1 === $stale['secret_generation'], 'Failed rotated refresh rewrote the catalog credential generation.');
    $assert('backend_context_changed' === $stale['stale_reason'], 'Credential rotation did not identify stale backend context.');
    $assert(null === $store->get_for_context('pt-primary', 'https://video.example.com', 2), 'Old catalog became authoritative for a new credential generation.');

    $api->result = array('ok'=>true,'data'=>$remote_data,'error'=>null);
    $rotated_ok = $service->refresh('pt-primary', 2000000040);
    $assert(\ArgentVideo\PeerTube_Publication_Catalog_Service::COMPLETE === $rotated_ok['status'], 'Credential-rotation refresh did not recover.');
    $catalog2 = $store->get('pt-primary');
    $assert(2 === $catalog2['secret_generation'] && false === $catalog2['stale'], 'Successful rotated refresh did not replace stale context with a fresh snapshot.');
    $assert($catalog2 === $store->get_for_context('pt-primary', 'https://video.example.com', 2), 'Rotated catalog context lookup failed.');

    // Canonical origin is part of the observation context just like credential
    // generation. A changed origin cannot inherit the previous catalog as current.
    $registry->descriptors['pt-primary']['config']['origin'] = 'https://video-two.example.com';
    $api->result = array('ok'=>false,'data'=>null,'error'=>array('status'=>'transport_error'));
    $origin_failed = $service->refresh('pt-primary', 2000000050);
    $assert(\ArgentVideo\PeerTube_Publication_Catalog_Service::REMOTE_FAILED === $origin_failed['status'], 'Origin-change failure classification mismatch.');
    $origin_stale = $store->get('pt-primary');
    $assert('https://video.example.com' === $origin_stale['origin'], 'Failed origin-change refresh rewrote the last-known-good origin.');
    $assert('backend_context_changed' === $origin_stale['stale_reason'], 'Origin change did not identify stale backend context.');
    $assert(null === $store->get_for_context('pt-primary', 'https://video-two.example.com', 2), 'Old catalog became current for a changed origin.');

    $api->result = array('ok'=>true,'data'=>$remote_data,'error'=>null);
    $origin_ok = $service->refresh('pt-primary', 2000000060);
    $assert(\ArgentVideo\PeerTube_Publication_Catalog_Service::COMPLETE === $origin_ok['status'], 'Origin-change refresh did not recover.');
    $catalog3 = $store->get('pt-primary');
    $assert('https://video-two.example.com' === $catalog3['origin'] && false === $catalog3['stale'], 'Successful origin-change refresh did not install fresh context.');

    // Read failures are local authentication/refusal state. They must not throw
    // through the administrator action and must never mutate managed credentials.
    $secrets->throw_on_read = true;
    $read_failed = $service->refresh('pt-primary', 2000000070);
    $secrets->throw_on_read = false;
    $assert(\ArgentVideo\PeerTube_Publication_Catalog_Service::REFUSED === $read_failed['status'], 'Secret read failure escaped the refused boundary.');
    $read_stale = $store->get('pt-primary');
    $assert(true === $read_stale['stale'] && 'authentication_required' === $read_stale['stale_reason'], 'Secret read failure did not leave a stale last-known-good snapshot.');
    $assert(0 === $secrets->replace_calls && 0 === $secrets->delete_calls, 'Publication discovery attempted to mutate managed credentials.');

    $option = \ArgentVideo\PeerTube_Publication_Catalog_Store::option_name('pt-future');
    $GLOBALS['awvp_catalog_options'][$option] = array('version'=>2,'future'=>true);
    $candidate = $catalog3;
    $candidate['backend_id']='pt-future';
    $assert(false === $store->save_last_known_good('pt-future', $candidate), 'Future/malformed catalog state was overwritten.');
    $assert(2 === ($GLOBALS['awvp_catalog_options'][$option]['version'] ?? 0), 'Refused catalog save mutated future state.');

    fwrite(STDOUT, "R46 PeerTube publication-catalog tests passed.\n");
}
