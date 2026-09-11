# ArgentWolf Video Processor agent instructions


## User-facing terminology

Keep administrator/editor copy in plain WordPress publishing language. Prefer **publish** or **upload to the PeerTube server** over internal terms such as *dispatch*. Prefer **change** over *mutate*, **fixed** over *resolved* when describing a corrected problem, and phrases such as **can no longer be changed** over *frozen*. Internal class names, constants, state-machine names, developer documentation, tests, and diagnostics may retain precise implementation terminology where needed; do not leak that vocabulary into ordinary settings/help text unless the distinction is necessary for safe operation.

This file contains public, project-specific guidance for contributors and coding
agents. Private hostnames, user names, deployment paths, credentials, and
production state do not belong in this repository.

## Project identity

- Product name: `ArgentWolf Video Processor`.
- Canonical development repository:
  `https://forgejo.argentwolf.org/alan/wp-argentwolf-video-processor`.
- Public GitHub mirror, public issue tracker, and funding surface:
  `https://github.com/thystra/wp-argentwolf-video-processor`.
- Primary branch: `main`.
- WordPress.org target slug: `argentwolf-video-processor`.
- Main plugin file: `argentwolf-video-processor.php`.
- Text domain: `argentwolf-video-processor`.
- PHP namespace retained for compatibility: `ArgentVideo`.
- WP-CLI command retained for compatibility: `wp argent-video`.

Do not shorten the public product name to “Argent Video Processor.”

Forgejo is authoritative for development history, branches, CI decisions, and
release preparation. GitHub is a downstream public mirror and the preferred
public issue/funding surface. A public GitHub issue may be promoted manually
into a Forgejo development issue when implementation work begins.

## Safety and workflow

- Verify host, repository, branch, remotes, HEAD, and worktree before mutation.
- Use prospective staging and validation before applying broad changes.
- Keep backups, validation reports, applicators, migration utilities, and other
  maintainer-only artifacts outside the tracked checkout. If `.project-local/`
  is used inside a checkout, it must be excluded locally and never committed.
- Preserve unexpected local work and stop rather than guessing.
- Distinguish applied, tested, committed, pushed, mirrored, packaged,
  submitted, approved, released, installed, and deployed. One state does not
  imply another.
- Treat documentation review as part of every implemented change, milestone,
  and tranche. Before declaring work complete, compare affected Markdown,
  examples, configuration references, CLI descriptions, release notes, source
  comments, and WordPress.org `readme.txt` with the resulting code; update
  stale text and verify local documentation links.
- Do not publish, deploy, activate migrations, or upload a WordPress.org release
  merely because code exists or CI is green.
- Repository-only tooling and evidence must never leak into the installable
  WordPress plugin ZIP.

## AI-assisted maintenance

AI-assisted tools may help draft, inspect, test, and review changes. The human
maintainer must inspect the result, approve the design, execute or review
validation, control releases and deployments, and remain accountable for the
software.

## R46.7 migration planning boundary

`PeerTube_Migration_Plan`, `PeerTube_Migration_Planner`, and `PeerTube_Migration_Admin` are planner-only. They may read local AWVP Video/attachment/post metadata, publishing defaults, backend descriptors, and cached publication catalogs, but may write only `Video_Meta::PEERTUBE_MIGRATION_PLAN`. They must not write live destination/publication/lifecycle/execution/serving state, enqueue tasks, perform PeerTube HTTP, or delete media. `MAX_SELECT_ALL` is deliberately bounded, and repeated same-source/same-target planning must preserve completed review. R46.8 is the only checkpoint allowed to promote a reviewed migration plan into executable state.

## WordPress development policy

`wordpress-development.md` is a required companion to this file. Review it
before changing WordPress-facing behavior, filesystem usage, settings, security
boundaries, third-party dependencies, packaging, `readme.txt`, or release
workflow.

When project-specific instructions and general WordPress guidance differ, use
the stricter safe rule unless a deliberate, documented project decision says
otherwise.

