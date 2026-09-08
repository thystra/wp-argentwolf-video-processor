<!-- File: CHANGELOG.md -->
# Changelog

## 2.0.0-rc5 - 2026-09-08

- Preserve canonical RC4 as immutable failed release evidence: Forgejo run 163 (internal run ID 438) built commit `7bac80f43fdc91bdedff01d52615cf14a658878d`, tree `992fa89ba60059466f8589ab8bed3744552407f2`, package SHA-256 `544d16307eb8e087a5b11dcfe024b11c2157be48731f0a7b585d84c593ec6f22`. Exact installed-package identity passed, but Plugin Check 2.1.0 static-new stopped before upgrade/clean-install phases on `upgrade_notice_limit` and one `WordPress.Security.NonceVerification.Recommended` finding; the intentional prerelease `stable_tag_mismatch` remained allowlisted.
- Shorten the RC upgrade notice to the WordPress.org 300-character limit and add a regression that checks every upgrade notice. Narrowly extend PHPCS suppression around the already-sanitized read-only Local Retention notice selector without changing nonce enforcement or runtime behavior.
- Forgejo CI run 164 is green for remediation commit `4beda89`. RC5 is a new candidate identity; RC4 bytes are never rebuilt, relabeled, or reused.

## 2.0.0-rc4 - 2026-09-08

- Consolidate ArgentWolf Video Processor administration under one tabbed **Settings > ArgentWolf Video Processor** page with Local Processing, PeerTube Servers, Publishing, Video Migration, and Local Retention tabs. The existing nonce/capability/action handlers remain authoritative; this change replaces the split menu surfaces rather than creating a second control path.
- Rework PeerTube server setup as a guided release-facing workflow: **Connect a PeerTube Instance**, **PeerTube URL**, constrained permanent **Backend ID**, friendly **Connection Label**, **Add PeerTube Server**, human-readable setup phases, explicit credential/channel/activation guidance, and field-specific validation errors. PeerTube username/password plus optional six-digit OTP are exchanged for managed encrypted tokens; no manually generated API key is required.
- Render administrator-facing timestamps through the configured WordPress timezone/date/time preferences and replace raw credential-lifecycle/internal-state wording with administrator-facing status labels. Improve publishing, migration, retention, and WordPress.org/README navigation copy to match the consolidated UI.
- Preserve RC3 as immutable qualification evidence. RC3 passed the exact-package Docker matrix, Plugin Check 2.1.0 gate, and disposable `ubuntuzfstest` qualification; controlled live testing then exposed the administrator-UX blockers corrected in RC4.

## 2.0.0-rc3 - 2026-09-07

- Correct the narrow PHPCS suppression scopes revealed by canonical RC2 Plugin Check 2.1.0 static-new: action-specific nonce seed reads, read-only query selectors, and the already-prefixed `argentwolf_video_processor_publication_plan_saved` hook are now covered at the exact multi-line expression/call sites that the sniffs inspect. This is a scanner-scope correction only and does not change runtime behavior or relax nonce enforcement.
- Preserve canonical RC2 as failed release evidence: Forgejo run 154 built commit `865a36190478c3f92c5fc69a09e1260cd7eb75ca`, tree `de4d422d065a0a4a9c067df31924dfb98d11863b`, package SHA-256 `d5d8296b0299e3d09fd2cf2a097054c56a9a39bbfd889ea848b946184813b29d`. Exact package identity passed and the intentional prerelease Stable-tag mismatch was allowed, but five nonce-analysis warnings and two false-positive hook-prefix warnings stopped static-new before upgrade phases.
- Forgejo CI run 155 is green for the scope-only correction. RC3 is a new candidate identity; RC1 and RC2 package bytes remain immutable failed evidence.

## 2.0.0-rc2 - 2026-09-07

- Address WordPress Plugin Check 2.1.0 findings discovered by the first canonical RC1 release-validation pass without weakening the 1.0-derived filesystem, atomic-state, or streaming safety boundaries. Normalize request input handling, add translator context, prefer WordPress URL/tag helpers, remove the WordPress-6.5-only `array_is_list()` dependency, and narrowly document reviewed direct-SQL/cURL boundaries where WordPress APIs do not provide equivalent semantics.
- Keep release-validation reports beneath the AWVP project parent and allow only the intentional prerelease `stable_tag_mismatch` finding while WordPress.org Stable tag remains `1.0.0`; every other Plugin Check ERROR/WARNING remains release-blocking.
- Preserve canonical RC1 as failed release evidence: Forgejo run 150 built commit `8c91274db7c38786878c69083c99522944df1d65`, tree `352a36fda45937873ea21cc5ae6534b117ebab04`, package SHA-256 `ee15a748a2fcda27dea2339888b570a85d848718a01947c56f581bfd39e18ab0`. Exact package identity passed, but Plugin Check static-new failed before tagging or live deployment.

