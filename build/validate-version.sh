#!/usr/bin/env bash
# File: build/validate-version.sh

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXPECTED_VERSION="${1:-}"
MAIN_FILE='argentwolf-video-processor.php'

if [[ ! "${EXPECTED_VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
    echo "Usage: $0 X.Y.Z[-prerelease]" >&2
    exit 2
fi

if ! command -v php >/dev/null 2>&1; then
    echo 'PHP is required to validate WordPress version ordering.' >&2
    exit 1
fi

PLUGIN_VERSION="$(
    sed -n 's/^ \* Version: //p' "${ROOT_DIR}/${MAIN_FILE}" |
        head -n 1
)"
RUNTIME_VERSION="$(
    sed -nE "s/^define\('ARGENT_VIDEO_VERSION', '([^']+)'\);$/\1/p" "${ROOT_DIR}/${MAIN_FILE}" |
        head -n 1
)"
STABLE_TAG="$(
    sed -n 's/^Stable tag: //p' "${ROOT_DIR}/readme.txt" |
        head -n 1
)"

if [[ "${PLUGIN_VERSION}" != "${EXPECTED_VERSION}" ]]; then
    echo "Plugin header version ${PLUGIN_VERSION:-<missing>} does not match ${EXPECTED_VERSION}." >&2
    exit 1
fi

if [[ "${RUNTIME_VERSION}" != "${EXPECTED_VERSION}" ]]; then
    echo "ARGENT_VIDEO_VERSION ${RUNTIME_VERSION:-<missing>} does not match ${EXPECTED_VERSION}." >&2
    exit 1
fi

if [[ ! "${STABLE_TAG}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "WordPress.org Stable tag must remain a numeric X.Y.Z release: ${STABLE_TAG:-<missing>}" >&2
    exit 1
fi

if [[ "${EXPECTED_VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    if [[ "${STABLE_TAG}" != "${EXPECTED_VERSION}" ]]; then
        echo "Final release ${EXPECTED_VERSION} requires matching Stable tag ${EXPECTED_VERSION}; found ${STABLE_TAG}." >&2
        exit 1
    fi
else
    if ! php -r 'exit(version_compare($argv[1], $argv[2], "<") ? 0 : 1);' "${STABLE_TAG}" "${EXPECTED_VERSION}"; then
        echo "Prerelease ${EXPECTED_VERSION} requires an older public numeric Stable tag; found ${STABLE_TAG}." >&2
        exit 1
    fi
fi

printf 'Version policy: PASS\n'
printf 'Plugin/runtime version: %s\n' "${PLUGIN_VERSION}"
printf 'WordPress.org Stable tag: %s\n' "${STABLE_TAG}"

# EOF: build/validate-version.sh
