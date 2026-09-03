#!/usr/bin/env bash
# Real-WordPress/mock-PeerTube matrix for R44 remote-asset commit/read reconciliation.
set -Eeuo pipefail
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
export AWVP_ADMIN_SMOKE_CLASS='r44'
export AWVP_ADMIN_SMOKE_MARKER='PEERTUBE_REMOTE_ASSET_RECONCILIATION'
export AWVP_ADMIN_FIXTURE_RELATIVE='tests/fixtures/peertube-remote-asset-reconciliation-smoke'
export AWVP_ADMIN_MOCK_RELATIVE='tests/fixtures/peertube-remote-asset-reconciliation-smoke'
export AWVP_ADMIN_BROWSER_SUPPORT_RELATIVE='tests/fixtures/peertube-admin-authorization-smoke'
exec bash "$SCRIPT_DIR/peertube-admin-authorization-smoke.sh"
