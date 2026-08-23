<?php
/**
 * Focused dependency-free tests for the PeerTube connection foundation.
 */

declare(strict_types=1);

namespace {
    define('AUTH_KEY', 'awvp-test-auth-key-0123456789abcdef');
    define('SECURE_AUTH_KEY', 'awvp-test-secure-auth-key-0123456789abcdef');
    define('AUTH_SALT', 'awvp-test-auth-salt-0123456789abcdef');

    $GLOBALS['awvp_options'] = array();
    $GLOBALS['awvp_autoload'] = array();
    $GLOBALS['awvp_force_add_autoload_true'] = false;
    $GLOBALS['awvp_autoload_set_fail'] = array();

    function wp_parse_url(string $url): array|false
    {
        $parsed = parse_url($url);
        return is_array($parsed) ? $parsed : false;
    }

    function get_option(string $option, mixed $default = false): mixed
    {
        return array_key_exists($option, $GLOBALS['awvp_options'])
            ? $GLOBALS['awvp_options'][$option]
            : $default;
    }

    function add_option(
        string $option,
        mixed $value = '',
        string $deprecated = '',
        mixed $autoload = null
    ): bool {
        unset($deprecated);

        if (array_key_exists($option, $GLOBALS['awvp_options'])) {
            return false;
        }

        $GLOBALS['awvp_options'][$option] = $value;
        $GLOBALS['awvp_autoload'][$option] = $GLOBALS['awvp_force_add_autoload_true']
            ? true
            : true === $autoload;

        return true;
    }

    function update_option(string $option, mixed $value, mixed $autoload = null): bool
    {
        $exists = array_key_exists($option, $GLOBALS['awvp_options']);
        $changed = ! $exists || $GLOBALS['awvp_options'][$option] !== $value;

        $GLOBALS['awvp_options'][$option] = $value;

        if (! array_key_exists($option, $GLOBALS['awvp_autoload'])) {
            $GLOBALS['awvp_autoload'][$option] = false;
        }

        if ($changed && is_bool($autoload)) {
            $GLOBALS['awvp_autoload'][$option] = $autoload;
        }

        return $changed;
    }

    function delete_option(string $option): bool
    {
        if (! array_key_exists($option, $GLOBALS['awvp_options'])) {
            return false;
        }

        unset($GLOBALS['awvp_options'][$option], $GLOBALS['awvp_autoload'][$option]);
        return true;
    }

    function wp_set_option_autoload(string $option, bool $autoload): bool
    {
        if (! array_key_exists($option, $GLOBALS['awvp_options'])) {
            return false;
        }

        if (
            ! empty($GLOBALS['awvp_autoload_set_fail'][$option])
            || (
                isset($GLOBALS['awvp_autoload_set_fail_prefix'])
                && str_starts_with($option, $GLOBALS['awvp_autoload_set_fail_prefix'])
            )
        ) {
            return false;
        }

        $changed = ($GLOBALS['awvp_autoload'][$option] ?? false) !== $autoload;
        $GLOBALS['awvp_autoload'][$option] = $autoload;
        return $changed;
    }

    function wp_load_alloptions(bool $force_cache = false): array
    {
        unset($force_cache);

        $result = array();
        foreach ($GLOBALS['awvp_options'] as $option => $value) {
            if (! empty($GLOBALS['awvp_autoload'][$option])) {
                $result[$option] = $value;
            }
        }

        return $result;
    }
}

namespace ArgentVideo {
    require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Api_Error.php';
    require_once dirname(__DIR__) . '/includes/Backend_Secret_Store.php';
    require_once dirname(__DIR__) . '/includes/Backend_Secret_Crypto.php';
    require_once dirname(__DIR__) . '/includes/Managed_Backend_Secret_Store.php';

    $assert = static function (bool $condition, string $message): void {
        if (! $condition) {
            throw new \RuntimeException($message);
        }
    };

    $assert(
        'https://video.example.org' === PeerTube_Origin::sanitize('HTTPS://Video.Example.Org/'),
        'HTTPS origin canonicalization failed.'
    );
    $assert(
        'https://video.example.org:8443' === PeerTube_Origin::sanitize('https://video.example.org:8443'),
        'Non-default production port identity should remain explicit.'
    );

