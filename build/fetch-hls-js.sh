#!/usr/bin/env bash
# File: build/fetch-hls-js.sh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Pin an exact stable release. Do not use npm major ranges or canary builds.
# npm verifies registry package integrity before extraction. This script then
# verifies package identity, SPDX license identity, license text, JavaScript
# syntax, and the runtime Hls.version value.
HLS_VERSION='1.6.16'
HLS_LICENSE='Apache-2.0'
TARGET_DIR="${ARGENT_VIDEO_HLS_TARGET_DIR:-${ROOT_DIR}/assets/vendor}"
TARGET_FILE="${TARGET_DIR}/hls.min.js"
TARGET_LICENSE="${TARGET_DIR}/hls.LICENSE"
TARGET_VERSION="${TARGET_DIR}/hls.VERSION"
TARGET_HASH="${TARGET_DIR}/hls.SHA256"
PACKAGE_OVERRIDE="${ARGENT_VIDEO_HLS_PACKAGE_FILE:-}"
NPM_REGISTRY='https://registry.npmjs.org/'

require_command() {
  if ! command -v "$1" >/dev/null 2>&1; then
    printf 'Required build command is unavailable: %s\n' "$1" >&2
    exit 1
  fi
}

validate_apache_license() {
  local license="$1"

  if [[ ! -s "${license}" ]]; then
    echo 'Vendored hls.js license is missing.' >&2
    return 1
  fi

  node - "${license}" <<'NODE'
'use strict';
const fs = require('fs');
const licensePath = process.argv[2];
let text;
try {
  text = fs.readFileSync(licensePath, 'utf8').replace(/\s+/g, ' ');
} catch (error) {
  console.error(`Unable to read vendored hls.js license: ${error.message}`);
  process.exit(1);
}
const required = [
  /Apache License(?:,)? Version 2\.0/i,
  /apache\.org\/licenses\/LICENSE-2\.0/i,
  /AS IS/i,
  /limitations under the License/i,
];
for (const pattern of required) {
  if (!pattern.test(text)) {
    console.error(`Vendored hls.js license does not satisfy Apache-2.0 policy: missing ${pattern}`);
    process.exit(1);
  }
}
NODE
}

validate_runtime_asset() {
  local asset="$1"
  local license="$2"

  if [[ ! -s "${asset}" ]] || [[ "$(wc -c < "${asset}")" -le 100000 ]]; then
    echo 'Vendored hls.js asset is missing or unexpectedly small.' >&2
    return 1
  fi

  validate_apache_license "${license}"
  node --check "${asset}" >/dev/null
  node - "${asset}" "${HLS_VERSION}" <<'NODE'
'use strict';
const assetPath = process.argv[2];
const expected = process.argv[3];
let exported;
try {
  exported = require(assetPath);
} catch (error) {
  console.error(`Unable to load vendored hls.js asset: ${error.message}`);
  process.exit(1);
}
const Hls = exported && exported.default ? exported.default : exported;
const actual = Hls && Hls.version;
if (actual !== expected) {
  console.error(`Vendored hls.js runtime version mismatch: expected ${expected}, found ${String(actual)}`);
  process.exit(1);
}
NODE
}

require_command node
require_command tar
require_command sha256sum
mkdir -p "${TARGET_DIR}"

if validate_runtime_asset "${TARGET_FILE}" "${TARGET_LICENSE}" 2>/dev/null; then
  printf '%s\n' "${HLS_VERSION}" > "${TARGET_VERSION}"
  (cd "${TARGET_DIR}" && sha256sum hls.min.js > "$(basename "${TARGET_HASH}")")
  printf 'Using verified existing hls.js %s asset: %s\n' "${HLS_VERSION}" "${TARGET_FILE}"
  exit 0
fi

TMP_ROOT="$(mktemp -d)"
cleanup() { rm -rf "${TMP_ROOT}"; }
trap cleanup EXIT

