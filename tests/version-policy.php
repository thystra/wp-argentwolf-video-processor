<?php
/**
 * File: tests/version-policy.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = (string) file_get_contents($root . '/argentwolf-video-processor.php');
$readme = (string) file_get_contents($root . '/readme.txt');
$failures = array();

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$plugin_version = null;
$runtime_version = null;
$stable_tag = null;

if (1 === preg_match('/^[\\h]*\\*[\\h]+Version:[\\h]*([0-9A-Za-z.-]+)[\\h]*$/m', $plugin, $matches)) {
    $plugin_version = $matches[1];
}
if (1 === preg_match("/^define\\('ARGENT_VIDEO_VERSION', '([^']+)'\\);$/m", $plugin, $matches)) {
    $runtime_version = $matches[1];
}
if (1 === preg_match('/^Stable tag:[\\h]*([0-9.]+)[\\h]*$/m', $readme, $matches)) {
    $stable_tag = $matches[1];
}

$assert($plugin_version === $runtime_version, 'Plugin header and ARGENT_VIDEO_VERSION must match.');
$assert(
    null !== $plugin_version
        && 1 === preg_match('/^2\.0\.0(?:-rc[1-9][0-9]*)?$/', $plugin_version),
    'The 2.0 release line must use 2.0.0-rcN candidates or final 2.0.0.'
);

if (null !== $plugin_version && null !== $stable_tag) {
    if ('2.0.0' === $plugin_version) {
        $assert('2.0.0' === $stable_tag, 'Final 2.0.0 must publish matching WordPress.org Stable tag 2.0.0.');
    } else {
        $assert('1.0.0' === $stable_tag, 'WordPress.org Stable tag must remain 1.0.0 throughout the 2.0 RC cycle.');
        $assert(version_compare($stable_tag, $plugin_version, '<'), 'Public stable release must compare lower than the installed RC.');
    }
}

if (1 === preg_match('/^== Upgrade Notice ==\R(?<notices>.*?)(?=^== |\z)/ms', $readme, $matches)) {
    preg_match_all('/^= [^\r\n=]+ =\R([^\r\n]*)/m', $matches['notices'], $notice_matches);
    foreach ($notice_matches[1] as $upgrade_notice) {
        $assert(strlen($upgrade_notice) <= 300, 'Every WordPress.org upgrade notice must be 300 characters or fewer.');
    }
}

// WordPress plugin update checks use PHP version comparison semantics. Preserve
// the exact promotion ordering required by the live RC -> final validation gate.
$assert(version_compare('2.0.0-rc1', '2.0.0-rc2', '<'), 'Later RCs must compare newer than earlier RCs.');
$assert(version_compare('2.0.0-rc2', '2.0.0-rc3', '<'), 'RC3 must compare newer than RC2.');
$assert(version_compare('2.0.0-rc3', '2.0.0-rc4', '<'), 'RC4 must compare newer than RC3.');
$assert(version_compare('2.0.0-rc4', '2.0.0-rc5', '<'), 'RC5 must compare newer than RC4.');
$assert(version_compare('2.0.0-rc5', '2.0.0-rc6', '<'), 'RC6 must compare newer than RC5.');
$assert(version_compare('2.0.0-rc6', '2.0.0-rc7', '<'), 'RC7 must compare newer than RC6.');
$assert(version_compare('2.0.0-rc7', '2.0.0-rc8', '<'), 'RC8 must compare newer than RC7.');
$assert(version_compare('2.0.0-rc8', '2.0.0-rc9', '<'), 'RC9 must compare newer than RC8.');
$assert(version_compare('2.0.0-rc99', '2.0.0', '<'), 'Final 2.0.0 must compare newer than every numbered 2.0.0 RC.');
$assert(version_compare('1.0.0', '2.0.0', '<'), 'Public 1.0.0 must compare older than final 2.0.0.');

if ([] !== $failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "2.0 release-version policy tests passed.\n");

// EOF: tests/version-policy.php
