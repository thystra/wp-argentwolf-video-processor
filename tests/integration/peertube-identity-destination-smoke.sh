#!/usr/bin/env bash
# Real-WordPress/browser matrix for the R39 identity/destination checkpoint.

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

export AWVP_ADMIN_SMOKE_CLASS='r39'
export AWVP_ADMIN_SMOKE_MARKER='PEERTUBE_IDENTITY_DESTINATION'
export AWVP_ADMIN_FIXTURE_RELATIVE='tests/fixtures/peertube-identity-destination-smoke'
export AWVP_ADMIN_MOCK_RELATIVE='tests/fixtures/peertube-password-grant-smoke'
export AWVP_ADMIN_BROWSER_SUPPORT_RELATIVE='tests/fixtures/peertube-admin-authorization-smoke'

exec bash "$SCRIPT_DIR/peertube-admin-authorization-smoke.sh"
