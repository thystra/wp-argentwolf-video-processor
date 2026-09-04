# Sourced by the exact-export administrator smoke after the active backend prerequisite.
AWVP_R45_INDETERMINATE_SETUP="/var/www/html/wp-content/plugins/argentwolf-video-processor/tests/fixtures/peertube-task-cli-smoke/setup.php"

wp_cli --context=cli eval-file "$AWVP_R45_INDETERMINATE_SETUP" --use-include
AWVP_R45_INDETERMINATE_REQUESTS_BEFORE="$(grep -c '^' "$REQUEST_LOG")"

awvp_r45_indeterminate_worker() {
    local expected=$1
    local output=''
    if ! output="$(wp_cli --context=cli --no-color argent-video peertube-task-worker --once 2>&1)"; then
        echo "$output" >&2
        fail "The one-shot PeerTube task worker command failed during $expected for $CURRENT_CASE."
    fi
    case "$expected" in
        init)
            [[ "$output" == *'PeerTube task worker advanced task '*': requeued;'* ]] \
                || fail "The one-shot worker did not report the bounded session-init step for $CURRENT_CASE."
            ;;
        indeterminate)
            [[ "$output" == *'PeerTube task worker advanced task '*': failed;'* ]] \
                || fail "The uncertain byte-bearing PUT did not stop at a failed intervention boundary for $CURRENT_CASE."
            ;;
        idle)
            [[ "$output" == *'PeerTube task worker idle;'* ]] \
                || fail "The post-indeterminate worker was not idle for $CURRENT_CASE."
            ;;
        *)
            fail "Unknown R45 indeterminate CLI smoke step: $expected"
            ;;
    esac
}

# Establish the resumable session in one fresh WP-CLI process.
awvp_r45_indeterminate_worker init

# Arm the isolated mock to durably record the consequential byte-bearing PUT
# and then terminate its own HTTP process before a response is received.
: > "$CASE_STATE_DIRECTORY/drop-upload-chunk"
awvp_r45_indeterminate_worker indeterminate

# The mock must actually be gone. Any later automatic offset probe, PUT replay,
# or replacement initialization would therefore make the fresh command fail.
AWVP_R45_MOCK_STOPPED=0
for _ in $(seq 1 30); do
    if [[ 'false' == "$(docker container inspect --format '{{.State.Running}}' "$MOCK_NAME" 2>/dev/null || echo false)" ]]; then
        AWVP_R45_MOCK_STOPPED=1
        break
    fi
    sleep 1
done
[[ "$AWVP_R45_MOCK_STOPPED" == '1' ]] \
    || fail "The isolated PeerTube fixture did not terminate after the uncertain PUT for $CURRENT_CASE."
echo "PEERTUBE_TASK_CLI_INDETERMINATE_TRANSPORT_DROP=$CURRENT_CASE:PASS"

AWVP_R45_INDETERMINATE_REQUESTS_AFTER_DROP="$(grep -c '^' "$REQUEST_LOG")"
[[ "$AWVP_R45_INDETERMINATE_REQUESTS_AFTER_DROP" -eq $((AWVP_R45_INDETERMINATE_REQUESTS_BEFORE + 2)) ]] \
    || fail "The indeterminate CLI request count differed after init + one byte-bearing PUT for $CURRENT_CASE."

# A completely fresh WP-CLI process must find no eligible PeerTube task. Since
# the mock is dead, this also proves no automatic zero-byte reconciliation,
# chunk replay, or replacement upload initialization was attempted.
awvp_r45_indeterminate_worker idle
AWVP_R45_INDETERMINATE_REQUESTS_AFTER_IDLE="$(grep -c '^' "$REQUEST_LOG")"
[[ "$AWVP_R45_INDETERMINATE_REQUESTS_AFTER_IDLE" == "$AWVP_R45_INDETERMINATE_REQUESTS_AFTER_DROP" ]] \
    || fail "A post-indeterminate worker changed the remote request transcript for $CURRENT_CASE."

AWVP_R45_INDETERMINATE_INIT_COUNT="$(grep -c '^POST /api/v1/videos/upload-resumable ' "$REQUEST_LOG" || true)"
AWVP_R45_INDETERMINATE_BYTE_PUT_COUNT="$(grep -c '^PUT /api/v1/videos/upload-resumable .* range=bytes=[0-9]' "$REQUEST_LOG" || true)"
AWVP_R45_INDETERMINATE_PROBE_COUNT="$(grep -c '^PUT /api/v1/videos/upload-resumable .* range=bytes=\*/' "$REQUEST_LOG" || true)"
AWVP_R45_INDETERMINATE_REMOTE_GET_COUNT="$(grep -c '^GET /api/v1/videos/[0-9a-f-]\+ ' "$REQUEST_LOG" || true)"
[[ "$AWVP_R45_INDETERMINATE_INIT_COUNT" == '1' \
    && "$AWVP_R45_INDETERMINATE_BYTE_PUT_COUNT" == '1' \
    && "$AWVP_R45_INDETERMINATE_PROBE_COUNT" == '0' \
    && "$AWVP_R45_INDETERMINATE_REMOTE_GET_COUNT" == '0' ]] \
    || fail "The post-indeterminate remote request counts differed for $CURRENT_CASE."
echo "PEERTUBE_TASK_CLI_INDETERMINATE_INIT_POST_COUNT=$CURRENT_CASE:$AWVP_R45_INDETERMINATE_INIT_COUNT"
echo "PEERTUBE_TASK_CLI_INDETERMINATE_BYTE_PUT_COUNT=$CURRENT_CASE:$AWVP_R45_INDETERMINATE_BYTE_PUT_COUNT"
echo "PEERTUBE_TASK_CLI_INDETERMINATE_ZERO_BYTE_PROBE_COUNT=$CURRENT_CASE:$AWVP_R45_INDETERMINATE_PROBE_COUNT"
echo "PEERTUBE_TASK_CLI_INDETERMINATE_REMOTE_GET_COUNT=$CURRENT_CASE:$AWVP_R45_INDETERMINATE_REMOTE_GET_COUNT"
echo "PEERTUBE_TASK_CLI_INDETERMINATE_NO_REPLAY=$CURRENT_CASE:PASS"
unset AWVP_R45_INDETERMINATE_SETUP AWVP_R45_INDETERMINATE_REQUESTS_BEFORE
unset AWVP_R45_INDETERMINATE_REQUESTS_AFTER_DROP AWVP_R45_INDETERMINATE_REQUESTS_AFTER_IDLE AWVP_R45_MOCK_STOPPED
unset AWVP_R45_INDETERMINATE_INIT_COUNT AWVP_R45_INDETERMINATE_BYTE_PUT_COUNT AWVP_R45_INDETERMINATE_PROBE_COUNT AWVP_R45_INDETERMINATE_REMOTE_GET_COUNT
unset -f awvp_r45_indeterminate_worker
