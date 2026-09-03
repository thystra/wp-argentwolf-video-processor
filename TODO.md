# ArgentWolf Video Processor TODO

## Milestone 1 — public repository cleanup

- [ ] Remove the private `AGENTS-PROFILE.md`.
- [ ] Remove production-specific `ops/` material from the public repository.
- [ ] Remove private hostnames, user names, absolute paths, and deployment state.
- [ ] Replace `AGENTS.md` with portable project instructions.
- [ ] Add `ARCHITECTURE.md` and this `TODO.md`.
- [ ] Confirm backups are written under
      `~/src/backups/wp-argentwolf-video-processor-backups/`.

## Milestone 2 — canonical ArgentWolf identity

- [ ] Rename the public product to ArgentWolf Video Processor.
- [ ] Rename the main file to `argentwolf-video-processor.php`.
- [ ] Change the text domain and package root to
      `argentwolf-video-processor`.
- [ ] Update GitHub Actions, tests, and build tooling.
- [ ] Use `Alan Johnson` as the plugin author.
- [ ] Use WordPress.org contributor username `thystra`.
- [ ] Retain established options, post meta, database table, hooks, cron
      identifiers, namespace, admin page slug, and `wp argent-video` command.

## Milestone 3 — support and documentation

- [ ] Add Settings and GitHub links to the plugin action row.
- [ ] Add a Support development section to the settings page.
- [ ] Update `README.md`, `readme.txt`, and `CHANGELOG.md`.
- [ ] Disclose system FFmpeg, FFprobe, WP-CLI, `proc_open()`, and `exec()`
      requirements.
- [ ] Clarify that metadata stripping applies to derivatives, not originals.
- [ ] Document that the plugin uses no remote processing service or telemetry.

## Milestone 4 — deterministic release package

- [ ] Change the ZIP root to `argentwolf-video-processor/`.
- [ ] Package from an explicit runtime allowlist.
- [ ] Exclude agents, architecture, TODO, tests, CI, build, ops, and local files.
- [ ] Preserve pinned hls.js identity, syntax, version, license, and checksum
      validation.
- [ ] Verify a single ZIP root and the required vendor files.
- [ ] Produce and verify `SHA256SUMS`.

## Milestone 5 — automated and static validation

- [ ] Run PHP lint across source and tests.
- [ ] Run dependency-free tests.
- [ ] Run open_basedir regression tests.
- [ ] Run smoke loading tests.
- [ ] Run the real FFmpeg integration test.
- [ ] Run the hls.js vendoring regression test.
- [ ] Run JavaScript syntax validation.
- [ ] Run `git diff --check`.
- [ ] Run the official WordPress Plugin Check against the exact release.
- [ ] Resolve or document every Plugin Check result.

## Milestone 6 — runtime upgrade validation
- [x] Install `0.3.2` on a clean WordPress test site with `WP_DEBUG` enabled.
- [x] Upgrade a test site from active `0.3.1` and verify creation of `argentwolf_video_processor_logs` without changing `argent_video_jobs`.
- [ ] Build, validate, tag, publish, and submit `0.3.3` with `Tested up to: 7.1`.
- [ ] Upgrade a test site from active `0.2.3`.
- [ ] Verify the old-to-new plugin basename transition.
- [ ] Verify all settings are preserved.
- [ ] Verify existing queue rows and attachment metadata are preserved.
- [ ] Verify existing progressive and HLS outputs still render.
- [ ] Verify new uploads queue and process.
- [ ] Verify backlog modes and manual dispatch.
- [ ] Verify the existing `wp argent-video` commands.
- [ ] Verify scheduled dispatch does not run FFmpeg in WP-Cron.
- [ ] Verify deactivation/reactivation and default non-destructive uninstall.
- [ ] Verify settings and GitHub support links.
## Milestone 7 — WordPress.org submission

- [ ] Confirm requested slug `argentwolf-video-processor`.
- [ ] Confirm contributor `thystra` and author `Alan Johnson`.
- [ ] Confirm WordPress/PHP requirements and Tested up to values.
- [ ] Audit GPL compatibility and bundled Apache-2.0 hls.js license.
- [ ] Confirm no custom update checker, telemetry, secrets, or private paths.
- [ ] Inspect the final ZIP manifest and checksum.
- [ ] Commit and push the reviewed source.
- [ ] Tag the approved release.
- [ ] Submit the exact reviewed ZIP to WordPress.org.
- [ ] Record review feedback without claiming approval prematurely.

## Deferred enhancements

- [ ] Progress reporting for active FFmpeg jobs.
- [ ] Safe cancellation of an active FFmpeg process.
- [ ] Optional additional adaptive codecs after compatibility review.
- [ ] Multisite-specific administration and queue behavior.

## 1.0 release closure

- [x] Receive WordPress.org approval for the corrected 0.3.3 review package.
- [x] Promote the approved codebase to metadata-only stable version 1.0.0.
- [x] Build one canonical 1.0.0 ZIP from the reviewed Forgejo commit.
- [x] Validate exact 0.3.3 -> 1.0.0 upgrade and installed-package byte identity.
- [x] Pass WordPress 7.1 / Plugin Check 2.1.0 / WP_DEBUG release gates.
- [x] Tag `v1.0.0` only after canonical artifact validation.
- [x] Publish and redownload the exact Forgejo 1.0.0 asset with matching SHA-256.
- [x] Publish `trunk`, `tags/1.0.0`, and directory assets to WordPress.org SVN.
- [x] Merge the completed `release/1.x` line into `main`.
- [x] Forward-port the stable 1.0 baseline into `develop-2.0`.

