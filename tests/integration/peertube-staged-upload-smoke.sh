#!/usr/bin/env bash
# Real-WordPress/mock-PeerTube matrix for the R43 first executable resumable-upload checkpoint.

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

export AWVP_ADMIN_SMOKE_CLASS='r43'
export AWVP_ADMIN_SMOKE_MARKER='PEERTUBE_STAGED_UPLOAD'
export AWVP_ADMIN_FIXTURE_RELATIVE='tests/fixtures/peertube-staged-upload-smoke'
export AWVP_ADMIN_MOCK_RELATIVE='tests/fixtures/peertube-staged-upload-smoke'
export AWVP_ADMIN_BROWSER_SUPPORT_RELATIVE='tests/fixtures/peertube-admin-authorization-smoke'

exec bash "$SCRIPT_DIR/peertube-admin-authorization-smoke.sh"
