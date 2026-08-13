<!-- File: CHANGELOG.md -->
# Changelog

## 0.3.1 - 2026-08-13

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
