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
- [ ] Future schema normalization (target 3.0): normalize AWVP custom tables to
      `{$wpdb->prefix}argentwolf_video_processor_jobs`,
      `{$wpdb->prefix}argentwolf_video_processor_remote_assets`,
      `{$wpdb->prefix}argentwolf_video_processor_tasks`, and
      `{$wpdb->prefix}argentwolf_video_processor_logs`. Implement this only as an
      explicit versioned, resumable, idempotent, fail-closed migration with
      old/new-table state verification; do not rename physical tables in the 2.0
      release line.

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
- [x] Recreate permanent `release/1.x` directly from `v1.0.0` before entering
  the 2.0 RC line, preserving an isolated maintenance path for public 1.0.x.

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
- [x] R42/R43 `develop-2.0` integration closure: exact commit
  `bb98090900bd53540b60cfa1fe02e76e0e420334`, tree
  `a65cf633bab608fc741639fe093f9f11f09b4e9a`, passed Forgejo CI run 104 (17s)
  and is the clean R44 branch authority.
- [x] R44 remote-asset persistence/readiness checkpoint: exact feature commit
  `0845a7ab70386fc8b4d7f56eecef13eb131a54b8`, tree
  `74c8a4cf6be5273fc549d70a2beb763437137eee`, passed Forgejo push CI run 105
  and the isolated two-case
  `peertube-remote-asset-reconciliation-smoke.sh` matrix. The successful report
  is `peertube-r44-smoke-20260903T013651Z-1386010.log`, SHA-256
  `6b6432ee3da0bbcc51835bc792aa18f47cec7242fd755976031b46454c8e714a`.
  Both supported cases passed the R44 browser/state reconciliation boundary,
  exact isolated remote-read/upload request-count assertions, encrypted-secret
  persistence, no automatic remote retry, no plaintext canaries, no gated
  `WP_DEBUG` diagnostics, and cleanup. R44 remains a development checkpoint and
  exposes no production upload/reconcile entry point, automatic polling, source
  cleanup, publication, retention, or remote delete.
- [x] R44 qualification/integration closure: correctly based PR #2 into
  `develop-2.0` passed Forgejo pull-request CI run 107. It was merged as
  `911f97edab6b2bc395851307d82e683a2b8b746a`, tree
  `74c8a4cf6be5273fc549d70a2beb763437137eee`, with first parent
  `bb98090900bd53540b60cfa1fe02e76e0e420334` and second parent
  `0845a7ab70386fc8b4d7f56eecef13eb131a54b8`. Forgejo integration CI run 108
  passed. The integration tree exactly matches the qualified R44 feature tree.

### R45 PeerTube asynchronous upload coordination and transport

- [x] R45.1: add the durable generic `Task_Repository` for
  `argent_video_tasks`, including idempotent enqueue, atomic claim, lock-token
  conditional complete/fail/reschedule, stale recovery, type-owned queue views,
  and the 65,535-attempt ceiling needed by resumable upload coordination.
  Forgejo CI runs 112 and 113 passed the repository checkpoint and correction.
- [x] R45.2: add `PeerTube_Upload_Task_Coordinator` for exactly
  `peertube_upload_advance` and `peertube_remote_reconcile`; one invocation
  delegates at most one R43/R44 advancement and never silently reconciles an
  `upload_indeterminate` request. Forgejo CI run 114 passed.
- [x] R45.3a: add the type-owned one-shot `PeerTube_Task_Worker` without
  modifying the legacy FFmpeg `Worker`; exact commit
  `b28fe12c795d7d9348c97e8bcc8d43d498e98345`, tree
  `298e1de6f1f2ced97f56599535c12f03f90ebb37`, passed Forgejo CI run 115.
- [x] R45.3b/c: wire the explicit one-shot WP-CLI boundary
  `wp argent-video peertube-task-worker --once`, then qualify real WordPress /
  mock-PeerTube fresh-process happy/wait execution. CLI composition commit
  `4332407ceed7528ee577209ff05033d3f20dcda8` passed CI run 116; the corrected
  public subcommand/smoke sequence reached exact source
  `ab3a036292dc144f91166b77376058c276021756`, tree
  `8d6d97314adad395716288d9ae97be93298ccb44`, with CI run 117 passing.
