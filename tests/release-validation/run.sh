#!/usr/bin/env bash
set -Eeuo pipefail

section() { printf '\n===== %s =====\n' "$1"; }
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

HARNESS_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd -- "$HARNESS_ROOT/../.." && pwd)"
PAYLOAD_REF="${1:-${AWVP_RELEASE_PAYLOAD:-}}"

[[ -n "$PAYLOAD_REF" ]] || fail "Usage: $0 <payload-id|payload-directory>"

if [[ -d "$PAYLOAD_REF" ]]; then
    PAYLOAD_DIR="$(cd "$PAYLOAD_REF" && pwd -P)"
elif [[ -d "$HARNESS_ROOT/payloads/$PAYLOAD_REF" ]]; then
    PAYLOAD_DIR="$(cd "$HARNESS_ROOT/payloads/$PAYLOAD_REF" && pwd -P)"
else
    fail "Payload not found: $PAYLOAD_REF"
fi

case "$PAYLOAD_DIR" in
    "$HARNESS_ROOT"/payloads/*) ;;
    *) fail "Payload must live under $HARNESS_ROOT/payloads" ;;
esac

PAYLOAD_REL="${PAYLOAD_DIR#"$HARNESS_ROOT"/}"
[[ -f "$PAYLOAD_DIR/payload.sh" ]] || fail "Payload definition missing: $PAYLOAD_DIR/payload.sh"

# shellcheck disable=SC1090
source "$PAYLOAD_DIR/payload.sh"

required_scalars=(
    PAYLOAD_ID PLUGIN_SLUG PLUGIN_ROOT PLUGIN_MAIN DB_VERSION_OPTION
    CANDIDATE_ARTIFACT CANDIDATE_SHA256 CANDIDATE_VERSION CANDIDATE_STABLE_TAG
    CANDIDATE_DB_VERSION BASE_ARTIFACT BASE_SHA256 BASE_VERSION BASE_DB_VERSION
    PLUGIN_CHECK_VERSION PLUGIN_CHECK_SHA256 PLUGIN_CHECK_CASE PLUGIN_CHECK_FORMAT
    DEBUG_PATTERN
)
for name in "${required_scalars[@]}"; do
    [[ -n "${!name:-}" ]] || fail "Payload required scalar missing: $name"
done
(( ${#MATRIX[@]} > 0 )) || fail "Payload MATRIX is empty"
(( ${#CLEAN_PHASES[@]} > 0 )) || fail "Payload CLEAN_PHASES is empty"
(( ${#UPGRADE_PRE_PHASES[@]} > 0 )) || fail "Payload UPGRADE_PRE_PHASES is empty"
(( ${#UPGRADE_POST_PHASES[@]} > 0 )) || fail "Payload UPGRADE_POST_PHASES is empty"
(( ${#TEST_ENV[@]} > 0 )) || fail "Payload TEST_ENV is empty"
(( ${#PLUGIN_CHECK_STATIC_MODES[@]} > 0 )) || fail "Payload PLUGIN_CHECK_STATIC_MODES is empty"
(( ${#PLUGIN_CHECK_RUNTIME_MODES[@]} > 0 )) || fail "Payload PLUGIN_CHECK_RUNTIME_MODES is empty"

BUNDLE_ROOT="${BUNDLE_ROOT:-$PROJECT_ROOT}"
ARTIFACT_DIR="${ARTIFACT_DIR:-$BUNDLE_ROOT/artifacts}"
CACHE_DIR="${CACHE_DIR:-$BUNDLE_ROOT/.cache}"
REPORT_DIR="${REPORT_DIR:-$HOME/awvp-vm-test-reports}"
PLUGIN_CHECK_URL="${PLUGIN_CHECK_URL:-https://downloads.wordpress.org/plugin/plugin-check.${PLUGIN_CHECK_VERSION}.zip}"
PLUGIN_CHECK_ZIP="${PLUGIN_CHECK_ZIP:-$CACHE_DIR/plugin-check.${PLUGIN_CHECK_VERSION}.zip}"

CANDIDATE_ZIP="$ARTIFACT_DIR/$CANDIDATE_ARTIFACT"
BASE_ZIP="$ARTIFACT_DIR/$BASE_ARTIFACT"

PAYLOAD_TOKEN="$(printf '%s' "$PAYLOAD_ID" | tr '[:lower:].-' '[:upper:]__' | tr -cd 'A-Z0-9_')"
RUN_ID="awvp-${PAYLOAD_ID}-release-validation-$(date -u +%Y%m%dT%H%M%SZ)-$$"
REPORT="$REPORT_DIR/${RUN_ID}.txt"

mkdir -p "$REPORT_DIR" "$CACHE_DIR"

ACTIVE_CONTAINERS=()
ACTIVE_NETWORKS=()
ACTIVE_VOLUMES=()
CURRENT_CASE="preflight"
CURRENT_PHASE="preflight"

exec > >(tee "$REPORT") 2>&1

cleanup_resources() {
    local name
    for name in "${ACTIVE_CONTAINERS[@]:-}"; do
        docker rm -f "$name" >/dev/null 2>&1 || true
    done
    for name in "${ACTIVE_NETWORKS[@]:-}"; do
        docker network rm "$name" >/dev/null 2>&1 || true
    done
    for name in "${ACTIVE_VOLUMES[@]:-}"; do
        docker volume rm -f "$name" >/dev/null 2>&1 || true
    done
    ACTIVE_CONTAINERS=()
    ACTIVE_NETWORKS=()
    ACTIVE_VOLUMES=()
}

on_exit() {
    local rc=$?
    if [[ "$rc" -ne 0 ]]; then
        echo
        echo "RESULT=AWVP_RELEASE_VALIDATION_FAILED"
        echo "PAYLOAD_RESULT=AWVP_${PAYLOAD_TOKEN}_RELEASE_VALIDATION_FAILED"
        echo "Payload=$PAYLOAD_ID"
        echo "Failed case=$CURRENT_CASE"
        echo "Failed phase=$CURRENT_PHASE"
        echo "Report=$REPORT"
    fi
    cleanup_resources
    exit "$rc"
}
trap on_exit EXIT

for command in docker sha256sum unzip openssl sed grep awk tr; do
    command -v "$command" >/dev/null 2>&1 || fail "Required host command missing: $command"
done
docker info >/dev/null 2>&1 || fail "Docker daemon is not accessible"

section "PAYLOAD CONTRACT"
echo "payload_id=$PAYLOAD_ID"
echo "payload_dir=$PAYLOAD_DIR"
echo "upgrade_pre_phases=${UPGRADE_PRE_PHASES[*]}"
echo "upgrade_post_phases=${UPGRADE_POST_PHASES[*]}"
echo "clean_phases=${CLEAN_PHASES[*]}"
echo "plugin_check_format=$PLUGIN_CHECK_FORMAT"
echo "plugin_check_static_modes=${PLUGIN_CHECK_STATIC_MODES[*]:-}"
echo "plugin_check_runtime_modes=${PLUGIN_CHECK_RUNTIME_MODES[*]:-}"

for phase in \
    "${UPGRADE_PRE_PHASES[@]}" \
    "${UPGRADE_POST_PHASES[@]}" \
    "${CLEAN_PHASES[@]}"; do
    [[ "$phase" != */* ]] || fail "Payload phase must be a basename: $phase"
    [[ -f "$PAYLOAD_DIR/$phase" ]] || fail "Payload phase missing: $phase"
