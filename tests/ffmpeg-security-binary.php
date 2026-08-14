<?php
/**
 * File: tests/ffmpeg-security-binary.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/Shell_Probe.php';
require_once dirname(__DIR__) . '/includes/FFmpeg_Security.php';

use ArgentVideo\FFmpeg_Security;

$ffmpeg = getenv('ARGENT_VIDEO_TEST_FFMPEG') ?: '/usr/bin/ffmpeg';
$expected = strtolower(trim((string) (getenv('ARGENT_VIDEO_EXPECT_SECURITY') ?: 'allow')));
if (! in_array($expected, array('allow', 'block'), true)) {
    fwrite(STDERR, "FAIL: ARGENT_VIDEO_EXPECT_SECURITY must be allow or block.\n");
    exit(2);
}

$assessment = FFmpeg_Security::assess($ffmpeg);
$allowed = ! empty($assessment['processing_allowed']);
$summary = array(
    'ffmpeg' => $ffmpeg,
    'version' => $assessment['version'] ?? '',
    'version_raw' => $assessment['version_raw'] ?? '',
    'processing_allowed' => $allowed,
    'advisories' => $assessment['advisories'] ?? array(),
);
fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$want_allowed = 'allow' === $expected;
if ($want_allowed !== $allowed) {
    fwrite(STDERR, sprintf(
        "FAIL: real FFmpeg security expectation was %s but assessment was %s.\n",
        $expected,
        $allowed ? 'allow' : 'block'
    ));
    exit(1);
}

fwrite(STDOUT, "Real FFmpeg security expectation matched: {$expected}.\n");

// EOF: tests/ffmpeg-security-binary.php
