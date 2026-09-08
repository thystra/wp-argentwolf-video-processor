# ArgentWolf Video Processor 2.0 Architecture Foundation

Status: 2.0 release-candidate contract
Target branch: `develop-2.0`
Stable baseline: WordPress.org-published `1.0.0`; `v1.0.0` identifies the released source, while later stable-main documentation/closure commits do not change the released artifact. The permanent `release/1.x` maintenance branch is rooted at that exact tag.
Current controlled candidate: `2.0.0-rc7`; RC packages are Forgejo-only and WordPress.org `Stable tag` remains `1.0.0` until final promotion.

## 1. Product direction

ArgentWolf Video Processor (AWVP) 2.0 expands from a local WordPress video
transcoder into a WordPress-centered video publishing control plane.

The normal content-authoring workflow should remain inside WordPress. Remote
video platforms such as PeerTube are processing/distribution backends, not
separate authoring destinations that require an editor to upload elsewhere and
paste links back into WordPress.

Primary product principle:

> WordPress is the authoring/control plane; configured backends perform video
> processing, hosting, delivery, and federation.

The native backend from the 1.x line remains a supported backend. PeerTube is
the first remote backend.

## 2. Release and branch boundary

The 1.0 line remains focused on the submitted/reviewed 0.3.1 behavior and any
changes required for WordPress.org approval.

The 2.0 line must not be used to satisfy review fixes for 1.0.

Recommended development topology:

- `main`: stable/public line after 1.0 promotion.
- `review-fixes-0.3.1`: review line until 1.0 promotion is complete.
- `develop-2.0`: 2.0 integration branch.
- short-lived `feature/2.0-*` branches: individual implementation tranches,
  merged into `develop-2.0` after review and validation.

If WordPress.org requires 1.0 changes after `develop-2.0` is created, forward
port those fixes deliberately into `develop-2.0`. Do not mix unfinished 2.0
work back into the 1.0 line.

## 3. Compatibility rules

Retain established public compatibility identifiers unless a separately
reviewed migration requires otherwise:

- `ArgentVideo` PHP namespace;
- `wp argent-video` WP-CLI command;
- established `argent_video_processor_*` options;
- established `_argent_video_*` attachment metadata;
- established `argent_video_*` hooks and cron identifiers;
- existing settings page slug;
- existing `argent_video_jobs` table until a reviewed schema migration changes
  or supplements it.

Existing local AWVP video output must remain renderable during upgrade.

## 4. Revised source-retention invariant

The 1.x safety rule "preserve every original WordPress attachment" is refined
for 2.0.

2.0 must preserve the WordPress/AWVP media identity and must never
destructively remove a physical source merely because a transfer was attempted.

A physical WordPress source may be deleted only when all of the following are
true:

1. the operator selected a retention policy that permits deletion;
2. the target backend accepted the asset;
3. required backend processing completed successfully;
4. AWVP positively verified the remote asset and required delivery state;
5. the AWVP record contains sufficient remote identity/state for later
   reconciliation;
6. cleanup is performed by a bounded, auditable job rather than inline in the
   editor request.

Hosted source copies are not assumed to be archival masters. Presets must make
this distinction clear.

## 5. Core concepts

### 5.1 AWVP Video

A stable internal media identity referenced by WordPress content.

An AWVP Video must not be identified solely by a PeerTube UUID or remote URL.
Its internal identity remains stable if a backend changes or if a remote asset
is migrated.

Representative fields/concepts:

- internal AWVP video ID;
- optional WordPress attachment ID;
- one or more associated WordPress posts;
- per-post sequence number for generated titles;
- source/staging state;
- selected backend and channel/destination;
- remote asset identity;
- desired publication state;
- actual remote publication/processing state;
- metadata and metadata-origin flags;
- storage/retention profile snapshot;
- processing/reconciliation/cleanup state;
- audit timestamps and last error.

The exact persistence schema is an implementation-tranche decision and must be
reviewed before migration code is activated.

### 5.2 Backend

A configured video processing/hosting service.

Initial backend types:

- Local AWVP backend (existing FFmpeg/HLS workflow);
- PeerTube backend.

The backend abstraction must not assume only one configured PeerTube server.

### 5.3 Destination

A publication target composed of:

- backend instance; and
- backend-specific channel/account destination.

For PeerTube, backend instance and channel are separate concepts.

Example destinations:

- `Home PeerTube -> Vacations`
- `Photos Social -> Photography`

### 5.4 Remote Asset

A backend-side representation of an AWVP Video.

An AWVP Video may temporarily have more than one remote asset during a safe
copy/move/migration operation. The model must therefore avoid assuming a
one-to-one lifetime relationship between an AWVP Video and one remote UUID.

### 5.5 Publishing Profile

An operator-confirmed set of defaults applied to new videos.

Profiles set values; they do not permanently lock them. Per-video overrides
produce a Custom state.

Initial storage-oriented presets:

- Keep Everything;
- Balanced / Recommended;
- Minimize Storage;
- External Archive;
- Custom.

Profiles may also include processing timing, cleanup delay, delivery formats,
resolution policy, verification requirements, and other coherent workflow
defaults. Publication/privacy behavior should remain independently visible and
must not change unexpectedly because a storage preset was selected.