## Compatibility invariants

The public rename must not reset or migrate established installation data
without a separately reviewed migration. Retain the existing:

- `argent_video_processor_*` options;
- `_argent_video_*` attachment metadata;
- `argent_video_jobs` database table;
- `argent_video_*` hooks and cron identifiers;
- `wp argent-video` CLI command;
- Settings page slug `argent-video-processor`.

The directory and main-file rename requires an explicit upgrade test from the
legacy `wp-argent-video-processor/wp-argent-video-processor.php` basename.

Temporary operator migration utilities used to validate a development change
must remain outside the submitted plugin package. Do not retain a legacy
migration path in the public runtime unless that compatibility behavior has
been intentionally designed, reviewed, and tested as a supported feature.

Existing legacy identifiers remain where compatibility requires them. New global identifiers introduced after the public rename should use the canonical `argentwolf_video_processor_*` prefix (or the full `argentwolf-video-processor` slug where hyphenated slugs are appropriate) unless a separately reviewed compatibility requirement says otherwise.

## Architecture invariants

- Preserve every original WordPress attachment.
- Never run FFmpeg inside the recurring WP-Cron callback or an administrator web
  request.
- The recurring event may only inspect the queue and launch a detached worker.
- Run at most one worker per WordPress site.
- Claim jobs atomically and recover stale jobs safely.
- Build output in temporary locations and validate it before atomic installation.
- Keep temporary and final plugin-created media inside the same managed uploads
  boundary so promotion can remain same-filesystem and atomic.
- Resolve the uploads base dynamically with `wp_upload_dir()`; never assume
  `wp-content/uploads`.
- All plugin-created media belongs under:
  `wp_upload_dir()['basedir']/argentwolf-video-processor/<attachment-id>/`.
- Validate that every destination is inside the managed plugin uploads root
  before `wp_mkdir_p()`, FFmpeg output, `file_put_contents()`, `rename()`,
  `wp_delete_file()`, recursive deletion, or another filesystem mutation.
- Path validation must reject traversal, sibling-prefix tricks, and unsafe
  symlink resolution. Do not rely on a later URL conversion as the first
  confinement check.
- Do not write generated media into the plugin directory, WordPress core,
  another plugin or theme, or an arbitrary path outside the managed uploads
  root.
- Strip generated-file metadata when enabled, but do not claim the original was
  sanitized.
- Keep progressive fallbacks when adaptive HLS is enabled.
- Use administrator-configured system FFmpeg, FFprobe, and WP-CLI binaries. Do
  not bundle FFmpeg.
- Treat FFmpeg security advisories as capability-aware runtime gates. Record the CVE ID and NVD URL, check whether the affected decoder/encoder is compiled in, recognize known fixed release floors, and block new transcoding when an affected enabled capability is not known patched.
- CI must not process media with an FFmpeg build that the runtime security gate would block. `build/install-ci-ffmpeg.sh` pins reviewed security-patched FFmpeg releases, verifies the official release signature and signing-key fingerprint, and keeps MagicYUV enabled so CVE-2026-8461 tests exercise the patched decoder path. Routine CI should consume the project-owned prebuilt image under `build/ci/ffmpeg/` rather than recompiling the same FFmpeg release on every runner. Compile again when the FFmpeg/image definition changes or when compilation/provenance itself is the test objective. Review current advisories before changing the pin or image.
- Treat shell command paths and arguments as untrusted. Validate configuration,
  use fixed argument construction, and quote arguments safely before execution.
- Public requests must never directly execute FFmpeg.
- Persistent operational diagnostics belong in bounded database-backed history, not append-only filesystem logs.
- Detached-process/bootstrap capture is ephemeral scratch data: allocate it through WordPress temporary-file facilities, associate it with a durable run record before launch, persist bounded useful output before deletion, and reconcile stale captures before a later launch can discard evidence.
- Bound diagnostics in both dimensions: enforce a per-record capture ceiling and a finite record-count retention policy. Error retention must also be bounded and administrator-configurable.

