#!/usr/bin/env bash
# Real-WordPress/browser matrix for the R41 PeerTube token lifecycle checkpoint.

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

export AWVP_ADMIN_SMOKE_CLASS='r41'
export AWVP_ADMIN_SMOKE_MARKER='PEERTUBE_TOKEN_LIFECYCLE'
export AWVP_ADMIN_FIXTURE_RELATIVE='tests/fixtures/peertube-token-lifecycle-smoke'
export AWVP_ADMIN_MOCK_RELATIVE='tests/fixtures/peertube-token-lifecycle-smoke'
export AWVP_ADMIN_BROWSER_SUPPORT_RELATIVE='tests/fixtures/peertube-admin-authorization-smoke'

exec bash "$SCRIPT_DIR/peertube-admin-authorization-smoke.sh"