A video snapshots the applicable defaults when it is created. Later global
profile changes affect new videos unless the operator explicitly applies them
to existing/pending videos.

### Managed backend assets versus external embeds

A configured backend represents a manageable destination/asset relationship.
An arbitrary embeddable PeerTube watch URL from an unconfigured origin is an
external reference, not a configured backend.

External PeerTube embeds:

- do not synthesize a backend descriptor;
- do not imply credentials, ownership, or destructive-management authority;
- remain attached to the stable AWVP Video identity through a separate bounded
  external-reference representation defined before block/editor implementation.

## 6. Multi-backend routing rules

### 6.1 One configured backend

If exactly one usable backend/destination exists, AWVP may apply the confirmed
global default and begin the configured upload workflow automatically.

### 6.2 Multiple configured backends

Do not auto-route among multiple backends based on WordPress categories, tags,
or other ambiguous content metadata.

When multiple backends are configured:

- staged remote-upload mode: require explicit destination confirmation before
  AWVP forwards any staged video bytes to a remote backend;
- any future direct browser-to-backend mode must satisfy the same destination
  gate before the browser receives upload authority;
- WordPress-staging mode: the browser may upload the source to WordPress first,
  but AWVP must require a destination before forwarding it remotely.

The editor should offer:

> Use this destination for the rest of this post's videos.

That selection becomes a visible post-level default for subsequently added
videos. Existing videos are not automatically migrated when the post default
changes. Every video remains individually overridable.

Destination precedence:

1. explicit per-video destination;
2. post-level destination for subsequently added videos;
3. sole configured backend/default destination when genuinely unambiguous;
4. otherwise require user selection.

## 7. Authentication and secrets

Each backend connection owns independent authentication and capability state.

For PeerTube, AWVP should support dedicated service accounts/connections rather
than requiring the operator's everyday administrative account.

Long-lived credentials and refresh/access tokens must remain server-side and
must never be embedded in Gutenberg/browser JavaScript.

Configuration should support:

- managed WordPress-side secret storage for ordinary installations;
- optional external secret sourcing through `wp-config.php` and/or environment
  variables for advanced deployments;
- token/bootstrap workflows that avoid retaining a user's PeerTube password
  when reusable OAuth/API tokens are sufficient.

Connection setup is a durable cross-store operation, not a read-then-write
convenience flow. Before a password grant, AWVP must have durably reserved a
bounded non-secret operation record, an exact empty managed-secret slot, and a
disabled backend descriptor. Reusable tokens are encrypted into that reserved
slot before identity/destination verification and activation. A grant whose
remote outcome is uncertain must remain explicitly indeterminate and must not
be retried automatically.

AWVP must expose connection health without exposing secrets.

Initial connection administration uses a separate server-rendered
`manage_options` settings page. Loading that page is read-only. Each
authenticated, nonce-protected POST may advance at most one reviewed local
preparation, password-grant, or credential-free reconciliation boundary before
a fixed local `303` redirect. Credential submission requires explicit
authorization of the displayed exact external origin; an allowlisted
development-only HTTP origin requires a separate acknowledgement that the
transport is plaintext. This surface stops after encrypted-token persistence
and reconciliation and exposes no bearer token or reusable PeerTube credential
to browser JavaScript.

## 8. Editor workflow

### 8.1 Preferred AWVP Video block

Introduce a dedicated AWVP Video block as the preferred authoring surface.

Operator modes should include:

- use AWVP as the default Video block in the inserter;
- show both AWVP Video and WordPress Core Video;
- leave Core Video behavior unchanged and expose AWVP separately.

Do not unregister or destructively rewrite historical Core Video blocks merely
because AWVP is activated.

Where supported, file-drop transforms may cause dropped video files to create
an AWVP Video block directly.

### 8.2 Immediate processing

If defaults and destination are unambiguous, upload/processing should begin as
soon as practical after block insertion so the author can continue drafting.

The UI should show inherited settings and allow overrides without forcing a
modal for every video.

### 8.3 Multiple videos per post

Generated title suggestions must include a persistent per-post sequence:

- `Our Trip - Video 01`
- `Our Trip - Video 02`

The sequence number is assigned, not recalculated from current block order.
Deleting or rearranging videos must not silently rename already-created remote
assets.

If a post title is unavailable, AWVP may use a provisional title such as
`Video 01`. If the author has not manually edited the video title, AWVP may
later suggest/apply the post-derived title before publication.

Manual metadata edits must prevent later automatic overwrites.

## 9. Processing and publication timing

Upload/processing timing and publication timing are independent settings.

Supported workflow goals include:

- upload/process now, publish with the WordPress post;
- upload/process now, remain unlisted/private;
- publish to backend immediately;
- defer remote processing until WordPress publication when a retained staging
  source exists.

For storage-minimizing/direct-upload deployments, the recommended scheduled
post flow is:

1. upload to PeerTube now;
2. transcode now;
3. retain non-public state;
4. synchronize publication with the WordPress post.

AWVP must reconcile desired versus actual state and must not trust one timer or
one webhook/event.

Post rescheduling, early manual publication, return-to-draft, or missed cron
events must be repairable by an idempotent reconciliation pass.

## 10. Metadata

At minimum expose appropriate backend metadata from WordPress:

