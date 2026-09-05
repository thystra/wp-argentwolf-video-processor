<!-- File: CHANGELOG.md -->
# Changelog

## 2.0.0 - Unreleased

- Add an origin-bound WordPress safe-HTTP client and bounded PeerTube instance
  detection through `GET /api/v1/config`, followed by authenticated identity and
  owned-channel discovery through the configured PeerTube origin.
- Add the tranche 2.0-3 connection foundation: durable connection journaling,
  encrypted server-side token persistence, restart-safe coordination, and an
  explicit password/OTP grant bootstrap that keeps credentials out of durable
  operation state and browser projections.
- Add a dedicated administrator settings boundary with capability- and
  nonce-protected connection, grant, reconciliation, identity-verification, and
  destination-selection actions.
- Add fail-closed identity verification and owned-destination selection that
  re-prove current remote authority before selection and before the operation may
  reach `activation_ready`.
- Preserve the existing local backend and 1.0 runtime behavior while adding an
  explicit, restart-safe local activation path for a freshly verified PeerTube
  descriptor. Activation changes only the exact registry state/default destination
  and makes the conservative PeerTube adapter/factory surface eligible.
- Add an explicit, restart-safe PeerTube credential lifecycle: bounded refresh-token
  rotation into a new encrypted managed-secret generation, explicit token revocation,
  exact local descriptor retirement, and exact-generation secret deletion. Uncertain
  refresh/revoke outcomes are never automatically replayed, and media upload,
  processing, publication, library, retention, and remote-media mutation remain
  outside this checkpoint.
- Add the first tranche 2.0-4/R42 staged-upload state foundation without yet
  enabling a PeerTube media POST: immutable managed-source commitments, exact
  backend/origin/destination binding, a bounded non-autoloaded exact-CAS upload
  journal, durable in-flight/indeterminate/reconciliation states, separate remote
  identity versus remote-asset commit, and a cleanup gate that cannot open before
  positive remote-ready verification.
- Add the R43 executable resumable-upload boundary behind the still-disabled
  staged-ingest capability: bounded PeerTube resumable initialization/chunk/offset
  probe primitives, durable claim-before-I/O service execution, byte-range
  reconciliation after uncertain chunk PUTs, and fail-closed handling that never
  automatically replays an uncertain byte-bearing request. No WordPress/admin/REST/
  AJAX/CLI/cron entry point invokes the service yet, and the legacy multipart upload
  endpoint remains outside the reviewed surface.
- Add the R44 post-create persistence/reconciliation boundary: idempotently commit
  a positively observed PeerTube identity into `argent_video_remote_assets`, then
  use bounded bearer-authenticated `GET /api/v1/videos/{uuid}` observations to
  journal processing waits, positive private/non-live readiness, missing videos,
  and terminal processing failures. Relational-row/journal crash windows are
  restart-safe; no production upload/reconcile entry point, automatic polling,
  source cleanup, publication, retention, or remote delete authority is enabled.
- Add the R45 asynchronous PeerTube execution boundary without touching the legacy
  FFmpeg queue/worker: a generic lock-token-guarded `argent_video_tasks`
  repository, type-owned PeerTube claims/recovery, a bounded upload/reconciliation
  coordinator, a one-shot PeerTube task worker, and the explicit development
  command `wp argent-video peertube-task-worker --once`. Durable waits require a
  later invocation, and an uncertain byte-bearing upload remains non-replayable.
- Qualify the R45 one-shot path in isolated WordPress 6.4/PHP 8.1 and WordPress
  7.1/PHP 8.3 Docker matrices, including fresh-process happy/wait execution and a
  transport-drop case proving one byte-bearing PUT, zero automatic replay, zero
  offset probe, and durable `upload_indeterminate` fencing. Add a detached
  PeerTube task-launcher foundation while leaving it unwired from cron/admin.
- Add backend-scoped PeerTube upload segmentation policy with a 128 MiB default,
  accepted 0–8192 MiB range, and `0` meaning all remaining bytes in one resumable
  segment. Stream policy-sized file slices through WordPress safe HTTP/cURL rather
  than materializing large upload bodies in PHP memory, and expose the tuning
  control on the authenticated PeerTube settings page. Saving the setting does
  not itself start a transfer; automatic scheduling and ingest/processing
  capability advertisement remain disabled.
- Expand focused PeerTube security/state tests and isolated real-WordPress Docker
  development matrices through the R39 identity/destination checkpoint, with an
  R40 activation continuation that proves activation performs no additional
  PeerTube HTTP request or media mutation.
- Move routine CI to a project-owned FFmpeg 9.0.1 toolchain image so ordinary
  source/test runs do not repeatedly compile the same verified FFmpeg build on
  each runner; retain the signed-source build as the image bootstrap/provenance
  path.

