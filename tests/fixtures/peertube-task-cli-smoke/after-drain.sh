# Sourced by the exact-export administrator smoke after the active backend prerequisite.
AWVP_R45_FIXTURE_PATH="/var/www/html/wp-content/plugins/argentwolf-video-processor/$FIXTURE_RELATIVE"

wp_cli --context=cli eval-file "$AWVP_R45_FIXTURE_PATH/setup.php" --use-include
AWVP_R45_REQUESTS_BEFORE="$(grep -c '^' "$REQUEST_LOG")"

awvp_r45_run_drain() {
    local expected=$1
    local output=''
    if ! output="$(wp_cli --context=cli --no-color argent-video peertube-task-worker --drain 2>&1)"; then
        echo "$output" >&2
        fail "The bounded-drain PeerTube task worker command failed during $expected for $CURRENT_CASE."
    fi
    case "$expected" in
        wait)
            [[ "$output" == *'PeerTube task worker stopped at a durable boundary after 4 bounded step(s);'* ]] \
                || fail "The drain worker did not cross the expected init/upload/handoff/reconcile boundaries for $CURRENT_CASE."
            [[ "$output" == *'peertube_remote_reconcile): requeued;'* ]] \
                || fail "The drain worker did not stop at the durable reconciliation wait for $CURRENT_CASE."
            ;;
        ready)
            [[ "$output" == *'PeerTube task worker stopped at a durable boundary after 1 bounded step(s);'* ]] \
                || fail "The drain worker did not complete the post-wait readiness step for $CURRENT_CASE."
            [[ "$output" == *'peertube_remote_reconcile): complete;'* ]] \
                || fail "The drain worker did not report reconciliation completion for $CURRENT_CASE."
            ;;
        idle)
            [[ "$output" == *'PeerTube task worker idle;'* ]] \
                || fail "The drain worker was not idle during $expected for $CURRENT_CASE."
            ;;
        *)
            fail "Unknown R45 drain smoke step: $expected"
            ;;
    esac
}

# One fresh process must cross every immediately runnable boundary for the one
# logical operation, then stop before the future retry time instead of sleeping.
awvp_r45_run_drain wait
AWVP_R45_REQUESTS_AFTER_WAIT="$(grep -c '^' "$REQUEST_LOG")"
[[ "$AWVP_R45_REQUESTS_AFTER_WAIT" -eq $((AWVP_R45_REQUESTS_BEFORE + 3)) ]] \
    || fail "R45 drain request count differed before the durable wait for $CURRENT_CASE."

awvp_r45_run_drain idle
AWVP_R45_REQUESTS_AFTER_IDLE="$(grep -c '^' "$REQUEST_LOG")"
[[ "$AWVP_R45_REQUESTS_AFTER_IDLE" == "$AWVP_R45_REQUESTS_AFTER_WAIT" ]] \
    || fail "R45 drain immediate retry polled PeerTube during the durable wait for $CURRENT_CASE."

echo "PEERTUBE_TASK_CLI_DRAIN_DURABLE_WAIT_IDLE=$CURRENT_CASE:PASS"
sleep 31
awvp_r45_run_drain ready
awvp_r45_run_drain idle

echo "PEERTUBE_TASK_CLI_DRAIN_SEQUENCE=$CURRENT_CASE:PASS"
unset AWVP_R45_FIXTURE_PATH AWVP_R45_REQUESTS_BEFORE AWVP_R45_REQUESTS_AFTER_WAIT AWVP_R45_REQUESTS_AFTER_IDLE
unset -f awvp_r45_run_drain