## 2.0.0-rc1 - 2026-09-07

- Address findings from the first canonical RC1 WordPress Plugin Check 2.1.0 pass:
  normalize request inputs, add translator context, prefer WordPress URL/tag helpers,
  remove checker-incompatible list helpers, narrowly document the reviewed atomic-SQL/
  dynamic-IN/cURL streaming boundaries, keep validation reports under the AWVP project
  parent, and allow only the intentional prerelease `stable_tag_mismatch` while every
  other ERROR/WARNING remains release-blocking.
- Align the PeerTube backend capability map with qualified 2.0 runtime support: AWVP-staged/server-push ingest, PeerTube processing, embed delivery, and verified privacy mutation are advertised; direct-browser ingest, account-video selection, provider-native scheduling, source-retention guarantees, and remote delete remain disabled.
- Add R46.9 explicit post-cutover local retention. KEEP remains the default;
  destructive per-video policies require a 1-365 day grace period and current
  verified PeerTube serving. Cleanup is journaled and detached, fences local
  processing, requires exclusive/current attachment ownership, revalidates a
  confined stat identity through ctime before source deletion, preserves the
  WordPress attachment object, and treats uncertainty as KEEP. R46.8 is qualified at commit
  `9485ebb`, tree `3ed54e0c5cd7c436b54f63da49b3dcf76305cfc6`, Forgejo CI 135 green.

- Add R46.6 verified local-first serving cutover. A strict non-secret serving
  authority is written only from positively verified current publication evidence;
  the AWVP block independently revalidates lifecycle/plan/execution/remote-asset
  state on render and otherwise falls back to the local WordPress player. Only
  public/unlisted PeerTube targets cut over in this checkpoint; private/internal
  audience semantics remain local.

- Add the R46.5b detached publication executor. Generation-fenced publication
  tasks now freeze a non-secret reviewed manifest, stage/reuse an immutable MP4,
  reuse the existing private resumable upload/reconciliation path, and only after
  `ready_verified` apply and verify current WordPress-authorized PeerTube metadata
  and privacy. Non-private updates re-check WordPress afterward and immediately
  correct to Private if reveal authority changed in flight; per-video execution
  locking prevents adjacent generations from performing concurrent publication
  mutation. The `--once` diagnostic set remains upload/reconcile only and serving
  cutover is still deferred.
- Add the R46.5a WordPress-authoritative publication lifecycle intent boundary.
  Reviewed plan saves and post-status changes derive generation-fenced local
  private-upload/reveal intent and enqueue `peertube_publication_sync` without
  PeerTube HTTP or worker ownership. Actual WordPress `publish` is the only
  state that may authorize final privacy; reschedule/revert/private/trash intent
  supersedes older reveal generations with a private target.

- Enter the controlled 2.0 release-candidate line. The plugin/runtime version is `2.0.0-rc1` while WordPress.org `Stable tag` deliberately remains `1.0.0`; RC packages stay on Forgejo and are not published to WordPress.org SVN.
- Preserve the exact public `v1.0.0` lineage on the permanent `release/1.x` maintenance branch before final 2.0 promotion.
- Add R46.8 explicit one-way local-to-PeerTube migration promotion. A fresh execution revalidates the ready migration plan against current source/provider/default/thumbnail state, creates a crash-recoverable local commitment journal, promotes the exact reviewed publication plan and concrete destination, and hands off only through the existing publication synchronizer. No second uploader, provider HTTP path, serving cutover, or cleanup authority is introduced; committed migration targets cannot be rolled back or retargeted by ordinary editor destination controls.
- Add the R46.7 existing-video PeerTube migration planner and Needs Review workflow. Planning is isolated from live destination/publication/lifecycle/execution/serving state, supports bounded selected/select-all batches, preserves completed same-target review, and keeps WordPress tags as explicitly-reviewed suggestions without truncating more than five. No upload, task dispatch, provider HTTP, or serving cutover is added.
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
- Add the R45.4b3 bounded-drain execution mode. The detached launcher now invokes
  `wp argent-video peertube-task-worker --drain`; the worker reclaims only the
  same immediately-runnable task (or that operation's deterministic reconciliation
  handoff), never sleeps or polls future work, and yields only at a durable request
  boundary. Runtime/request guards scale at one minute per 128 MiB with a one-hour
  floor and six-hour ceiling; `--once` remains available unchanged.
