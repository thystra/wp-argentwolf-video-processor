<?php
/**
 * Focused dependency-free tests for the PeerTube connection foundation.
 */

declare(strict_types=1);

namespace {
    function wp_parse_url(string $url): array|false
    {
        $parsed = parse_url($url);
        return is_array($parsed) ? $parsed : false;
    }
}

namespace ArgentVideo {
    require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Api_Error.php';

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

    echo "AWVP PeerTube origin/error foundation tests passed.\n";
}