## Source layout

- `argentwolf-video-processor.php`: metadata, constants, dependency loading, and
  bootstrap only.
- `includes/`: runtime services.
- `blocks/`: canonical block.json metadata plus shipped Gutenberg editor assets.
- `assets/js/`: locally maintained browser player integration.
- `assets/vendor/`: runtime third-party browser assets that are actually shipped.
- `build/`: deterministic release tooling.
- `tests/`: dependency-free, open_basedir, smoke, vendor, storage-boundary, and
  FFmpeg tests.
- `.forgejo/workflows/`: canonical Forgejo CI and release-candidate workflow
  definitions.
- `.github/workflows/`: downstream public-mirror validation workflows only;
  they must not independently rebuild or publish canonical release bytes.
- `wordpress-org-assets/`: source-controlled WordPress.org directory artwork;
  never part of the installable plugin ZIP.
- `ARCHITECTURE.md`: design and invariants.
- `wordpress-development.md`: WordPress.org/security/packaging guidance for
  contributors and agents.
- `TODO.md`: milestones and release gates.

Prefer focused classes over adding substantial logic to the main plugin file.
Filesystem ownership and path confinement should be centralized rather than
reimplemented ad hoc by individual callers.

## Editing and patching

- Require a clean worktree before broad transformations.
- Back up outside the checkout, preferably under the project workspace rather
  than inside the Git repository.
- Build and validate a prospective tree before modifying the checkout.
- Prefer complete-file installation or reviewed unified patches over fragile
  global substring replacements.
- Review the staged diff independently of the applicator that produced it.
- Do not add private operator information to public documentation.
- Do not commit release ZIPs or transient validation output.
- Build-time verification metadata may exist in build/test workspaces, but only
  runtime-required files belong in the release ZIP.
- A one-time production migration utility for the legacy derivative layout may
  be retained in maintainer-local project support storage, but must not be
  shipped in the WordPress.org package.

Applicators and validators are part of the release-safety boundary. When an applicator defect is discovered, record the failure signature, root cause, prevention rule, and a focused regression guard rather than merely repairing the immediate anchor.

Prefer structural edits over presentation-sensitive text anchors. In descending order of preference, use an exact reviewed Git base or file checksum; file path plus class/method/function/hook identity; parser/token/AST or keyed-field structure; a reviewed unified patch; explicit managed-region markers; and only then tightly scoped plain-text matching for small controlled files. Avoid anchors that depend on whitespace, indentation, wrapping, bullet order, or the exact wording of natural-language prose when a more stable boundary exists.

For documentation on an exact reviewed Git base, patch by parsed section boundaries or install a reviewed complete-file/unified-patch result, then validate the resulting diff and required semantic content. Do not make an otherwise-correct applicator fail merely because one explanatory sentence was rewrapped or rephrased.

## Validation

Before commit, run the applicable complete suite. At minimum:

```bash
find . -type f -name '*.php' -not -path './dist/*' -print0 |
  sort -z |
  xargs -0 -n1 php -l

php tests/run.php
php -d open_basedir="${PWD}:/tmp" tests/open-basedir.php
php tests/smoke-load.php
php tests/ffmpeg-integration.php
bash tests/vendor-fetch.sh
node --check assets/js/argent-video-player.js
git diff --check
```

Storage or deletion changes also require focused tests proving that:

- custom WordPress upload locations work;
- generated, temporary, and final paths stay under the managed plugin root;
- traversal and sibling-prefix paths fail closed;
- unsafe symlink targets fail closed;
- no write, rename, or delete operation can escape the managed root;
- attachment cleanup refuses unmanaged legacy/arbitrary paths;
- HLS playlist writes use the same confinement policy.

Run tests against the staged prospective tree when practical, not only the
developer's mutable working copy.