- [x] R45.3d: qualify the dangerous uncertain-byte-bearing-PUT case. Exact
  commit `8cb8c21a59a47085b2231b97bfed7af001418251`, tree
  `188d4b3fcd40eb573b0efde1aeb05ab130032dbf`, passed Forgejo CI run 118. The
  R45 happy/wait matrix and the R44 reconciliation regression also passed on
  those exact bytes; the indeterminate matrix proved no automatic PUT replay,
  no automatic zero-byte probe, no remote-read handoff, and preserved staged
  source authority.
- [x] R45.4a: add a separate detached PeerTube task-launcher foundation and
  type-owned due/stale-work detection without wiring it to cron or admin.
  Qualified commit prefix `3084e348f0`; Forgejo CI run 119 passed.
- [x] R45.4b1: add backend-scoped non-secret upload segmentation policy with a
  128 MiB default, 0–8192 MiB accepted range, and `0` meaning one segment with
  all remaining bytes. Qualified commit prefix `ab74815`; Forgejo CI run 120
  passed.
- [x] R45.4b2: stream policy-sized staged-file slices through the reviewed
  resumable PUT boundary and expose the backend setting in the PeerTube admin
  page while preserving the safe-HTTP/origin and R43 no-replay boundaries.
  Exact source `ca1194235e8a6f7f0c16e8087906816a9ceb50eb`, tree
  `6facc70f9c48f2abb8ebf11e3c6ae4215e4d7b5f`, passed the retained R45
  happy/wait, R45 indeterminate/no-replay, and R44 reconciliation Docker matrices
  on those exact clean bytes; feature-branch CI qualification remains recorded
  separately when available.
- [x] R45.4b3: qualify bounded drain execution. Exact commit
  `33bdd109da2f452afb2058ce0d044d10a729c669`, tree
  `a89963f3e9def2ba65bd43589c87e13a3f4a9b57`, passed Forgejo CI run 122 plus
  the exact-source drain, one-shot regression, indeterminate/no-replay, and R44
  reconciliation Docker matrices. `--drain` follows only one logical operation,
  never sleeps/polls future work, and yields only at a durable boundary under the
  one-minute-per-128-MiB, one-hour-to-six-hour guard; `--once` remains unchanged.
- [x] R45.4b4: durable failed-upload notification is qualified at exact commit
  `96fe661682accaa63e2860dc236cb9c1f4733950`, tree
  `7f938a05c446e000b0d45db76e03e703432a10dc`, with Forgejo CI run 123 plus the
  exact-source notification/no-replay, drain, one-shot, and R44 Docker matrices.
  `peertube_upload_failure_notify` is idempotent per upload-operation record
  revision, resolves the initiating WordPress user with post-author fallback,
  sends only from detached drain execution, retries rejected `wp_mail()` without
  replaying upload work, and excludes credentials, raw response bodies, secret
  references, and filesystem paths.
- [x] R45.5: recurring production wake-up exact candidate
  `07259b927b7cd7ff393807777cf23de4f64c0795`, tree
  `7ec2177ded8d522ceaf8e6911194a8085d25aed2`, passed the dedicated cron-wiring
  WordPress matrix plus the retained drain, indeterminate/notification/no-replay,
  one-shot, and R44 matrices on exact clean bytes. It registers the reviewed
  `PeerTube_Task_Worker_Launcher` on the existing five-minute
  `argent_video_processor_dispatch` event; the callback performs only the
  due/stale owned-task probe and detached `--drain` launch, with no second
  scheduler, browser/admin launch surface, or inline PeerTube HTTP. Feature-branch
  Forgejo CI run 124 is green; the exact candidate is qualified as a development
  checkpoint.
- [x] R45.6 capability activation is qualified at exact commit
  `a73739e5bd051e708f1616a207bf579e6f2abb93`, tree
  `0098986b489a29099c4215fb804cf21f10aff632`; Forgejo CI run 142 is green and
  all eight retained real-WordPress/mock-PeerTube Docker matrices passed on those
  exact clean bytes under Docker 29.8.0. The qualified map advertises only
  AWVP-staged ingest, server push, PeerTube processing, managed embed delivery,
  and the separately qualified R46.5 verified privacy mutation. Direct-browser
  ingest, WordPress-attachment direct ingest, account-video listing/selection,
  provider-native scheduling, backend source-retention guarantees, and remote
  delete remain false. The qualification transcript SHA-256 is
  `deeffa1bc987facb78023c1c447a7e44a6d9cb6fc93f907cadac52c64cd3273f`.


