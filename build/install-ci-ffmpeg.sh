#!/usr/bin/env bash
# Build a cryptographically verified FFmpeg release for CI.
#
# This helper is repository-only build infrastructure. It does not affect the
# plugin's runtime policy of using administrator-configured system FFmpeg.
#
# Usage:
#   bash build/install-ci-ffmpeg.sh 9.0.1
#
# Environment:
#   ARGENT_VIDEO_CI_FFMPEG_PREFIX  installation prefix
#   ARGENT_VIDEO_CI_FFMPEG_JOBS    make parallelism (default 2)

main() {
    local version="${1:-9.0.1}"
    local fingerprint='FCF986EA15E6E293A5644F10B4322F04D67658D8'
    local jobs="${ARGENT_VIDEO_CI_FFMPEG_JOBS:-2}"
    local runner_tmp="${RUNNER_TEMP:-/tmp}"
    local prefix="${ARGENT_VIDEO_CI_FFMPEG_PREFIX:-${runner_tmp}/argentwolf-ci-ffmpeg-${version}}"
    local work_root
    local gnupg
    local actual_fpr

    fail() {
        printf 'ERROR: %s\n' "$*" >&2
        return 1
    }

    case "${version}" in
        8.1.2|9.0.1) ;;
        *)
            fail "Unsupported CI FFmpeg version: ${version}. Review security advisories before changing the pinned release."
            return 1
            ;;
    esac

    case "${jobs}" in
        ''|*[!0-9]*)
            fail "ARGENT_VIDEO_CI_FFMPEG_JOBS must be a positive integer."
            return 1
            ;;
    esac
    if (( jobs < 1 || jobs > 16 )); then
        fail "ARGENT_VIDEO_CI_FFMPEG_JOBS must be between 1 and 16."
        return 1
    fi

    for command in curl gpg make pkg-config tar awk grep; do
        command -v "${command}" >/dev/null 2>&1 || {
            fail "Required build command is missing: ${command}"
            return 1
        }
    done

    work_root="$(mktemp -d "${runner_tmp%/}/argentwolf-ci-ffmpeg.XXXXXXXX")" || {
        fail "Could not create FFmpeg build workspace."
        return 1
    }
    gnupg="${work_root}/gnupg"
    mkdir -m 700 "${gnupg}" || {
        rm -rf -- "${work_root}"
        fail "Could not create isolated GnuPG home."
        return 1
    }

    cleanup() {
        rm -rf -- "${work_root}"
    }

    printf 'Building CI FFmpeg %s\n' "${version}"
    printf 'Install prefix: %s\n' "${prefix}"
    printf 'Release-key fingerprint: %s\n' "${fingerprint}"

    if ! curl --fail --silent --show-error --location \
        --proto '=https' --tlsv1.2 \
        -o "${work_root}/ffmpeg-devel.asc" \
        'https://ffmpeg.org/ffmpeg-devel.asc'
    then
        cleanup
        fail "Could not download the FFmpeg release signing key."
        return 1
    fi

    if ! gpg --batch --homedir "${gnupg}" --import \
        "${work_root}/ffmpeg-devel.asc" >/dev/null 2>&1
    then
        cleanup
        fail "Could not import the FFmpeg release signing key."
        return 1
    fi

    actual_fpr="$(
        gpg --batch --homedir "${gnupg}" --with-colons --fingerprint |
        awk -F: '$1 == "fpr" { print $10; exit }'
    )"
    if [[ "${actual_fpr}" != "${fingerprint}" ]]; then
        cleanup
        fail "FFmpeg release signing-key fingerprint mismatch: ${actual_fpr:-missing}"
        return 1
    fi

    if ! curl --fail --silent --show-error --location \
        --proto '=https' --tlsv1.2 \
        -o "${work_root}/ffmpeg-${version}.tar.gz" \
        "https://ffmpeg.org/releases/ffmpeg-${version}.tar.gz"
    then
        cleanup
        fail "Could not download FFmpeg ${version} source."
        return 1
    fi

    if ! curl --fail --silent --show-error --location \
        --proto '=https' --tlsv1.2 \
        -o "${work_root}/ffmpeg-${version}.tar.gz.asc" \
        "https://ffmpeg.org/releases/ffmpeg-${version}.tar.gz.asc"
    then
        cleanup
        fail "Could not download FFmpeg ${version} signature."
        return 1
    fi

    if ! gpg --batch --homedir "${gnupg}" --verify \
        "${work_root}/ffmpeg-${version}.tar.gz.asc" \
        "${work_root}/ffmpeg-${version}.tar.gz"
    then
        cleanup
        fail "FFmpeg ${version} release signature verification failed."
        return 1
    fi

    if ! tar -xzf "${work_root}/ffmpeg-${version}.tar.gz" -C "${work_root}"; then
        cleanup
        fail "Could not extract FFmpeg ${version} source."
        return 1
    fi

    rm -rf -- "${prefix}"
    mkdir -p "${prefix}" || {
        cleanup
        fail "Could not create FFmpeg install prefix."
        return 1
    }

    (
        cd "${work_root}/ffmpeg-${version}" || exit 1

        ./configure \
            --prefix="${prefix}" \
            --disable-debug \
            --disable-doc \
            --disable-ffplay \
            --enable-gpl \
            --enable-libopus \
            --enable-libvpx \
            --enable-libx264 \
        && make -j"${jobs}" \
        && make install
    )
    local build_rc=$?
    if (( build_rc != 0 )); then
        cleanup
        fail "FFmpeg ${version} configure/build/install failed."
        return 1
    fi

    if ! "${prefix}/bin/ffmpeg" -version |
        head -n1 |
        grep -Fq "ffmpeg version ${version} "
    then
        cleanup
        fail "Installed FFmpeg version does not match ${version}."
        return 1
    fi

    if ! "${prefix}/bin/ffmpeg" -hide_banner -decoders 2>/dev/null |
        awk '$2 == "magicyuv" { found=1 } END { exit !found }'
    then
        cleanup
        fail "CI FFmpeg does not expose the MagicYUV decoder; patched-path coverage would be lost."
        return 1
    fi

    for encoder in libx264 libvpx-vp9 libopus aac; do
        if ! "${prefix}/bin/ffmpeg" -hide_banner -encoders 2>/dev/null |
            awk -v encoder="${encoder}" '$2 == encoder { found=1 } END { exit !found }'
        then
            cleanup
            fail "Required CI FFmpeg encoder is unavailable: ${encoder}"
            return 1
        fi
    done

    if [[ -n "${GITHUB_PATH:-}" ]]; then
        printf '%s\n' "${prefix}/bin" >> "${GITHUB_PATH}"
    fi

    printf 'CI_FFMPEG_PREFIX=%s\n' "${prefix}"
    printf 'CI_FFMPEG_VERSION=%s\n' "${version}"
    printf 'CI_FFMPEG_MAGICYUV_DECODER=enabled\n'
    printf 'CI FFmpeg build: PASS\n'

    cleanup
    return 0
}

main "$@"