- Add and qualify the R45.4b4 durable failed-upload notification boundary.
  Upload failures or holds that require human attention enqueue the dedicated
  `peertube_upload_failure_notify` task before the failing task is released; drain
  execution resolves the initiating WordPress user (falling back to post author)
  and sends a sanitized `wp_mail()` message with post/backend/state/progress,
  last-request size, transport/API classification, HTTP status/retry detail when
  available, and an AWVP admin link. Mail rejection is retried durably without
  replaying the upload; credentials, filesystem paths, and raw remote bodies are
  excluded. Exact commit `96fe661682accaa63e2860dc236cb9c1f4733950`, tree
  `7f938a05c446e000b0d45db76e03e703432a10dc`, passed Forgejo CI run 123 and the
  retained exact-source notification/no-replay, drain, one-shot, and R44 matrices.
- Wire the R45.5 production wake-up through the existing five-minute
  `argent_video_processor_dispatch` event. The callback only probes for due queued
  or stale owned PeerTube tasks and invokes the already-reviewed detached
  `peertube-task-worker --drain --quiet` launcher; it performs no PeerTube HTTP
  inline, adds no second scheduler, and adds no administrator transfer-launch
  surface.
- Begin R46 destination/publication modeling without enabling editor or remote
  publication mutation. Missing legacy destination metadata resolves permanently
  to WordPress/local rather than the current site default; malformed present
  destination state fails closed. Add a versioned per-video PeerTube publication
  plan with independent explicitly reviewed tags (maximum five), title/Markdown
  description, channel, support selection, provider vocabulary IDs, comments,
  sensitive-content/moderation and embed-domain policy, dispatch timing, and a
  WordPress-authoritative release policy. Remote readiness remains distinct from
  editorial metadata readiness and does not block WordPress publication.
- Add R46.2 durable video-publishing defaults and reusable support presets. The
  non-autoloaded settings record defaults safely to WordPress/local when absent,
  preserves malformed/future stored state, supports site and active-PeerTube
  backend overrides, and exposes a nonce/capability-protected Settings page.
  Moderation values are editor prefills only and never satisfy required per-video
  review; changing defaults never rewrites existing video destinations/plans.
- Add R46.3a read-only PeerTube publication-catalog discovery. An explicit
  administrator refresh reads the connected account's owned channels and public
  provider vocabularies/configuration, persists a bounded non-secret per-backend
  last-known-good catalog bound to canonical origin + managed-secret generation,
  persistently marks retained data stale after failed/refused refresh, and
  performs no remote video mutation.
- Add the R46.3b single AWVP Gutenberg Video block foundation. The dynamic block
  serializes only a stable AWVP Video ID, adopts existing WordPress video
  attachments idempotently under a per-attachment claim lock, resolves the site
  destination default only for new identities, and exposes explicit local/active
  PeerTube destination selection through a capability-aware REST boundary. Remote
  destination remains planning state: frontend playback stays on the local
  WordPress attachment and no PeerTube dispatch/publication/cutover is enabled.
- Add the R46.3c PeerTube publication wizard/review boundary. The Gutenberg
  inspector edits the strict per-video publication plan with independent reviewed
  tags, cached provider choices, support/thumbnail/comments/download/moderation
  fields, and dispatch timing. Unsupported provider privacy values remain
  discoverable but unselectable; defaults never satisfy required review. Saving
  remains editor-only state and performs no PeerTube HTTP or task/post-status work.
- Add the R46.4 local-only editorial publication gate. Anchored AWVP blocks with
  concrete PeerTube destinations must have coherent destination/plan state and
  explicit title/channel/tags/privacy/moderation review before WordPress may enter
  `publish`, `future`, or `private`. Gutenberg provides an editor lock and the
  server independently enforces REST/pre-write boundaries. PeerTube HTTP, catalog
  freshness, upload/task/transcoding state, and remote readiness are intentionally
  excluded, so reviewed posts remain publishable while remote work is unavailable.
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