Temporary-process and diagnostic-lifecycle changes also require focused regression coverage proving that:

- temporary process capture uses WordPress-resolved temporary storage and cleans up on normal completion, process/launch failure, and partial-allocation failure;
- diagnostic data is persisted before its temporary capture is deleted; when deletion depends on a database write, require exactly one affected durable row and preserve the capture on zero-row or failed updates;
- stale detached-worker captures are recovered without truncating the only remaining evidence;
- diagnostic retention limits and per-run size bounds are enforced.

## Distribution package invariants

Build the installable ZIP with the reviewed release builder and an explicit
version matching the main plugin header and `readme.txt` Stable Tag.

The release ZIP must:

- contain exactly one top-level `argentwolf-video-processor/` directory;
- contain only runtime plugin files plus required license/readme material;
- exclude tests, CI, build tooling, AGENTS files, maintainer documentation,
  migration utilities, backups, reports, repository metadata, and
  `wordpress-org-assets/`;
- include the pinned `hls.min.js` runtime and its distributable license;
- exclude `hls.VERSION` and `hls.SHA256` from the runtime package unless a future
  runtime feature genuinely requires them. They may remain build-time integrity
  evidence.

Inspect the final ZIP manifest and checksum. The artifact that passes Plugin
Check and final install testing is the artifact that is submitted; do not
silently rebuild equivalent-looking bytes afterward.

## WordPress.org release gate

Before an initial submission or corrected review upload:

- re-read `wordpress-development.md` and the current official WordPress.org
  plugin guidelines;
- run the official Plugin Check plugin against the exact release ZIP;
- treat Plugin Check as one gate, not proof of complete compliance;
- resolve or deliberately document every finding;
- manually review common WordPress.org issues that automated checks can miss;
- test a clean installation with `WP_DEBUG` enabled;
- test supported upgrades, including the basename transition where applicable;
- verify settings, queue rows, attachment metadata, generated outputs, cron
  scheduling, CLI commands, rendering, and uninstall behavior;
- verify capabilities, nonces, validation/sanitization, contextual escaping,
  filesystem confinement, and destructive operations;
- confirm no custom update checker or telemetry is present unless intentionally
  designed and compliant;
- confirm all external requirements, services, bundled dependencies, licenses,
  and privacy behavior are disclosed;
- verify plugin headers, `readme.txt`, Stable Tag, changelog, and package version
  agree;
- inspect the final package manifest and checksum;
- preserve the exact reviewed artifact until the review cycle is complete.

When the Plugins Team requests corrections, review the whole codebase for the
same class of issue, not only the cited line. Upload the complete corrected ZIP
through the submission page and reply briefly in the existing review email
thread.

## WordPress.org review lessons

WordPress.org reviewer findings are durable engineering lessons, not one-line corrections. For each finding, identify the failure class and root cause, inspect the complete source tree for related instances, encode the prevention rule in project guidance, and add focused regression coverage before treating the correction as complete.

- Classify every plugin-created file by ownership, lifetime, and exposure before choosing storage. Persistent application/diagnostic state and short-lived process scratch are different classes.
- Prefer WordPress path/file APIs over raw environment assumptions. Use `wp_upload_dir()` for managed generated uploads storage and WordPress temporary-file facilities (`get_temp_dir()` / `wp_tempnam()`) for genuinely temporary scratch that has explicit cleanup.
- Do not allow diagnostic logs or process captures to accumulate indefinitely in the system temporary directory.
- Non-public diagnostics must not become publicly downloadable merely because uploads storage is convenient; database-backed local history is preferred for AWVP worker diagnostics.
- Every temporary-file allocation needs failure-safe cleanup, including partial allocation and external-process launch failures.
- Do not mechanically replace `ABSPATH` because a reviewer highlighted it. Classify the path semantics: plugin paths use plugin directory APIs, uploads use `wp_upload_dir()`, while WP-CLI `--path` legitimately requires the WordPress installation root.
- A persistent or append-only diagnostic stream requires explicit size and retention bounds. “Keep errors” never means unlimited growth.