    foreach (
        array(
            'https://user:pass@video.example.org',
            'https://video.example.org/api/v1',
            'https://video.example.org/?x=1',
            'https://video.example.org/#fragment',
            ' http://video.example.org',
            'http://video.example.org',
            'https://127.0.0.1:9000',
            'https://localhost',
            'https://peertube.test',
        ) as $invalid
    ) {
        $assert('' === PeerTube_Origin::sanitize($invalid), 'Unsafe/invalid origin accepted: ' . $invalid);
    }

    define(
        'ARGENT_VIDEO_PEERTUBE_DEV_ORIGINS',
        array('http://127.0.0.1:9000', 'http://peertube.test:9000')
    );

    $assert(
        'http://127.0.0.1:9000' === PeerTube_Origin::sanitize('http://127.0.0.1:9000'),
        'Exact development IPv4 origin was not allowed.'
    );
    $assert(
        'http://peertube.test:9000' === PeerTube_Origin::sanitize('http://peertube.test:9000/'),
        'Exact development DNS origin was not canonicalized/allowed.'
    );
    $assert(
        '' === PeerTube_Origin::sanitize('http://127.0.0.1:9001'),
        'Unlisted development origin must fail closed.'
    );

    $error = PeerTube_Api_Error::normalize(
        429,
        array('Retry-After' => '17', 'X-RateLimit-Reset' => '12345'),
        '{"type":"about:blank","code":"rate_limit","detail":"Try later"}'
    );
    $assert('rate_limited' === $error['status'], '429 did not normalize to rate_limited.');
    $assert(17 === $error['retry_after'], 'Retry-After was not preserved.');
    $assert(12345 === $error['rate_reset'], 'Rate reset was not preserved.');
    $assert('Try later' === $error['detail'], 'Safe detail was not retained.');

    $secret_error = PeerTube_Api_Error::normalize(
        401,
        array(),
        '{"detail":"access_token=should-not-escape"}'
    );
    $assert('' === $secret_error['detail'], 'Secret-like remote error detail leaked.');

    $store = new Managed_Backend_Secret_Store();
    $assert($store->available(), 'Managed secret store should be available in focused test.');

    $invalid_utf8_secret = array(
        'access_token'       => "\xB1\x31",
        'refresh_token'      => '',
        'access_expires_at'  => 2000000000,
        'refresh_expires_at' => 0,
    );
    $assert(
        '' === $store->create('peertube-crypto-fail', $invalid_utf8_secret),
        'Crypto/JSON failure must return a controlled create failure.'
    );

    $manifest = get_option(Managed_Backend_Secret_Store::OPTION, null);
    $assert(
        array('version' => 1) === $manifest,
        'Managed secret provider manifest must be present and versioned.'
    );
    $assert(
        false === ($GLOBALS['awvp_autoload'][Managed_Backend_Secret_Store::OPTION] ?? true),
        'Managed secret provider manifest must not autoload.'
    );

    $secret1 = array(
        'access_token'       => 'access-one-secret',
        'refresh_token'      => 'refresh-one-secret',
        'access_expires_at'  => 2000000000,
        'refresh_expires_at' => 2001000000,
    );
    $secret2 = array(
        'access_token'       => 'access-two-secret',
        'refresh_token'      => 'refresh-two-secret',
        'access_expires_at'  => 2002000000,
        'refresh_expires_at' => 2003000000,
    );

    $ref1 = $store->create('peertube-one', $secret1);
    $assert('' !== $ref1, 'First managed secret create failed.');
    $record_option1 = Managed_Backend_Secret_Store::OPTION . '_' . $ref1;
    $record1_before = get_option($record_option1, null);

    $ref2 = $store->create('peertube-two', $secret2);
    $assert('' !== $ref2 && $ref2 !== $ref1, 'Second managed secret create failed.');
    $record_option2 = Managed_Backend_Secret_Store::OPTION . '_' . $ref2;

    $assert(
        $record1_before === get_option($record_option1, null),
        'Creating a second backend secret must not rewrite the first record option.'
    );
    $assert(
        null !== get_option($record_option2, null),
        'Second backend secret must have an independent record option.'
    );
    $assert(
        false === ($GLOBALS['awvp_autoload'][$record_option1] ?? true)
        && false === ($GLOBALS['awvp_autoload'][$record_option2] ?? true),
        'Managed secret record options must not autoload.'
    );

