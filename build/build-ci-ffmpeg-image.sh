#!/usr/bin/env bash
set -euo pipefail

mode="${1:-build}"
case "${mode}" in
    build|--push) ;;
    *)
        printf 'Usage: %s [build|--push]\n' "$0" >&2
        exit 2
        ;;
esac

for command in docker git; do
    command -v "${command}" >/dev/null 2>&1 || {
        printf 'ERROR: required command is missing: %s\n' "${command}" >&2
        exit 1
    }
done

if [[ -n "$(git status --porcelain)" ]]; then
    printf 'ERROR: build the CI image only from a clean committed repository tree.\n' >&2
    git status --short >&2
    exit 1
fi

repository="${AWVP_CI_IMAGE_REPOSITORY:-forgejo.argentwolf.org/alan/wp-argentwolf-video-processor/ci-ffmpeg}"
tag="${AWVP_CI_IMAGE_TAG:-9.0.1-bookworm-v1}"
ffmpeg_version="${AWVP_CI_FFMPEG_VERSION:-9.0.1}"
build_jobs="${AWVP_CI_FFMPEG_BUILD_JOBS:-4}"
base_tag="${AWVP_CI_BASE_IMAGE:-node:24-bookworm-slim}"
source_revision="$(git rev-parse HEAD)"
image="${repository}:${tag}"

printf 'Pulling CI base image: %s\n' "${base_tag}"
docker pull "${base_tag}"
base_digest_ref="$(
    docker image inspect \
        --format '{{index .RepoDigests 0}}' \
        "${base_tag}"
)"
if [[ -z "${base_digest_ref}" || "${base_digest_ref}" != *@sha256:* ]]; then
    printf 'ERROR: could not resolve an immutable base-image digest for %s.\n' "${base_tag}" >&2
    exit 1
fi

printf 'Building AWVP CI image\n'
printf '  source revision: %s\n' "${source_revision}"
printf '  base image:      %s\n' "${base_digest_ref}"
printf '  FFmpeg:          %s\n' "${ffmpeg_version}"
printf '  output image:    %s\n' "${image}"

docker build \
    --file build/ci/ffmpeg/Dockerfile \
    --build-arg "AWVP_CI_BASE_IMAGE=${base_digest_ref}" \
    --build-arg "BASE_IMAGE_REFERENCE=${base_digest_ref}" \
    --build-arg "SOURCE_REVISION=${source_revision}" \
    --build-arg "FFMPEG_VERSION=${ffmpeg_version}" \
    --build-arg "FFMPEG_BUILD_JOBS=${build_jobs}" \
    --tag "${image}" \
    .

docker run --rm "${image}" awvp-verify-ci-image

if [[ "${mode}" == '--push' ]]; then
    printf 'Publishing immutable image tag: %s\n' "${image}"
    docker push "${image}"
    docker pull "${image}"
    printf 'Published image digest(s):\n'
    docker image inspect \
        --format '{{range .RepoDigests}}{{println .}}{{end}}' \
        "${image}"
else
    printf 'CI_IMAGE_BUILD=PASS\n'
    printf 'Re-run with --push after review and docker login to publish this tag.\n'
fi
