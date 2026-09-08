#!/usr/bin/env bash
set -Eeuo pipefail

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP="$(mktemp -d /tmp/awvp-build-bundle-test.XXXXXX)"
trap 'rm -rf "$TMP"' EXIT

FIXTURE_ROOT="$TMP/project"
HARNESS="$FIXTURE_ROOT/tests/release-validation"
PAYLOAD="$HARNESS/payloads/fixture"
OUT="$TMP/out"
mkdir -p "$PAYLOAD" "$HARNESS/php" "$OUT"

cp "$PROJECT_ROOT/tests/release-validation/build-bundle.sh" "$HARNESS/build-bundle.sh"
printf '# fixture\n' > "$FIXTURE_ROOT/AGENTS-TESTING.md"
printf '# fixture\n' > "$HARNESS/README.md"
printf '#!/usr/bin/env bash\nexit 0\n' > "$HARNESS/run.sh"
chmod +x "$HARNESS/run.sh"
printf '<?php // fixture\n' > "$HARNESS/php/common.php"

printf 'candidate bytes\n' > "$TMP/candidate.zip"
printf 'base bytes\n' > "$TMP/base.zip"
CANDIDATE_SHA="$(sha256sum "$TMP/candidate.zip" | awk '{print $1}')"
BASE_SHA="$(sha256sum "$TMP/base.zip" | awk '{print $1}')"

cat > "$PAYLOAD/payload.sh" <<EOF_PAYLOAD
PAYLOAD_ID="fixture"
CANDIDATE_ARTIFACT="candidate.zip"
CANDIDATE_SHA256="\${AWVP_RC_CANDIDATE_SHA256:-}"
BASE_ARTIFACT="base.zip"
BASE_SHA256="$BASE_SHA"
UPGRADE_PRE_PHASES=(seed.php)
UPGRADE_POST_PHASES=(upgrade.php)
CLEAN_PHASES=(clean.php)
EOF_PAYLOAD
printf '<?php // seed\n' > "$PAYLOAD/seed.php"
printf '<?php // upgrade\n' > "$PAYLOAD/upgrade.php"
printf '<?php // clean\n' > "$PAYLOAD/clean.php"

AWVP_RC_CANDIDATE_SHA256="$CANDIDATE_SHA" \
    bash "$HARNESS/build-bundle.sh" fixture "$TMP/candidate.zip" "$TMP/base.zip" "$OUT" >/dev/null

BUNDLE="$OUT/awvp-fixture-release-validation.zip"
[[ -f "$BUNDLE" ]] || fail "Bundle was not created"

EXTRACT="$TMP/extract"
mkdir -p "$EXTRACT"
unzip -q "$BUNDLE" -d "$EXTRACT"
ROOT="$EXTRACT/awvp-fixture-release-validation"
BUNDLED_PAYLOAD="$ROOT/tests/release-validation/payloads/fixture/payload.sh"

for phase in seed.php upgrade.php clean.php; do
    [[ -f "$ROOT/tests/release-validation/payloads/fixture/$phase" ]] || \
        fail "Bundled phase missing: $phase"
done

unset AWVP_RC_CANDIDATE_SHA256
# shellcheck disable=SC1090
source "$BUNDLED_PAYLOAD"
[[ "$CANDIDATE_SHA256" == "$CANDIDATE_SHA" ]] || fail "Bundled candidate hash was not pinned"
[[ "$CANDIDATE_ARTIFACT" == 'candidate.zip' ]] || fail "Bundled candidate artifact was not pinned"

(
    cd "$ROOT"
    sha256sum -c SHA256SUMS >/dev/null
)

echo 'AWVP release-validation bundle self-containment test passed.'