- title;
- description;
- tags;
- destination/channel;
- privacy/publication state;
- thumbnail where supported;
- captions/subtitles where supported;
- comments/download policy where supported;
- language/category/licence where supported.

Defaults may prefill from WordPress content but should not create permanent
coupling after the author manually edits a field.

Metadata should record whether its current value came from a default/template
or was manually overridden.

## 11. Upload modes

### 11.1 WordPress staging

Browser -> WordPress staging -> backend.

Useful when destination is not yet selected; for PeerTube this staged path is
the initial/default upload architecture.

The staged source remains governed by explicit retention and cleanup rules.

### 11.2 Deferred direct browser-to-backend upload

This is not an initial-release path. The normal PeerTube architecture is
browser -> AWVP staging -> authenticated AWVP server -> PeerTube. Direct
browser upload may be reconsidered later only with suitably scoped upload
authority and an acceptable cross-origin/retry/security model.


Browser -> backend, coordinated by WordPress/AWVP.

Goals:

- avoid PHP/WordPress body-size limits where possible;
- avoid duplicate local SSD consumption;
- avoid browser -> WordPress -> backend double transfer;
- keep long-lived backend credentials out of the browser.

The implementation must use a backend-supported safe authenticated/resumable
mechanism. Do not expose reusable PeerTube credentials to client-side code.

## 12. Storage policy and presets

Track independently:

- WordPress ingest/staging source;
- backend-retained source/original;
- backend delivery derivatives;
- local AWVP derivatives where the local backend is used.

For each destructive cleanup, require positive verification and an auditable
cleanup job.

Storage presets must describe their consequences in plain language.

Typical deployment patterns:

### Keep Everything

- keep WordPress source;
- keep backend source;
- keep delivery derivatives.

### Balanced / Recommended

- configurable WordPress source retention;
- keep backend source;
- keep delivery derivatives.

### Minimize Storage

- delete WordPress source after verified backend processing;
- do not retain backend source where backend policy permits;
- retain only required web-delivery derivatives.

### External Archive

- assume authoritative masters exist outside WordPress/backend;
- delete online ingest/source copies after verification;
- retain delivery assets only.

## 13. System Status / Help

AWVP must include a one-stop system status and help area.

Distinguish:

- detected;
- inferred;
- unknown;
- warning;
- blocking.

Check/report where practical:

- effective WordPress upload limit;
- PHP `file_uploads`;
- PHP `upload_max_filesize`;
- PHP `post_max_size`;
- temporary upload path/writability;
- WordPress upload filesystem free space;
- backend connectivity/authentication/version;
- backend channel availability;
- backend quotas/limits;
- backend processing/capability state;
- known advertised upload ceiling;
- unknown reverse-proxy/web-server ceilings.

AWVP should advise but not silently rewrite PHP, Apache, nginx, Caddy,
WordPress-hosting, or PeerTube server configuration.

Help should include current, clearly version-qualified examples for common PHP,
WordPress, nginx, Apache, Caddy, and PeerTube/reverse-proxy settings.

## 14. Status / Operations

Provide an AWVP operations console that queries/reconciles backend state.

At minimum distinguish:

- staged;
- queued;
- uploading;
- backend accepted;
- processing/transcoding;
- ready;
- scheduled;
- private/unlisted/public;
- failed;
- out of sync;
- cleanup pending;
- migration pending;
- orphan/inconsistent.

Provide:

- summary counts;
- filters;
- per-video details;
- links to associated WordPress content;
- links to remote backend assets;
- refresh/reconcile;
- bounded retry actions;
- cleanup actions;
- audit/error history.

Desired state and actual backend state must be displayed separately.

## 15. Existing-library migration

Provide a resumable/idempotent migration wizard.

Inventory:

- existing WordPress video attachments;
- Core Video block uses;
- existing AWVP local videos;
- direct local video URLs where safely identifiable;
- unsupported/unknown embeds/references;
- duplicate reuse of one attachment across multiple posts.

Migration behavior:

1. inventory and classify;
2. select profile/destination;
3. dry-run/preview;
4. upload each unique media asset once;
5. wait for backend processing;
6. positively verify;
7. update AWVP metadata and/or post blocks only where necessary;
8. verify rendered state;
9. clean local source only when policy allows;
10. retain audit/rollback information.

Unknown or unsupported references must be reported for manual review rather than
silently rewritten.

Migration must be resumable after browser/session interruption.

## 16. Backend move/copy behavior

Changing a destination after a remote upload has begun is a migration, not a
metadata edit.

A safe move/copy must:

1. create the target remote asset;
2. process and verify it;
3. update the AWVP remote-asset relationship;
4. optionally remove the source remote asset only after verification.

Never change a stored backend ID/UUID and assume the bytes moved.

## 17. Proposed 2.0 implementation tranches

### Tranche 2.0-1 — architecture and persistence contract

- adopt this design contract;
- inventory current options/meta/job schema;
- define AWVP Video, Backend, Destination, Remote Asset, Profile, and State
  interfaces;
- decide persistence schema and migration/versioning strategy;
- add tests for compatibility invariants;
- no live PeerTube calls.

### Tranche 2.0-2 — backend registry and local-backend adapter