## 2.0 development status

- [x] R33: bounded PeerTube origin detection and safe HTTP foundation.
- [x] R34: authenticated PeerTube API and identity primitives.
- [x] R35: durable connection and encrypted-secret persistence foundation.
- [x] R36: restart-safe local connection coordinator.
- [x] R37: bounded password/OTP grant bootstrap and encrypted token persistence.
- [x] R38: explicit administrator authorization and settings boundary.
- [x] R39: authenticated identity verification and owned-destination selection.
- [x] R40: activate the verified PeerTube descriptor and make the adapter/factory
  eligible without crossing the media-upload boundary. Exact feature commit
  `1fcb8e45fd9b1aaeb4fe2aad1e31928327cc0d69` passed Forgejo CI run 86 and the
  isolated WordPress 6.4/7.1 Docker activation matrix; qualified feature closure
  `e1819ecb83377bf97d03cd331fc31c6400ea1b41` was merged into `develop-2.0` as
  `67bb455f59450bab66cca1d59389e8fb637755ba`, and integration CI run 88 passed
  in 9 seconds.
- [x] R41: bounded token refresh, revoke, and disconnect lifecycle. Exact
  qualified feature commit `7276ef4fab4d2d0bc96afd16c0da39c0d0dca72d`, tree
  `39b54899930bbe0abbdb0dde8a4604d3cab016fc`, passed Forgejo CI run 96 and the
  isolated WordPress 6.4/7.1 Docker lifecycle matrix. Documentation-only
  qualification closure `911b1ff57893ffe30bafeffa26a2852a213b51a6` passed
  Forgejo CI run 97, was merged into `develop-2.0` as
  `dfc5c2b6e2521f0ecbba6806dc398608d0968b0e`, tree
  `f15847a00d2907cf70dd9d53325267e861e5755b`, and integration CI run 98 passed.
  The qualified lifecycle proved exact refresh/revoke request counts, no
  automatic remote retry, no plaintext token canaries, managed-secret removal
  after confirmed retirement, no upload mutations, and clean `WP_DEBUG`. R41
  remains a development checkpoint and authorizes no media upload.
- [x] Integrate the exact qualified R41 feature history into `develop-2.0` and
  qualify merge `dfc5c2b6e2521f0ecbba6806dc398608d0968b0e` with Forgejo CI run
  98.
- [x] Commit the R41 integration closure on `develop-2.0`. Exact closure
  `45b8faed47147f3052a557aa6511d84ad25dca9c`, tree
  `581d4f98ee24146309788bf6e8ad59794161d27e`, passed Forgejo CI run 99 and is
  the clean authority for tranche 2.0-4.
- [x] R42 staged-upload foundation authority: exact feature commit
  `b1c500252ddb6632388fbbb08aee4015fc9e3636`, tree
  `3c0ee7e142ac48349bda4b72545dfbd76425bac5`, passed Forgejo CI run 100
  (16s) and is the qualified R43 branch baseline. R42 crossed no PeerTube media
  mutation boundary and therefore required no standalone Docker media-mutation
  matrix.
- [x] R43 executable resumable-upload transport/service boundary: exact feature
  commit `4d38158335ec6cd8c7528a4dbb29b065a7ba7ec9`, tree
  `772308d60722002769c712717628261993b63299`, passed Forgejo CI run 101
  (11s). The isolated `peertube-staged-upload-smoke.sh` matrix passed both
  supported WordPress/PHP/MariaDB cases with exactly one resumable-init POST and
  one byte-bearing PUT per case, zero offset probes on the happy path, no
  automatic retry, no plaintext canaries, source bytes preserved, no remote
  asset row committed, all ingest/processing capability bits still false, and no
  gated `WP_DEBUG` diagnostics. The successful report is
  `peertube-r43-smoke-20260903T004335Z-1367351.log`, SHA-256
  `68f25862862784862343aec197a184fd36588f945f3143a8d7f6c9ded0e37c0d`.
  R43 remains a development checkpoint: its executor is not reachable from a
  production WordPress/admin/REST/AJAX/CLI/cron/worker entry point.

- [x] R42/R43 qualification closure and integration: documentation-only feature
  closure `55058b0ecfbb3cc00f220d000158799ea966d6d5` passed Forgejo CI run 102
  (17s) and was merged into `develop-2.0` as
  `080d9f5455842d7dd2d1279693e15e59140cdbfe`, tree
  `52c1db86bee485215417e243bacb23f9656258a8`, with first parent
  `45b8faed47147f3052a557aa6511d84ad25dca9c` and second parent
  `55058b0ecfbb3cc00f220d000158799ea966d6d5`. Forgejo integration CI run 103
  passed in 16 seconds.
- [ ] Commit the R42/R43 integration closure on `develop-2.0`, require exact-commit
  Forgejo CI, and use the resulting clean branch tip as the authority for the next
  checkpoint: durable `remote_created` -> AWVP remote-asset persistence plus
  restart-safe processing/readiness reconciliation, still without a production
  upload entry point.