### R46 video destination, publication metadata, and migration

- [x] R46 design contract: changing the site default applies only to newly
  authored videos. Missing legacy destination metadata resolves to WordPress/local
  permanently; existing videos are never rerouted merely because the site default
  changes. One AWVP video block will expose a per-video destination override.
- [x] R46 design contract: PeerTube video tags are independent from WordPress post
  tags and require explicit review, including an explicit reviewed zero-tag state;
  no more than five PeerTube tags may be selected.
- [x] R46 design contract: PeerTube publication metadata includes title, Markdown
  description, channel, support selection/presets, final privacy, licence,
  category, language, optional cover/thumbnail, comments policy, sensitive-content
  declaration/classification, and optional embed-domain restriction. Captions and
  chapters are deferred unless a later implementation makes them a small additive
  extension.
- [x] R46 design contract: unresolved required editorial metadata may block
  WordPress publication, but PeerTube upload/transcoding/readiness does not. The
  local file remains the serving fallback until the remote copy and intended
  visibility are verified.
- [x] R46 design contract: after metadata review the author can choose `Send now`
  or `Send when scheduled or published`. Scheduling may start an early private
  PeerTube upload, but public reveal is authorized by the actual WordPress post
  publication transition, not merely the scheduled timestamp.
- [x] R46 design contract: local-to-PeerTube migration is explicit and logically
  one-way. The wizard supports individual/select-all planning, per-video metadata
  review/Needs Review, local-first serving during migration, verified cutover, and
  separately configured post-cutover retention. WordPress/blog storage is not the
  archival master.
- [x] R46.1: destination/publication-plan model foundation implemented and exact
  source committed/pushed, with the canonical legacy-local destination resolver
  and strict per-video PeerTube
  publication-plan persistence/review contract, but no site default, block UI,
  migration, post-status hooks, visibility mutation, serving cutover, or cleanup.
- [x] R46.2: site/backend publishing defaults and reusable support presets are
  qualified at commit `86c08ea4b9cad49ab55bd462135c2c7b5c3fb6b3`, tree
  `13ce8a4219c99f9d66b4089efb807530ca780e61`, with the exact dependency-free
  qualification and Forgejo CI run 126 green. The non-autoloaded fail-closed
  settings store keeps the upgrade-safe local default, support preset resolution,
  backend channel/provider overrides, moderation prefills that do not imply
  review, and the nonce/capability-protected administrator settings page.
- [x] R46.3a: read-only PeerTube publication-choice discovery is qualified at
  commit prefix `0a13687`, tree
  `3587e3d15fef45d2776f3273ad517b26dc1aebe6`; Forgejo CI run 127 is green.
  The explicit administrator refresh discovers owned channels, provider privacy/
  licence/category/language vocabularies, server version, and conservative
  moderation/privacy signals. Last-known-good non-secret cache state is bound to
  backend + canonical origin + managed-secret generation; failed/refused refresh
  preserves provider data and marks it stale, and page GET performs no remote HTTP.
- [x] R46.3b: the single dynamic AWVP Gutenberg Video block foundation is
  qualified at commit prefix `4905076`, tree
  `67054ea387ec90c484da01e437010772aad08a13`; Forgejo CI run 128 is green.
  Stable AWVP Video ID binding, idempotent WordPress-video adoption, one-time
  default resolution, explicit local/active-PeerTube destination planning, and
  local frontend serving are now the qualified editor foundation.
- [x] R46.3c: the PeerTube publication wizard and explicit review UX are
  qualified at tree `67444badccb625a458f3436cc596557cfce16817`; Forgejo CI run
  129 is green. The resulting commit hash was not captured in this handoff. The
  wizard edits the strict per-video plan, revalidates provider IDs against the
  cached backend catalog, preserves independent reviewed PeerTube tags including
  explicit zero tags, exposes channel/support/privacy/licence/category/language/
  thumbnail/comments/downloads/moderation and dispatch timing, and grants no
  PeerTube HTTP, task dispatch, post-status, reveal, migration, or serving-cutover
  authority.
