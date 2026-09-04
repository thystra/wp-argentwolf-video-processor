#!/usr/bin/env bash
# Real-WordPress/browser smoke for the explicit administrator connection boundary.
#
# This exports one exact clean commit and runs it against the oldest and newest
# supported WordPress/PHP/MariaDB cases. It is development-checkpoint evidence,
# not the canonical release-validation runner or an installable-artifact gate.

set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
REPOSITORY_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd -P)"
SMOKE_CLASS="${AWVP_ADMIN_SMOKE_CLASS:-r38}"
SMOKE_MARKER="${AWVP_ADMIN_SMOKE_MARKER:-PEERTUBE_ADMIN_AUTHORIZATION}"
FIXTURE_RELATIVE="${AWVP_ADMIN_FIXTURE_RELATIVE:-tests/fixtures/peertube-admin-authorization-smoke}"
FIXTURE_ROOT="$REPOSITORY_ROOT/$FIXTURE_RELATIVE"
MOCK_RELATIVE="${AWVP_ADMIN_MOCK_RELATIVE:-tests/fixtures/peertube-password-grant-smoke}"
MOCK_ROOT="$REPOSITORY_ROOT/$MOCK_RELATIVE"
BROWSER_SUPPORT_RELATIVE="${AWVP_ADMIN_BROWSER_SUPPORT_RELATIVE:-$FIXTURE_RELATIVE}"
BROWSER_SUPPORT_ROOT="$REPOSITORY_ROOT/$BROWSER_SUPPORT_RELATIVE"
AFTER_BROWSER_RELATIVE="${AWVP_ADMIN_AFTER_BROWSER_RELATIVE:-}"
AFTER_BROWSER_ROOT=''
if [[ -n "$AFTER_BROWSER_RELATIVE" ]]; then
    AFTER_BROWSER_ROOT="$REPOSITORY_ROOT/$AFTER_BROWSER_RELATIVE"
fi

if [[ ! "$SMOKE_CLASS" =~ ^[a-z][a-z0-9-]{0,15}$ ]]; then
    echo 'The administrator smoke class is invalid.' >&2
    exit 2
fi
if [[ ! "$SMOKE_MARKER" =~ ^[A-Z][A-Z0-9_]{0,63}$ ]]; then
    echo 'The administrator smoke marker is invalid.' >&2
    exit 2
fi
for relative_path in "$FIXTURE_RELATIVE" "$MOCK_RELATIVE" "$BROWSER_SUPPORT_RELATIVE"; do
    if [[ ! "$relative_path" =~ ^tests/fixtures/[a-z0-9-]+$ ]]; then
        echo 'An administrator smoke fixture path is invalid.' >&2
        exit 2
    fi
done
if [[ -n "$AFTER_BROWSER_RELATIVE" ]] && [[ ! "$AFTER_BROWSER_RELATIVE" =~ ^tests/fixtures/[a-z0-9-]+/[a-z0-9-]+\.sh$ ]]; then
    echo 'The administrator smoke after-browser hook path is invalid.' >&2
    exit 2
fi
FIXTURE_NAME="${FIXTURE_RELATIVE##*/}"

WP64_IMAGE='wordpress:6.4.2-php8.1-apache@sha256:edb987c81a75daa2cde1520b307ef7b8490864301468b564cdb61b58f920dc1c'
WP64_CLI_IMAGE='wordpress:cli-php8.1@sha256:ab5fb76caa861f32c21e1d95a057f52007f4af7130fb16a0f68874dabe0549a4'
MARIADB106_IMAGE='mariadb:10.6.27@sha256:4066a44f4a0143c310fbe6972c254bbbb7a844a2be1418831a987fdbbc8ff8bd'
WP71_IMAGE='wordpress:7.1.0-php8.3-apache@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf'
WP71_CLI_IMAGE='wordpress:cli-php8.3@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586'
MARIADB1011_IMAGE='mariadb:10.11.18@sha256:de61fed4a40d3842f3ee09944ba52792156cfd9adf489b2cc670fc6ded28df8d'