- wrap existing local processing behind the backend abstraction;
- preserve current local behavior;
- prove 1.x video rendering and jobs still work;
- add backend capability model.

### Tranche 2.0-3 — PeerTube connection and API client

- multiple backend records;
- atomic disabled-descriptor persistence;
- bounded non-secret connection journal and pending/reconciliation states;
- independently encrypted, non-autoloaded credential records;
- explicit bounded administrator start/resume/grant/reconcile actions;
- connection test;
- capability/version discovery;
- channel discovery;
- quota/limit/status reads;
- no automatic publication yet.

### Tranche 2.0-4 — PeerTube upload and remote state machine

- server-side staged upload path;
- remote asset creation;
- processing polling/reconciliation;
- positive verification;
- safe retries/idempotence;
- storage cleanup gates.

R42 is the first deliberately non-mutating checkpoint inside this tranche. It
establishes the state and persistence contracts before the first upload POST:

- a staged source is identified only by `wordpress_staging`, a relative path
  inside AWVP-managed uploads storage, exact byte length, and SHA-256;
- the operation immutably binds AWVP Video ID, backend ID, canonical PeerTube
  origin, selected destination, and source commitment into one intent hash;
- upload execution must first persist a unique attempt commitment in
  `upload_in_flight` and must re-prove both the current active descriptor and
  the source bytes against that operation;
- a future uploader may classify only a positively non-mutating outcome as
  retry-safe, returning to explicit retry readiness or bounded
  `retry_wait`, but an uncertain request enters `upload_indeterminate` and has
  no transition that silently starts another upload;
- reconciliation may move an indeterminate operation forward only when an exact
  remote video identity is positively found;
- remote API identity and the durable `argent_video_remote_assets` row are
  separate states (`remote_created` then `remote_committed`), so an operation
  journal is never treated as the sole durable remote-asset record;
- source cleanup cannot be planned until a committed remote asset has been
  positively verified ready; the later cleanup service may emit
  `confirm_source_cleanup` only after re-proving confinement and actual source
  absence.

The future staging producer must finalize a staged identity path atomically and
must never modify that path in place after it becomes upload authority. R42's
size/hash/inode checks are an integrity/reconciliation fence, not a substitute
for a cooperative immutable staging contract under an arbitrary concurrent
writer.

R42 itself creates no staged files, schedules no transfer task, exposes no new
administrator mutation endpoint, and contains no PeerTube media-upload API call.
The existing PeerTube adapter therefore continues to report staged ingest,
server push, and processing as unsupported until the subsequent execution/API
checkpoint is implemented and qualified.

### Tranche 2.0-5 — profiles and retention policies

- global confirmed default profile;
- presets and Custom mode;
- per-video snapshot/override;
- cleanup delay/audit;
- no destructive migration without verification.

### Tranche 2.0-6 — AWVP Video block and metadata workflow

- editor block;
- file-drop transform;
- generated title sequence;
- metadata panel;
- one-backend automatic destination;
- multiple-backend explicit destination gate;
- post-level "use for rest of this post" destination.

### Tranche 2.0-7 — staged-transfer resilience and upload hardening

- harden temporary AWVP staging and cleanup/recovery behavior;
- support retry-safe and resumable AWVP server-to-backend upload where the
  verified backend API supports it;
- reconcile interrupted transfers without duplicating remote assets;
- preserve the untouched staging source until positive backend acceptance;
- keep long-lived backend credentials out of browser JavaScript;
- leave direct browser-to-backend upload as a post-initial-release optimization.

### Tranche 2.0-8 — publication synchronization

- publish-with-post;
- private/unlisted/scheduled state;
- reschedule/early publish/return-to-draft handling;
- periodic reconciler;
- explicit policy when the post is due but a video is not ready.

### Tranche 2.0-9 — Status / Operations and Site Health

- one-stop dashboard;
- per-backend health;
- all-video reconciliation;
- filters/retry/reconcile;
- Site Health integration.

### Tranche 2.0-10 — System Status and administrator help

- PHP/WP limits;
- disk/temp checks;
- backend quotas/capabilities;
- unknown reverse-proxy reporting;
- Apache/nginx/Caddy/PHP/WordPress/PeerTube guidance.

### Tranche 2.0-11 — existing-library migration wizard

- inventory;
- dry run;
- deduplication;
- batch/resume;
- safe block/reference conversion;
- positive verification;
- cleanup;
- report/rollback evidence.

### Tranche 2.0-12 — hardening and release gates

- clean 1.x -> 2.0 upgrade;
- local backend regression;
- multi-PeerTube integration tests;
- credential/privacy review;
- destructive-boundary tests;
- WordPress Plugin Check;
- packaging/documentation/release review.

## 18. Non-goals for the foundation tranche

The foundation tranche does not:

- change the submitted 0.3.1/1.0 runtime;
- publish a 2.0 release;
- alter production;
- create PeerTube accounts;
- store credentials;
- upload or delete media;
- rewrite existing posts;
- migrate the database.

Those require separately reviewed implementation tranches.

### 2.0 release-candidate version boundary

After completion of the reviewed R46 implementation slices, the assembled 2.0
line enters controlled release-candidate testing as `2.0.0-rcN`. The plugin
header and `ARGENT_VIDEO_VERSION` carry that RC identity, while `readme.txt`
continues to declare the currently public numeric WordPress.org Stable tag
(`1.0.0` during RC1). RC packages are canonical Forgejo artifacts only and must
not be published to WordPress.org SVN.