- [x] R46.4: local-only WordPress editorial publish validation is qualified at
  commit `5c30a62`, tree `922fdb59ca6f784260e1aa5cd6e1ee163118adc1`; Forgejo CI
  run 130 is green. `publish`, `future`, and `private` require coherent local
  destination/anchor/backend/channel state plus explicit title/channel/tags/
  privacy/moderation review, while PeerTube readiness remains non-blocking.
- [x] R46.5a: durable WordPress-authoritative publication lifecycle intent is
  qualified at commit `7b639d8`, tree
  `867a13ba23495bfacb7e2e065061c2ce34637842`; Forgejo CI run 131 is green.
  Reviewed-plan saves and post-status changes derive generation-fenced local
  upload/reveal intent and enqueue `peertube_publication_sync`; scheduled content
  targets PeerTube private, actual WordPress `publish` alone authorizes final
  privacy, and later generations supersede stale reveal intent. This checkpoint
  performs no PeerTube HTTP and did not give the worker ownership of the task.
- [x] R46.5b: detached PeerTube publication execution is qualified at commit
  `8a7685b`, tree `c6b0db36226bf17738840b3474afdb05af3529c5`; Forgejo CI run
  132 is green. The detached `--drain` worker owns publication sync/finalize,
  reuses the private resumable uploader, positively verifies final publication,
  rechecks WordPress after non-private PUTs, and immediately re-privates if reveal
  authority changed. `--once` remains upload/reconcile only and serving cutover
  remains separate.
- [x] R46.6: verified local-first PeerTube serving cutover is qualified at commit
  prefix `012bcdc`, tree `03cc4e647a65c778ace3fbaddb02374f4e9f9b4b`; Forgejo CI
  run 133 is green. Public/unlisted remote serving requires exact current local
  lifecycle/plan/destination/execution/asset evidence; any uncertainty, private
  target, or superseding WordPress state immediately retains the local shortcode
  path with no frontend PeerTube HTTP.
- [x] R46.7: existing-video migration planning and Needs Review are qualified at
  commit `15b5dec`, tree `0eb6728c2a39a6a00440153dae54fb215eee26da`; Forgejo CI
  run 134 is green. The bounded planner writes only inert migration state, preserves
  same-target review, and never silently truncates more than five WordPress tag
  suggestions or wakes publication execution.
- [x] R46.8: explicit one-way migration promotion is qualified at commit
  `9485ebb`, tree `3ed54e0c5cd7c436b54f63da49b3dcf76305cfc6`; Forgejo CI run
  135 is green. `ready` plans are revalidated against current source identity,
  active backend, fresh current-secret-generation catalog, provider choices,
  support preset, and thumbnail bytes before the crash-recoverable migration
  execution journal commits. Promotion converges forward through the qualified
  publication synchronizer and freezes backend/channel rollback or retargeting.
- [x] R46.9: explicit post-cutover local retention is qualified at commit
  `773c7e9`, tree `9f1e853b1c0a4a526a1ae73ad17c8f5ccc686132`; Forgejo CI run 138
  is green. KEEP is the default. Per-video policies may delete only AWVP-managed
  copies or, after an explicit non-WordPress master-authority decision, all local
  video copies. Destructive policies require a 1-365 day grace period and current
  verified PeerTube serving evidence. Cleanup is a durable detached-worker task,
  rechecks serving/file identity, current exclusive attachment ownership, and
  local-job quiescence immediately before deletion, preserves the WordPress
  attachment object, and treats every uncertainty as KEEP.

### 2.0 release-candidate and final-release gates

- [x] Enter the controlled RC line as `2.0.0-rc1` while leaving WordPress.org
  `Stable tag: 1.0.0`; enforce that split in normal CI and canonical builds.
- [x] Add regression coverage proving PHP/WordPress ordering for `1.0.0 < 2.0.0`,
  increasing `2.0.0-rcN`, and `2.0.0-rcN < 2.0.0`.
- [x] Qualify R46.9 hardening on exact Forgejo source/CI and close the final R46
  implementation checkpoint: `773c7e9` / tree
  `9f1e853b1c0a4a526a1ae73ad17c8f5ccc686132` / Forgejo CI run 138.