RUN_TOKEN="$(date -u +%Y%m%dT%H%M%SZ)-$$"
RESOURCE_PREFIX="awvp-$SMOKE_CLASS-$RUN_TOKEN"
RESOURCE_LABEL_KEY='org.argentwolf.awvp.test.run'

WORK_DIRECTORY=''
SOURCE_EXPORT=''
EXPORTED_FIXTURES_ROOT=''
EXPORTED_FIXTURE_ROOT=''
EXPORTED_MOCK_ROOT=''
EXPORTED_BROWSER_SUPPORT_ROOT=''
EXPORTED_AFTER_BROWSER=''
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

DOCKER_READY=0
NETWORK_CREATE_ATTEMPTED=0
VOLUME_CREATE_ATTEMPTED=0
DATABASE_CREATE_ATTEMPTED=0
WORDPRESS_CREATE_ATTEMPTED=0
MOCK_CREATE_ATTEMPTED=0
RESULT='FAIL'

REPORT_DIRECTORY_INPUT="${AWVP_ADMIN_REPORT_DIR:-${AWVP_R38_REPORT_DIR:-}}"
if [[ -n "$REPORT_DIRECTORY_INPUT" ]]; then
    if [[ "$REPORT_DIRECTORY_INPUT" != /* ]]; then
        echo 'The administrator smoke report directory must be an absolute path.' >&2
        exit 2
    fi
    case "$REPORT_DIRECTORY_INPUT/" in
        "$REPOSITORY_ROOT/"*)
            echo 'The administrator smoke report directory must remain outside the repository checkout.' >&2
            exit 2
            ;;
    esac
    mkdir -p -- "$REPORT_DIRECTORY_INPUT"
    REPORT_DIRECTORY="$(cd -- "$REPORT_DIRECTORY_INPUT" && pwd -P)"
else
    REPORT_DIRECTORY="$(mktemp -d "/tmp/awvp-$SMOKE_CLASS-report.XXXXXX")"
fi

case "$REPORT_DIRECTORY/" in
    "$REPOSITORY_ROOT/"*)
        echo 'The administrator smoke report directory must remain outside the repository checkout.' >&2
        exit 2
        ;;
esac

REPORT_FILE="$REPORT_DIRECTORY/peertube-$SMOKE_CLASS-smoke-$RUN_TOKEN.log"
touch -- "$REPORT_FILE"
exec > >(tee -a "$REPORT_FILE") 2>&1

fail() {
    echo "${SMOKE_MARKER}_SMOKE_ERROR=$*" >&2
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
            /tmp/awvp-*-work.*)
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

    echo "${SMOKE_MARKER}_SMOKE=$RESULT"
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

wait_for_wordpress_http() {
    local attempt=''

    for attempt in $(seq 1 60); do
        if docker run --rm \
            --network "$NETWORK_NAME" \
            --entrypoint php \
            "$WP_CLI_IMAGE" \
            -r '$headers = @get_headers("http://wp/wp-login.php"); exit(is_array($headers) && isset($headers[0]) ? 0 : 1);' \
            >/dev/null 2>&1; then
            echo "WORDPRESS_HTTP_READY=$CURRENT_CASE:PASS"
            return 0
        fi
        sleep 1
    done

    docker logs "$WORDPRESS_NAME" || true
    fail "The internal WordPress HTTP path did not become ready for $CURRENT_CASE."
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
    local api_request_log=''
    local expected_api_request_log=''
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
        --init \
        --name "$MOCK_NAME" \
        --label "$RESOURCE_LABEL_KEY=$RUN_TOKEN" \
        --network "$NETWORK_NAME" \
        --network-alias peertube.test \
        --user "$(id -u):$(id -g)" \
        --entrypoint php \
        -v "$EXPORTED_MOCK_ROOT:/awvp-mock:ro" \
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
        --title="AWVP $SMOKE_CLASS administrator connection smoke $CURRENT_CASE" \
        --admin_user=awvpadmin \
        --admin_password='AWVP-disposable-test-only-123!' \
        --admin_email=awvp@example.invalid \
        --skip-email
    wp_cli user create \
        awvpsubscriber \
        awvpsubscriber@example.invalid \
        --role=subscriber \
        --user_pass='AWVP-subscriber-test-only-123!'

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
            fwrite( STDERR, "The administrator smoke runtime configuration is incomplete.\n" );
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

    wp_cli --context=cli eval-file \
        "/var/www/html/wp-content/plugins/argentwolf-video-processor/$FIXTURE_RELATIVE/seed-update-check-baseline.php" \
        --use-include
    echo "WORDPRESS_UPDATE_CHECK_BASELINE=$CURRENT_CASE:PASS"

    wait_for_wordpress_http

    docker run --rm \
        --network "$NETWORK_NAME" \
        --user "$(id -u):$(id -g)" \
        --read-only \
        --tmpfs /tmp:rw,nosuid,nodev,noexec,size=16m \
        --entrypoint php \
        -e AWVP_R38_WORDPRESS_URL=http://wp \
        -v "$EXPORTED_FIXTURES_ROOT:/awvp-fixtures:ro" \
        "$WP_CLI_IMAGE" \
        "/awvp-fixtures/$FIXTURE_NAME/assert-browser.php"
    echo "${SMOKE_MARKER}_BROWSER=$CURRENT_CASE:PASS"

    if [[ -n "$EXPORTED_AFTER_BROWSER" ]]; then
        # The hook is committed test code sourced from the exact read-only Git
        # export. It may call the local wp_cli()/fail() harness functions, but
        # it receives no production runtime authority.
        # shellcheck disable=SC1090
        source "$EXPORTED_AFTER_BROWSER"
        echo "${SMOKE_MARKER}_AFTER_BROWSER=$CURRENT_CASE:PASS"
    fi

    wp_cli --context=cli eval-file \
        "/var/www/html/wp-content/plugins/argentwolf-video-processor/$FIXTURE_RELATIVE/assert-state.php" \
        --use-include
    echo "${SMOKE_MARKER}_STATE=$CURRENT_CASE:PASS"

    [[ -f "$REQUEST_LOG" ]] || fail "The isolated fixture did not produce a request log for $CURRENT_CASE."
    api_request_log="$(sed -n '1,40p' "$REQUEST_LOG")"
    if [[ -f "$EXPORTED_FIXTURE_ROOT/expected-requests.log" ]]; then
        expected_api_request_log="$(sed -n '1,40p' "$EXPORTED_FIXTURE_ROOT/expected-requests.log")"
    else
        expected_api_request_log=$'GET /api/v1/oauth-clients/local auth=none body=none\nPOST /api/v1/users/token scenario=success otp=none form=password'
    fi
    if [[ "$expected_api_request_log" != "$api_request_log" ]]; then
        echo 'ISOLATED_FIXTURE_REQUEST_LOG_DIFFERED=YES'
        fail "The isolated administrator request sequence differed for $CURRENT_CASE."
    fi
    echo "ISOLATED_ADMIN_AUTHORIZATION_REQUEST_SEQUENCE=$CURRENT_CASE:PASS"
    local oauth_get_count token_post_count revoke_post_count upload_init_count upload_chunk_count upload_probe_count remote_video_get_count
    oauth_get_count="$(grep -c '^GET /api/v1/oauth-clients/local ' "$REQUEST_LOG" || true)"
    token_post_count="$(grep -c '^POST /api/v1/users/token ' "$REQUEST_LOG" || true)"
    revoke_post_count="$(grep -c '^POST /api/v1/users/revoke-token ' "$REQUEST_LOG" || true)"
    upload_init_count="$(grep -c '^POST /api/v1/videos/upload-resumable ' "$REQUEST_LOG" || true)"
    upload_chunk_count="$(grep -c '^PUT /api/v1/videos/upload-resumable .* range=bytes=[0-9]' "$REQUEST_LOG" || true)"
    upload_probe_count="$(grep -c '^PUT /api/v1/videos/upload-resumable .* range=bytes=\*/' "$REQUEST_LOG" || true)"
    remote_video_get_count="$(grep -c '^GET /api/v1/videos/[0-9a-f-]\+ ' "$REQUEST_LOG" || true)"
    echo "ADMIN_AUTHORIZATION_OAUTH_GET_COUNT=$CURRENT_CASE:$oauth_get_count"
    echo "ADMIN_AUTHORIZATION_TOKEN_POST_COUNT=$CURRENT_CASE:$token_post_count"
    echo "ADMIN_AUTHORIZATION_REVOKE_POST_COUNT=$CURRENT_CASE:$revoke_post_count"
    if [[ "$SMOKE_CLASS" == 'r43' || "$SMOKE_CLASS" == 'r44' || "$SMOKE_CLASS" == 'r45cli' ]]; then
        [[ "$upload_init_count" == '1' && "$upload_chunk_count" == '1' && "$upload_probe_count" == '0' ]] \
            || fail "The R43/R44 upload mutation count differed for $CURRENT_CASE."
        echo "STAGED_UPLOAD_INIT_POST_COUNT=$CURRENT_CASE:$upload_init_count"
        echo "STAGED_UPLOAD_BYTE_PUT_COUNT=$CURRENT_CASE:$upload_chunk_count"
        echo "STAGED_UPLOAD_ZERO_BYTE_PROBE_COUNT=$CURRENT_CASE:$upload_probe_count"
    fi
    if [[ "$SMOKE_CLASS" == 'r44' || "$SMOKE_CLASS" == 'r45cli' ]]; then
        [[ "$remote_video_get_count" == '2' ]] || fail "The R44 remote-video GET count differed for $CURRENT_CASE."
        echo "REMOTE_VIDEO_GET_COUNT=$CURRENT_CASE:$remote_video_get_count"
    fi
    echo "ADMIN_AUTHORIZATION_AUTOMATIC_REMOTE_RETRY=$CURRENT_CASE:NONE"

    for forbidden_value in \
        'r37-oauth-client-id' \
        'r37-oauth-client-secret-canary' \
        'r37-success-user-canary' \
        'r37-success-password-canary' \
        'r37-success-access-token-canary' \
        'r37-success-refresh-token-canary' \
        'r41-refreshed-access-token-canary' \
        'r41-refreshed-refresh-token-canary' \
        'AWVP-disposable-test-only-123!' \
        'AWVP-subscriber-test-only-123!'; do
        if grep --fixed-strings --quiet -- "$forbidden_value" "$REPORT_FILE"; then
            fail "A synthetic authorization secret appeared in the preserved report for $CURRENT_CASE."
        fi
        if grep --fixed-strings --quiet -- "$forbidden_value" "$REQUEST_LOG"; then
            fail "A synthetic authorization secret appeared in the redacted request log for $CURRENT_CASE."
        fi
        if docker exec "$WORDPRESS_NAME" \
            grep --fixed-strings --quiet -- \
                "$forbidden_value" /var/www/html/wp-content/debug.log \
            >/dev/null 2>&1; then
            fail "A synthetic authorization secret appeared in WP_DEBUG_LOG for $CURRENT_CASE."
        fi
    done
    echo "ADMIN_AUTHORIZATION_PLAINTEXT_CANARIES=$CURRENT_CASE:NONE"
    if [[ "$SMOKE_CLASS" == 'r41' ]]; then
        echo "ADMIN_AUTHORIZATION_MANAGED_SECRET_REMOVAL=$CURRENT_CASE:PASS"
    else
        echo "ADMIN_AUTHORIZATION_ENCRYPTED_SECRET_PERSISTENCE=$CURRENT_CASE:PASS"
    fi
    if [[ "$SMOKE_CLASS" == 'r43' || "$SMOKE_CLASS" == 'r44' || "$SMOKE_CLASS" == 'r45cli' ]]; then
        echo "ADMIN_AUTHORIZATION_UPLOAD_MUTATIONS=$CURRENT_CASE:RESUMABLE_PRIVATE_STAGED_UPLOAD_ONLY"
    else
        echo "ADMIN_AUTHORIZATION_UPLOAD_MUTATIONS=$CURRENT_CASE:NONE"
    fi

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

[[ -f "$FIXTURE_ROOT/assert-browser.php" ]] || fail 'The administrator browser fixture is missing.'
[[ -f "$FIXTURE_ROOT/assert-state.php" ]] || fail 'The administrator state fixture is missing.'
[[ -f "$FIXTURE_ROOT/seed-update-check-baseline.php" ]] \
    || fail 'The administrator update-check baseline fixture is missing.'
[[ -f "$MOCK_ROOT/mock-router.php" ]] || fail 'The reusable password-grant mock is missing.'
[[ -f "$BROWSER_SUPPORT_ROOT/assert-browser.php" ]] \
    || fail 'The administrator browser support fixture is missing.'
if [[ -n "$AFTER_BROWSER_ROOT" ]]; then
    [[ -f "$AFTER_BROWSER_ROOT" ]] || fail 'The administrator after-browser hook is missing.'
fi
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

WORK_DIRECTORY="$(mktemp -d "/tmp/awvp-$SMOKE_CLASS-work.XXXXXX")"
SOURCE_EXPORT="$WORK_DIRECTORY/argentwolf-video-processor"
EXPORTED_FIXTURES_ROOT="$SOURCE_EXPORT/tests/fixtures"
EXPORTED_FIXTURE_ROOT="$SOURCE_EXPORT/$FIXTURE_RELATIVE"
EXPORTED_MOCK_ROOT="$SOURCE_EXPORT/$MOCK_RELATIVE"
EXPORTED_BROWSER_SUPPORT_ROOT="$SOURCE_EXPORT/$BROWSER_SUPPORT_RELATIVE"
if [[ -n "$AFTER_BROWSER_RELATIVE" ]]; then
    EXPORTED_AFTER_BROWSER="$SOURCE_EXPORT/$AFTER_BROWSER_RELATIVE"
fi
mkdir -p -- "$SOURCE_EXPORT"
git -C "$REPOSITORY_ROOT" archive --format=tar --output="$WORK_DIRECTORY/source.tar" HEAD
tar -xf "$WORK_DIRECTORY/source.tar" -C "$SOURCE_EXPORT"
rm -f -- "$WORK_DIRECTORY/source.tar"
chmod -R a+rX "$SOURCE_EXPORT"
[[ -f "$EXPORTED_FIXTURE_ROOT/assert-browser.php" ]] || fail 'The committed browser fixture was not exported.'
[[ -f "$EXPORTED_FIXTURE_ROOT/assert-state.php" ]] || fail 'The committed state fixture was not exported.'
[[ -f "$EXPORTED_FIXTURE_ROOT/seed-update-check-baseline.php" ]] \
    || fail 'The committed update-check baseline fixture was not exported.'
[[ -f "$EXPORTED_MOCK_ROOT/mock-router.php" ]] || fail 'The committed reusable mock was not exported.'
[[ -f "$EXPORTED_BROWSER_SUPPORT_ROOT/assert-browser.php" ]] \
    || fail 'The committed browser support fixture was not exported.'
if [[ -n "$EXPORTED_AFTER_BROWSER" ]]; then
    [[ -f "$EXPORTED_AFTER_BROWSER" ]] || fail 'The committed after-browser hook was not exported.'
fi
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
echo "${SMOKE_MARKER}_MATRIX_ASSERTIONS=PASS"