if [[ -n "${PACKAGE_OVERRIDE}" ]]; then
  if [[ ! -f "${PACKAGE_OVERRIDE}" ]]; then
    printf 'HLS package override does not exist: %s\n' "${PACKAGE_OVERRIDE}" >&2
    exit 1
  fi
  PACKAGE_FILE="${TMP_ROOT}/hls.js-${HLS_VERSION}.tgz"
  cp -- "${PACKAGE_OVERRIDE}" "${PACKAGE_FILE}"
  PACKAGE_SOURCE='local package override'
else
  require_command npm
  PACK_OUTPUT="$(
    npm pack "hls.js@${HLS_VERSION}" \
      --silent \
      --ignore-scripts \
      --registry="${NPM_REGISTRY}" \
      --pack-destination "${TMP_ROOT}"
  )"
  PACK_NAME="$(printf '%s\n' "${PACK_OUTPUT}" | tail -n1 | tr -d '\r')"
  if [[ -z "${PACK_NAME}" ]]; then
    echo 'npm pack did not report a package filename.' >&2
    exit 1
  fi
  if [[ "${PACK_NAME}" = /* ]]; then
    PACKAGE_FILE="${PACK_NAME}"
  else
    PACKAGE_FILE="${TMP_ROOT}/${PACK_NAME}"
  fi
  PACKAGE_SOURCE='npm registry'
fi

if [[ ! -s "${PACKAGE_FILE}" ]]; then
  echo 'Selected hls.js npm package is missing or empty.' >&2
  exit 1
fi

EXTRACT_DIR="${TMP_ROOT}/extract"
mkdir -p "${EXTRACT_DIR}"
tar -xzf "${PACKAGE_FILE}" -C "${EXTRACT_DIR}"
PACKAGE_DIR="${EXTRACT_DIR}/package"
PACKAGE_JSON="${PACKAGE_DIR}/package.json"
PACKAGE_ASSET="${PACKAGE_DIR}/dist/hls.min.js"
PACKAGE_LICENSE="${PACKAGE_DIR}/LICENSE"

node - "${PACKAGE_JSON}" "${HLS_VERSION}" "${HLS_LICENSE}" <<'NODE'
'use strict';
const fs = require('fs');
const packagePath = process.argv[2];
const expectedVersion = process.argv[3];
const expectedLicense = process.argv[4];
let metadata;
try {
  metadata = JSON.parse(fs.readFileSync(packagePath, 'utf8'));
} catch (error) {
  console.error(`Unable to read hls.js package metadata: ${error.message}`);
  process.exit(1);
}
if (metadata.name !== 'hls.js' || metadata.version !== expectedVersion) {
  console.error(`Unexpected npm package identity: ${String(metadata.name)}@${String(metadata.version)}`);
  process.exit(1);
}
if (metadata.license !== expectedLicense) {
  console.error(`Unexpected hls.js package license: expected ${expectedLicense}, found ${String(metadata.license)}`);
  process.exit(1);
}
NODE

TMP_ASSET="${TMP_ROOT}/hls.min.js"
TMP_LICENSE="${TMP_ROOT}/hls.LICENSE"
cp -- "${PACKAGE_ASSET}" "${TMP_ASSET}"
cp -- "${PACKAGE_LICENSE}" "${TMP_LICENSE}"
validate_runtime_asset "${TMP_ASSET}" "${TMP_LICENSE}"

# Install the exact license shipped in the verified npm package. License text
# may legitimately differ in formatting or notices from repository snapshots;
# package identity and substantive Apache-2.0 validation are authoritative.
install -m 0644 "${TMP_ASSET}" "${TARGET_FILE}"
install -m 0644 "${TMP_LICENSE}" "${TARGET_LICENSE}"
printf '%s\n' "${HLS_VERSION}" > "${TARGET_VERSION}"
(cd "${TARGET_DIR}" && sha256sum hls.min.js > "$(basename "${TARGET_HASH}")")

validate_runtime_asset "${TARGET_FILE}" "${TARGET_LICENSE}"
printf 'Installed and verified hls.js %s (%s) from %s: %s\n' \
  "${HLS_VERSION}" "${HLS_LICENSE}" "${PACKAGE_SOURCE}" "${TARGET_FILE}"

# EOF: build/fetch-hls-js.sh
