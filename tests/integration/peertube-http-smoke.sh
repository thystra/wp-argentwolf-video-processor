#!/usr/bin/env bash
# Focused Docker smoke for the R33 read-only PeerTube HTTP/API checkpoint.
#
# This is development-checkpoint evidence, not the canonical release-validation
# runner and not an installable-artifact release gate.

set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
REPOSITORY_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd -P)"
FIXTURE_ROOT="$REPOSITORY_ROOT/tests/fixtures/peertube-http-smoke"

WORDPRESS_IMAGE='wordpress:7.0.2-php8.3-apache@sha256:b2d7e3153c8a96f90305a3102fb6439335237fb1a9655b617d15c5168ce2f7a3'
WP_CLI_IMAGE='wordpress:cli-php8.3@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586'
DATABASE_IMAGE='mariadb:10.11.18@sha256:de61fed4a40d3842f3ee09944ba52792156cfd9adf489b2cc670fc6ded28df8d'

RUN_TOKEN="$(date -u +%Y%m%dT%H%M%SZ)-$$"
RESOURCE_PREFIX="awvp-r33-$RUN_TOKEN"
NETWORK_NAME="$RESOURCE_PREFIX-net"
VOLUME_NAME="$RESOURCE_PREFIX-wpdata"
DATABASE_NAME="$RESOURCE_PREFIX-db"
WORDPRESS_NAME="$RESOURCE_PREFIX-wp"
MOCK_NAME="$RESOURCE_PREFIX-peertube"
DATABASE_PASSWORD=''
DATABASE_ROOT_PASSWORD=''

WORK_DIRECTORY=''
REQUEST_LOG=''
SOURCE_EXPORT=''
EXPORTED_FIXTURE_ROOT=''