done

[[ -f "$CANDIDATE_ZIP" ]] || fail "Candidate ZIP missing: $CANDIDATE_ZIP"
[[ -f "$BASE_ZIP" ]] || fail "Upgrade-base ZIP missing: $BASE_ZIP"

section "ARTIFACT AUTHORITY"
candidate_sha="$(sha256sum "$CANDIDATE_ZIP" | awk '{print $1}')"
base_sha="$(sha256sum "$BASE_ZIP" | awk '{print $1}')"
echo "candidate_zip=$CANDIDATE_ZIP"
echo "candidate_sha256=$candidate_sha"
echo "base_zip=$BASE_ZIP"
echo "base_sha256=$base_sha"
[[ "$candidate_sha" == "$CANDIDATE_SHA256" ]] || fail "Candidate SHA-256 mismatch"
[[ "$base_sha" == "$BASE_SHA256" ]] || fail "Upgrade-base SHA-256 mismatch"

unzip -t "$CANDIDATE_ZIP" >/dev/null
unzip -t "$BASE_ZIP" >/dev/null

candidate_version="$(
    unzip -p "$CANDIDATE_ZIP" "$PLUGIN_ROOT/$PLUGIN_MAIN" |
        sed -nE 's/^ \* Version:[[:space:]]*([^[:space:]]+).*/\1/p' |
        head -1
)"
base_version="$(
    unzip -p "$BASE_ZIP" "$PLUGIN_ROOT/$PLUGIN_MAIN" |
        sed -nE 's/^ \* Version:[[:space:]]*([^[:space:]]+).*/\1/p' |
        head -1
)"
candidate_stable="$(
    unzip -p "$CANDIDATE_ZIP" "$PLUGIN_ROOT/readme.txt" |
        sed -nE 's/^Stable tag:[[:space:]]*([^[:space:]]+).*/\1/p' |
        head -1
)"

