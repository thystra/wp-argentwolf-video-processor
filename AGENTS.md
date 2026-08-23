# ArgentWolf Video Processor agent instructions

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
- CI must not process media with an FFmpeg build that the runtime security gate would block. `build/install-ci-ffmpeg.sh` pins a reviewed security-patched FFmpeg release, verifies the official release signature and signing-key fingerprint, and keeps MagicYUV enabled so CVE-2026-8461 tests exercise the patched decoder path. Review current advisories before changing that pin.
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
- Every code release increments the plugin version. Keep the main plugin header,
  `readme.txt` Stable Tag, changelog, Git tag, release artifact name, and
  WordPress.org SVN tag aligned.
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

## Stable 1.0 and shared engineering baseline

Current stable release: `1.0.0`.

Cross-project release, validator, partial-mutation, shared-host, and ZFS lessons
are centralized in `wp-plugin-template`. AWVP keeps project-specific behavior,
release evidence, and test contracts here. Future work, especially the 2.0
line, applies the shared template guidance together with `AGENTS-TESTING.md`,
`wordpress-development.md`, and the 2.0 architecture.
