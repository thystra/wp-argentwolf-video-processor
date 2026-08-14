<?php
/**
 * File: tests/ffmpeg-security.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/FFmpeg_Security.php';

use ArgentVideo\FFmpeg_Security;

$decoder_present = "Decoders:\n V....D magicyuv            MagicYUV video\n";
$decoder_absent = "Decoders:\n V....D h264                H.264 / AVC\n";

$cases = array(
    array('5.1.9', $decoder_present, false, 'vulnerable'),
    array('5.1.9', $decoder_absent, true, 'not_affected'),
    array('5.1.10', $decoder_present, true, 'patched'),
    array('7.1.4', $decoder_present, false, 'vulnerable'),
    array('7.1.5', $decoder_present, true, 'patched'),
    array('8.0.2', $decoder_present, false, 'vulnerable'),
    array('8.0.3', $decoder_present, true, 'patched'),
    array('8.1.1', $decoder_present, false, 'vulnerable'),
    array('8.1.2', $decoder_present, true, 'patched'),
    array('9.0', $decoder_present, true, 'patched'),
);

$failures = array();
foreach ($cases as [$version, $decoders, $expected_allowed, $expected_status]) {
    $assessment = FFmpeg_Security::evaluate("ffmpeg version {$version} test-build\n", $decoders);
    $advisory = $assessment['advisories'][0] ?? array();
    if ($expected_allowed !== (bool) $assessment['processing_allowed']) {
        $failures[] = "FFmpeg {$version} allowed state mismatch.";
    }
    if ($expected_status !== ($advisory['status'] ?? '')) {
        $failures[] = "FFmpeg {$version} advisory status mismatch: " . ($advisory['status'] ?? '(missing)');
    }
    if (FFmpeg_Security::CVE_2026_8461 !== ($advisory['id'] ?? '')) {
        $failures[] = "FFmpeg {$version} did not report CVE-2026-8461.";
    }
    if (FFmpeg_Security::CVE_2026_8461_URL !== ($advisory['url'] ?? '')) {
        $failures[] = "FFmpeg {$version} did not report the required NVD URL.";
    }
}

$unknown = FFmpeg_Security::evaluate('ffmpeg version git-build', $decoder_present);
if (! empty($unknown['processing_allowed']) || 'unverified' !== ($unknown['advisories'][0]['status'] ?? '')) {
    $failures[] = 'Unparseable version with MagicYUV enabled must fail closed.';
}

$unknown_without_decoder = FFmpeg_Security::evaluate('ffmpeg version git-build', $decoder_absent);
if (empty($unknown_without_decoder['processing_allowed']) || 'not_affected' !== ($unknown_without_decoder['advisories'][0]['status'] ?? '')) {
    $failures[] = 'MagicYUV-disabled build must pass this CVE regardless of version parsing.';
}

$decoder_probe_failed = FFmpeg_Security::evaluate('ffmpeg version 9.0', '', true, false);
if (! empty($decoder_probe_failed['processing_allowed']) || 'unverified' !== ($decoder_probe_failed['advisories'][0]['status'] ?? '')) {
    $failures[] = 'Failed decoder inventory must fail closed.';
}

if ([] !== $failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "FFmpeg security advisory matrix tests passed.\n");

// EOF: tests/ffmpeg-security.php
