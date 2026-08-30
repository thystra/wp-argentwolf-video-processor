#!/usr/bin/env bash
# Real-WordPress/database smoke for the explicit unregistered R37 grant service.
#
# This exports one exact clean commit and runs it against the oldest and newest
# supported WordPress/PHP/MariaDB cases. It is development-checkpoint evidence,
# not the canonical release-validation runner or an installable-artifact gate.

set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
REPOSITORY_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd -P)"
FIXTURE_RELATIVE='tests/fixtures/peertube-password-grant-smoke'
FIXTURE_ROOT="$REPOSITORY_ROOT/$FIXTURE_RELATIVE"

WP64_IMAGE='wordpress:6.4.2-php8.1-apache@sha256:edb987c81a75daa2cde1520b307ef7b8490864301468b564cdb61b58f920dc1c'
WP64_CLI_IMAGE='wordpress:cli-php8.1@sha256:ab5fb76caa861f32c21e1d95a057f52007f4af7130fb16a0f68874dabe0549a4'
MARIADB106_IMAGE='mariadb:10.6.27@sha256:4066a44f4a0143c310fbe6972c254bbbb7a844a2be1418831a987fdbbc8ff8bd'
WP71_IMAGE='wordpress:7.1.0-php8.3-apache@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf'
WP71_CLI_IMAGE='wordpress:cli-php8.3@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586'
MARIADB1011_IMAGE='mariadb:10.11.18@sha256:de61fed4a40d3842f3ee09944ba52792156cfd9adf489b2cc670fc6ded28df8d'

RUN_TOKEN="$(date -u +%Y%m%dT%H%M%SZ)-$$"
RESOURCE_PREFIX="awvp-r37-$RUN_TOKEN"
RESOURCE_LABEL_KEY='org.argentwolf.awvp.test.run'

WORK_DIRECTORY=''
SOURCE_EXPORT=''
EXPORTED_FIXTURE_ROOT=''
CURRENT_CASE=''
CASE_STATE_DIRECTORY=''
REQUEST_LOG=''
NETWORK_NAME=''
VOLUME_NAME=''
DATABASE_NAME=''
WORDPRESS_NAME=''
MOCK_NAME=''
DATABASE_PASSWORD=''
DATABASE_ROOT_PASSWORD=''
WORDPRESS_CONFIG_EXTRA=''
WORDPRESS_IMAGE=''
WP_CLI_IMAGE=''
DATABASE_IMAGE=''
GRANT_STEP=''

DOCKER_READY=0
NETWORK_CREATE_ATTEMPTED=0
VOLUME_CREATE_ATTEMPTED=0
DATABASE_CREATE_ATTEMPTED=0
WORDPRESS_CREATE_ATTEMPTED=0
MOCK_CREATE_ATTEMPTED=0
RESULT='FAIL'

