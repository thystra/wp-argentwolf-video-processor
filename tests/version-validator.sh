#!/usr/bin/env bash
# File: tests/version-validator.sh

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP_ROOT="$(mktemp -d)"
trap 'rm -rf "${TMP_ROOT}"' EXIT
mkdir -p "${TMP_ROOT}/build"
cp "${ROOT_DIR}/build/validate-version.sh" "${TMP_ROOT}/build/validate-version.sh"

write_fixture() {
    local header_version="$1"
    local runtime_version="$2"
    local stable_tag="$3"

    cat > "${TMP_ROOT}/argentwolf-video-processor.php" <<PHP
<?php
/**
 * Version: ${header_version}
 */
define('ARGENT_VIDEO_VERSION', '${runtime_version}');
PHP

    cat > "${TMP_ROOT}/readme.txt" <<TXT
=== ArgentWolf Video Processor ===
Stable tag: ${stable_tag}
TXT
}

expect_pass() {
    local version="$1"
    if ! bash "${TMP_ROOT}/build/validate-version.sh" "${version}" >/dev/null 2>&1; then
        printf 'FAIL: expected version policy to accept %s\n' "${version}" >&2
        exit 1
    fi
}

expect_fail() {
    local version="$1"
    if bash "${TMP_ROOT}/build/validate-version.sh" "${version}" >/dev/null 2>&1; then
        printf 'FAIL: expected version policy to reject %s\n' "${version}" >&2
        exit 1
    fi
}

write_fixture '2.0.0-rc1' '2.0.0-rc1' '1.0.0'
expect_pass '2.0.0-rc1'

write_fixture '2.0.0-rc2' '2.0.0-rc2' '1.0.0'
expect_pass '2.0.0-rc2'

write_fixture '2.0.0-rc1' '2.0.0-rc1' '2.0.0'
expect_fail '2.0.0-rc1'

write_fixture '2.0.0' '2.0.0' '1.0.0'
expect_fail '2.0.0'

write_fixture '2.0.0' '2.0.0' '2.0.0'
expect_pass '2.0.0'

write_fixture '2.0.0-rc1' '2.0.0-rc2' '1.0.0'
expect_fail '2.0.0-rc1'

printf 'Release version validator tests passed.\n'

# EOF: tests/version-validator.sh