- [x] Qualify the final R45.6 capability truth-up on exact source before RC
  integration: `a73739e5bd051e708f1616a207bf579e6f2abb93` / tree
  `0098986b489a29099c4215fb804cf21f10aff632` / Forgejo CI run 142 plus all eight
  retained real-WordPress/mock-PeerTube Docker matrices.
- [x] Merge the qualified R45/R46 feature line into `develop-2.0` at
  `f7165ae`; Forgejo CI run 144 is green.
- [x] Close the first RC filesystem blocker at `eef5c31`; Forgejo CI run 145 is
  green. PeerTube publication staging now captures only a real WordPress video
  attachment confined beneath the current uploads root and copies only into the
  validated AWVP-managed staging subtree.
- [x] Complete the remaining 2.0 filesystem delta audit against the
  WordPress.org-approved 1.0 confinement model. The video-source blocker was closed
  at `eef5c31` / Forgejo CI 145; the remaining custom-thumbnail escape was closed at
  `0dd5189b55f5b2634fe4dd9dfbb2327a5a79fd99` / Forgejo CI 147. PeerTube staging
  and AWVP-managed derivatives remain beneath `wp_upload_dir()['basedir']`, managed
  deletion remains behind `Storage`, physical source deletion remains behind confined
  attachment-derived identity plus `wp_delete_file()`, and publication thumbnails are
  captured from confined WordPress image attachments into bounded in-memory bytes
  before HTTP so arbitrary/stored filesystem paths never become outbound or deletion
  authority.
- [x] Cut and preserve the first canonical RC1 candidate before tagging: Forgejo run 150
  built commit `8c91274db7c38786878c69083c99522944df1d65`, tree
  `352a36fda45937873ea21cc5ae6534b117ebab04`, ZIP SHA-256
  `ee15a748a2fcda27dea2339888b570a85d848718a01947c56f581bfd39e18ab0`.
  Exact installed-package identity passed, but Plugin Check 2.1.0 static-new failed;
  RC1 was not tagged or live-distributed and those exact bytes remain failed evidence.
- [x] Remediate the RC1 Plugin Check findings at `1dacb4a`; Forgejo CI run 151 is green.
  Keep the existing filesystem/atomic/streaming safety boundaries intact, move release
  reports beneath the AWVP project parent, and permit only the intentional prerelease
  `stable_tag_mismatch` while every other Plugin Check ERROR/WARNING remains blocking.
- [x] Advance to `2.0.0-rc2` and preserve one canonical package: Forgejo run 154
  built commit `865a36190478c3f92c5fc69a09e1260cd7eb75ca`, tree
  `de4d422d065a0a4a9c067df31924dfb98d11863b`, ZIP SHA-256
  `d5d8296b0299e3d09fd2cf2a097054c56a9a39bbfd889ea848b946184813b29d`.
  Exact package identity passed; RC2 was not tagged or live-distributed.
- [x] Run the canonical RC2 Plugin Check entry gate. The intentional prerelease
  `stable_tag_mismatch` was accepted, but static-new stopped on five nonce-analysis
  warnings plus two false-positive hook-prefix warnings before the public-1.0.0
  upgrade phases ran. Preserve the exact run-154 report/package as failed evidence.
- [x] Correct those seven PHPCS suppression-scope findings without changing runtime
  behavior or weakening nonce enforcement; Forgejo CI run 155 is green.
- [x] Advance the corrected source to `2.0.0-rc3`, obtain green Forgejo CI, and
  preserve one canonical package. Canonical run 158 built commit
  `e9a8e6c42c04e81b3d7d1c3b92c78568c1f8ee49`; exact ZIP SHA-256
  `a5e8e51edf71f41be65cb6d4d2c456aa3f3505cdc73bd82dc5350611de4709ac`.
  The corrected reusable RC3 release-validation payload passed from exact public
  1.0.0 across WordPress 6.4/PHP 8.1/MariaDB 10.6, WordPress 7.1/PHP 8.3/
  MariaDB 10.11, and WordPress 7.1/PHP 8.3/MySQL 8.0. The upgrade fixtures prove
  stored legacy `core/video` markup, attachment relationship/metadata, source/managed
  bytes, and completed local job remain unchanged, while the explicit 2.0
  `argentwolf-video-processor/video` block registers and renders locally.
