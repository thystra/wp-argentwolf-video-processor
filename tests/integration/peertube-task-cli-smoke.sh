#!/usr/bin/env bash
# Real-WordPress/mock-PeerTube matrix for the R45 one-shot WP-CLI task worker.
set -Eeuo pipefail
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
export AWVP_ADMIN_SMOKE_CLASS='r45cli'
export AWVP_ADMIN_SMOKE_MARKER='PEERTUBE_TASK_CLI'
export AWVP_ADMIN_FIXTURE_RELATIVE='tests/fixtures/peertube-task-cli-smoke'
export AWVP_ADMIN_MOCK_RELATIVE='tests/fixtures/peertube-remote-asset-reconciliation-smoke'
export AWVP_ADMIN_BROWSER_SUPPORT_RELATIVE='tests/fixtures/peertube-admin-authorization-smoke'
export AWVP_ADMIN_AFTER_BROWSER_RELATIVE='tests/fixtures/peertube-task-cli-smoke/after-browser.sh'
if [[ -n "${AWVP_R45_REPORT_DIR:-}" ]]; then
    export AWVP_ADMIN_REPORT_DIR="$AWVP_R45_REPORT_DIR"
fi
exec bash "$SCRIPT_DIR/peertube-admin-authorization-smoke.sh"