The permanent `release/1.x` branch preserves the exact `v1.0.0` public lineage
for any necessary 1.0.x maintenance. Final 2.0 promotion must be functionally
frozen from the last accepted RC: change release/version metadata to `2.0.0`,
set `Stable tag: 2.0.0`, rebuild from the reviewed promotion commit, and rerun
the package/VM gates. WordPress/PHP version ordering must prove both
`1.0.0 < 2.0.0` and `2.0.0-rcN < 2.0.0`; live RC -> final upgrade on the
controlled production validation site is an explicit release gate.

## Stable 1.0 synchronization

The stable 1.0 line was forward-ported into `develop-2.0` after the first public
WordPress.org release.

Authority at synchronization:

- released `v1.0.0` source:
  `f656cdaba54fa63771187ca8b4fa6e19a20989f6`;
- canonical/public Forgejo 1.0.0 ZIP SHA-256:
  `7bbafd11c4d1f2805cfe66bb448ddac656eecc8bb2d2d12adf23a7173225468e`;
- stable `main` synchronization point:
  `a7849773754dc03d527df8b25a3571ebed673ab6`;
- pre-synchronization `develop-2.0` tip:
  `76909b3afc652e8506c66f395ab475013f95f76f`.

The merge preserves both sides of the intentional divergence:

- stable 1.0 queue/worker-diagnostics/database/release-review fixes;
- the existing 2.0 persistence model, backend registry/local adapter, and
  PeerTube connection contracts.

This synchronization does not itself bump the runtime version to 2.0.0 and is
not a 2.0 release.

### R43 executable resumable boundary

R43 turns the R42 state model into a narrowly executable internal boundary without
yet making it a production feature. The HTTP/API layer understands only resumable
initialization, bounded chunk PUT, and zero-byte offset probe. The service performs
claim-before-I/O, re-proves local fences after the claim, reads source bytes only for
the exact claimed chunk, and converts uncertain outcomes into a non-replayable state.
A chunk can become retryable only after a later explicit probe proves the server's
confirmed offset; an uncertain init remains indeterminate. At the R43 checkpoint no
caller was wired from WordPress runtime entry points and no ingest/processing
capability was enabled.

### R45 asynchronous task and streamed-upload execution

R45 adds a production-reachable **explicit WP-CLI-only** consumer without
repurposing the attachment/FFmpeg queue. `argent_video_tasks` is consumed through
lock-token-guarded, type-owned claims for exactly `peertube_upload_advance` and
`peertube_remote_reconcile`. `wp argent-video peertube-task-worker --once`
retains the one-task boundary; R45.4b3 also provides `--drain`, which follows one
logical operation only across immediately-runnable durable transitions. It may
reclaim the same task or that operation's deterministic reconciliation handoff,
never unrelated queue work. Durable processing/rate waits do not become polling
loops, and an `upload_indeterminate` operation remains a hard no-replay boundary.

Upload segmentation is backend-scoped operational policy, not backend identity or
credential authority. The default is 128 MiB, valid values are 0–8192 MiB, and
`0` means one resumable segment containing all remaining source bytes. R45 keeps
PeerTube's resumable endpoint in every case; `0` does not switch to the legacy
multipart upload endpoint.

Policy-sized source segments are streamed from an already-confined staged-file
descriptor through the WordPress safe-HTTP/cURL boundary. The complete source is
re-proved against the immutable staged identity when a slice opens, the selected
slice is hashed, and the bytes handed to cURL are checked against that slice
before a positive result can advance durable state. Large segments therefore do
not require a correspondingly large PHP request-body string. The final
remote-created response receives the stronger full-source post-transfer proof.

The PeerTube settings page may save this non-secret per-backend segment policy,
but that POST performs no media transfer. R45.5 registers the already-reviewed
PeerTube detached launcher on the plugin's existing five-minute
`argent_video_processor_dispatch` event. That cron callback only probes the
generic task table for due queued or stale owned PeerTube work and, when needed,
starts `wp argent-video peertube-task-worker --drain --quiet`; all upload,
reconciliation, and mail execution remains inside the detached WP-CLI process.
No second scheduler or administrator transfer-launch action is added.
Drain/process and streamed-request guards scale at one minute per 128 MiB with a
one-hour floor and six-hour ceiling; the worker observes its deadline only at
safe durable request boundaries. PeerTube staged-ingest/server-push/processing
capability advertisement remained false through R45.5; R45.6 later activated only
the separately qualified capability map described below.

R45.4b4 adds a separate durable notification branch without expanding media
transport authority. Human-attention upload failures enqueue
`peertube_upload_failure_notify`; `--drain` may claim that type, resolve the
initiating WordPress user (post-author fallback), and call `wp_mail()` with a
bounded sanitized snapshot. Mail retry affects only the notification task and
cannot cause upload replay. The `--once` task-type set remains upload/reconcile
only, preserving its qualified no-replay diagnostic behavior.


## R46 destination/publication and migration refinement

R46 refines the earlier destination/publication sketches with these binding
rules:

- a missing legacy per-video destination means WordPress/local forever unless an
  explicit migration changes it; site-default changes affect only new authoring;
- one AWVP block selects a final destination; separate Local/PeerTube block types
  are not required;
- PeerTube publication metadata, especially the maximum-five tag set, is reviewed
  independently from WordPress post metadata;
- unresolved required editorial metadata may block post publication, but remote
  upload/transcoding/readiness never does; local playback remains available until
  verified cutover;
- authors choose `Send now` or `Send when scheduled or published` after review;
- early/scheduled PeerTube preparation remains private, and actual WordPress
  publication is authoritative for later remote reveal;
- post-status hooks enqueue durable visibility work only and perform no remote HTTP
  inline;
- existing-video migration is explicit, local-first during transfer, and logically
  one-way after verified cutover; local retention is a separate policy.

The full frozen contract and checkpoint sequence live in
`docs/2.0/VIDEO-DESTINATION-PUBLICATION.md`.

R46.2 materializes the authoring-default layer as a separate non-autoloaded
versioned option. The resolver hierarchy is per-video explicit state (later editor
checkpoint) over backend provider/channel overrides over site defaults. Missing
settings default to local for new authoring only; existing video resolution never
consults this option. Support presets resolve to Markdown values before an operation
is frozen, and moderation defaults are prefills only, never proof of review.


R46.3a adds a separate read-only discovery plane for editor choices. Each active
PeerTube backend can be explicitly refreshed into a bounded non-secret,
non-autoloaded last-known-good catalog of owned channels, provider vocabularies,
server version, and conservative moderation/privacy signals. Each observation is
bound to backend ID + canonical origin + managed-secret generation; this keeps a
credential rotation or origin change from inheriting old discovery authority.
Discovery does not run during page rendering, does not alter backend capability
advertisement, and has no video create/update/publication authority. Failed
refreshes retain prior valid provider data but mark the snapshot stale so editor
choice availability does not collapse to an empty set or masquerade as current.

### R46.3b single-block editor plane

R46.3b introduces one dynamic `argentwolf-video-processor/video` Gutenberg block
as the durable editor identity surface. The serialized block attribute is only the
stable AWVP Video ID; attachment identity, origin, destination, provider choices,
and future publication state remain server-owned model state.

An existing WordPress video attachment is adopted through a narrow application
service under a per-attachment non-autoloaded option claim. The attachment reverse
pointer and AWVP Video forward pointer are verified together, repeated requests
converge on the established identity, and an ambiguous malformed reverse pointer
fails closed rather than being silently repaired. Site defaults are resolved only
when a new AWVP Video is created or an explicit “use site default” selection is
made; an existing binding is never reinterpreted through mutable current defaults.

The R46.3b REST surface exposes only bind, bounded editor-state read, and concrete
destination selection. It owns no PeerTube HTTP, credential, task-dispatch,
publication, or post-status authority. A PeerTube destination therefore remains
planning state. Dynamic frontend rendering still starts from the WordPress
attachment and `wp_video_shortcode()`, preserving local serving/Renderer behavior
until the separately reviewed cutover checkpoint.

### R46.3c publication-editor plane

R46.3c layers a publication-plan editor over the single R46.3b AWVP Video block.
The block still serializes only `videoId`; publication state remains server-owned
post meta validated by `PeerTube_Publication_Plan`. A dedicated application
service reads the concrete video destination, active backend descriptor, R46.2
defaults/presets, and R46.3a last-known-good catalog. Its REST controller is only a
capability-aware read/save boundary and writes no post meta directly.

Provider-backed values are validated against the selected backend catalog before
plan persistence. Password-protected privacy and any other advertised but
unsupported privacy remain observational rather than authoring authority. A
catalog whose canonical origin no longer matches, or whose stale reason records a
backend-context change, cannot authorize a save. Same-context stale data may
remain usable for editorial work because the resulting plan is not a frozen remote
operation; later dispatch construction must revalidate provider state again.

Required review is explicit per video. Defaults may prefill fields but never review
them. Editing title, channel, independent PeerTube tags, final privacy, or
moderation clears the associated review flag; destination/plan channel drift also
clears channel review in the projected draft. A fully reviewed plan may store a
dispatch policy, but R46.3c has no PeerTube HTTP, credential lifecycle, task queue,
post-status, reveal, migration, cleanup, or serving-cutover authority.

### R46.4 local editorial publication gate

R46.4 separates **editorial readiness** from **remote readiness** at the WordPress
publication boundary. `Editorial_Publish_Validator` parses the candidate post
content, including bounded nested/reusable block expansion, and validates only
AWVP Videos whose immutable origin post is the post being published. A reused
AWVP block therefore does not grant a second post authority over the original
video publication plan.

A missing legacy destination still resolves local and an explicit local
destination requires no PeerTube publication review. A concrete PeerTube
destination must have a valid versioned `PeerTube_Publication_Plan`, matching
backend/anchor/channel state, and explicit title/channel/tags/privacy/moderation
review. The validator does not read the provider catalog, managed secrets, tasks,
upload journals, remote assets, transcoding state, or PeerTube HTTP. Those are
execution/readiness concerns and cannot block WordPress publication once editorial
intent is locally complete.