if [[ -n "${AWVP_R33_REPORT_DIR:-}" ]]; then
    if [[ "$AWVP_R33_REPORT_DIR" != /* ]]; then
        echo 'AWVP_R33_REPORT_DIR must be an absolute path.' >&2
        exit 2
    fi
    case "$AWVP_R33_REPORT_DIR/" in
        "$REPOSITORY_ROOT/"*)
            echo 'AWVP_R33_REPORT_DIR must remain outside the repository checkout.' >&2
            exit 2
            ;;
    esac
    mkdir -p -- "$AWVP_R33_REPORT_DIR"
    REPORT_DIRECTORY="$(cd -- "$AWVP_R33_REPORT_DIR" && pwd -P)"
else
    REPORT_DIRECTORY="$(mktemp -d /tmp/awvp-r33-report.XXXXXX)"
fi

case "$REPORT_DIRECTORY/" in
    "$REPOSITORY_ROOT/"*)
        echo 'AWVP_R33_REPORT_DIR must remain outside the repository checkout.' >&2
        exit 2
        ;;
esac

REPORT_FILE="$REPORT_DIRECTORY/peertube-http-smoke-$RUN_TOKEN.log"
touch -- "$REPORT_FILE"
exec > >(tee -a "$REPORT_FILE") 2>&1

RESULT='FAIL'
DOCKER_READY=0
NETWORK_CREATED=0
VOLUME_CREATED=0
DATABASE_CREATED=0
WORDPRESS_CREATED=0
MOCK_CREATED=0

fail() {
    echo "PEERTUBE_HTTP_SMOKE_ERROR=$*" >&2
    exit 1
}

cleanup() {
    local original_status=$?
    local cleanup_failed=0

    trap - EXIT
    set +e

    if (( DOCKER_READY == 1 )); then
        local container_spec container_created container_name owner_label
        for container_spec in \
            "$MOCK_CREATED|$MOCK_NAME" \
            "$WORDPRESS_CREATED|$WORDPRESS_NAME" \
            "$DATABASE_CREATED|$DATABASE_NAME"; do
            IFS='|' read -r container_created container_name <<<"$container_spec"
            if (( container_created == 1 )); then
                owner_label="$(
                    docker container inspect \
                        --format '{{ index .Config.Labels "org.argentwolf.awvp.test.run" }}' \
                        "$container_name" 2>/dev/null
                )"
                if [[ "$owner_label" != "$RUN_TOKEN" ]]; then
                    echo "REFUSED_UNOWNED_CONTAINER=$container_name" >&2
                    cleanup_failed=1
                    continue
                fi
                docker rm -f "$container_name" >/dev/null 2>&1 || cleanup_failed=1
            fi
        done

        if (( VOLUME_CREATED == 1 )); then
            owner_label="$(
                docker volume inspect \
                    --format '{{ index .Labels "org.argentwolf.awvp.test.run" }}' \
                    "$VOLUME_NAME" 2>/dev/null
            )"
            if [[ "$owner_label" == "$RUN_TOKEN" ]]; then
                docker volume rm -f "$VOLUME_NAME" >/dev/null 2>&1 || cleanup_failed=1
            else
                echo "REFUSED_UNOWNED_VOLUME=$VOLUME_NAME" >&2
                cleanup_failed=1
            fi
        fi

        if (( NETWORK_CREATED == 1 )); then
            owner_label="$(
                docker network inspect \
                    --format '{{ index .Labels "org.argentwolf.awvp.test.run" }}' \
                    "$NETWORK_NAME" 2>/dev/null
            )"
            if [[ "$owner_label" == "$RUN_TOKEN" ]]; then
                docker network rm "$NETWORK_NAME" >/dev/null 2>&1 || cleanup_failed=1
            else
                echo "REFUSED_UNOWNED_NETWORK=$NETWORK_NAME" >&2
                cleanup_failed=1
            fi
        fi
    fi

    if [[ -n "$WORK_DIRECTORY" ]]; then
        case "$WORK_DIRECTORY" in
            /tmp/awvp-r33-work.*)
                rm -rf -- "$WORK_DIRECTORY" || cleanup_failed=1
                ;;
            *)
                cleanup_failed=1
                echo "REFUSED_UNEXPECTED_WORK_DIRECTORY=$WORK_DIRECTORY" >&2
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

    echo "PEERTUBE_HTTP_SMOKE=$RESULT"
    echo "REPORT_FILE=$REPORT_FILE"
    printf 'REPORT_VIEW_COMMAND=cat %q\n' "$REPORT_FILE"
    exit "$original_status"
}

trap cleanup EXIT
trap 'exit 130' INT TERM

WORK_DIRECTORY="$(mktemp -d /tmp/awvp-r33-work.XXXXXX)"
REQUEST_LOG="$WORK_DIRECTORY/requests.log"

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "Required command is unavailable: $1"
}

wait_for_database() {
    local attempt

    for attempt in $(seq 1 60); do
        if docker exec "$DATABASE_NAME" \
            mariadb-admin ping -h127.0.0.1 -uroot "-p$DATABASE_ROOT_PASSWORD" --silent \
            >/dev/null 2>&1; then
            echo 'DATABASE_LOCAL_READY=PASS'
            return 0
        fi
        sleep 1
    done

    docker logs "$DATABASE_NAME" || true
    fail 'MariaDB did not become ready.'
}

wait_for_database_consumer_path() {
    local attempt

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
            echo 'DB_CONSUMER_PATH_READY=PASS'
            return 0
        fi
        sleep 1
    done

    fail 'The WP-CLI image could not reach MariaDB through the case network.'
}

wait_for_wordpress_files() {
    local attempt

    for attempt in $(seq 1 60); do
        if docker exec "$WORDPRESS_NAME" \
            sh -c 'test -f /var/www/html/wp-includes/version.php && test -f /var/www/html/wp-config.php' \
            >/dev/null 2>&1; then
            echo 'WORDPRESS_FILES_READY=PASS'
            return 0
        fi
        sleep 1
    done

    docker logs "$WORDPRESS_NAME" || true
    fail 'WordPress core/config files did not become ready.'
}

wait_for_mock() {
    local attempt

    for attempt in $(seq 1 60); do
        if docker run --rm \
            --network "$NETWORK_NAME" \
            --entrypoint php \
            "$WP_CLI_IMAGE" \
            -r '$body = @file_get_contents("http://peertube.test:9000/health"); exit("ready\n" === $body ? 0 : 1);' \
            >/dev/null 2>&1; then
            echo 'ISOLATED_PEERTUBE_FIXTURE_READY=PASS'
            return 0
        fi
        sleep 1
    done

    docker logs "$MOCK_NAME" || true
    fail 'The isolated PeerTube fixture did not become ready.'
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
        "$WP_CLI_IMAGE" \
        --path=/var/www/html "$@"
}

for command_name in chmod docker git grep id mkdir mktemp openssl rm sed seq tar tee; do
    require_command "$command_name"
done

DATABASE_PASSWORD="awvp-db-$(openssl rand -hex 16)"
DATABASE_ROOT_PASSWORD="awvp-root-$(openssl rand -hex 16)"

[[ -f "$FIXTURE_ROOT/mock-router.php" ]] || fail 'PeerTube mock router fixture is missing.'
[[ -f "$FIXTURE_ROOT/assert-detect.php" ]] || fail 'WordPress assertion fixture is missing.'
[[ -f "$REPOSITORY_ROOT/argentwolf-video-processor.php" ]] || fail 'Plugin bootstrap is missing.'

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

SOURCE_EXPORT="$WORK_DIRECTORY/argentwolf-video-processor"
EXPORTED_FIXTURE_ROOT="$SOURCE_EXPORT/tests/fixtures/peertube-http-smoke"
mkdir -p -- "$SOURCE_EXPORT"
git -C "$REPOSITORY_ROOT" archive --format=tar --output="$WORK_DIRECTORY/source.tar" HEAD
tar -xf "$WORK_DIRECTORY/source.tar" -C "$SOURCE_EXPORT"
rm -f -- "$WORK_DIRECTORY/source.tar"
chmod -R a+rX "$SOURCE_EXPORT"
[[ -f "$EXPORTED_FIXTURE_ROOT/mock-router.php" ]] || fail 'Committed mock fixture was not exported.'
[[ -f "$EXPORTED_FIXTURE_ROOT/assert-detect.php" ]] || fail 'Committed assertion fixture was not exported.'
echo 'SOURCE_EXPORT_FROM_COMMIT=PASS'

for image_reference in "$WORDPRESS_IMAGE" "$WP_CLI_IMAGE" "$DATABASE_IMAGE"; do
    docker pull "$image_reference"
    image_id="$(docker image inspect --format '{{.Id}}' "$image_reference")"
    [[ -n "$image_id" ]] || fail "Could not resolve image identity: $image_reference"
    echo "IMAGE_REFERENCE=$image_reference"
    echo "IMAGE_ID=$image_id"
done

docker network create \
    --internal \
    --label "org.argentwolf.awvp.test.run=$RUN_TOKEN" \
    "$NETWORK_NAME" >/dev/null
NETWORK_CREATED=1
[[ 'true' == "$(docker network inspect --format '{{.Internal}}' "$NETWORK_NAME")" ]] \
    || fail 'Docker did not create an internal-only network.'
echo 'DOCKER_NETWORK_INTERNAL=PASS'

docker volume create \
    --label "org.argentwolf.awvp.test.run=$RUN_TOKEN" \
    "$VOLUME_NAME" >/dev/null
VOLUME_CREATED=1

docker run -d \
    --name "$DATABASE_NAME" \
    --label "org.argentwolf.awvp.test.run=$RUN_TOKEN" \
    --network "$NETWORK_NAME" \
    --network-alias db \
    -e MARIADB_DATABASE=wordpress \
    -e MARIADB_USER=wordpress \
    -e "MARIADB_PASSWORD=$DATABASE_PASSWORD" \
    -e "MARIADB_ROOT_PASSWORD=$DATABASE_ROOT_PASSWORD" \
    "$DATABASE_IMAGE" >/dev/null
DATABASE_CREATED=1

wait_for_database
wait_for_database_consumer_path

printf -v WORDPRESS_CONFIG_EXTRA '%s\n' \
    'define( "WP_DEBUG_LOG", true );' \
    'define( "WP_DEBUG_DISPLAY", false );' \
    'define( "WP_HTTP_BLOCK_EXTERNAL", true );' \
    'define( "WP_ACCESSIBLE_HOSTS", "peertube.test" );' \
    'define( "ARGENT_VIDEO_PEERTUBE_DEV_ORIGINS", array( "http://peertube.test:9000" ) );'

docker run -d \
    --name "$WORDPRESS_NAME" \
    --label "org.argentwolf.awvp.test.run=$RUN_TOKEN" \
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
WORDPRESS_CREATED=1

wait_for_wordpress_files

docker run -d \
    --name "$MOCK_NAME" \
    --label "org.argentwolf.awvp.test.run=$RUN_TOKEN" \
    --network "$NETWORK_NAME" \
    --network-alias peertube.test \
    --user "$(id -u):$(id -g)" \
    --entrypoint php \
    -v "$EXPORTED_FIXTURE_ROOT:/awvp-mock:ro" \
    -v "$WORK_DIRECTORY:/awvp-state" \
    "$WP_CLI_IMAGE" \
    -S 0.0.0.0:9000 /awvp-mock/mock-router.php >/dev/null
MOCK_CREATED=1

wait_for_mock

for container_name in "$DATABASE_NAME" "$WORDPRESS_NAME" "$MOCK_NAME"; do
    if [[ -n "$(docker port "$container_name" 2>/dev/null)" ]]; then
        fail "A disposable container published a host port: $container_name"
    fi
done
echo 'HOST_PORTS_PUBLISHED=NONE'

wp_cli core install \
    --url='http://wp' \
    --title='AWVP R33 isolated HTTP smoke' \
    --admin_user=awvpadmin \
    --admin_password='AWVP-disposable-test-only-123!' \
    --admin_email=awvp@example.invalid \
    --skip-email

wp_cli plugin activate argentwolf-video-processor
wp_cli plugin is-active argentwolf-video-processor
echo 'PLUGIN_ACTIVATION=PASS'
echo "WORDPRESS_VERSION=$(wp_cli core version)"
echo "PHP_VERSION=$(wp_cli eval 'echo PHP_VERSION;')"

wp_cli eval-file \
    /var/www/html/wp-content/plugins/argentwolf-video-processor/tests/fixtures/peertube-http-smoke/assert-detect.php \
    --use-include

[[ -f "$REQUEST_LOG" ]] || fail 'The isolated fixture did not produce a request log.'
CONFIG_REQUEST_COUNT="$(grep -Fxc 'GET /api/v1/config' "$REQUEST_LOG" || true)"
[[ '1' == "$CONFIG_REQUEST_COUNT" ]] \
    || fail "Expected exactly one config request, observed: $CONFIG_REQUEST_COUNT"

if grep -Ev '^(GET /health|GET /api/v1/config)$' "$REQUEST_LOG" >/dev/null; then
    echo '--- ISOLATED FIXTURE REQUEST LOG'
    sed -n '1,120p' "$REQUEST_LOG"
    fail 'The isolated fixture received an unexpected request.'
fi
echo 'ISOLATED_CONFIG_REQUEST_COUNT=1'

if docker exec "$WORDPRESS_NAME" \
    sh -c 'test -f /var/www/html/wp-content/debug.log && grep -E "PHP (Fatal error|Parse error|Warning|Notice|Deprecated)" /var/www/html/wp-content/debug.log' \
    >/dev/null 2>&1; then
    docker exec "$WORDPRESS_NAME" tail -n 120 /var/www/html/wp-content/debug.log || true
    fail 'WP_DEBUG_LOG contains a PHP diagnostic.'
fi
echo 'WP_DEBUG_DIAGNOSTICS=NONE'

RESULT='PASS'
echo 'PEERTUBE_HTTP_CHECKPOINT_ASSERTIONS=PASS'
