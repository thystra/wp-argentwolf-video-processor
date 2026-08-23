#!/usr/bin/env bash
set -Eeuo pipefail

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
section() { printf '\n===== %s =====\n' "$1"; }

HARNESS_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd -- "$HARNESS_ROOT/../.." && pwd)"

PAYLOAD_ID="${1:-}"
CANDIDATE_SOURCE="${2:-}"
BASE_SOURCE="${3:-}"
OUTPUT_DIR="${4:-$PROJECT_ROOT/dist}"

[[ -n "$PAYLOAD_ID" && -n "$CANDIDATE_SOURCE" && -n "$BASE_SOURCE" ]] || {
    echo "Usage: $0 <payload-id> <candidate.zip> <base.zip> [output-dir]" >&2
    exit 2
}

PAYLOAD_DIR="$HARNESS_ROOT/payloads/$PAYLOAD_ID"
[[ -f "$PAYLOAD_DIR/payload.sh" ]] || fail "Unknown payload: $PAYLOAD_ID"

bash -n "$PAYLOAD_DIR/payload.sh"
# shellcheck disable=SC1090
source "$PAYLOAD_DIR/payload.sh"

[[ -f "$CANDIDATE_SOURCE" ]] || fail "Candidate source missing: $CANDIDATE_SOURCE"
[[ -f "$BASE_SOURCE" ]] || fail "Base source missing: $BASE_SOURCE"

candidate_sha="$(sha256sum "$CANDIDATE_SOURCE" | awk '{print $1}')"
base_sha="$(sha256sum "$BASE_SOURCE" | awk '{print $1}')"
[[ "$candidate_sha" == "$CANDIDATE_SHA256" ]] || fail "Candidate source hash mismatch"
[[ "$base_sha" == "$BASE_SHA256" ]] || fail "Base source hash mismatch"

mkdir -p "$OUTPUT_DIR"
TMP="$(mktemp -d /tmp/awvp-release-bundle.XXXXXX)"
trap 'rm -rf "$TMP"' EXIT

BUNDLE_NAME="awvp-${PAYLOAD_ID}-release-validation"
BUNDLE="$TMP/$BUNDLE_NAME"
mkdir -p "$BUNDLE/artifacts" "$BUNDLE/tests/release-validation/php" \
    "$BUNDLE/tests/release-validation/payloads/$PAYLOAD_ID"

install -m 0644 "$PROJECT_ROOT/AGENTS-TESTING.md" "$BUNDLE/AGENTS-TESTING.md"
install -m 0644 "$HARNESS_ROOT/README.md" "$BUNDLE/tests/release-validation/README.md"
install -m 0755 "$HARNESS_ROOT/run.sh" "$BUNDLE/tests/release-validation/run.sh"

for file in "$HARNESS_ROOT"/php/*.php; do
    [[ -f "$file" ]] || continue
    install -m 0644 "$file" "$BUNDLE/tests/release-validation/php/$(basename "$file")"
done

for file in "$PAYLOAD_DIR"/*; do
    [[ -f "$file" ]] || continue
    mode=0644
    [[ -x "$file" ]] && mode=0755
    install -m "$mode" "$file" \
        "$BUNDLE/tests/release-validation/payloads/$PAYLOAD_ID/$(basename "$file")"
done

install -m 0644 "$CANDIDATE_SOURCE" "$BUNDLE/artifacts/$CANDIDATE_ARTIFACT"
install -m 0644 "$BASE_SOURCE" "$BUNDLE/artifacts/$BASE_ARTIFACT"

cat > "$BUNDLE/README.md" <<EOF
# AWVP release-validation bundle: $PAYLOAD_ID

This bundle contains the reusable AWVP release-validation engine, the complete
$PAYLOAD_ID payload, and the exact candidate/base plugin artifacts declared by
that payload.

Verify and run on the disposable Docker validation host:

\`\`\`bash
sha256sum -c SHA256SUMS
bash tests/release-validation/run.sh $PAYLOAD_ID
\`\`\`

Candidate SHA-256: \`$CANDIDATE_SHA256\`
Upgrade-base SHA-256: \`$BASE_SHA256\`
EOF

# Optional: preseed the exact pinned Plugin Check package.
if [[ -n "${PLUGIN_CHECK_SOURCE:-}" ]]; then
    [[ -f "$PLUGIN_CHECK_SOURCE" ]] || fail "PLUGIN_CHECK_SOURCE missing: $PLUGIN_CHECK_SOURCE"
    pc_sha="$(sha256sum "$PLUGIN_CHECK_SOURCE" | awk '{print $1}')"
    [[ "$pc_sha" == "$PLUGIN_CHECK_SHA256" ]] || fail "PLUGIN_CHECK_SOURCE hash mismatch"
    mkdir -p "$BUNDLE/.cache"
    install -m 0644 "$PLUGIN_CHECK_SOURCE" \
        "$BUNDLE/.cache/plugin-check.${PLUGIN_CHECK_VERSION}.zip"
fi

(
    cd "$BUNDLE"
    find . -type f ! -name SHA256SUMS -print0 |
        sort -z |
        xargs -0 sha256sum > SHA256SUMS
)

OUT="$OUTPUT_DIR/${BUNDLE_NAME}.zip"
rm -f "$OUT"
(
    cd "$TMP"
    zip -X -qr "$OUT" "$BUNDLE_NAME"
)

section "RESULT"
echo "RESULT=AWVP_RELEASE_VALIDATION_BUNDLE_BUILT"
echo "payload=$PAYLOAD_ID"
echo "candidate_sha256=$candidate_sha"
echo "base_sha256=$base_sha"
echo "bundle=$OUT"
echo "bundle_sha256=$(sha256sum "$OUT" | awk '{print $1}')"
