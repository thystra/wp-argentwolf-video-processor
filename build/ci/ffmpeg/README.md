# AWVP CI FFmpeg image

Routine AWVP CI must test FFmpeg behavior, not spend every run rebuilding the
same FFmpeg toolchain. This image moves the reviewed source build to an
infrequent image-publication step while preserving the existing upstream
signature and capability checks.

## Authority and contents

- FFmpeg source is downloaded from `ffmpeg.org` by
  `build/install-ci-ffmpeg.sh`.
- The official FFmpeg release signature and release-key fingerprint are verified
  before compilation.
- The image intentionally keeps the MagicYUV decoder enabled so the FFmpeg
  security-advisory path exercises an affected capability on a patched release.
- `libx264`, `libvpx-vp9`, `libopus`, and AAC encoder availability is verified.
- The runtime image also contains the PHP/Node/Git/archive tools required by the
  ordinary AWVP CI workflow.
- The image is repository-only test infrastructure. AWVP runtime installations
  continue to use administrator-configured system FFmpeg/FFprobe binaries.

Current image coordinates:

```text
forgejo.argentwolf.org/alan/wp-argentwolf-video-processor/ci-ffmpeg:9.0.1-bookworm-v2
```

Treat the versioned tag as immutable. Never overwrite an already-published tag;
change the suffix (`v2`, `v3`, ...) when the image definition changes. After the
first successful image qualification, record and use the registry digest in CI
instead of relying indefinitely on the tag.

## Build and qualify

Run from a clean, committed AWVP repository checkout on a trusted x86-64
Docker host:

```bash
bash build/build-ci-ffmpeg-image.sh
```

The helper resolves `node:24-bookworm-slim` to its current immutable registry
digest before the build, records that base plus the exact AWVP source revision
in OCI labels, builds FFmpeg through the signed-source helper, and runs
`awvp-verify-ci-image` against the finished image.

The Docker build is expected to compile FFmpeg once. Routine plugin CI should
not invoke `build/install-ci-ffmpeg.sh` again after it has switched to the
qualified image.

## Publish to Forgejo

Authenticate interactively or with an appropriately scoped operator token; do
not put registry credentials in the repository or command history.

```bash
docker login forgejo.argentwolf.org
bash build/build-ci-ffmpeg-image.sh --push
```

The helper pulls the just-published image and prints its immutable registry
digest. Preserve that output as image qualification evidence; the next small CI
lock patch should replace the versioned tag in the workflows with that digest.

The package must be anonymously pullable before the mirrored GitHub Actions job
is switched to the Forgejo-hosted image. If public pull is intentionally not
allowed, keep GitHub on a separately published public image or retain its
independent source-build lane.

## Updating FFmpeg or the image

1. review current FFmpeg security advisories and the required runtime capability
   matrix;
2. add the reviewed FFmpeg version to `build/install-ci-ffmpeg.sh`;
3. increment the immutable image tag suffix or FFmpeg version;
4. build and run `awvp-verify-ci-image` on the image;
5. publish once and record its digest;
6. run the complete AWVP CI suite in that exact image;
7. pin CI to the reviewed digest;
8. never replace the published image under the same versioned tag.

Compilation remains appropriate when the compilation itself, compiler/toolchain
compatibility, upstream release provenance, or image construction is the subject
under test. It is not the default for ordinary source/test runs.
