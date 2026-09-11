## 2.0.0-rc12 - 2026-09-10

- Preserve qualified RC11 candidate #4 as immutable live-test evidence: commit `c56090eb9b5fd6f92010b7c00a6bf47154607659`, tree `e6a53bb1a412ab9f1558c0e41c778385a4481ec0`, exact ZIP SHA-256 `0d555201cb1a77f8b515d923f888d88e9421c4b6579d506a8de0940c08e14af6`. RC12 is a new package identity after the fresh-video live test exposed runtime defects.
- Allow reviewed PeerTube description/support Markdown to carry LF/CRLF line breaks through the bounded multipart publication PUT, while retaining strict control-character validation and supporting an empty description.
- Add a local-only safe-finalizer recovery boundary for `mutation_not_sent`: Overview can expose **Retry publication**, requeueing the exact failed finalizer/task generation without re-uploading the source or manufacturing a new publication generation.
- Fix durable operator-event `created_at` writes by supplying the complete `$wpdb->insert()` format map; historical zero timestamps are left untouched rather than guessed.
- Remove the development-only `R46.3c` label from the editor dispatch-timing help copy.
- Gate periodic broken-publication health checks, Overview incidents, and publication-health email ownership behind durable evidence that the remote asset has previously passed public-serving qualification (or exact pre-health serving authority). Initial private/processing publication state remains owned by the publication/finalizer workflow rather than being misreported as an outage.
- Add an explicit administrator resolution boundary for unreconcilable `upload_indeterminate` initialization requests: ordinary Republish remains blocked until the administrator checks PeerTube and confirms no matching remote video exists; retirement preserves the old journal/audit evidence, never sends a remote request, releases only that retired intent fence, and then allows an explicit new-generation Republish. Resumable chunk uncertainties remain reconciliation-only and cannot be retired this way.

<!-- File: CHANGELOG.md -->
# Changelog

## 2.0.0-rc11 - 2026-09-10

- Preserve canonical RC10 candidate #2 as immutable qualification/live-test evidence: commit `215e7b12582d01ea27f4e1c08b965812f3f13d72`, tree `22892bcbc2449d4e0d31fa8bfff5701ff5feb85c`, exact ZIP SHA-256 `3d4ce93bac8611524d03057ecf270e8e309222b0314f5006ba3520c14afac160`. RC11 carries post-RC10 live findings into a new package identity.
- Add model DB schema 3 publication-health state. Periodic detached checks validate the actual unauthenticated public/embed URL that visitors receive; provider API publish state is supplementary diagnosis only and cannot make an unusable public URL eligible. Expected provider processing is a separate non-failure state.
- Add backend-independent serving priority/failover. Local WordPress is priority 0, verified healthy remote publications compete by configured positive priority, and the frontend falls through to the next viable source without render-time network calls. Two-success hysteresis avoids failback flapping.
- Estimate processing readiness per backend from source size plus at most the 10 most recent successful `upload accepted -> publicly playable` observations from the last 90 days. Processing retries use the estimate without spending finite publication-attempt budget.
- Add immediate Overview health incidents, backend-wide outage deduplication, configurable administrator/publishing-user/origin-author email timing (Off / delayed / immediate), recovery notices, and daily PeerTube credential/catalog maintenance as a separate server-level concern.
- Add restart-safe **Republish** from a retained WordPress original to the same or another configured PeerTube server. Republish advances one exact new publication generation/upload operation while preserving historical serving authority, remote assets, tasks, events, and journals until replacement verification completes.
- Improve **Status & Needs Attention** so resolved work disappears, exact still-current issues can be marked reviewed into a collapsed section or removed from the presentation without deleting audit evidence, and actionable rows link to the real review target instead of generic stale diagnostics.
- Add per-server **Serving priority** and default **Language** administration while retaining backward compatibility with RC10 settings records that lack a language override.
- Scale Local Retention for large libraries with one site-wide **AWVP Default Policy**, a compact searchable/filterable one-row-per-video list, per-row selection, and select-all-filtered bulk application. WordPress remains the Archive of Record by default and blocks automatic original-source deletion unless that site-wide authority is explicitly changed.

## 2.0.0-rc10 - 2026-09-10

