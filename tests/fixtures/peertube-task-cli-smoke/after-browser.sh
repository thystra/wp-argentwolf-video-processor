# Sourced by the exact-export administrator smoke after the active backend prerequisite.
AWVP_R45_FIXTURE_PATH="/var/www/html/wp-content/plugins/argentwolf-video-processor/$FIXTURE_RELATIVE"

wp_cli --context=cli eval-file "$AWVP_R45_FIXTURE_PATH/setup.php" --use-include
AWVP_R45_REQUESTS_BEFORE="$(grep -c '^' "$REQUEST_LOG")"

awvp_r45_run_worker() {
    local expected=$1
    local output=''
    if ! output="$(wp_cli --context=cli --no-color argent-video peertube-task-worker --once 2>&1)"; then
        echo "$output" >&2
        fail "The one-shot PeerTube task worker command failed during $expected for $CURRENT_CASE."
    fi
    case "$expected" in
        init|remote-commit|processing)
            [[ "$output" == *'PeerTube task worker advanced task '*': requeued;'* ]] \
                || fail "The one-shot worker did not report a requeued bounded step during $expected for $CURRENT_CASE."
            ;;
        handoff|ready)
            [[ "$output" == *'PeerTube task worker advanced task '*': complete;'* ]] \
                || fail "The one-shot worker did not report a completed bounded step during $expected for $CURRENT_CASE."
            ;;
        idle)
            [[ "$output" == *'PeerTube task worker idle;'* ]] \
                || fail "The one-shot worker was not idle during $expected for $CURRENT_CASE."
            ;;
        *)
            fail "Unknown R45 CLI smoke step: $expected"
            ;;
    esac
}

# Every call below is a fresh WP-CLI container/process. No command is allowed
# to loop internally or consume a second task advancement.
awvp_r45_run_worker init
awvp_r45_run_worker handoff
awvp_r45_run_worker remote-commit
awvp_r45_run_worker processing

AWVP_R45_REQUESTS_AFTER_PROCESSING="$(grep -c '^' "$REQUEST_LOG")"
[[ "$AWVP_R45_REQUESTS_AFTER_PROCESSING" -eq $((AWVP_R45_REQUESTS_BEFORE + 3)) ]] \
    || fail "R45 CLI bounded upload/processing requests differed before the durable wait for $CURRENT_CASE."

# The processing observation journals a 30-second retry boundary. An immediate
# fresh command must be idle and must not poll PeerTube again.
awvp_r45_run_worker idle
AWVP_R45_REQUESTS_AFTER_IDLE="$(grep -c '^' "$REQUEST_LOG")"
[[ "$AWVP_R45_REQUESTS_AFTER_IDLE" == "$AWVP_R45_REQUESTS_AFTER_PROCESSING" ]] \
    || fail "R45 CLI immediate retry performed remote polling during the durable wait for $CURRENT_CASE."

echo "PEERTUBE_TASK_CLI_DURABLE_WAIT_IDLE=$CURRENT_CASE:PASS"
sleep 31
awvp_r45_run_worker ready
awvp_r45_run_worker idle

echo "PEERTUBE_TASK_CLI_FRESH_PROCESS_SEQUENCE=$CURRENT_CASE:PASS"
unset AWVP_R45_FIXTURE_PATH AWVP_R45_REQUESTS_BEFORE AWVP_R45_REQUESTS_AFTER_PROCESSING AWVP_R45_REQUESTS_AFTER_IDLE
unset -f awvp_r45_run_worker