## Release discipline

- Forgejo is authoritative. GitHub is the downstream public mirror, public issue
  tracker, and funding surface.
- Public GitHub issue intake does not make GitHub the development authority.
- Push/PR package jobs are validation unless a release workflow explicitly
  produces canonical release artifacts.
- Canonical release bytes must be built once from an exact reviewed Forgejo
  commit by the manually dispatched native Forgejo release-candidate workflow.
  The workflow requires the requested version, full approved commit SHA, and an
  explicit `BUILD-CANONICAL` confirmation, and refuses to rebuild after the
  corresponding Git tag exists.
- Forgejo Actions artifacts are temporary transport/evidence, not the permanent
  release archive. Download and preserve the canonical ZIP, checksum, and
  provenance immediately after the approved build, then promote those exact
  bytes unchanged to the Forgejo Release, GitHub Release, and WordPress.org
  surfaces as their separate gates permit.
- Every code release increments the plugin version. Final WordPress.org releases
  keep the main plugin header, `ARGENT_VIDEO_VERSION`, `readme.txt` Stable Tag,
  changelog, Git tag, release artifact name, and WordPress.org SVN tag aligned.
  Controlled Forgejo prereleases are the exception: use `X.Y.Z-rcN` or, when
  multiple immutable builds are needed within one RC series, `X.Y.Z-rcN.M`
  consistently for the plugin/runtime version, changelog, Git tag, and artifact.
  `N` identifies the RC series; `M` starts at 1 and increments for every rebuilt
  source/package identity in that series. Never reuse one prerelease version for
  different bytes. Leave
  `readme.txt` Stable Tag on the current numeric WordPress.org release. Never
  publish an RC to WordPress.org SVN. Final promotion must change the runtime and
  Stable Tag to the numeric release and then prove both public-stable -> final and
  RC -> final update ordering/upgrade behavior.
- Tag only after the reviewed commit is pushed, native CI passes, and the exact
  canonical release-candidate bytes pass the required package/WordPress gates.
- The downstream GitHub mirror must not auto-build release bytes from a tag.
  Create its release by uploading the already-preserved canonical ZIP and
  checksum without rebuilding them.
- Do not rebuild the WordPress.org ZIP separately for GitHub, Forgejo, or SVN.
- WordPress.org approval, SVN publication, Git/Forgejo release publication,
  staging installation, and production deployment are separate gates.
- After WordPress.org approval, keep directory artwork in top-level SVN
  `assets/`, not `trunk/assets`, plugin runtime `assets/`, or a version tag.
- Do not edit a released SVN tag. Create a new version for code changes.

## Validation control maturity

- Match validation depth to the maturity and risk of the function being
  validated. New, destructive, security-sensitive, authority, data-integrity,
  filesystem-boundary, and release-immutability controls should begin
  fail-closed with strong independent checks.
- Once a control has been independently proven and is reliable, retire
  redundant implementation-level checks and rely on the appropriate higher-level
  contract unless there is a documented reason for continued strict validation.
- Do not retain stale or duplicative controls merely because they were useful
  during initial validation. False failures, brittleness, and avoidable rework
  are operational risks.
- If unusually strict or redundant checks are retained after a function is
  proven, document the reason and the condition or review point for relaxing
  them.

## Applicator and verifier locale determinism

- **Pin collation semantics before comparing ordered machine output.** The R16
  backend/local applicator generated its expected changed-path list with
  Python's deterministic string ordering but compared it with shell `sort`
  under the host's active locale. On that host, `_` collated differently, so
  `Backend_Adapter_Factory.php` and `Backend_Adapter.php` appeared in a
  different order even though the changed-path set was exactly correct. Any
  applicator, verifier, manifest builder, checksum generator, or release tool
  that depends on textual/path ordering must establish the same ordering rule
  on every producer and consumer. For shell tooling, set `LC_ALL=C` (and
  normally `LANG=C`) before `sort`, `comm`, or other collation-sensitive
  operations unless a different locale is deliberately part of the contract.
  Do not rely on the operator host's inherited locale.