- Preserve canonical RC9 as immutable live-test evidence. The final RC9 source line includes `878304ba8cbaf24e5017263a2f7f5c6a79f6fbfb` and Plugin Check remediation commit `854014d03b4fd55940ab6e9bc49fa2b13035c3ba`; exact installable ZIP SHA-256 `d28b56e9eaf0ca482559244753677eca10ec0bb4a7bc45e382c42cefc8239c23`. RC10 fixes belong to a new candidate identity and must never replace those bytes.
- Repair live credential/catalog authority: workers can advance the restart-safe refresh lifecycle, refresh generation-bound publication catalogs, force fresh durable option reads at authority boundaries, and return recoverable authority waits to the queue without consuming the finite task-attempt budget. Publication/pre-upload watchers use a short 120-second process budget and promote to the existing size-derived long upload budget only after real upload work is claimed.
- Correct provider semantics from live RC9 testing: valid multi-word tags are accepted; read-back tags compare as normalized unordered sets; authenticated same-origin `/videos/embed/<provider-id>` short identifiers are valid even when they differ from the canonical UUID; local/preflight exceptions and definite HTTP rejections remain distinguishable from uncertain consequential mutation outcomes.
- Add durable operator diagnostics through model DB schema version 2 and `argent_video_events`. The Overview presents seven stable pipeline steps, HTTP status and transfer progress where available, automatic/recommended actions, bounded recent activity, and nested technical IDs while redacting secret-shaped context. Test-5-equivalent applied publications with missing local serving authority are reconciled locally even when the old lifecycle enqueue flag is already clear, without another upload or publication PUT.
- Prevent normal PeerTube routing from manufacturing local FFmpeg cancellations: routing is resolved before local enqueue, smart backlog excludes remote-bound media, and queued/just-claimed race rows are discarded before FFmpeg starts. Genuine progressed cancellation remains visible as an exceptional state.
- Ship responsive frontend containment in `blocks/video/style.css` for both local `<video>` and PeerTube iframe rendering, preserve explicit no-autoplay behavior, and require the stylesheet in the deterministic builder plus Forgejo/GitHub ZIP inspection.
- Add bounded read-only AWVP 1.x migration discovery and explicit adoption. Upgrade/discovery alone never mass-creates 2.0 Videos. Selected legacy attachments can be adopted into a local 2.0 identity during migration planning without FFmpeg, PeerTube work, or historical `post_content` changes; eligible historical Core Video/shortcode rendering follows verified serving authority at runtime while ambiguous multi-post anchors fail closed.
- Replace the per-video master/grace/destructive-confirmation workflow with a fail-closed site-wide archive-of-record policy. WordPress-as-archive is the default and forbids automatic original deletion. A one-time acknowledgement is required only when changing away from that policy; source-deletion permission is rechecked through detached execution, and switching back protects originals immediately.
- Standardize release-facing terminology on **PeerTube server** and label **Backend ID (internal identifier)** with explicit guidance that it is the stable server correlation key used in logs, diagnostics, and error messages.

## 2.0.0-rc9 - 2026-09-09

- Preserve canonical RC8 as immutable qualification/live-test evidence. Source commit `57ba6de225fee3bca200c2faaaf214086eaa83c7`, tree `49ffd65ccffdecc850bce89a4d7ff1b538391951`, and exact package SHA-256 `943b729de982c48a8019cf0dddda5a746e59d0d5be1ab0900784592981d215dd` passed Plugin Check 2.1.0, installed-package identity, and the complete three-clean / three-public-1.0.0-upgrade release-validation matrix. Controlled RC8 live testing then exposed the RC9 execution/recovery defects; RC8 bytes remain immutable.
- Replace five-minute PeerTube execution pacing with durable event-driven task wakeups and a bounded detached watcher that rechecks the site-wide PeerTube-owned queue in at most five-second slices. A separate one-minute WordPress schedule is recovery-only. Normal/exceptional CLI exit releases the short launch lock; `upload_indeterminate` remains a hard no-replay boundary.
- Add bounded incomplete-publication recovery and an administrator Overview. Automatic recovery runs for 24 hours from the original incomplete lifecycle observation, may be explicitly resumed for another 24-hour window, and can never extend beyond the original 168-hour hard cap. **Status & Needs Attention** identifies work by Media Library/post/author information, shows upload phase/byte progress, and relegates operation IDs/remote UUIDs to diagnostics.
- Make PeerTube publication source-first: stage the confined WordPress original with its validated `video/*` content type, prevent AWVP-generated derivatives from becoming upload authority, and cancel queued or already-claimed local FFmpeg work once a non-local destination owns the video. Verified PeerTube serving is resolved before local-source readability, and AWVP-generated/staging derivatives may be removed only after verified remote cutover while the WordPress original remains subject solely to explicit R46.9 retention policy.
- Harden publication finalization from RC8 Test 3: omit blank optional `support`/`nsfwSummary` multipart fields, perform full-state read-before-write/read-after-write verification, converge a provably already-applied prior publication without replaying the PUT, and keep uncertain remote mutations held for explicit review. New installations use `ARGENTWOLF_VIDEO_PROCESSOR_PEERTUBE_PRIVATE_ORIGINS`; the legacy `ARGENT_VIDEO_PEERTUBE_DEV_ORIGINS` alias remains supported.

## 2.0.0-rc8 - 2026-09-08

