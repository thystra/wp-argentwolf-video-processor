#!/usr/bin/env bash
set -Eeuo pipefail

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
require_text() {
    local file="$1"
    local text="$2"
    local message="$3"
    grep -Fq -- "$text" "$file" || fail "$message"
}

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
RUNNER="$ROOT/tests/release-validation/run.sh"
DOC="$ROOT/AGENTS-TESTING.md"

bash -n "$RUNNER"

require_text "$RUNNER" 'failure_collection=INDEPENDENT_CASES_AND_ASSERTION_PHASES' \
    'Runner does not advertise aggregate independent failure collection.'
require_text "$RUNNER" 'run_case_assertion()' \
    'Runner lacks assertion-phase collection wrapper.'
require_text "$RUNNER" 'run_case_collect()' \
    'Runner lacks independent disposable-case collection wrapper.'
require_text "$RUNNER" 'CASE_FAILURE_PHASES=' \
    'Runner does not report per-case failed assertion phases.'
require_text "$RUNNER" 'Failed cases=${#CASE_FAILURES[@]}' \
    'Runner does not report aggregate failed-case count.'
require_text "$RUNNER" "printf 'Failed case=%s\\n' \"\${CASE_FAILURES[@]}\"" \
    'Runner does not enumerate every failed case in the final summary.'
require_text "$RUNNER" 'run_case_collect "$pc_label" "plugin-check"' \
    'Plugin Check case is still invoked through fail-fast case execution.'
require_text "$RUNNER" 'run_case_collect "$label" "upgrade"' \
    'Upgrade matrix cases are not collected independently.'
require_text "$RUNNER" 'run_case_collect "$label" "clean"' \
    'Clean matrix cases are not collected independently.'
require_text "$DOC" 'keep running later independent Plugin Check modes and disposable matrix cases' \
    'Testing contract does not document exhaustive independent failure collection.'
require_text "$DOC" 'exit nonzero after the aggregate summary if any case or assertion failed' \
    'Testing contract does not preserve fail-closed aggregate result semantics.'

echo 'AWVP release-validation aggregate failure-collection policy test passed.'