- [x] Run the official WordPress Plugin Check 2.1.0 against the exact canonical RC3
  ZIP. Static `new`, runtime `new`, and runtime `update` passed with only the
  intentional prerelease `stable_tag_mismatch` allowed; all other ERROR/WARNING
  findings remain blocking. Historical 1.0 filesystem/package-review parity was also
  rechecked: generated media remains uploads-confined, raw writes/renames remain
  behind `Storage`, and repository-only/vendor-metadata files are absent from the ZIP.
- [x] Pass the exact-package isolated Docker upgrade/clean-install, regression,
  Plugin Check, database-repair, and package-identity release gate for canonical RC3.
- [x] Re-run the self-contained exact-package RC3 release-validation bundle on the
  preferred disposable `ubuntuzfstest` VM with the no-production/no-PeerTube
  isolation contract. The exact run-158 candidate, public 1.0.0 base, pinned Plugin
  Check 2.1.0 package, and validation harness identities were preserved; the VM gate
  passed and RC3 remained byte-for-byte unchanged.
- [x] Complete the RC4 administrator-UI remediation identified during the controlled
  RC3 live walkthrough: consolidate all AWVP settings under one tabbed settings page,
  clarify PeerTube server setup/credentials/phases/actions and Backend ID validation,
  use release-facing copy, and render administrator times in the WordPress timezone.
  Forgejo CI 161 qualified the UI commit and CI 162/163 qualified the RC4 transition/
  canonical source line. Canonical run 163 (internal run ID 438) built commit
  `7bac80f43fdc91bdedff01d52615cf14a658878d`, tree
  `992fa89ba60059466f8589ab8bed3744552407f2`, ZIP SHA-256
  `544d16307eb8e087a5b11dcfe024b11c2157be48731f0a7b585d84c593ec6f22`.
- [x] Preserve canonical RC4 as failed release evidence. Exact package identity passed,
  but Plugin Check 2.1.0 static-new stopped before upgrade/clean-install phases on
  `upgrade_notice_limit` and one `WordPress.Security.NonceVerification.Recommended`
  finding; only the intentional prerelease `stable_tag_mismatch` was allowed. Do not
  rebuild or reuse RC4 bytes.
- [x] Resolve the two RC4 Plugin Check findings at remediation commit `4beda89`: keep
  every WordPress.org upgrade notice at or below 300 characters with regression
  coverage, and narrowly cover the already-sanitized read-only Local Retention notice
  selector without weakening nonce enforcement. Forgejo CI run 164 is green.
- [x] Advance the corrected source to `2.0.0-rc5` and preserve its one canonical
  package. Forgejo CI 165 qualified the RC5 transition. Canonical run 166 (internal
  run ID 441) built commit `e9b2f725b3f06c20550b59044d58456774e7f8a3`,
  tree `026c32e3f5ddf4af79828412a1a0ed82eaf36a1b`, ZIP SHA-256
  `b68212fdedf25d190535b3e9a65d26cd1a7cfe18c07792eaef50208f5cb83c08`.
- [x] Preserve canonical RC5 as failed release evidence. Exact package identity passed,
  but Plugin Check 2.1.0 static-new stopped before upgrade/clean-install phases on
  `WordPress.Security.ValidatedSanitizedInput.MissingUnslash` and
  `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` for the read-only
  Local Retention notice selector; only the intentional prerelease
  `stable_tag_mismatch` was allowed. Do not rebuild or reuse RC5 bytes.
- [x] Resolve the RC5 input-sanitization findings at remediation commit
  `1a108800145f53230ba57fc0c954eeb892035b90`, tree
  `c6d1c0b53e0313cc358e194591b6f5f91799e555`: unslash and sanitize the read-only
  Local Retention notice directly at the request boundary while preserving the
  reviewed nonce-verification suppression and retention behavior. Forgejo CI 167 is
  green.
- [x] Advance to canonical `2.0.0-rc6`: Forgejo run 169 (internal run ID 444)
  built commit `7f8f7da2647d56a458d33367b36e4fe622281afd`, tree
  `d70134d0486fea81553f2dfe6e06b52dfc4c1ed4`, ZIP SHA-256
  `56800942972df47970208d2a14f93650252a9702abce747c1d45ce1304430324`. Preserve
  those bytes as immutable RC6 evidence.
