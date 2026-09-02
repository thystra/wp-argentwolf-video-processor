#!/usr/bin/env bash
set -euo pipefail

version="${ARGENT_VIDEO_CI_FFMPEG_VERSION:-9.0.1}"
ffmpeg="${ARGENT_VIDEO_TEST_FFMPEG:-/opt/awvp-ffmpeg/bin/ffmpeg}"
ffprobe="${ARGENT_VIDEO_TEST_FFPROBE:-/opt/awvp-ffmpeg/bin/ffprobe}"

for command in awk grep ldd php node npm git curl rsync sed unzip zip; do
    command -v "${command}" >/dev/null 2>&1 || {
        printf 'ERROR: required CI command is missing: %s\n' "${command}" >&2
        exit 1
    }
done

[[ -x "${ffmpeg}" ]] || { printf 'ERROR: FFmpeg is missing: %s\n' "${ffmpeg}" >&2; exit 1; }
[[ -x "${ffprobe}" ]] || { printf 'ERROR: FFprobe is missing: %s\n' "${ffprobe}" >&2; exit 1; }

ffmpeg_version_line="$("${ffmpeg}" -version | sed -n '1p')"
ffprobe_version_line="$("${ffprobe}" -version | sed -n '1p')"

[[ "${ffmpeg_version_line}" == "ffmpeg version ${version} "* ]] || {
    printf 'ERROR: FFmpeg version does not match %s.\n' "${version}" >&2
    exit 1
}
[[ "${ffprobe_version_line}" == "ffprobe version ${version} "* ]] || {
    printf 'ERROR: FFprobe version does not match %s.\n' "${version}" >&2
    exit 1
}

if ! "${ffmpeg}" -hide_banner -decoders 2>/dev/null |
    awk '$2 == "magicyuv" { found=1 } END { exit !found }'
then
    printf 'ERROR: MagicYUV decoder is unavailable; advisory-path coverage would be lost.\n' >&2
    exit 1
fi

for encoder in libx264 libvpx-vp9 libopus aac; do
    if ! "${ffmpeg}" -hide_banner -encoders 2>/dev/null |
        awk -v encoder="${encoder}" '$2 == encoder { found=1 } END { exit !found }'
    then
        printf 'ERROR: required CI FFmpeg encoder is unavailable: %s\n' "${encoder}" >&2
        exit 1
    fi
done

ldd_output="$(ldd "${ffmpeg}")"
if grep -F 'not found' <<<"${ldd_output}" >/dev/null; then
    printf 'ERROR: FFmpeg has unresolved shared-library dependencies.\n' >&2
    printf '%s\n' "${ldd_output}" >&2
    exit 1
fi

printf 'AWVP_CI_IMAGE_FFMPEG=%s\n' "${ffmpeg_version_line}"
printf 'AWVP_CI_IMAGE_PHP=%s\n' "$(php -r 'echo PHP_VERSION;')"
printf 'AWVP_CI_IMAGE_NODE=%s\n' "$(node --version)"
printf 'AWVP_CI_IMAGE_VERIFY=PASS\n'
