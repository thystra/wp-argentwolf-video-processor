#!/usr/bin/env bash
# File: tests/vendor-fetch.sh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP_ROOT="$(mktemp -d)"
cleanup() { rm -rf "${TMP_ROOT}"; }
trap cleanup EXIT

make_asset() {
  local path="$1"
  cat > "${path}" <<'JS'
'use strict';module.exports=function Hls(){};module.exports.version='1.6.16';
JS
  python3 - "${path}" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
with path.open('a', encoding='utf-8') as handle:
    handle.write('/*' + ('x' * 110000) + '*/\n')
PY
}

VALID_PACKAGE_DIR="${TMP_ROOT}/valid/package"
VALID_TARGET_DIR="${TMP_ROOT}/valid-target"
mkdir -p "${VALID_PACKAGE_DIR}/dist" "${VALID_TARGET_DIR}"
cat > "${VALID_PACKAGE_DIR}/package.json" <<'JSON'
{"name":"hls.js","version":"1.6.16","license":"Apache-2.0"}
JSON
cat > "${VALID_PACKAGE_DIR}/LICENSE" <<'LICENSE'
HLS.js package notice used by the regression test.

Apache License, Version 2.0
https://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software distributed
under the License is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR
CONDITIONS OF ANY KIND. See the License for the specific language governing
permissions and limitations under the License.
LICENSE
make_asset "${VALID_PACKAGE_DIR}/dist/hls.min.js"
tar -czf "${TMP_ROOT}/hls.js-1.6.16-valid.tgz" -C "${TMP_ROOT}/valid" package

VALID_OUTPUT="$(
  ARGENT_VIDEO_HLS_PACKAGE_FILE="${TMP_ROOT}/hls.js-1.6.16-valid.tgz" \
  ARGENT_VIDEO_HLS_TARGET_DIR="${VALID_TARGET_DIR}" \
    bash "${ROOT_DIR}/build/fetch-hls-js.sh"
)"

printf '%s\n' "${VALID_OUTPUT}"
if [[ "${VALID_OUTPUT}" != *'from local package override:'* ]]; then
  echo 'Vendor fetch did not identify the local package override.' >&2
  exit 1
fi
if [[ "${VALID_OUTPUT}" == *'from the npm registry:'* ]]; then
  echo 'Vendor fetch incorrectly reported an npm registry source.' >&2
  exit 1
fi

test -s "${VALID_TARGET_DIR}/hls.min.js"
test -s "${VALID_TARGET_DIR}/hls.LICENSE"
test "$(cat "${VALID_TARGET_DIR}/hls.VERSION")" = '1.6.16'
cmp -s "${VALID_PACKAGE_DIR}/LICENSE" "${VALID_TARGET_DIR}/hls.LICENSE"
(cd "${VALID_TARGET_DIR}" && sha256sum --check hls.SHA256)
node - "${VALID_TARGET_DIR}/hls.min.js" <<'NODE'
const Hls = require(process.argv[2]);
if (Hls.version !== '1.6.16') {
  process.exit(1);
}
NODE

INVALID_PACKAGE_DIR="${TMP_ROOT}/invalid/package"
INVALID_TARGET_DIR="${TMP_ROOT}/invalid-target"
mkdir -p "${INVALID_PACKAGE_DIR}/dist" "${INVALID_TARGET_DIR}"
cat > "${INVALID_PACKAGE_DIR}/package.json" <<'JSON'
{"name":"hls.js","version":"1.6.16","license":"MIT"}
JSON
cp "${VALID_PACKAGE_DIR}/LICENSE" "${INVALID_PACKAGE_DIR}/LICENSE"
make_asset "${INVALID_PACKAGE_DIR}/dist/hls.min.js"
tar -czf "${TMP_ROOT}/hls.js-1.6.16-invalid.tgz" -C "${TMP_ROOT}/invalid" package

if ARGENT_VIDEO_HLS_PACKAGE_FILE="${TMP_ROOT}/hls.js-1.6.16-invalid.tgz" \
   ARGENT_VIDEO_HLS_TARGET_DIR="${INVALID_TARGET_DIR}" \
     bash "${ROOT_DIR}/build/fetch-hls-js.sh" >/dev/null 2>&1; then
  echo 'Vendor fetch accepted a package with a non-Apache SPDX license.' >&2
  exit 1
fi

printf 'Vendor fetch regression test passed.\n'

# EOF: tests/vendor-fetch.sh
