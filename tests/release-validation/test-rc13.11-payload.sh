#!/usr/bin/env bash
set -Eeuo pipefail

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
PAYLOAD="$ROOT/tests/release-validation/payloads/2.0.0-rc13.11"

[[ -f "$PAYLOAD/payload.sh" ]] || fail 'RC13.11 payload definition is missing.'
bash -n "$PAYLOAD/payload.sh"

# shellcheck disable=SC1090
AWVP_RC_CANDIDATE_SHA256="$(printf '0%.0s' {1..64})" source "$PAYLOAD/payload.sh"

[[ "$PAYLOAD_ID" == '2.0.0-rc13.11' ]] || fail 'RC13.11 payload ID drifted.'
[[ "$CANDIDATE_VERSION" == '2.0.0-rc13.11' ]] || fail 'RC13.11 candidate version drifted.'
[[ "$CANDIDATE_ARTIFACT" == 'argentwolf-video-processor-2.0.0-rc13.11.zip' ]] || fail 'RC13.11 candidate artifact drifted.'
[[ "$BASE_VERSION" == '1.0.0' ]] || fail 'RC13.11 public upgrade base drifted.'
[[ "$CANDIDATE_STABLE_TAG" == '1.0.0' ]] || fail 'RC13.11 prerelease stable tag drifted.'

phase_present() {
    local needle="$1"
    shift
    local item
    for item in "$@"; do
        [[ "$item" == "$needle" ]] && return 0
    done
    return 1
}

phase_present 'assert-livefix.php' "${CLEAN_PHASES[@]}" \
    || fail 'RC13.11 clean matrix omits managed-PeerTube livefix qualification.'
phase_present 'assert-livefix.php' "${UPGRADE_POST_PHASES[@]}" \
    || fail 'RC13.11 upgrade matrix omits managed-PeerTube livefix qualification.'
! phase_present 'assert-external-embedding.php' "${CLEAN_PHASES[@]}" \
    || fail 'RC13.11 clean matrix retained deferred generic external-video qualification.'
! phase_present 'assert-external-embedding.php' "${UPGRADE_POST_PHASES[@]}" \
    || fail 'RC13.11 upgrade matrix retained deferred generic external-video qualification.'
[[ ! -e "$PAYLOAD/assert-external-embedding.php" ]] \
    || fail 'RC13.11 payload still ships the deferred generic external-video assertion.'

for phase in \
    "${UPGRADE_PRE_PHASES[@]}" \
    "${UPGRADE_POST_PHASES[@]}" \
    "${CLEAN_PHASES[@]}" \
    "${UNINSTALL_PHASES[@]}"; do
    [[ "$phase" != */* ]] || fail "Payload phase must be a basename: $phase"
    [[ -f "$PAYLOAD/$phase" ]] || fail "RC13.11 payload phase missing: $phase"
done

echo 'AWVP RC13.11 release-validation payload contract test passed.'