if [[ -n "${AWVP_R37_REPORT_DIR:-}" ]]; then
    if [[ "$AWVP_R37_REPORT_DIR" != /* ]]; then
        echo 'AWVP_R37_REPORT_DIR must be an absolute path.' >&2
        exit 2
    fi
    case "$AWVP_R37_REPORT_DIR/" in
        "$REPOSITORY_ROOT/"*)
            echo 'AWVP_R37_REPORT_DIR must remain outside the repository checkout.' >&2
            exit 2
            ;;
    esac
    mkdir -p -- "$AWVP_R37_REPORT_DIR"
    REPORT_DIRECTORY="$(cd -- "$AWVP_R37_REPORT_DIR" && pwd -P)"
else
    REPORT_DIRECTORY="$(mktemp -d /tmp/awvp-r37-report.XXXXXX)"
fi

case "$REPORT_DIRECTORY/" in
    "$REPOSITORY_ROOT/"*)
        echo 'AWVP_R37_REPORT_DIR must remain outside the repository checkout.' >&2
        exit 2
        ;;
esac

REPORT_FILE="$REPORT_DIRECTORY/peertube-password-grant-smoke-$RUN_TOKEN.log"
touch -- "$REPORT_FILE"
exec > >(tee -a "$REPORT_FILE") 2>&1

fail() {
    echo "PEERTUBE_PASSWORD_GRANT_SMOKE_ERROR=$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "Required command is unavailable: $1"
}

ensure_image() {
    local image_reference=$1
    local image_id=''
    local acquisition='LOCAL_CACHE'

    if ! docker image inspect "$image_reference" >/dev/null 2>&1; then
        docker pull "$image_reference"
        acquisition='PULLED'
    fi

    image_id="$(docker image inspect --format '{{.Id}}' "$image_reference")"
    [[ -n "$image_id" ]] || fail "Could not resolve image identity: $image_reference"
    echo "IMAGE_REFERENCE=$image_reference"
    echo "IMAGE_ID=$image_id"
    echo "IMAGE_ACQUISITION=$acquisition"
}

resource_owner() {
    local kind=$1
    local name=$2

    case "$kind" in
        container)
            docker container inspect \
                --format "{{ index .Config.Labels \"$RESOURCE_LABEL_KEY\" }}" \
                "$name" 2>/dev/null
            ;;
        volume|network)
            docker "$kind" inspect \
                --format "{{ index .Labels \"$RESOURCE_LABEL_KEY\" }}" \
                "$name" 2>/dev/null
            ;;
        *)
            return 2
            ;;
    esac
}

cleanup_case() {
    local cleanup_failed=0
    local owner_label=''
    local container_spec=''
    local attempted=''
    local name=''

    if (( DOCKER_READY != 1 )); then
        return 0
    fi
    if ! docker info >/dev/null 2>&1; then
        echo 'DOCKER_CLEANUP_ACCESS=FAIL' >&2
        return 1
    fi

    for container_spec in \
        "$MOCK_CREATE_ATTEMPTED|$MOCK_NAME" \
        "$WORDPRESS_CREATE_ATTEMPTED|$WORDPRESS_NAME" \
        "$DATABASE_CREATE_ATTEMPTED|$DATABASE_NAME"; do
        IFS='|' read -r attempted name <<<"$container_spec"
        if (( attempted != 1 )) || [[ -z "$name" ]]; then
            continue
        fi
        if ! docker container inspect "$name" >/dev/null 2>&1; then
            continue
        fi
        owner_label="$(resource_owner container "$name")"
        if [[ "$owner_label" != "$RUN_TOKEN" ]]; then
            echo "REFUSED_UNOWNED_CONTAINER=$name" >&2
            cleanup_failed=1
            continue
        fi
        if ! docker rm -f -v "$name" >/dev/null 2>&1; then
            cleanup_failed=1
        fi
        if docker container inspect "$name" >/dev/null 2>&1; then
            echo "CONTAINER_CLEANUP_REMAINS=$name" >&2
            cleanup_failed=1
        fi
    done

    if (( VOLUME_CREATE_ATTEMPTED == 1 )) && [[ -n "$VOLUME_NAME" ]] \
        && docker volume inspect "$VOLUME_NAME" >/dev/null 2>&1; then
        owner_label="$(resource_owner volume "$VOLUME_NAME")"
        if [[ "$owner_label" != "$RUN_TOKEN" ]]; then
            echo "REFUSED_UNOWNED_VOLUME=$VOLUME_NAME" >&2
            cleanup_failed=1
        elif ! docker volume rm -f "$VOLUME_NAME" >/dev/null 2>&1; then
            cleanup_failed=1
        fi
        if docker volume inspect "$VOLUME_NAME" >/dev/null 2>&1; then
            echo "VOLUME_CLEANUP_REMAINS=$VOLUME_NAME" >&2
            cleanup_failed=1
        fi
    fi

    if (( NETWORK_CREATE_ATTEMPTED == 1 )) && [[ -n "$NETWORK_NAME" ]] \
        && docker network inspect "$NETWORK_NAME" >/dev/null 2>&1; then
        owner_label="$(resource_owner network "$NETWORK_NAME")"
        if [[ "$owner_label" != "$RUN_TOKEN" ]]; then
            echo "REFUSED_UNOWNED_NETWORK=$NETWORK_NAME" >&2
            cleanup_failed=1
        elif ! docker network rm "$NETWORK_NAME" >/dev/null 2>&1; then
            cleanup_failed=1
        fi
        if docker network inspect "$NETWORK_NAME" >/dev/null 2>&1; then
            echo "NETWORK_CLEANUP_REMAINS=$NETWORK_NAME" >&2
            cleanup_failed=1
        fi
    fi

    if (( cleanup_failed == 0 )); then
        if [[ -n "$CURRENT_CASE" ]]; then
            echo "CASE_RESOURCE_CLEANUP=$CURRENT_CASE:PASS"
        fi
        NETWORK_CREATE_ATTEMPTED=0
        VOLUME_CREATE_ATTEMPTED=0
        DATABASE_CREATE_ATTEMPTED=0
        WORDPRESS_CREATE_ATTEMPTED=0
        MOCK_CREATE_ATTEMPTED=0
        NETWORK_NAME=''
        VOLUME_NAME=''
        DATABASE_NAME=''
        WORDPRESS_NAME=''
        MOCK_NAME=''
        CASE_STATE_DIRECTORY=''
        REQUEST_LOG=''
        CURRENT_CASE=''
        return 0
    fi

    if [[ -n "$CURRENT_CASE" ]]; then
        echo "CASE_RESOURCE_CLEANUP=$CURRENT_CASE:FAIL" >&2
    fi
    return 1
}

cleanup() {
    local original_status=$?
    local cleanup_failed=0

    trap - EXIT
    set +e

    if ! cleanup_case; then
        cleanup_failed=1
    fi

    if [[ -n "$WORK_DIRECTORY" ]]; then
        case "$WORK_DIRECTORY" in
            /tmp/awvp-r37-work.*)
                if ! rm -rf -- "$WORK_DIRECTORY"; then
                    cleanup_failed=1
                fi
                ;;
            *)
                echo "REFUSED_UNEXPECTED_WORK_DIRECTORY=$WORK_DIRECTORY" >&2
                cleanup_failed=1
                ;;
        esac
    fi

    if (( cleanup_failed == 0 )); then
        echo 'RESOURCE_CLEANUP=PASS'
    else
        echo 'RESOURCE_CLEANUP=FAIL'
        RESULT='FAIL'
        if (( original_status == 0 )); then
            original_status=1
        fi
    fi

    echo "PEERTUBE_PASSWORD_GRANT_SMOKE=$RESULT"
    echo "REPORT_FILE=$REPORT_FILE"
    printf 'REPORT_VIEW_COMMAND=cat %q\n' "$REPORT_FILE"
    exit "$original_status"
}

trap cleanup EXIT
trap 'exit 130' INT TERM

wait_for_database() {
    local attempt=''

    for attempt in $(seq 1 60); do
        if docker exec "$DATABASE_NAME" \
            mariadb-admin ping -h127.0.0.1 -uroot "-p$DATABASE_ROOT_PASSWORD" --silent \
            >/dev/null 2>&1; then
            echo "DATABASE_LOCAL_READY=$CURRENT_CASE:PASS"
            return 0
        fi
        sleep 1
    done

    docker logs "$DATABASE_NAME" || true
    fail "MariaDB did not become ready for $CURRENT_CASE."
}

wait_for_database_consumer_path() {
    local attempt=''

    for attempt in $(seq 1 60); do
        if docker run --rm \
            --network "$NETWORK_NAME" \
            --entrypoint php \
            -e AWVP_DB_HOST=db \
            -e AWVP_DB_USER=wordpress \
            -e "AWVP_DB_PASSWORD=$DATABASE_PASSWORD" \
            -e AWVP_DB_NAME=wordpress \
            "$WP_CLI_IMAGE" \
            -r '$connection = @mysqli_connect((string) getenv("AWVP_DB_HOST"), (string) getenv("AWVP_DB_USER"), (string) getenv("AWVP_DB_PASSWORD"), (string) getenv("AWVP_DB_NAME"), 3306); if (! $connection) { exit(1); } $result = mysqli_query($connection, "SELECT 1"); exit(false !== $result ? 0 : 1);' \
            >/dev/null 2>&1; then
            echo "DB_CONSUMER_PATH_READY=$CURRENT_CASE:PASS"
            return 0
        fi
        sleep 1
    done

    fail "The WP-CLI consumer path could not reach MariaDB for $CURRENT_CASE."
}

wait_for_wordpress_files() {
    local attempt=''

    for attempt in $(seq 1 60); do
        if docker exec "$WORDPRESS_NAME" \
            sh -c 'test -f /var/www/html/wp-includes/version.php && test -f /var/www/html/wp-config.php' \
            >/dev/null 2>&1; then
            echo "WORDPRESS_FILES_READY=$CURRENT_CASE:PASS"
            return 0
        fi
        sleep 1
    done

    docker logs "$WORDPRESS_NAME" || true
    fail "WordPress files did not become ready for $CURRENT_CASE."
}

wait_for_mock() {
    local attempt=''

    for attempt in $(seq 1 60); do
        if docker run --rm \
            --network "$NETWORK_NAME" \
            --entrypoint php \
            "$WP_CLI_IMAGE" \
            -r '$body = @file_get_contents("http://peertube.test:9000/health"); exit("ready\n" === $body ? 0 : 1);' \
            >/dev/null 2>&1; then
            echo "ISOLATED_PEERTUBE_FIXTURE_READY=$CURRENT_CASE:PASS"
            return 0
        fi
        sleep 1
    done

    docker logs "$MOCK_NAME" || true
    fail "The isolated PeerTube fixture did not become ready for $CURRENT_CASE."
}

wp_cli() {
    docker run --rm \
        --network "$NETWORK_NAME" \
        --volumes-from "$WORDPRESS_NAME" \
        --user 33:33 \
        -e WORDPRESS_DB_HOST=db:3306 \
        -e WORDPRESS_DB_USER=wordpress \
        -e "WORDPRESS_DB_PASSWORD=$DATABASE_PASSWORD" \
        -e WORDPRESS_DB_NAME=wordpress \
        -e WORDPRESS_DEBUG=1 \
        -e "AWVP_R37_STEP=$GRANT_STEP" \
        -e "WORDPRESS_CONFIG_EXTRA=$WORDPRESS_CONFIG_EXTRA" \
        "$WP_CLI_IMAGE" \
        --path=/var/www/html \
        --url=http://wp \
        "$@"
}

run_case() {
    local expected_wordpress=$1
    local expected_php=$2
    local expected_database=$3
    local observed_wordpress=''
    local observed_php=''
    local observed_database=''
    local container_name=''
    local grant_step=''
    local process_count=0
    local api_request_log=''
    local expected_api_request_log=''
    local mock_running=''
    local forbidden_value=''

    CURRENT_CASE=$4
    WORDPRESS_IMAGE=$5
    WP_CLI_IMAGE=$6
    DATABASE_IMAGE=$7
    NETWORK_NAME="$RESOURCE_PREFIX-$CURRENT_CASE-net"
    VOLUME_NAME="$RESOURCE_PREFIX-$CURRENT_CASE-wpdata"
    DATABASE_NAME="$RESOURCE_PREFIX-$CURRENT_CASE-db"
    WORDPRESS_NAME="$RESOURCE_PREFIX-$CURRENT_CASE-wp"
    MOCK_NAME="$RESOURCE_PREFIX-$CURRENT_CASE-peertube"
    CASE_STATE_DIRECTORY="$WORK_DIRECTORY/$CURRENT_CASE-state"
    REQUEST_LOG="$CASE_STATE_DIRECTORY/requests.log"
    DATABASE_PASSWORD="awvp-db-$(openssl rand -hex 16)"
    DATABASE_ROOT_PASSWORD="awvp-root-$(openssl rand -hex 16)"

    mkdir -p -- "$CASE_STATE_DIRECTORY"
    echo "CASE_BEGIN=$CURRENT_CASE"

    NETWORK_CREATE_ATTEMPTED=1
    docker network create \
        --internal \
        --label "$RESOURCE_LABEL_KEY=$RUN_TOKEN" \
        "$NETWORK_NAME" >/dev/null
    [[ "$RUN_TOKEN" == "$(resource_owner network "$NETWORK_NAME")" ]] \
        || fail "The Docker network is not owned by $CURRENT_CASE."
    [[ 'true' == "$(docker network inspect --format '{{.Internal}}' "$NETWORK_NAME")" ]] \
        || fail "Docker did not create an internal-only network for $CURRENT_CASE."
    echo "DOCKER_NETWORK_OWNERSHIP=$CURRENT_CASE:PASS"
    echo "DOCKER_NETWORK_INTERNAL=$CURRENT_CASE:PASS"

    VOLUME_CREATE_ATTEMPTED=1
    docker volume create \
        --label "$RESOURCE_LABEL_KEY=$RUN_TOKEN" \
        "$VOLUME_NAME" >/dev/null
    [[ "$RUN_TOKEN" == "$(resource_owner volume "$VOLUME_NAME")" ]] \
        || fail "The Docker volume is not owned by $CURRENT_CASE."
    echo "DOCKER_VOLUME_OWNERSHIP=$CURRENT_CASE:PASS"

    DATABASE_CREATE_ATTEMPTED=1
    docker run -d \
        --name "$DATABASE_NAME" \
        --label "$RESOURCE_LABEL_KEY=$RUN_TOKEN" \
        --network "$NETWORK_NAME" \
        --network-alias db \
        -e MARIADB_DATABASE=wordpress \
        -e MARIADB_USER=wordpress \
        -e "MARIADB_PASSWORD=$DATABASE_PASSWORD" \
        -e "MARIADB_ROOT_PASSWORD=$DATABASE_ROOT_PASSWORD" \
        "$DATABASE_IMAGE" >/dev/null

    wait_for_database
    wait_for_database_consumer_path

    printf -v WORDPRESS_CONFIG_EXTRA '%s\n' \
        'define( "WP_DEBUG_LOG", true );' \
        'define( "WP_DEBUG_DISPLAY", false );' \
        'define( "WP_HTTP_BLOCK_EXTERNAL", true );' \
        'define( "WP_ACCESSIBLE_HOSTS", "peertube.test" );' \
        'define( "ARGENT_VIDEO_PEERTUBE_DEV_ORIGINS", array( "http://peertube.test:9000" ) );' \
        'define( "DISABLE_WP_CRON", true );'

    WORDPRESS_CREATE_ATTEMPTED=1
    docker run -d \
        --name "$WORDPRESS_NAME" \
        --label "$RESOURCE_LABEL_KEY=$RUN_TOKEN" \
        --network "$NETWORK_NAME" \
        --network-alias wp \
        -v "$VOLUME_NAME:/var/www/html" \
        -v "$SOURCE_EXPORT:/var/www/html/wp-content/plugins/argentwolf-video-processor:ro" \
        -e WORDPRESS_DB_HOST=db:3306 \
        -e WORDPRESS_DB_USER=wordpress \
        -e "WORDPRESS_DB_PASSWORD=$DATABASE_PASSWORD" \
        -e WORDPRESS_DB_NAME=wordpress \
        -e WORDPRESS_DEBUG=1 \
        -e "WORDPRESS_CONFIG_EXTRA=$WORDPRESS_CONFIG_EXTRA" \
        "$WORDPRESS_IMAGE" >/dev/null

    wait_for_wordpress_files

    MOCK_CREATE_ATTEMPTED=1
    docker run -d \
        --name "$MOCK_NAME" \
        --label "$RESOURCE_LABEL_KEY=$RUN_TOKEN" \
        --network "$NETWORK_NAME" \
        --network-alias peertube.test \
        --user "$(id -u):$(id -g)" \
        --entrypoint php \
        -v "$EXPORTED_FIXTURE_ROOT:/awvp-mock:ro" \
        -v "$CASE_STATE_DIRECTORY:/awvp-state" \
        "$WP_CLI_IMAGE" \
        -S 0.0.0.0:9000 /awvp-mock/mock-router.php >/dev/null

    wait_for_mock

    for container_name in "$DATABASE_NAME" "$WORDPRESS_NAME" "$MOCK_NAME"; do
        [[ "$RUN_TOKEN" == "$(resource_owner container "$container_name")" ]] \
            || fail "A disposable container is not owned by $CURRENT_CASE: $container_name"
        if [[ -n "$(docker port "$container_name" 2>/dev/null)" ]]; then
            fail "A disposable container published a host port: $container_name"
        fi
    done
    echo "HOST_PORTS_PUBLISHED=$CURRENT_CASE:NONE"

    if wp_cli core is-installed >/dev/null 2>&1; then
        fail "The per-case WordPress database was not fresh for $CURRENT_CASE."
    fi
    echo "FRESH_WORDPRESS_SITE=$CURRENT_CASE:PASS"

    wp_cli core install \
        --url='http://wp' \
        --title="AWVP R37 password grant smoke $CURRENT_CASE" \
        --admin_user=awvpadmin \
        --admin_password='AWVP-disposable-test-only-123!' \
        --admin_email=awvp@example.invalid \
        --skip-email

    if ! wp_cli eval '
        $valid = defined( "WP_DEBUG" ) && true === WP_DEBUG
            && defined( "WP_DEBUG_LOG" ) && true === WP_DEBUG_LOG
            && defined( "WP_DEBUG_DISPLAY" ) && false === WP_DEBUG_DISPLAY
            && defined( "WP_HTTP_BLOCK_EXTERNAL" ) && true === WP_HTTP_BLOCK_EXTERNAL
            && defined( "WP_ACCESSIBLE_HOSTS" ) && "peertube.test" === WP_ACCESSIBLE_HOSTS
            && defined( "ARGENT_VIDEO_PEERTUBE_DEV_ORIGINS" )
            && array( "http://peertube.test:9000" ) === ARGENT_VIDEO_PEERTUBE_DEV_ORIGINS
            && defined( "DISABLE_WP_CRON" ) && true === DISABLE_WP_CRON
            && isset( $_SERVER["HTTP_HOST"] ) && "wp" === $_SERVER["HTTP_HOST"];
        if ( ! $valid ) {
            fwrite( STDERR, "The R37 runtime configuration is incomplete.\n" );
            exit( 1 );
        }
        echo "WORDPRESS_RUNTIME_CONFIGURATION=PASS\n";
    '; then
        fail "The WordPress and WP-CLI configuration differed for $CURRENT_CASE."
    fi

    observed_wordpress="$(wp_cli core version)"
    [[ "$expected_wordpress" == "$observed_wordpress" ]] \
        || fail "Expected WordPress $expected_wordpress for $CURRENT_CASE; observed $observed_wordpress."
    echo "WORDPRESS_VERSION=$CURRENT_CASE:$observed_wordpress"

    observed_php="$(wp_cli eval 'echo PHP_VERSION;')"
    [[ "$observed_php" == "$expected_php".* ]] \
        || fail "Expected PHP $expected_php.x for $CURRENT_CASE; observed $observed_php."
    echo "PHP_VERSION=$CURRENT_CASE:$observed_php"

    observed_database="$(wp_cli eval 'global $wpdb; echo $wpdb->db_version();')"
    [[ "$expected_database" == "$observed_database" ]] \
        || fail "Expected MariaDB $expected_database for $CURRENT_CASE; observed $observed_database."
    echo "DATABASE_VERSION=$CURRENT_CASE:$observed_database"

    wp_cli --context=cli plugin activate argentwolf-video-processor
    wp_cli --context=cli plugin is-active argentwolf-video-processor
    echo "PLUGIN_ACTIVATION=$CURRENT_CASE:PASS"

    for grant_step in \
        success-start \
        success-manifest \
        success-secret-plan \
        success-secret-apply \
        success-secret-confirm \
        success-link-plan \
        success-link-apply \
        success-link-confirm \
        success-ready \
        otp-start \
        otp-secret-plan \
        otp-secret-apply \
        otp-secret-confirm \
        otp-link-plan \
        otp-link-apply \
        otp-link-confirm \
        otp-ready \
        transport-start \
        transport-secret-plan \
        transport-secret-apply \
        transport-secret-confirm \
        transport-link-plan \
        transport-link-apply \
        transport-link-confirm \
        transport-ready \
        success-submit \
        success-reconcile \
        otp-submit-required \
        otp-reconcile-required \
        otp-submit-success \
        otp-reconcile-success \
        transport-submit \
        transport-reconcile \
        transport-resubmit \
        final; do
        GRANT_STEP=$grant_step
        echo "GRANT_PROCESS_BEGIN=$CURRENT_CASE:$grant_step"
        wp_cli --context=cli eval-file \
            "/var/www/html/wp-content/plugins/argentwolf-video-processor/$FIXTURE_RELATIVE/assert-step.php" \
            --use-include
        echo "GRANT_PROCESS_END=$CURRENT_CASE:$grant_step:PASS"
        process_count=$((process_count + 1))
    done
    GRANT_STEP=''

    [[ 35 -eq $process_count ]] || fail "The R37 WP-CLI process count differed for $CURRENT_CASE."
    echo "PASSWORD_GRANT_WP_CLI_PROCESS_COUNT=$CURRENT_CASE:$process_count"
    echo "PASSWORD_GRANT_RESTART_SEQUENCE=$CURRENT_CASE:PASS"

    [[ -f "$REQUEST_LOG" ]] || fail "The isolated fixture did not produce a request log for $CURRENT_CASE."
    api_request_log="$(sed -n '1,120p' "$REQUEST_LOG")"
    expected_api_request_log=$'GET /api/v1/oauth-clients/local auth=none body=none\nPOST /api/v1/users/token scenario=success otp=none form=password\nGET /api/v1/oauth-clients/local auth=none body=none\nPOST /api/v1/users/token scenario=otp-required otp=none form=password\nGET /api/v1/oauth-clients/local auth=none body=none\nPOST /api/v1/users/token scenario=otp-success otp=valid form=password\nGET /api/v1/oauth-clients/local auth=none body=none\nPOST /api/v1/users/token scenario=transport-drop otp=none form=password'
    if [[ "$expected_api_request_log" != "$api_request_log" ]]; then
        echo '--- ISOLATED FIXTURE REQUEST LOG'
        sed -n '1,120p' "$REQUEST_LOG"
        fail "The isolated grant request sequence differed for $CURRENT_CASE."
    fi
    echo "ISOLATED_PASSWORD_GRANT_REQUEST_SEQUENCE=$CURRENT_CASE:PASS"
    echo "PASSWORD_GRANT_OAUTH_GET_COUNT=$CURRENT_CASE:4"
    echo "PASSWORD_GRANT_TOKEN_POST_COUNT=$CURRENT_CASE:4"
    echo "PASSWORD_GRANT_SUCCESS_POST_COUNT=$CURRENT_CASE:1"
    echo "PASSWORD_GRANT_OTP_REQUIRED_AND_RETRY=$CURRENT_CASE:PASS"
    echo "PASSWORD_GRANT_TRANSPORT_INDETERMINATE_NO_RETRY=$CURRENT_CASE:PASS"

    mock_running="$(docker container inspect --format '{{.State.Running}}' "$MOCK_NAME")"
    [[ 'false' == "$mock_running" ]] \
        || fail "The transport fixture did not terminate its connection for $CURRENT_CASE."
    echo "ISOLATED_TRANSPORT_DROP=$CURRENT_CASE:PASS"

    for forbidden_value in \
        'r37-oauth-client-id' \
        'r37-oauth-client-secret-canary' \
        'r37-success-user-canary' \
        'r37-success-password-canary' \
        'r37-success-access-token-canary' \
        'r37-success-refresh-token-canary' \
        'r37-otp-user-canary' \
        'r37-otp-password-canary' \
        'r37-otp-access-token-canary' \
        'r37-otp-refresh-token-canary' \
        'r37-transport-user-canary' \
        'r37-transport-password-canary' \
        '731946'; do
        if grep --fixed-strings --quiet -- "$forbidden_value" "$REPORT_FILE"; then
            fail "A synthetic grant secret appeared in the preserved report for $CURRENT_CASE."
        fi
        if grep --fixed-strings --quiet -- "$forbidden_value" "$REQUEST_LOG"; then
            fail "A synthetic grant secret appeared in the redacted request log for $CURRENT_CASE."
        fi
        if docker exec "$WORDPRESS_NAME" \
            grep --fixed-strings --quiet -- \
                "$forbidden_value" /var/www/html/wp-content/debug.log \
            >/dev/null 2>&1; then
            fail "A synthetic grant secret appeared in WP_DEBUG_LOG for $CURRENT_CASE."
        fi
    done
    echo "PASSWORD_GRANT_PLAINTEXT_CANARIES=$CURRENT_CASE:NONE"
    echo "PASSWORD_GRANT_ENCRYPTED_SECRET_PERSISTENCE=$CURRENT_CASE:PASS"
    echo "PASSWORD_GRANT_PUBLIC_OUTPUT_SECRETS=$CURRENT_CASE:NONE"
    echo "PASSWORD_GRANT_UPLOAD_MUTATIONS=$CURRENT_CASE:NONE"
    echo "PASSWORD_GRANT_SERVICE_REGISTRATION=$CURRENT_CASE:NONE"

    if docker exec "$WORDPRESS_NAME" \
        sh -c 'test -f /var/www/html/wp-content/debug.log && grep -E "PHP (Fatal error|Parse error|Warning|Notice|Deprecated)|WordPress database error|was called incorrectly" /var/www/html/wp-content/debug.log' \
        >/dev/null 2>&1; then
        if ! docker exec "$WORDPRESS_NAME" cat /var/www/html/wp-content/debug.log; then
            fail "A diagnostic was detected but the complete debug log could not be preserved for $CURRENT_CASE."
        fi
        fail "WP_DEBUG_LOG contains a PHP or WordPress diagnostic for $CURRENT_CASE."
    fi
    echo "WP_DEBUG_DIAGNOSTICS=$CURRENT_CASE:NONE"
    echo "CASE_ASSERTIONS=$CURRENT_CASE:PASS"

    if ! cleanup_case; then
        fail "Owned resource cleanup failed for $CURRENT_CASE."
    fi
}

for command_name in chmod date docker git grep id mkdir mktemp openssl rm sed seq sleep tar tee; do
    require_command "$command_name"
done

[[ -f "$FIXTURE_ROOT/mock-router.php" ]] || fail 'The password-grant mock router is missing.'
[[ -f "$FIXTURE_ROOT/assert-step.php" ]] || fail 'The password-grant assertion fixture is missing.'
[[ -f "$REPOSITORY_ROOT/argentwolf-video-processor.php" ]] || fail 'The plugin bootstrap is missing.'

docker info >/dev/null 2>&1 || fail 'Docker is unavailable to the current account.'
DOCKER_READY=1
echo 'DOCKER_ACCESS=PASS'

[[ 'true' == "$(git -C "$REPOSITORY_ROOT" rev-parse --is-inside-work-tree 2>/dev/null)" ]] \
    || fail 'Run this checkpoint smoke from a Git checkout.'
[[ "$REPOSITORY_ROOT" == "$(git -C "$REPOSITORY_ROOT" rev-parse --show-toplevel)" ]] \
    || fail 'The smoke script must resolve to the exact Git repository root.'
if [[ -n "$(git -C "$REPOSITORY_ROOT" status --porcelain --untracked-files=all)" ]]; then
    fail 'The source checkout is dirty; commit or otherwise establish an exact checkpoint first.'
fi

SOURCE_COMMIT="$(git -C "$REPOSITORY_ROOT" rev-parse HEAD)"
SOURCE_TREE="$(git -C "$REPOSITORY_ROOT" rev-parse 'HEAD^{tree}')"
echo "SOURCE_COMMIT=$SOURCE_COMMIT"
echo "SOURCE_TREE=$SOURCE_TREE"
echo 'SOURCE_WORKTREE=CLEAN'
echo 'VALIDATION_CLASS=DEVELOPMENT_CHECKPOINT_NOT_RELEASE_GATE'

WORK_DIRECTORY="$(mktemp -d /tmp/awvp-r37-work.XXXXXX)"
SOURCE_EXPORT="$WORK_DIRECTORY/argentwolf-video-processor"
EXPORTED_FIXTURE_ROOT="$SOURCE_EXPORT/$FIXTURE_RELATIVE"
mkdir -p -- "$SOURCE_EXPORT"
git -C "$REPOSITORY_ROOT" archive --format=tar --output="$WORK_DIRECTORY/source.tar" HEAD
tar -xf "$WORK_DIRECTORY/source.tar" -C "$SOURCE_EXPORT"
rm -f -- "$WORK_DIRECTORY/source.tar"
chmod -R a+rX "$SOURCE_EXPORT"
[[ -f "$EXPORTED_FIXTURE_ROOT/mock-router.php" ]] || fail 'The committed grant mock was not exported.'
[[ -f "$EXPORTED_FIXTURE_ROOT/assert-step.php" ]] || fail 'The committed grant assertion fixture was not exported.'
echo 'SOURCE_EXPORT_FROM_COMMIT=PASS'
echo 'SOURCE_EXPORT_RUNTIME_MOUNT=READ_ONLY'

for image_reference in \
    "$WP64_IMAGE" \
    "$WP64_CLI_IMAGE" \
    "$MARIADB106_IMAGE" \
    "$WP71_IMAGE" \
    "$WP71_CLI_IMAGE" \
    "$MARIADB1011_IMAGE"; do
    ensure_image "$image_reference"
done

run_case \
    '6.4.2' \
    '8.1' \
    '10.6.27' \
    'wp64-php81-mariadb106' \
    "$WP64_IMAGE" \
    "$WP64_CLI_IMAGE" \
    "$MARIADB106_IMAGE"

run_case \
    '7.1' \
    '8.3' \
    '10.11.18' \
    'wp71-php83-mariadb1011' \
    "$WP71_IMAGE" \
    "$WP71_CLI_IMAGE" \
    "$MARIADB1011_IMAGE"

RESULT='PASS'
echo 'PEERTUBE_PASSWORD_GRANT_MATRIX_ASSERTIONS=PASS'