- [x] Resume controlled live WordPress/PeerTube testing with canonical RC6 on
  `wolfandraven.blog`. The walkthrough reached real provider setup and publication,
  then exposed stock UploadX protocol-relative `Location` rejection, an
  upload-indeterminate finalizer loop, local hls.js/MediaElement player conflict,
  and release-facing authoring/setup usability defects.
- [x] Advance to canonical `2.0.0-rc7`. Forgejo CI 171 built source commit
  `8f6e54c`, tree `e148985c3a37111c36d30fa52c0a58fbf5aaf67e`; exact package SHA-256
  `89bf6d73eb0e6466eb587eabbb01d822d980e1ecd84492475033aa7c27b2e10a` passed
  Plugin Check 2.1.0, exact installed-package identity, the complete three-clean /
  three-public-1.0.0-upgrade matrix, and disposable `ubuntuzfstest` qualification.
- [x] Install canonical RC7 through the normal WordPress web UI on
  `wolfandraven.blog`. Existing local video playback now renders picture+sound
  without autoplay, the RC6 indeterminate upload remains untouched at attempt 1,
  and its finalizer stops at the explicit intervention boundary instead of polling.
- [x] Diagnose the fresh RC7 publication blocker: revision `7952` (`inherit`) was
  created while anchor post `7948` remained `publish`, and its transition superseded
  video `7950` lifecycle generation 3 to `upload_authorized=false`. Fix by ignoring
  revisions/autosaves and requiring immutable origin-anchor ownership for transition
  authority; add regression coverage. Alphabetize category presentation while cutting
  the required next candidate.
- [x] Advance to canonical `2.0.0-rc8`. Source commit
  `57ba6de225fee3bca200c2faaaf214086eaa83c7`, tree
  `49ffd65ccffdecc850bce89a4d7ff1b538391951`, exact package SHA-256
  `943b729de982c48a8019cf0dddda5a746e59d0d5be1ab0900784592981d215dd` passed
  Plugin Check 2.1.0, exact package identity, and all three clean / three public-1.0.0
  upgrade release-validation cases.
- [x] Resume controlled live validation with canonical RC8. The revision/origin-anchor
  fix held, but Test 3 exposed additional RC9 requirements: PeerTube publication must
  use the WordPress original instead of requiring a redundant local FFmpeg derivative;
  empty optional `support`/`nsfwSummary` request fields must be omitted; consequential
  publication needs read-before/read-after verification and safe retry semantics;
  detached PeerTube work must wake promptly instead of waiting for the five-minute cron;
  incomplete work needs bounded recovery/Resume and recognizable administrator status;
  verified remote serving must survive allowed local-source removal and clean only
  generated/staging derivatives automatically.
- [ ] Advance the corrected source to `2.0.0-rc9`, obtain green Forgejo CI, build one
  canonical RC9 package, and rerun Plugin Check plus the complete exact-package
  clean-install/public-1.0.0-upgrade matrix and disposable `ubuntuzfstest` gate.
- [ ] Install canonical RC9 through the WordPress web UI on `wolfandraven.blog` and
  resume the preserved live tests. Prove event-driven pickup, original-source resumable
  upload, remote processing, read-after-write publication verification, serving cutover,
  non-autoplay PeerTube playback, bounded recovery/Overview behavior, and verified
  derivative cleanup. Leave historical RC6/RC8 indeterminate evidence untouched.
- [ ] If defects require code changes, increment `2.0.0-rcN`, rebuild, and rerun
  the affected gates; never mutate or reuse an existing RC version/tag.
- [ ] Freeze the last accepted RC and promote to `2.0.0` with release/version
  metadata changes only; set `Stable tag: 2.0.0` and rerun the exact package gates.
- [ ] Publish final `2.0.0` through WordPress.org SVN only after final package
  qualification, then prove the controlled live `2.0.0-rcN -> 2.0.0` automatic
  WordPress update path.
- [ ] Separately prove a clean public `1.0.0 -> 2.0.0` WordPress upgrade before
  declaring the 2.0 release closed.