`Editorial_Publish_Gate` provides two defenses. Gutenberg receives a block-level
`core/editor` save lock only while the edited post is targeting `publish`,
`future`, or `private`, leaving draft work saveable. Server-side public REST post
types receive a `rest_pre_insert_<post_type>` filter that can return a bounded
`WP_Error` naming the exact unresolved decisions. A conservative
`wp_insert_post_data` fallback prevents non-REST transitions from exposing
unresolved AWVP content without publishing and then reverting; for already
publicational content it retains the prior live content rather than silently
making unresolved edits live. R46.4 adds no post-transition/reveal hook: actual
scheduled publication and remote visibility synchronization remain R46.5 work.

### R46.5a WordPress-authoritative lifecycle intent

R46.5a introduces a local orchestration plane between reviewed editorial intent
and the later detached remote executor. `PeerTube_Publication_Synchronizer` is
wired to publication-plan saves and WordPress post-status transitions. It derives
one strict `PeerTube_Publication_Lifecycle` record per anchored AWVP Video and
queues a `peertube_publication_sync` task keyed by video + lifecycle generation +
plan commitment.

The status projection is deliberately asymmetric. `future` may authorize upload
but always targets PeerTube private. Actual WordPress `publish` is the only state
that can set `reveal_authorized=true` and target the reviewed final privacy.
`private`, draft/pending, reschedule/revert, trash, and unknown statuses target
remote private. Generation increments make stale scheduled/reveal tasks harmless
once R46.5b consumes them: the worker must re-read the current lifecycle generation
before mutation.

This sub-checkpoint has no PeerTube API dependency and does not own its new task
type in `PeerTube_Task_Worker`/launcher yet. Post hooks therefore cannot perform
network I/O even indirectly through the current worker. R46.5a leaves the qualified
five-minute dispatch topology unchanged and adds no bootstrap/retry scheduler; a
reviewed-plan save or actual WordPress status transition establishes/retries local
lifecycle intent. Pre-R46.5 reviewed plans remain inert until one of those events.

### R46.5b detached publication execution

R46.5b turns the generation-fenced lifecycle task into remote work only inside the
already-qualified detached PeerTube `--drain` worker. `peertube_publication_sync`
and `peertube_publication_finalize` are added to the launcher/drain owned set; the
manual/diagnostic `--once` set remains exactly upload + reconciliation. No new
WP-Cron event or browser/admin execution surface is introduced.

Sync re-reads the current lifecycle generation, strict reviewed plan, concrete
PeerTube destination, active backend, current managed-secret generation, fresh
backend-context catalog, and publishing defaults. It freezes a non-secret
`PeerTube_Publication_Manifest`, including resolved support Markdown and immutable
thumbnail identity, into `_argent_video_peertube_publication_execution`. It then
stages/reuses a confined AWVP-managed MP4 and creates or recovers the existing
staged-upload operation for the reviewed channel. Resumable initialization remains
privacy `3`; sync merely queues the existing upload coordinator and a low-priority
finalizer.

Finalize cannot mutate until the existing operation is `ready_verified` with an
exact remote asset/UUID. A short-lived non-autoloaded per-video execution lock
serializes publication workers across lifecycle generations while still allowing
the WordPress synchronizer to write a newer generation during network I/O. Before
mutation, the worker re-checks generation + plan commitment, destination/channel,
provider context, frozen manifest, thumbnail bytes, and the actual anchor status.
Only actual WordPress `publish` plus current `reveal_authorized=true` permits a
non-private target.

The final publication PUT is bounded to reviewed metadata fields. Success is not
accepted until a separate video-status GET verifies UUID, owned channel, ready
state, and target privacy. After a non-private PUT, WordPress is checked again. If
reveal authority disappeared during the cross-system race window, the same worker
issues one privacy-only correction to `3` and positively verifies it before
completing. A mutation whose acceptance is indeterminate is terminally held rather
than automatically replayed. Provider edits that would require an undocumented
clear encoding (for example removing an applied thumbnail or clearing an applied
non-empty tag list) fail closed. R46.5b still grants no serving cutover, source
cleanup, retention, remote deletion, or migration authority.
### R46.6 verified local-first serving cutover

R46.6 adds a local-only serving cutover after the qualified R46.5b publication executor. `_argent_video_serving_authority` is a strict version-1 non-secret evidence record, never a provider credential or remote mutation command. The detached finalizer may write it only after the current lifecycle/plan/destination/applied execution and durable remote-asset row agree that the exact asset is ready, verified, and at the intended public/unlisted privacy. A retry after publication is already applied may converge the local cutover without fresh provider credentials or HTTP.

Frontend rendering does not trust the cutover record alone. `Video_Serving_Service` re-reads lifecycle generation/plan hash, destination/channel, applied execution, published anchor state, and the remote-asset row. Any mismatch, stale generation, malformed record, changed remote state/privacy, or missing verification returns an empty remote resolution and the dynamic block follows the existing local `wp_video_shortcode()`/Renderer path. Private/internal PeerTube targets deliberately remain local because WordPress audience membership does not prove PeerTube authentication. No frontend network call, cleanup, deletion, or migration is introduced.

## R46.7 inert existing-video migration planning

