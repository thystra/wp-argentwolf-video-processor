# AWVP release-validation payload: 0.3.2
# Sourced by tests/release-validation/run.sh.

PAYLOAD_ID="0.3.2"
PLUGIN_SLUG="argentwolf-video-processor"
PLUGIN_ROOT="argentwolf-video-processor"
PLUGIN_MAIN="argentwolf-video-processor.php"
DB_VERSION_OPTION="argent_video_processor_db_version"

CANDIDATE_ARTIFACT="argentwolf-video-processor-0.3.2.zip"
CANDIDATE_SHA256="14053ff6eec6b6187e3c68f93816c1a7628d08868b36bf8c475c83583763258c"
CANDIDATE_VERSION="0.3.2"
CANDIDATE_STABLE_TAG="0.3.2"
CANDIDATE_DB_VERSION="2"

BASE_ARTIFACT="argentwolf-video-processor-0.3.1.zip"
BASE_SHA256="abd1612df9c6d55a1959a6108d82b36a98affbeb674009b4ef34ebcd6e7203b5"
BASE_VERSION="0.3.1"
BASE_DB_VERSION="1"

PLUGIN_CHECK_VERSION="2.1.0"
PLUGIN_CHECK_SHA256="6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4"
PLUGIN_CHECK_CASE="wp70-mariadb1011"
PLUGIN_CHECK_FORMAT="strict-table"
PLUGIN_CHECK_STATIC_MODES=(new)
PLUGIN_CHECK_RUNTIME_MODES=(new update)

DEBUG_PATTERN='argentwolf-video-processor|ArgentVideo|argent_video_|argentwolf_video_processor'

WP64_IMAGE="wordpress:6.4.2-php8.1-apache@sha256:edb987c81a75daa2cde1520b307ef7b8490864301468b564cdb61b58f920dc1c"
CLI81_IMAGE="wordpress:cli-php8.1@sha256:ab5fb76caa861f32c21e1d95a057f52007f4af7130fb16a0f68874dabe0549a4"
MDB106_IMAGE="mariadb:10.6.27@sha256:4066a44f4a0143c310fbe6972c254bbbb7a844a2be1418831a987fdbbc8ff8bd"
WP70_IMAGE="wordpress:7.0.2-php8.3-apache@sha256:b2d7e3153c8a96f90305a3102fb6439335237fb1a9655b617d15c5168ce2f7a3"
CLI83_IMAGE="wordpress:cli-php8.3@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586"
MDB1011_IMAGE="mariadb:10.11.18@sha256:de61fed4a40d3842f3ee09944ba52792156cfd9adf489b2cc670fc6ded28df8d"
MYSQL80_IMAGE="mysql:8.0@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b"

MATRIX=(
    "wp64-mariadb106|$WP64_IMAGE|$CLI81_IMAGE|$MDB106_IMAGE|mariadb"
    "wp70-mariadb1011|$WP70_IMAGE|$CLI83_IMAGE|$MDB1011_IMAGE|mariadb"
    "wp70-mysql80|$WP70_IMAGE|$CLI83_IMAGE|$MYSQL80_IMAGE|mysql"
)

UPGRADE_PRE_PHASES=(
    seed-upgrade.php
)

UPGRADE_POST_PHASES=(
    assert-upgrade.php
    assert-diagnostics.php
    assert-repeat-repair.php
)

CLEAN_PHASES=(
    assert-clean.php
    assert-diagnostics.php
    assert-repeat-repair.php
)

TEST_ENV=(
    "AWVP_TEST_CANDIDATE_VERSION=$CANDIDATE_VERSION"
    "AWVP_TEST_BASE_VERSION=$BASE_VERSION"
    "AWVP_TEST_CANDIDATE_DB_VERSION=$CANDIDATE_DB_VERSION"
    "AWVP_TEST_BASE_DB_VERSION=$BASE_DB_VERSION"
    "AWVP_TEST_SUCCESS_RETENTION=10"
    "AWVP_TEST_ERROR_RETENTION=100"
)