- **Do not make incidental ordering an authority boundary.** If the invariant is
  only membership ("these paths and no others changed"), compare sets or
  explicit missing/unexpected collections rather than positional arrays.
  Require exact ordering only when order itself is semantically significant or
  serialized into a canonical artifact. When exact order is required across
  languages/tools, add a focused regression proving they produce identical
  ordering for punctuation-sensitive names such as `A_B` versus `A_B_C` or
  `Backend_Adapter.php` versus `Backend_Adapter_Factory.php`.

- **Locale normalization belongs at the start of the verifier.** Apply the
  deterministic locale before any order-sensitive command, not only at the one
  comparison that previously failed. A repair must include a regression that
  reproduces the historical path-order case and proves the normalized command
  agrees with the expected ordering.

## Stable 1.0 and shared engineering baseline

Current public stable release: `1.0.0`. The permanent `release/1.x` maintenance
branch is rooted at exact tag `v1.0.0` so emergency 1.0.x work remains possible
while 2.0 advances independently. Current controlled candidate: `2.0.0-rc13.1`; it
must remain off WordPress.org SVN until final 2.0.0 promotion.

Cross-project release, validator, partial-mutation, shared-host, and ZFS lessons
are centralized in `wp-plugin-template`. AWVP keeps project-specific behavior,
release evidence, and test contracts here. Future work, especially the 2.0
line, applies the shared template guidance together with `AGENTS-TESTING.md`,
`wordpress-development.md`, and the 2.0 architecture.

- R46.3c publication editing must keep the block attribute surface stable (`videoId`
  only). Provider vocabulary is read from the non-authoritative R46.3a cache and
  revalidated before plan persistence; explicit review is per-video and must never
  be inferred from site defaults. The editor/REST path must not perform PeerTube
  HTTP, enqueue tasks, publish posts, or alter serving authority.

- R46.4 editorial publication validation is local-only. Protected WordPress status
  attempts (`publish`, `future`, `private`) may be refused for incomplete/coherence-
  broken AWVP editorial state, but PeerTube catalog freshness, HTTP availability,
  credentials, upload/task/transcoding progress, remote readiness, and serving
  state must never be inputs. Reused blocks do not transfer publication authority
  away from the immutable origin post. Validation filters must not enqueue remote
  work, register reveal transitions, or publish-then-revert content.

## R46.6 serving-cutover boundary

Treat `_argent_video_serving_authority` as revocable evidence, not a destination selector. Public/unlisted remote rendering requires current lifecycle/plan/execution/remote-asset agreement; any uncertainty stays local. Do not add render-time PeerTube HTTP or cleanup authority while working in R46.6.


## RC11 public-serving health and failover boundary

Publication authority and visitor-facing health are separate durable facts. The final serving-health decision is based on the unauthenticated public/embed URL that AWVP would give a visitor; provider API publish state is diagnostic only and cannot make a failed public URL eligible. Never add provider HTTP to frontend rendering. Local is priority 0; remote priorities choose among already-verified healthy publications and do not imply replication or deletion authority. Expected processing is not a broken-publication failure. Preserve historical authority/assets/tasks/events when health degrades or Republish creates a replacement generation.

Periodic broken-publication monitoring begins only after durable evidence that the exact remote asset has already passed public-serving qualification (`last_healthy_at`) or has exact historical serving authority from an older installation. Pre-cutover private/processing/failure observations belong to the publication/finalizer state machine and must not create periodic outage incidents or publication-health email alerts.