echo "candidate_version=$candidate_version"
echo "candidate_stable_tag=$candidate_stable"
echo "base_version=$base_version"
[[ "$candidate_version" == "$CANDIDATE_VERSION" ]] || fail "Candidate plugin header mismatch"
[[ "$candidate_stable" == "$CANDIDATE_STABLE_TAG" ]] || fail "Candidate Stable Tag mismatch"
[[ "$base_version" == "$BASE_VERSION" ]] || fail "Upgrade-base plugin header mismatch"

if unzip -Z1 "$CANDIDATE_ZIP" |
    grep -Eq '(^|/)(AGENTS[^/]*\.md|tests/|README\.md|ARCHITECTURE\.md|TODO\.md|CHANGELOG\.md|wordpress-development\.md)'; then
    fail "Candidate unexpectedly contains repository-only docs/tests"
fi
echo "candidate_repository_only_exclusions=PASS"

section "PLUGIN CHECK PACKAGE"
if [[ ! -f "$PLUGIN_CHECK_ZIP" ]]; then
    if command -v curl >/dev/null 2>&1; then
        curl -fL --retry 3 --connect-timeout 15 \
            -o "$PLUGIN_CHECK_ZIP.tmp" "$PLUGIN_CHECK_URL"
    elif command -v wget >/dev/null 2>&1; then
        wget -O "$PLUGIN_CHECK_ZIP.tmp" "$PLUGIN_CHECK_URL"
    else
        fail "Need curl or wget to retrieve Plugin Check ${PLUGIN_CHECK_VERSION}"
    fi
    mv "$PLUGIN_CHECK_ZIP.tmp" "$PLUGIN_CHECK_ZIP"
fi

unzip -t "$PLUGIN_CHECK_ZIP" >/dev/null
plugin_check_sha="$(sha256sum "$PLUGIN_CHECK_ZIP" | awk '{print $1}')"
plugin_check_version="$(
    unzip -p "$PLUGIN_CHECK_ZIP" plugin-check/plugin.php |
        sed -nE 's/^ \* Version:[[:space:]]*([^[:space:]]+).*/\1/p' |
        head -1
)"
echo "plugin_check_url=$PLUGIN_CHECK_URL"
echo "plugin_check_zip=$PLUGIN_CHECK_ZIP"
echo "plugin_check_sha256=$plugin_check_sha"
echo "plugin_check_version=$plugin_check_version"
[[ "$plugin_check_version" == "$PLUGIN_CHECK_VERSION" ]] || fail "Plugin Check version mismatch"
[[ "$plugin_check_sha" == "$PLUGIN_CHECK_SHA256" ]] || fail "Plugin Check SHA-256 mismatch"

section "HOST / DOCKER"
uname -a
docker version
docker info --format 'DockerRootDir={{.DockerRootDir}} Driver={{.Driver}} CgroupVersion={{.CgroupVersion}}'
echo "run_id=$RUN_ID"
echo "bundle_root=$BUNDLE_ROOT"
echo "report=$REPORT"

section "PULL PINNED TEST IMAGES"
declare -A pulled=()
for entry in "${MATRIX[@]}"; do
    IFS='|' read -r label wp_image cli_image db_image db_kind <<<"$entry"
    [[ -n "$label" && -n "$wp_image" && -n "$cli_image" && -n "$db_image" && -n "$db_kind" ]] \
        || fail "Malformed MATRIX entry: $entry"
    for image in "$wp_image" "$cli_image" "$db_image"; do
        if [[ -z "${pulled[$image]:-}" ]]; then
            echo "--- docker pull $image"
            docker pull "$image"
            docker image inspect \
                --format='id={{.Id}} repo_digests={{json .RepoDigests}}' \
                "$image"
            pulled[$image]=1
        fi
    done