## 1.0.0 - 2026-08-22

- Promoted the WordPress.org-approved 0.3.3 codebase to the first stable 1.0.0 release.
- No functional or runtime behavior changes from 0.3.3.

## 0.3.3 - 2026-08-20

- Updated WordPress compatibility metadata to indicate testing through WordPress 7.1.
- No functional or runtime behavior changes from 0.3.2.

## 0.3.2 - 2026-08-17

- Replace the append-only system temporary worker log with bounded database-backed worker diagnostic history.
- Add `argentwolf_video_processor_logs` as the canonical new plugin table while retaining the legacy `argent_video_jobs` queue table for upgrade compatibility.
- Add configurable successful/error diagnostic retention, bounded per-run capture, stale detached-run recovery, administrator history display, and protected history clearing.
- Move process scratch capture to WordPress temporary-file facilities with failure-safe cleanup.
- Record the WordPress.org review and applicator-anchor lessons in contributor guidance and update release documentation.

## 0.3.1 - 2026-08-13

- Add a capability-aware FFmpeg security gate with explicit CVE-2026-8461 / NVD reporting; block new transcoding when MagicYUV is enabled on an unpatched or unverifiable build.
- Add Site Health/admin/CLI security status and cross-version FFmpeg security matrix adapters.

- Confine generated MP4, WebM, HLS, and temporary files to the plugin-owned
  `wp_upload_dir()['basedir']/argentwolf-video-processor/<attachment-id>/`
  storage boundary while preserving original Media Library attachments.
- Centralize generated-media path creation, confinement, URL conversion, atomic
  promotion, and destructive cleanup in `Storage`.
- Reject traversal, sibling-prefix, and unsafe symlink escapes before filesystem
  mutations and derive attachment cleanup from the managed attachment directory
  rather than stored arbitrary paths.
- Add storage-boundary coverage for custom upload locations, path escapes,
  symlinks, HLS writes, cleanup, and FFmpeg integration.
- Keep hls.js version/checksum records as controlled build-time integrity
  evidence while excluding `hls.VERSION` and `hls.SHA256` from the installable
  WordPress.org package.
- Retain legacy-output migration as maintainer/operator tooling outside the
  public runtime and distribution package.

## 0.3.0 - 2026-07-29

- Resolve WordPress Plugin Check findings with identifier placeholders,
  WordPress file-deletion APIs, and narrowly documented worker, queue, and
  atomic-filesystem exceptions.
- Standardize the public product name as ArgentWolf Video Processor.
- Change the WordPress.org target slug, main filename, package root, and text
  domain to `argentwolf-video-processor`.
- Retain existing options, attachment metadata, queue table, hooks, cron
  identifiers, namespace, Settings page slug, and `wp argent-video` command.
- Remove private operator profiles and production-specific operations material
  from the public repository.
- Add public agent, architecture, milestone, privacy, and WordPress.org
  submission documentation.
- Add Settings and GitHub project links to the plugin action row.
- Add a Support development section to the settings page.
- Change release packaging to an explicit runtime allowlist.

## 0.2.3 - 2026-07-27

- Fix binary diagnostics and detached worker launch under per-site PHP
  `open_basedir` restrictions.
- Probe configured executables through safely quoted shell commands instead of
  PHP filesystem stat calls.
- Report PHP SAPI and active `open_basedir` in diagnostics.

## 0.2.2 - 2026-07-27

- Fix tagged-release HLS.js vendoring when the npm package license text differs
  from the repository snapshot.
- Validate the exact package SPDX license as `Apache-2.0` and substantively
  inspect the package-provided license text.
- Install the exact license shipped in the verified npm package into the release
  ZIP.
- Treat vendored HLS.js files as generated release assets.

## 0.2.1 - 2026-07-27

- Validate the exact hls.js npm package and runtime version.
- Verify package identity, JavaScript syntax, player version, license, and
  checksum.
- Add an offline regression test for player vendoring.

## 0.2.0 - 2026-07-27

- Add adaptive HLS with available 360p, 480p, and 720p H.264/AAC renditions.
- Add native-HLS playback and a pinned local hls.js player.
- Preserve progressive WebM and MP4 fallbacks.
- Add administrator backlog operations and CLI scan modes.
- Add system-binary, codec, HLS, and player diagnostics.
- Add real FFmpeg adaptive-output integration tests.

## 0.1.1 - 2026-07-27

- Fix FFmpeg compatibility by relying on default input autorotation.
- Improve failed-job output and required-codec diagnostics.
- Add a real FFmpeg integration test.

## 0.1.0 - 2026-07-26

- Initial queue, detached worker, FFmpeg processing, validation, metadata
  stripping, render substitution, administration, CLI, and release workflow.

<!-- EOF: CHANGELOG.md -->
