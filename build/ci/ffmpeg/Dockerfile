# syntax=docker/dockerfile:1

ARG AWVP_CI_BASE_IMAGE=node:24-bookworm-slim

FROM ${AWVP_CI_BASE_IMAGE} AS ffmpeg-builder

ARG FFMPEG_VERSION=9.0.1
ARG FFMPEG_BUILD_JOBS=4

ENV DEBIAN_FRONTEND=noninteractive \
    ARGENT_VIDEO_CI_FFMPEG_PREFIX=/opt/awvp-ffmpeg \
    ARGENT_VIDEO_CI_FFMPEG_JOBS=${FFMPEG_BUILD_JOBS}

RUN apt-get update \
    && apt-get install --yes --no-install-recommends \
        build-essential \
        ca-certificates \
        curl \
        gnupg \
        libopus-dev \
        libvpx-dev \
        libx264-dev \
        nasm \
        pkg-config \
        tar \
        yasm \
    && rm -rf /var/lib/apt/lists/*

COPY build/install-ci-ffmpeg.sh /usr/local/src/install-ci-ffmpeg.sh
RUN bash /usr/local/src/install-ci-ffmpeg.sh "${FFMPEG_VERSION}"

FROM ${AWVP_CI_BASE_IMAGE}

ARG FFMPEG_VERSION=9.0.1
ARG SOURCE_REVISION=unknown
ARG BASE_IMAGE_REFERENCE=unknown

LABEL org.opencontainers.image.source="https://forgejo.argentwolf.org/alan/wp-argentwolf-video-processor" \
      org.opencontainers.image.title="AWVP CI FFmpeg" \
      org.opencontainers.image.description="ArgentWolf Video Processor CI toolchain with verified upstream FFmpeg" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" \
      org.opencontainers.image.base.name="${BASE_IMAGE_REFERENCE}"

ENV DEBIAN_FRONTEND=noninteractive \
    ARGENT_VIDEO_CI_FFMPEG_VERSION=${FFMPEG_VERSION} \
    ARGENT_VIDEO_CI_FFMPEG_PREFIX=/opt/awvp-ffmpeg \
    ARGENT_VIDEO_TEST_FFMPEG=/opt/awvp-ffmpeg/bin/ffmpeg \
    ARGENT_VIDEO_TEST_FFPROBE=/opt/awvp-ffmpeg/bin/ffprobe \
    PATH=/opt/awvp-ffmpeg/bin:${PATH}

RUN apt-get update \
    && apt-get install --yes --no-install-recommends \
        ca-certificates \
        curl \
        git \
        libopus0 \
        libvpx7 \
        libx264-164 \
        php-cli \
        rsync \
        unzip \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=ffmpeg-builder /opt/awvp-ffmpeg /opt/awvp-ffmpeg
COPY build/ci/ffmpeg/verify-image.sh /usr/local/bin/awvp-verify-ci-image

RUN chmod 0755 /usr/local/bin/awvp-verify-ci-image \
    && awvp-verify-ci-image