done

IFS='|' read -r _lint_label _lint_wp LINT_CLI_IMAGE _lint_db _lint_kind <<<"${MATRIX[0]}"

section "HARNESS SYNTAX"
bash -n "$0"
bash -n "$PAYLOAD_DIR/payload.sh"
for php_file in "$HARNESS_ROOT"/php/*.php "$PAYLOAD_DIR"/*.php; do
    docker run --rm \
        --entrypoint php \
        -v "$HARNESS_ROOT:/awvp-test:ro" \
        "$LINT_CLI_IMAGE" \
        -l "/awvp-test/${php_file#"$HARNESS_ROOT"/}"
done
echo "HARNESS_SYNTAX=PASS"

wait_for_db_local() {
    local db_name="$1"
    local db_kind="$2"
    local root_password="$3"
    local attempt

    for attempt in $(seq 1 90); do
        if [[ "$db_kind" == "mariadb" ]]; then
            if docker exec "$db_name" \
                mariadb-admin ping -h127.0.0.1 -uroot "-p${root_password}" --silent \
                >/dev/null 2>&1; then
                echo "DB_LOCAL_READY=PASS"
                return 0
            fi
        else
            if docker exec "$db_name" \
                mysqladmin ping -h127.0.0.1 -uroot "-p${root_password}" --silent \
                >/dev/null 2>&1; then
                echo "DB_LOCAL_READY=PASS"
                return 0
            fi
        fi
        sleep 1
    done

    docker logs "$db_name" || true
    fail "Database did not become locally ready: $db_name"
}

wait_for_db_from_cli() {
    local network="$1"
    local cli_image="$2"
    local db_name="$3"
    local db_password="$4"
    local attempt

    for attempt in $(seq 1 90); do
        if docker run --rm \
            --network "$network" \
            --entrypoint php \
            -e AWVP_DB_HOST=db \
            -e AWVP_DB_USER=wordpress \
            -e "AWVP_DB_PASSWORD=$db_password" \
            -e AWVP_DB_NAME=wordpress \
            "$cli_image" \
            -r '
                mysqli_report(MYSQLI_REPORT_OFF);
                $db = mysqli_init();
                if (
                    false === $db
                    || ! @$db->real_connect(
                        getenv("AWVP_DB_HOST"),
                        getenv("AWVP_DB_USER"),
                        getenv("AWVP_DB_PASSWORD"),
                        getenv("AWVP_DB_NAME"),
                        3306
                    )
                ) {
                    exit(1);
                }
                $result = @$db->query("SELECT 1");
                $db->close();
                exit(false === $result ? 1 : 0);
            ' >/dev/null 2>&1; then
            echo "DB_CONSUMER_PATH_READY=PASS"
            return 0
        fi
        sleep 1
    done

    echo "--- database logs after consumer-path readiness failure"
    docker logs "$db_name" || true
    fail "WP-CLI consumer path could not resolve/authenticate/query database via alias db"
}

wait_for_wp_files() {
    local wp_name="$1"
    local attempt

    for attempt in $(seq 1 90); do
        if docker exec "$wp_name" \
            sh -c 'test -f /var/www/html/wp-includes/version.php && test -f /var/www/html/wp-config.php' \
            >/dev/null 2>&1; then
            echo "WORDPRESS_FILES_READY=PASS"
            return 0
        fi
        sleep 1
    done

    docker logs "$wp_name" || true
    fail "WordPress container did not initialize core/config files: $wp_name"
}

run_case() {
    local matrix_label="$1"
    local mode="$2"
    local wp_image="$3"
    local cli_image="$4"
    local db_image="$5"
    local db_kind="$6"

    CURRENT_CASE="${matrix_label}-${mode}"
    CURRENT_PHASE="case-setup"
    section "CASE $CURRENT_CASE"

    local suffix network db_name wp_name wp_volume db_password root_password
    suffix="$(printf '%s-%s' "$RUN_ID" "$CURRENT_CASE" | tr -cd '[:alnum:]_.-')"
    network="${suffix}-net"
    db_name="${suffix}-db"
    wp_name="${suffix}-wp"
    wp_volume="${suffix}-wpdata"
    db_password="$(openssl rand -hex 16)"
    root_password="$(openssl rand -hex 16)"

    docker network create --internal "$network" >/dev/null
    ACTIVE_NETWORKS+=("$network")
    docker volume create "$wp_volume" >/dev/null
    ACTIVE_VOLUMES+=("$wp_volume")

    if [[ "$db_kind" == "mariadb" ]]; then
        docker run -d \
            --name "$db_name" \
            --network "$network" \
            --network-alias db \
            -e MARIADB_DATABASE=wordpress \
            -e MARIADB_USER=wordpress \
            -e "MARIADB_PASSWORD=$db_password" \
            -e "MARIADB_ROOT_PASSWORD=$root_password" \
            "$db_image" >/dev/null
    elif [[ "$db_kind" == "mysql" ]]; then
        docker run -d \
            --name "$db_name" \
            --network "$network" \
            --network-alias db \
            -e MYSQL_DATABASE=wordpress \
            -e MYSQL_USER=wordpress \
            -e "MYSQL_PASSWORD=$db_password" \
            -e "MYSQL_ROOT_PASSWORD=$root_password" \
            "$db_image" >/dev/null
    else
        fail "Unknown database kind: $db_kind"
    fi
    ACTIVE_CONTAINERS+=("$db_name")

    wait_for_db_local "$db_name" "$db_kind" "$root_password"
    wait_for_db_from_cli "$network" "$cli_image" "$db_name" "$db_password"

    local config_extra
    config_extra="$(printf '%s\n' \
        'define("WP_DEBUG_LOG", true);' \
        'define("WP_DEBUG_DISPLAY", false);' \
        'define("WP_HTTP_BLOCK_EXTERNAL", true);')"

    docker run -d \
        --name "$wp_name" \
        --network "$network" \
        --network-alias wp \
        -v "$wp_volume:/var/www/html" \
        -e WORDPRESS_DB_HOST=db:3306 \
        -e WORDPRESS_DB_USER=wordpress \
        -e "WORDPRESS_DB_PASSWORD=$db_password" \
        -e WORDPRESS_DB_NAME=wordpress \
        -e WORDPRESS_DEBUG=1 \
        -e "WORDPRESS_CONFIG_EXTRA=$config_extra" \
        "$wp_image" >/dev/null
    ACTIVE_CONTAINERS+=("$wp_name")

    wait_for_wp_files "$wp_name"

    if [[ -n "$(docker port "$wp_name" 2>/dev/null)" || -n "$(docker port "$db_name" 2>/dev/null)" ]]; then
        fail "Disposable case unexpectedly published host ports"
    fi

    wp_cli() {
        local env_args=()
        local item
        for item in "${TEST_ENV[@]:-}"; do
            env_args+=(-e "$item")
        done

        docker run --rm \
            --network "$network" \
            --volumes-from "$wp_name" \
            -v "$ARTIFACT_DIR:/awvp-artifacts:ro" \
            -v "$HARNESS_ROOT:/awvp-test:ro" \
            -v "$(dirname "$PLUGIN_CHECK_ZIP"):/awvp-plugin-check:ro" \
            --user 33:33 \
            -e WORDPRESS_DB_HOST=db:3306 \
            -e WORDPRESS_DB_USER=wordpress \
            -e "WORDPRESS_DB_PASSWORD=$db_password" \
            -e WORDPRESS_DB_NAME=wordpress \
            "${env_args[@]}" \
            "$cli_image" \
            --path=/var/www/html "$@"
    }

    run_payload_phase() {
        local phase="$1"
        CURRENT_PHASE="$phase"
        echo "--- PAYLOAD PHASE $phase"
        wp_cli eval-file "/awvp-test/$PAYLOAD_REL/$phase" --use-include
        echo "PAYLOAD_PHASE_RESULT=$phase PASS"
    }

    verify_installed_package() {
        local artifact="$1"
        local expected_sha="$2"
        local expected_version="$3"

        CURRENT_PHASE="verify-installed-package-$expected_version"
        echo "--- VERIFY INSTALLED PACKAGE IDENTITY version=$expected_version"

        docker run --rm \
            --network "$network" \
            --volumes-from "$wp_name" \
            -v "$ARTIFACT_DIR:/awvp-artifacts:ro" \
            -v "$HARNESS_ROOT:/awvp-test:ro" \
            --user 33:33 \
            -e WORDPRESS_DB_HOST=db:3306 \
            -e WORDPRESS_DB_USER=wordpress \
            -e "WORDPRESS_DB_PASSWORD=$db_password" \
            -e WORDPRESS_DB_NAME=wordpress \
            -e "AWVP_RELEASE_PACKAGE_ZIP=/awvp-artifacts/$artifact" \
            -e "AWVP_RELEASE_PACKAGE_SHA256=$expected_sha" \
            -e "AWVP_RELEASE_PACKAGE_VERSION=$expected_version" \
            -e "AWVP_RELEASE_PLUGIN_MAIN=$PLUGIN_MAIN" \
            -e "AWVP_RELEASE_PLUGIN_DIR=/var/www/html/wp-content/plugins/$PLUGIN_SLUG" \
            "$cli_image" \
            --path=/var/www/html \
            eval-file /awvp-test/php/assert-installed-package.php --use-include
    }

    CURRENT_PHASE="core-install"
    wp_cli core install \
        --url="http://wp" \
        --title="AWVP release validation $CURRENT_CASE" \
        --admin_user=awvpadmin \
        --admin_password='AWVP-disposable-test-only-123!' \
        --admin_email=awvp@example.invalid \
        --skip-email

    echo "wordpress_version=$(wp_cli core version)"
    echo "php_version=$(wp_cli eval 'echo PHP_VERSION;')"
    echo "database_server=$(wp_cli eval 'global $wpdb; echo $wpdb->db_server_info();')"
    echo "database_version=$(wp_cli eval 'global $wpdb; echo $wpdb->db_version();')"

    if [[ "$mode" == "upgrade" ]]; then
        CURRENT_PHASE="install-base"
        wp_cli plugin install "/awvp-artifacts/$BASE_ARTIFACT" --activate
        wp_cli plugin is-active "$PLUGIN_SLUG"
        verify_installed_package "$BASE_ARTIFACT" "$BASE_SHA256" "$BASE_VERSION"

        for phase in "${UPGRADE_PRE_PHASES[@]}"; do
            run_payload_phase "$phase"
        done

        CURRENT_PHASE="install-candidate"
        wp_cli plugin install "/awvp-artifacts/$CANDIDATE_ARTIFACT" --force

        # A new WP-CLI bootstrap loads the active candidate and runs normal
        # plugin upgrade hooks before post-upgrade phases execute.
        wp_cli plugin is-active "$PLUGIN_SLUG"
        verify_installed_package "$CANDIDATE_ARTIFACT" "$CANDIDATE_SHA256" "$CANDIDATE_VERSION"

        for phase in "${UPGRADE_POST_PHASES[@]}"; do
            run_payload_phase "$phase"
        done
    elif [[ "$mode" == "clean" ]]; then
        CURRENT_PHASE="install-candidate"
        wp_cli plugin install "/awvp-artifacts/$CANDIDATE_ARTIFACT" --activate
        wp_cli plugin is-active "$PLUGIN_SLUG"
        verify_installed_package "$CANDIDATE_ARTIFACT" "$CANDIDATE_SHA256" "$CANDIDATE_VERSION"

        for phase in "${CLEAN_PHASES[@]}"; do
            run_payload_phase "$phase"
        done
    elif [[ "$mode" == "plugin-check" ]]; then
        CURRENT_PHASE="plugin-check-install"
        wp_cli plugin install "/awvp-artifacts/$CANDIDATE_ARTIFACT" --activate
        wp_cli plugin is-active "$PLUGIN_SLUG"
        verify_installed_package "$CANDIDATE_ARTIFACT" "$CANDIDATE_SHA256" "$CANDIDATE_VERSION"

        wp_cli plugin install "/awvp-plugin-check/$(basename "$PLUGIN_CHECK_ZIP")" --activate
        wp_cli plugin is-active plugin-check
        wp_cli plugin get plugin-check --fields=name,version,status --format=table

        local check_mode
        for check_mode in "${PLUGIN_CHECK_STATIC_MODES[@]:-}"; do
            CURRENT_PHASE="plugin-check-static-$check_mode"
            echo "--- Plugin Check static installed-exact-package mode=$check_mode"
            wp_cli plugin check "$PLUGIN_SLUG" \
                --mode="$check_mode" \
                --format="$PLUGIN_CHECK_FORMAT"
        done

        for check_mode in "${PLUGIN_CHECK_RUNTIME_MODES[@]:-}"; do
            CURRENT_PHASE="plugin-check-runtime-$check_mode"
            echo "--- Plugin Check installed/runtime mode=$check_mode"
            wp_cli plugin check "$PLUGIN_SLUG" \
                --mode="$check_mode" \
                --format=table \
                --require=/var/www/html/wp-content/plugins/plugin-check/cli.php
        done
    else
        fail "Unknown case mode: $mode"
    fi

    CURRENT_PHASE="final-state"
    echo "--- final plugin state"
    wp_cli plugin status "$PLUGIN_SLUG"
    echo "--- final AWVP DB version"
    wp_cli option get "$DB_VERSION_OPTION"
    echo "--- final WordPress table list"
    wp_cli eval '
        global $wpdb;
        foreach ($wpdb->get_col("SHOW TABLES") as $table) {
            echo $table . "\n";
        }
    '

    local debug_log
    debug_log="$(
        docker exec "$wp_name" sh -c \
            'test -f /var/www/html/wp-content/debug.log && cat /var/www/html/wp-content/debug.log || true'
    )"
    if [[ -n "$debug_log" ]]; then
        echo "--- wp-content/debug.log"
        printf '%s\n' "$debug_log"

        if printf '%s\n' "$debug_log" |
            grep -Ei '(PHP (Warning|Fatal|Deprecated|Notice)|doing_it_wrong|WordPress database error)' |
            grep -Ei "$DEBUG_PATTERN"; then
            fail "Plugin-related WP_DEBUG/database diagnostics detected in $CURRENT_CASE"
        fi
    fi

    echo "CASE_RESULT=$CURRENT_CASE PASS"

    docker rm -f "$wp_name" "$db_name" >/dev/null
    docker network rm "$network" >/dev/null
    docker volume rm -f "$wp_volume" >/dev/null
    ACTIVE_CONTAINERS=()
    ACTIVE_NETWORKS=()
    ACTIVE_VOLUMES=()
    CURRENT_PHASE="case-complete"
}

plugin_entry=""
for entry in "${MATRIX[@]}"; do
    IFS='|' read -r label _wp _cli _db _kind <<<"$entry"
    if [[ "$label" == "$PLUGIN_CHECK_CASE" ]]; then
        plugin_entry="$entry"
        break
    fi
done
[[ -n "$plugin_entry" ]] || fail "PLUGIN_CHECK_CASE is not present in MATRIX: $PLUGIN_CHECK_CASE"

IFS='|' read -r pc_label pc_wp pc_cli pc_db pc_kind <<<"$plugin_entry"
run_case "$pc_label" "plugin-check" "$pc_wp" "$pc_cli" "$pc_db" "$pc_kind"

for entry in "${MATRIX[@]}"; do
    IFS='|' read -r label wp_image cli_image db_image db_kind <<<"$entry"
    run_case "$label" "upgrade" "$wp_image" "$cli_image" "$db_image" "$db_kind"
    run_case "$label" "clean" "$wp_image" "$cli_image" "$db_image" "$db_kind"
done

CURRENT_CASE="complete"
CURRENT_PHASE="complete"

section "RESULT"
echo "RESULT=AWVP_RELEASE_VALIDATION_PASS"
echo "PAYLOAD_RESULT=AWVP_${PAYLOAD_TOKEN}_RELEASE_VALIDATION_PASS"
echo "Payload=$PAYLOAD_ID"
echo "Candidate version=$CANDIDATE_VERSION"
echo "Candidate SHA256=$CANDIDATE_SHA256"
echo "Upgrade base version=$BASE_VERSION"
echo "Upgrade base SHA256=$BASE_SHA256"
echo "Plugin Check version=$PLUGIN_CHECK_VERSION"
echo "Plugin Check SHA256=$plugin_check_sha"
echo "Plugin Check cases=1"
echo "Matrix cases=$((${#MATRIX[@]} * 2))"
echo "Upgrade fixtures=${#MATRIX[@]}"
echo "Clean activation fixtures=${#MATRIX[@]}"
echo "WordPress runtime networks=INTERNAL"
echo "DB consumer-path readiness=REQUIRED_AND_PASSED"
echo "Installed package byte identity=REQUIRED_AND_PASSED"
echo "Plugin Check target=INSTALLED_EXACT_PACKAGE_SLUG"
echo "WP_HTTP_BLOCK_EXTERNAL=TRUE"
echo "Host ports published=NO"
echo "PeerTube/API action=NO"
echo "Production WordPress action=NO"
echo "Report=$REPORT"

trap - EXIT
cleanup_resources
