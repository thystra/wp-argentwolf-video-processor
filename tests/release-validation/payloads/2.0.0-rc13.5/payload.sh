# AWVP release-validation payload: 2.0.0-rc13.5
# Sourced by tests/release-validation/run.sh.
#
# The RC candidate hash is intentionally operator-pinned at invocation time
# until the exact canonical Forgejo artifact is selected. The harness refuses
# to run when AWVP_RC_CANDIDATE_SHA256 is absent.

PAYLOAD_ID="2.0.0-rc13.5"
PLUGIN_SLUG="argentwolf-video-processor"
PLUGIN_ROOT="argentwolf-video-processor"
PLUGIN_MAIN="argentwolf-video-processor.php"
DB_VERSION_OPTION="argent_video_processor_db_version"

CANDIDATE_ARTIFACT="${AWVP_RC_CANDIDATE_ARTIFACT:-argentwolf-video-processor-2.0.0-rc13.5.zip}"
CANDIDATE_SHA256="${AWVP_RC_CANDIDATE_SHA256:-}"
CANDIDATE_VERSION="2.0.0-rc13.5"
CANDIDATE_STABLE_TAG="1.0.0"
CANDIDATE_DB_VERSION="2"
CANDIDATE_MODEL_DB_VERSION="3"

BASE_ARTIFACT="argentwolf-video-processor-1.0.0.zip"
BASE_SHA256="7bbafd11c4d1f2805cfe66bb448ddac656eecc8bb2d2d12adf23a7173225468e"
BASE_VERSION="1.0.0"
BASE_DB_VERSION="2"

PLUGIN_CHECK_VERSION="2.1.0"
PLUGIN_CHECK_SHA256="6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4"
PLUGIN_CHECK_CASE="wp71-mariadb1011"
PLUGIN_CHECK_FORMAT="strict-table"
PLUGIN_CHECK_STATIC_MODES=(new)
PLUGIN_CHECK_RUNTIME_MODES=(new update)
# The RC is intentionally not the WordPress.org stable tag. Plugin Check treats
# that prerelease-only state as an error; all other ERROR/WARNING findings remain
# release-blocking. Final 2.0.0 validation must remove this allowance.
PLUGIN_CHECK_ALLOWED_CODES=(stable_tag_mismatch)

DEBUG_PATTERN='argentwolf-video-processor|ArgentVideo|argent_video_|argentwolf_video_processor'

WP64_IMAGE="wordpress:6.4.2-php8.1-apache@sha256:edb987c81a75daa2cde1520b307ef7b8490864301468b564cdb61b58f920dc1c"
CLI81_IMAGE="wordpress:cli-php8.1@sha256:ab5fb76caa861f32c21e1d95a057f52007f4af7130fb16a0f68874dabe0549a4"
MDB106_IMAGE="mariadb:10.6.27@sha256:4066a44f4a0143c310fbe6972c254bbbb7a844a2be1418831a987fdbbc8ff8bd"
WP71_IMAGE="wordpress:7.1.0-php8.3-apache@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf"
CLI83_IMAGE="wordpress:cli-php8.3@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586"
MDB1011_IMAGE="mariadb:10.11.18@sha256:de61fed4a40d3842f3ee09944ba52792156cfd9adf489b2cc670fc6ded28df8d"
MYSQL80_IMAGE="mysql:8.0@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b"

MATRIX=(
    "wp64-mariadb106|$WP64_IMAGE|$CLI81_IMAGE|$MDB106_IMAGE|mariadb"
    "wp71-mariadb1011|$WP71_IMAGE|$CLI83_IMAGE|$MDB1011_IMAGE|mariadb"
    "wp71-mysql80|$WP71_IMAGE|$CLI83_IMAGE|$MYSQL80_IMAGE|mysql"
)

UPGRADE_PRE_PHASES=(
    seed-upgrade.php
)

UPGRADE_POST_PHASES=(
    assert-upgrade.php
    assert-legacy-migration.php
    assert-new-block.php
    assert-diagnostics.php
    assert-livefix.php
    assert-repeat-repair.php
)

CLEAN_PHASES=(
    assert-clean.php
    assert-new-block.php
    assert-diagnostics.php
    assert-livefix.php
    assert-repeat-repair.php
)

UNINSTALL_PHASES=(
    assert-uninstall.php
)

TEST_ENV=(
    "AWVP_TEST_CANDIDATE_VERSION=$CANDIDATE_VERSION"
    "AWVP_TEST_BASE_VERSION=$BASE_VERSION"
    "AWVP_TEST_CANDIDATE_DB_VERSION=$CANDIDATE_DB_VERSION"
    "AWVP_TEST_CANDIDATE_MODEL_DB_VERSION=$CANDIDATE_MODEL_DB_VERSION"
    "AWVP_TEST_BASE_DB_VERSION=$BASE_DB_VERSION"
    "AWVP_TEST_SUCCESS_RETENTION=10"
    "AWVP_TEST_ERROR_RETENTION=100"
)
