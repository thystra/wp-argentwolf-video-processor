<?php
/** Focused tests for backend-scoped PeerTube upload segmentation policy. */
declare(strict_types=1);

namespace ArgentVideo {
    final class Backend_Registry
    {
        public const PEERTUBE_TYPE = 'peertube';
        /** @var array<string,array<string,mixed>> */
        public array $descriptors = array();

        /** @return array<string,mixed>|null */
        public function get(string $backend_id): ?array
        {
            return $this->descriptors[$backend_id] ?? null;
        }
    }
}

namespace {
    $GLOBALS['awvp_policy_options'] = array();

    function get_option(string $option, mixed $default = false): mixed
    {
        return array_key_exists($option, $GLOBALS['awvp_policy_options'])
            ? $GLOBALS['awvp_policy_options'][$option]
            : $default;
    }

    function add_option(string $option, mixed $value, string $deprecated = '', bool $autoload = true): bool
    {
        unset($deprecated, $autoload);
        if (array_key_exists($option, $GLOBALS['awvp_policy_options'])) {
            return false;
        }
        $GLOBALS['awvp_policy_options'][$option] = $value;
        return true;
    }

    function update_option(string $option, mixed $value, ?bool $autoload = null): bool
    {
        unset($autoload);
        $changed = ! array_key_exists($option, $GLOBALS['awvp_policy_options'])
            || $GLOBALS['awvp_policy_options'][$option] !== $value;
        $GLOBALS['awvp_policy_options'][$option] = $value;
        return $changed;
    }

    require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Upload_Policy.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Upload_Policy_Store.php';

    use ArgentVideo\Backend_Registry;
    use ArgentVideo\PeerTube_Upload_Policy;
    use ArgentVideo\PeerTube_Upload_Policy_Store;

    $assert = static function (bool $ok, string $message): void {
        if (! $ok) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };

    $assert(128 === PeerTube_Upload_Policy::DEFAULT_CHUNK_MIB, 'Default PeerTube chunk size drifted.');
    $assert(8192 === PeerTube_Upload_Policy::MAX_CHUNK_MIB, 'Maximum PeerTube chunk setting drifted.');
    $assert(0 === PeerTube_Upload_Policy::chunk_mib('0'), 'Zero/whole-file policy was rejected.');
    $assert(128 === PeerTube_Upload_Policy::chunk_mib('128'), 'Canonical decimal chunk setting was rejected.');
    $assert(null === PeerTube_Upload_Policy::chunk_mib('0128'), 'Non-canonical chunk setting was accepted.');
    $assert(null === PeerTube_Upload_Policy::chunk_mib(-1), 'Negative chunk setting was accepted.');
    $assert(null === PeerTube_Upload_Policy::chunk_mib(8193), 'Chunk setting above the reviewed maximum was accepted.');

    $mib = 1048576;
    $assert(128 * $mib === PeerTube_Upload_Policy::bytes_for_remaining(128, 500 * $mib), 'Default chunk byte calculation drifted.');
    $assert(5 * $mib === PeerTube_Upload_Policy::bytes_for_remaining(128, 5 * $mib), 'Final short chunk was not bounded by remaining bytes.');
    $assert(500 * $mib === PeerTube_Upload_Policy::bytes_for_remaining(0, 500 * $mib), 'Zero policy did not select the entire remaining source.');

    $registry = new Backend_Registry();
    $registry->descriptors['pt-primary'] = array('id'=>'pt-primary','type'=>'peertube','state'=>'active');
    $registry->descriptors['pt-disabled'] = array('id'=>'pt-disabled','type'=>'peertube','state'=>'disabled');
    $store = new PeerTube_Upload_Policy_Store($registry);

    $assert(128 === $store->chunk_mib('pt-primary'), 'Absent backend policy did not use the 128 MiB default.');
    $assert(PeerTube_Upload_Policy_Store::APPLIED === $store->save_chunk_mib('pt-primary', '0'), 'Whole-remaining policy could not be saved.');
    $assert(0 === $store->chunk_mib('pt-primary'), 'Saved whole-remaining policy was not read back.');
    $assert(PeerTube_Upload_Policy_Store::PRESENT === $store->save_chunk_mib('pt-primary', 0), 'Idempotent policy save was not classified as present.');
    $assert(PeerTube_Upload_Policy_Store::APPLIED === $store->save_chunk_mib('pt-primary', 1024), '1 GiB policy could not be saved.');
    $assert(1024 === $store->chunk_mib('pt-primary'), '1 GiB policy did not persist.');
    $assert(PeerTube_Upload_Policy_Store::REFUSED === $store->save_chunk_mib('pt-primary', 9000), 'Out-of-range policy write was not refused.');
    $assert(PeerTube_Upload_Policy_Store::REFUSED === $store->save_chunk_mib('pt-disabled', 128), 'Disabled backend policy write was not refused.');
    $assert(PeerTube_Upload_Policy_Store::REFUSED === $store->save_chunk_mib('NOT VALID', 128), 'Malformed backend ID policy write was not refused.');

    // A malformed/future record must fail safe to the documented runtime
    // default and must not be destroyed merely because an administrator later
    // attempts another write.
    $malformed_key = 'argent_video_processor_peertube_upload_policy_pt-primary';
    $GLOBALS['awvp_policy_options'][$malformed_key] = array('version'=>2,'chunk_mib'=>512,'future'=>true);
    $assert(128 === $store->chunk_mib('pt-primary'), 'Malformed/future policy did not fail safe to the default.');
    $assert(PeerTube_Upload_Policy_Store::REFUSED === $store->save_chunk_mib('pt-primary', 256), 'Malformed/future policy state was overwritten.');
    $assert(2 === ($GLOBALS['awvp_policy_options'][$malformed_key]['version'] ?? 0), 'Refused write mutated future policy state.');

    fwrite(STDOUT, "PeerTube upload policy tests passed.\n");
}
