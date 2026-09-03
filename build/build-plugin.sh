#!/usr/bin/env bash
# File: build/build-plugin.sh

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-}"
SLUG='argentwolf-video-processor'
MAIN_FILE='argentwolf-video-processor.php'
DIST_DIR="${ROOT_DIR}/dist"
STAGE_ROOT="$(mktemp -d)"
STAGE_DIR="${STAGE_ROOT}/${SLUG}"

cleanup() {
    rm -rf "${STAGE_ROOT}"
    rm -f \
        "${ROOT_DIR}/assets/vendor/hls.min.js" \
        "${ROOT_DIR}/assets/vendor/hls.LICENSE" \
        "${ROOT_DIR}/assets/vendor/hls.VERSION" \
        "${ROOT_DIR}/assets/vendor/hls.SHA256"
}
trap cleanup EXIT

if [[ ! "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
    echo "Usage: $0 X.Y.Z" >&2
    exit 2
fi

PLUGIN_VERSION="$(
    sed -n 's/^ \* Version: //p' "${ROOT_DIR}/${MAIN_FILE}" |
        head -n 1
)"
STABLE_TAG="$(
    sed -n 's/^Stable tag: //p' "${ROOT_DIR}/readme.txt" |
        head -n 1
)"

if [[ "${PLUGIN_VERSION}" != "${VERSION}" ||
      "${STABLE_TAG}" != "${VERSION}" ]]; then
    echo "Plugin/readme version does not match ${VERSION}." >&2
    exit 1
fi

if [[ "${ARGENT_VIDEO_SKIP_HLS_FETCH:-0}" != '1' ]]; then
    if ! bash "${ROOT_DIR}/build/fetch-hls-js.sh"; then
        if [[ "${ARGENT_VIDEO_ALLOW_MISSING_HLS_JS:-0}" != '1' ]]; then
            echo 'Could not vendor hls.js; refusing to build a release package.' >&2
            exit 1
        fi
        echo 'WARNING: building without local hls.js.' >&2
    fi
elif [[ ! -s "${ROOT_DIR}/assets/vendor/hls.min.js" ]]; then
    if [[ "${ARGENT_VIDEO_ALLOW_MISSING_HLS_JS:-0}" != '1' ]]; then
        echo 'hls.js fetch was skipped and no local asset exists.' >&2
        exit 1
    fi
fi

rm -rf "${DIST_DIR}"
mkdir -p "${DIST_DIR}" "${STAGE_DIR}"

install -m 0644 "${ROOT_DIR}/${MAIN_FILE}" "${STAGE_DIR}/${MAIN_FILE}"
install -m 0644 "${ROOT_DIR}/LICENSE" "${STAGE_DIR}/LICENSE"
install -m 0644 "${ROOT_DIR}/readme.txt" "${STAGE_DIR}/readme.txt"
install -m 0644 "${ROOT_DIR}/uninstall.php" "${STAGE_DIR}/uninstall.php"

rsync -a "${ROOT_DIR}/includes/" "${STAGE_DIR}/includes/"
rsync -a "${ROOT_DIR}/assets/" "${STAGE_DIR}/assets/"

# hls.VERSION and hls.SHA256 are build-time integrity evidence only. The
# WordPress.org runtime package ships the verified hls.js runtime and license,
# but not internal build metadata.
rm -f \
    "${STAGE_DIR}/assets/vendor/hls.VERSION" \
    "${STAGE_DIR}/assets/vendor/hls.SHA256"

find "${STAGE_DIR}" -type f -name '*.php' -print0 |
    sort -z |
    xargs -0 -n1 php -l >/dev/null

for forbidden in \
    AGENTS.md \
    AGENTS-PROFILE.md \
    ARCHITECTURE.md \
    TODO.md \
    README.md \
    CHANGELOG.md \
    build \
    tests \
    ops \
    .github
do
    if [[ -e "${STAGE_DIR}/${forbidden}" ]]; then
        echo "Release package contains repository-only item: ${forbidden}" >&2
        exit 1
    fi
done

ZIP_NAME="${SLUG}-${VERSION}.zip"
(
    cd "${STAGE_ROOT}"
    zip -q -r "${DIST_DIR}/${ZIP_NAME}" "${SLUG}"
)

TOP_LEVEL_COUNT="$(
    unzip -Z1 "${DIST_DIR}/${ZIP_NAME}" |
        awk -F/ 'NF {print $1}' |
        sort -u |
        wc -l
)"
TOP_LEVEL_NAME="$(
    unzip -Z1 "${DIST_DIR}/${ZIP_NAME}" |
        awk -F/ 'NF {print $1}' |
        sort -u
)"

if [[ "${TOP_LEVEL_COUNT}" -ne 1 ||
      "${TOP_LEVEL_NAME}" != "${SLUG}" ]]; then
    echo "Release ZIP must contain exactly one ${SLUG}/ root." >&2
    exit 1
fi

ZIP_MANIFEST="$(mktemp)"
unzip -Z1 "${DIST_DIR}/${ZIP_NAME}" > "${ZIP_MANIFEST}"

for required in \
    hls.min.js \
    hls.LICENSE
do
    if ! grep -qx "${SLUG}/assets/vendor/${required}" "${ZIP_MANIFEST}"; then
        if [[ "${ARGENT_VIDEO_ALLOW_MISSING_HLS_JS:-0}" != '1' ]]; then
            echo "Release ZIP is missing assets/vendor/${required}." >&2
            rm -f "${ZIP_MANIFEST}"
            exit 1
        fi
    fi
done

for forbidden_vendor_metadata in \
    hls.VERSION \
    hls.SHA256
do
    if grep -qx "${SLUG}/assets/vendor/${forbidden_vendor_metadata}" "${ZIP_MANIFEST}"; then
        echo "Release ZIP contains build-only assets/vendor/${forbidden_vendor_metadata}." >&2
        rm -f "${ZIP_MANIFEST}"
        exit 1
    fi
done

rm -f "${ZIP_MANIFEST}"

(
    cd "${DIST_DIR}"
    sha256sum "${ZIP_NAME}" > SHA256SUMS
)

printf 'Built %s\n' "${DIST_DIR}/${ZIP_NAME}"
printf 'Checksums: %s\n' "${DIST_DIR}/SHA256SUMS"

# EOF: build/build-plugin.sh
