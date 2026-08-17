<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

$zip_path = awvp_release_env('AWVP_RELEASE_PACKAGE_ZIP');
$plugin_dir = awvp_release_env('AWVP_RELEASE_PLUGIN_DIR');
$expected_sha = awvp_release_env('AWVP_RELEASE_PACKAGE_SHA256');
$expected_version = awvp_release_env('AWVP_RELEASE_PACKAGE_VERSION');
$plugin_main = awvp_release_env('AWVP_RELEASE_PLUGIN_MAIN');

awvp_release_assert(is_file($zip_path), "Release package ZIP is missing: {$zip_path}");
awvp_release_assert(is_dir($plugin_dir), "Installed plugin directory is missing: {$plugin_dir}");
awvp_release_assert(
    hash_file('sha256', $zip_path) === $expected_sha,
    'Mounted release package SHA-256 changed before installed-tree verification.'
);
awvp_release_assert(
    class_exists('ZipArchive'),
    'ZipArchive is required for installed-package identity verification.'
);

$zip = new ZipArchive();
awvp_release_assert(true === $zip->open($zip_path), 'Could not open release package ZIP.');

$zip_files = array();
$root_prefix = null;

for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    awvp_release_assert(is_array($stat), "Could not stat ZIP entry {$i}.");

    $name = (string) ($stat['name'] ?? '');
    awvp_release_assert('' !== $name, "ZIP entry {$i} has an empty name.");

    if (str_ends_with($name, '/')) {
        continue;
    }

    $parts = explode('/', $name, 2);
    awvp_release_assert(
        2 === count($parts),
        "ZIP entry is not under a single plugin root: {$name}"
    );

    if (null === $root_prefix) {
        $root_prefix = $parts[0];
    }

    awvp_release_assert(
        $root_prefix === $parts[0],
        "ZIP contains multiple top-level roots: {$root_prefix}, {$parts[0]}"
    );

    $relative = $parts[1];
    awvp_release_assert(
        '' !== $relative
        && ! str_starts_with($relative, '/')
        && ! str_starts_with($relative, '../')
        && ! str_contains($relative, '/../'),
        "Unsafe ZIP relative path: {$relative}"
    );

    $content = $zip->getFromIndex($i);
    awvp_release_assert(false !== $content, "Could not read ZIP entry: {$name}");
    $zip_files[$relative] = hash('sha256', $content);
}

$zip->close();

awvp_release_assert(null !== $root_prefix, 'Release package ZIP contained no files.');
awvp_release_assert(
    isset($zip_files[$plugin_main]),
    "Plugin main file is missing from ZIP: {$plugin_main}"
);

$installed_files = array();
$base = rtrim($plugin_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($plugin_dir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    awvp_release_assert(
        $file instanceof SplFileInfo,
        'Unexpected installed-tree iterator entry.'
    );

    if (! $file->isFile()) {
        continue;
    }

    $path = $file->getPathname();
    awvp_release_assert(
        str_starts_with($path, $base),
        "Installed file escaped plugin root: {$path}"
    );

    $relative = substr($path, strlen($base));
    awvp_release_assert(
        false !== $relative && '' !== $relative,
        'Could not derive installed relative path.'
    );

    $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    $installed_files[$relative] = hash_file('sha256', $path);
}

ksort($zip_files);
ksort($installed_files);

$missing = array_diff_key($zip_files, $installed_files);
$extra = array_diff_key($installed_files, $zip_files);

awvp_release_assert(
    array() === $missing,
    'Installed plugin is missing package files: ' . implode(', ', array_keys($missing))
);
awvp_release_assert(
    array() === $extra,
    'Installed plugin contains files not present in package: ' . implode(', ', array_keys($extra))
);

foreach ($zip_files as $relative => $hash) {
    awvp_release_assert(
        isset($installed_files[$relative]) && $installed_files[$relative] === $hash,
        "Installed plugin file differs from package bytes: {$relative}"
    );
}

$main_path = $base . $plugin_main;
$main_contents = file_get_contents($main_path);
awvp_release_assert(false !== $main_contents, 'Could not read installed plugin main file.');
awvp_release_assert(
    1 === preg_match('/^[ \t*#@]*Version:\s*(\S+)/mi', $main_contents, $matches),
    'Could not read installed plugin Version header.'
);
awvp_release_assert(
    $expected_version === (string) ($matches[1] ?? ''),
    "Installed plugin version is not {$expected_version}."
);

echo 'AWVP_RELEASE_INSTALLED_PACKAGE_IDENTITY_PASS'
    . ' version=' . $expected_version
    . ' files=' . count($zip_files)
    . ' sha256=' . $expected_sha
    . "\n";