    $serialized = serialize($GLOBALS['awvp_options']);
    foreach (array(
        'access-one-secret',
        'refresh-one-secret',
        'access-two-secret',
        'refresh-two-secret',
    ) as $plaintext) {
        $assert(! str_contains($serialized, $plaintext), 'Secret stored in plaintext: ' . $plaintext);
    }

    $read1 = $store->read($ref1, 'peertube-one');
    $assert(is_array($read1) && 1 === $read1['generation'], 'First secret read/generation failed.');
    $assert('access-one-secret' === $read1['access_token'], 'First access token round-trip mismatch.');
    $assert(
        null === $store->read($ref1, 'peertube-two'),
        'Secret record must be bound to its backend ID.'
    );

    $replacement = array(
        'access_token'       => 'replacement-access-secret',
        'refresh_token'      => 'replacement-refresh-secret',
        'access_expires_at'  => 2004000000,
        'refresh_expires_at' => 2005000000,
    );

    $assert(
        ! $store->replace($ref1, 'peertube-one', $replacement, 7),
        'Stale generation replacement must fail.'
    );
    $assert(
        $store->replace($ref1, 'peertube-one', $replacement, 1),
        'Current generation replacement failed.'
    );
    $assert(
        $record_option2 === Managed_Backend_Secret_Store::OPTION . '_' . $ref2
        && null !== get_option($record_option2, null),
        'Replacing one record must not affect another record option.'
    );

    $read1b = $store->read($ref1, 'peertube-one');
    $assert(
        is_array($read1b)
        && 2 === $read1b['generation']
        && 'replacement-access-secret' === $read1b['access_token'],
        'Replacement round-trip/generation failed.'
    );

    $tampered = get_option($record_option1, null);
    $tampered['envelope']['ciphertext'][0] = (
        'A' === $tampered['envelope']['ciphertext'][0] ? 'B' : 'A'
    );
    $GLOBALS['awvp_options'][$record_option1] = $tampered;

    $assert(
        null === $store->read($ref1, 'peertube-one'),
        'Tampered ciphertext must fail closed.'
    );

    $GLOBALS['awvp_force_add_autoload_true'] = true;

    // We do not know the generated record option before create(), so fail all
    // autoload corrections that use the managed record-option prefix.
    $original_setter = $GLOBALS['awvp_autoload_set_fail'];
    $GLOBALS['awvp_autoload_set_fail_prefix'] = Managed_Backend_Secret_Store::OPTION . '_';

    // Rebind the stub behavior through a marker checked below by pre-seeding a
    // candidate is not possible; instead simulate broken add_option autoload
    // semantics and verify that successful create cannot leave an autoloaded
    // record. The implementation's postcondition must catch it.
    $before_options = array_keys($GLOBALS['awvp_options']);
    $ref_fail = $store->create('peertube-autoload-fail', $secret1);
    $assert(
        '' === $ref_fail,
        'Create must fail when a new record cannot establish non-autoload state.'
    );
    $after_options = array_keys($GLOBALS['awvp_options']);
    sort($before_options);
    sort($after_options);
    $assert(
        $before_options === $after_options,
        'Failed record autoload postcondition must roll back only the new record option.'
    );

    $GLOBALS['awvp_force_add_autoload_true'] = false;
    $GLOBALS['awvp_autoload_set_fail'] = $original_setter;
    unset($GLOBALS['awvp_autoload_set_fail_prefix']);

    $assert(
        array('version' => 1) === get_option(Managed_Backend_Secret_Store::OPTION, null),
        'Record-level operations must not rewrite the provider manifest.'
    );

    $assert($store->delete($ref2, 'peertube-two'), 'Record delete failed.');
    $assert(
        null === get_option($record_option2, null),
        'Record delete must remove only the selected record option.'
    );
    $assert(
        array('version' => 1) === get_option(Managed_Backend_Secret_Store::OPTION, null),
        'Record delete must preserve the provider manifest.'
    );

    echo "AWVP PeerTube secret record isolation tests passed.\n";
}