An `upload_indeterminate` request is never ordinary Republish authority. Resumable chunk uncertainty must be reconciled from the session/offset or exact remote identity. Only an unreconcilable initialization boundary with no session, confirmed bytes, or remote identity may be operator-retired, and only after an administrator explicitly confirms they checked the PeerTube server and found no matching remote video. Retirement preserves the old journal/event evidence, performs no remote request, releases only that retired intent fence, and permits a later explicit Republish to create a fresh operation.

Readiness estimation is advisory telemetry, not publication authority or audit evidence. Learn processing duration from the durable upload-accepted timestamp through the durable remote-ready verification timestamp; never include later finalizer, public-cutover, operator, or recovery delay. Estimator samples may be reset by an administrator per backend or globally, but a reset must not delete or rewrite task history, event history, upload journals, remote assets, publication-health records, or serving authority. Overview size bands must use the same bucket boundaries as the estimator implementation.

The normal PeerTube send boundary must not freeze a manifest or open an upload against an old provider catalog. When the current backend catalog is more than five minutes old, refresh it after credential repair and before upload staging/begin; if that refresh cannot establish current generation-bound provider choices, fail/wait closed before file transfer. Do not repeat this freshness refresh for every upload chunk or finalizer/processing poll.

## R46.8 migration execution boundary

`PeerTube_Migration_Executor` is a local promotion coordinator, not a provider executor. A fresh Start migration action must require a strict `ready` R46.7 plan and revalidate the hidden video/attachment/anchor identity, canonical-local destination, active PeerTube backend/origin, a non-stale current-secret-generation publication catalog, provider vocabulary, support preset resolution, and immutable thumbnail identity by successfully building the existing `PeerTube_Publication_Manifest`. It writes `_argent_video_peertube_migration_execution` before live promotion, then converges only forward: exact publication plan first, exact PeerTube destination second, journal `promoted`, and finally `PeerTube_Publication_Synchronizer::sync_video()`.

Do not add PeerTube HTTP, upload session creation, task enqueueing, remote-asset mutation, serving cutover, cleanup, or deletion to the migration executor. The synchronizer remains the sole handoff to R46.5. A present migration-execution journal is the one-way commitment boundary: planner review is frozen and ordinary destination/publication editor operations may not change backend/channel. Malformed/future journal state must be preserved and fail closed; prepared/promoted retries must be idempotent and crash-recoverable.

## R46.9 retention/cleanup boundary

Local retention is destructive and must remain fail-closed. KEEP is the default.
Never infer deletion authority from destination, remote readiness, serving
cutover, cleanup-state enum, or migration completion alone. A destructive
operation requires the explicit versioned per-video retention policy, its grace
period, current R46.6 serving evidence, and a durable cleanup execution journal.

`delete_all` must not run while `wordpress_source` is master. Physical source
deletion must preserve the WordPress attachment object, use a confined uploads
path, reject symlinks/escapes, compare exact file identity immediately before
`wp_delete_file()`, and verify absence afterward. The normal local FFmpeg queue
must be fenced while cleanup is running, with active jobs checked on both sides
of that fence. The cleanup journal's attachment must still be the video's current
attachment and must be exclusively owned by that one AWVP Video; duplicate or
ambiguous attachment references fail closed because managed output storage is
attachment-scoped. Bounded attachment-reference scans must fail closed when the
bound is exceeded, and trash must not bypass the fence. Source-file identity
includes relative path plus size/device/inode/mtime/ctime and is revalidated
immediately before physical deletion. Any mismatch means KEEP. Do not add
provider HTTP, remote deletion, a new scheduler, or retention work to the
qualified `--once` task set.


## R45.6 / RC PeerTube capability boundary

PeerTube capability advertisement must match implemented, qualified authority and
must not be treated as an execution entry point. For RC1 the only true PeerTube
capabilities are AWVP-staged ingest, server push, video processing, embed delivery,
and verified privacy mutation. Direct-browser ingest, WordPress-attachment direct
ingest, account-video listing/selection, provider-native scheduling, backend
source-retention guarantees, and remote delete remain false. Keep the exact-map
regression synchronized with any future capability change.