R46.7 introduces a planner-only layer between legacy/local inventory and the later one-way migration executor. Eligible items are hidden AWVP Videos whose canonical destination resolves to local (including missing legacy destination metadata), whose source attachment remains a video, and which do not already carry publication execution or serving-cutover evidence. The planner reads only local WordPress state, configured backend/default state, and the cached R46.3a publication catalog.

Planning stores `_argent_video_peertube_migration_plan`; it never edits `_argent_video_destination` or `_argent_video_peertube_publication_plan`. Consequently the R46.5 lifecycle synchronizer cannot be awakened by planning or review. WordPress tags may be copied into an inert publication draft only when no more than five unique values satisfy PeerTube tag constraints, but all tag review flags remain false. More than five suggestions produce `tag_selection_required` and an empty draft tag set rather than truncation.

The administrator workflow supports selected videos and a bounded select-all batch of at most 500 eligible items while scanning past ineligible AWVP rows. Replanning the same source/backend/channel preserves an existing reviewed plan. A migration record becomes `ready` only after the strict `PeerTube_Publication_Plan` is complete, provider-backed values still exist in the selected cached catalog, and all required review flags are explicit. `ready` is still inert; R46.8 must revalidate current source/provider/WordPress state and explicitly promote the record before any task or remote mutation exists.


## R46.8 explicit one-way migration promotion

R46.8 converts a `ready` R46.7 plan into live publication intent only after an explicit administrator action and a second execution-time validation. Before commitment, the executor proves the hidden AWVP Video still references the planned attachment and immutable anchor, its canonical destination is still local, no publication execution/serving evidence already exists, the target backend is active at the same origin, and the publication catalog is current rather than stale. It then calls `PeerTube_Publication_Manifest::build()` over the reviewed plan/current catalog/current publishing defaults. This revalidates provider vocabulary, owned channel, password/embed restrictions, sensitive-content capabilities, support-preset resolution, and thumbnail bytes using the same rules the detached publication executor will later freeze.

The local `_argent_video_peertube_migration_execution` journal is written in `prepared` before any live destination/publication change. That record hashes the exact `ready` migration plan and binds video/backend/channel/anchor. Promotion is deliberately ordered and replayable: write/verify the exact live PeerTube publication plan, then write/verify the concrete PeerTube destination, mark the journal `promoted`, and invoke the already-qualified `PeerTube_Publication_Synchronizer`. A successful synchronizer handoff records lifecycle generation/task identity and marks the journal `dispatched`. Crashes after any boundary can resume from the journal without duplicating upload work.

`prepared` is the one-way commitment boundary. R46.7 planning/review is frozen once it exists, and ordinary destination editing cannot return the video to Local or choose another backend/channel. Same-target publication metadata may still evolve after handoff through the normal R46.3c/R46.5 generation model. The migration executor itself performs no PeerTube HTTP, staged-upload operation creation, remote-asset mutation, serving cutover, or cleanup. R46.5/R46.6 remain the only remote execution and verified serving paths; R46.9 remains the only local-retention/cleanup checkpoint.

### R46.9 explicit post-cutover local retention

R46.9 is the only R46 checkpoint with local destructive authority. Retention is
per AWVP Video and defaults to `keep`. `delete_managed` may remove the
attachment's AWVP-managed storage tree and `_argent_video_outputs` projection
while preserving the physical WordPress source. `delete_all` additionally
removes the physical WordPress video file but never the attachment post; it is
refused while `wordpress_source` remains the declared master authority.

A destructive policy is not deletion authority by itself. The operator must
confirm the exact per-video policy, choose a 1-365 day grace period, and the
video must still have current R46.6 public/unlisted serving authority. The
cleanup journal freezes that serving generation/plan/manifest/remote asset and,
for source deletion, a confined uploads-relative size/device/inode/mtime/ctime source
identity. Cleanup runs only as `peertube_local_retention_cleanup` in the existing
detached `--drain` worker; `--once` is not expanded.

Immediately before deletion the worker re-proves the live non-trash AWVP Video,
its exact attachment binding and exclusive ownership of that attachment, serving
authority, master policy, grace expiry, source identity, and absence of queued/
processing local FFmpeg work. Duplicate or ambiguous attachment references fail
closed because managed outputs are attachment-scoped. Entering cleanup `running`
fences the ordinary local queue, including trash references, then the job
repository is checked a second time to close the enqueue race. Managed tree
deletion uses the existing confined `Storage` boundary. Physical source deletion
uses `wp_delete_file()` only after an immediate stat identity recheck and verifies
actual absence. A recovered `running` journal may confirm an exact already-absent
source without replaying deletion; a merely queued job may not. Any mismatch,
changed serving state, active local work, malformed record, or uncertainty means
KEEP. No remote PeerTube HTTP or remote deletion is part of retention cleanup.


### R45.6 / RC PeerTube capability truth-up

The RC capability map now advertises the already-qualified AWVP-staged ingest,
server-push transport, PeerTube processing/reconciliation, managed embed, and
verified privacy-mutation paths. This is descriptive eligibility only: media and
publication HTTP remain in the reviewed durable detached-worker boundaries. No
direct-browser ingest, account-video selection, provider-native scheduling,
backend source-retention guarantee, remote delete, new REST/AJAX/admin upload
action, or cron-inline PeerTube HTTP is authorized by this change.