- Preserve canonical RC7 as immutable qualification/live-test evidence. Forgejo CI 171 built validation-fix source commit `8f6e54c`, tree `e148985c3a37111c36d30fa52c0a58fbf5aaf67e`; the exact installable package SHA-256 `89bf6d73eb0e6466eb587eabbb01d822d980e1ecd84492475033aa7c27b2e10a` passed Plugin Check 2.1.0, exact installed-package identity, three clean-install fixtures, and three public-1.0.0 upgrade fixtures on the disposable VM. The normal WordPress web-UI upgrade on `wolfandraven.blog` also proved restored local HLS video+audio/non-autoplay and the RC6 upload-indeterminate finalizer fence.
- Fix the fresh-publication blocker exposed by RC7 live testing: WordPress created revision `7952` with `post_status=inherit` while published anchor post `7948` remained `publish`; the revision transition incorrectly advanced video `7950` to lifecycle generation 3 with upload/reveal authorization disabled. Publication synchronization now ignores WordPress revisions/autosaves and requires the transitioning post to be the video’s immutable origin anchor before it may advance or revoke PeerTube lifecycle authority. Copied/reused blocks on non-anchor posts remain display-only.
- Present PeerTube categories alphabetically by their human-readable labels in the block publication editor and Publishing settings while retaining provider IDs and cached provider ordering as non-authoritative data.

## 2.0.0-rc7 - 2026-09-08

- Preserve canonical RC6 as immutable live-test evidence: Forgejo run 169 (internal run ID 444) built commit `7f8f7da2647d56a458d33367b36e4fe622281afd`, tree `d70134d0486fea81553f2dfe6e06b52dfc4c1ed4`, package SHA-256 `56800942972df47970208d2a14f93650252a9702abce747c1d45ce1304430324`. Controlled live testing on `wolfandraven.blog` reached the real PeerTube setup/publication path and exposed the RC7 defects; do not rebuild or reuse RC6 bytes.
- Interoperate with stock PeerTube 8.2.4 / UploadX 6.2.1 resumable initialization when `Location` is a protocol-relative network-path reference (`//host/...`). The parser now distinguishes network-path, path-absolute, and absolute references; inherits only the configured scheme for network-path references; canonicalizes default HTTP/HTTPS ports; and retains strict origin, path, query, userinfo, fragment, and upload-session validation. Rejected locations receive bounded safe reason codes rather than persisting the capability-bearing header.
- Treat `upload_indeterminate` as an explicit publication-finalization intervention boundary instead of polling indefinitely. The failed RC6 init sent zero media bytes and remains untouched; RC7 live validation must start a fresh upload operation rather than replaying or mutating the old journal.
- Restore deterministic local playback for the dynamic AWVP Video block by rendering an AWVP-owned native `<video>` element instead of layering hls.js over WordPress MediaElement. Adaptive HLS remains primary; the verified generated MP4 is an emergency compatibility fallback only; inherited autoplay is stripped and PeerTube/local rendering must never autoplay. Add browser/player regression coverage alongside the existing FFmpeg/HLS validation.
- Improve the live authoring workflow: preserve raw PeerTube tag textarea input while typing, condense five explicit-review controls into one user-facing review checkbox while retaining internal fail-closed review fields, render channels/licences/categories/languages by provider label rather than raw IDs, distinguish initial **Load publishing options** from refresh, and add visible connection progress/next-step guidance with administrator-facing copy.

## 2.0.0-rc6 - 2026-09-08

- Preserve canonical RC5 as immutable failed release evidence: Forgejo run 166 (internal run ID 441) built commit `e9b2f725b3f06c20550b59044d58456774e7f8a3`, tree `026c32e3f5ddf4af79828412a1a0ed82eaf36a1b`, package SHA-256 `b68212fdedf25d190535b3e9a65d26cd1a7cfe18c07792eaef50208f5cb83c08`. Exact installed-package identity passed, but Plugin Check 2.1.0 static-new stopped before upgrade/clean-install phases on `WordPress.Security.ValidatedSanitizedInput.MissingUnslash` and `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` for the read-only Local Retention notice selector; the intentional prerelease `stable_tag_mismatch` remained allowlisted.
- Sanitize the Local Retention redirect notice directly at the request boundary with `wp_unslash()`, `sanitize_text_field()`, and `sanitize_key()` while preserving the reviewed read-only nonce-verification suppression and existing retention behavior. Add focused regression coverage for the input-boundary sanitization structure.
- Forgejo CI run 167 is green for remediation commit `1a108800145f53230ba57fc0c954eeb892035b90`, tree `c6d1c0b53e0313cc358e194591b6f5f91799e555`. RC6 is a new candidate identity; RC5 bytes are never rebuilt, relabeled, or reused.

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
