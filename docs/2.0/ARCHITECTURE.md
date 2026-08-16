# ArgentWolf Video Processor 2.0 Architecture Foundation

Status: proposed 2.0 development contract
Target branch: `develop-2.0`
Release assumption: the submitted 0.3.1 review line becomes the stable 1.0 line, subject to review-required fixes.

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

## 6. Multi-backend routing rules

### 6.1 One configured backend

If exactly one usable backend/destination exists, AWVP may apply the confirmed
global default and begin the configured upload workflow automatically.

### 6.2 Multiple configured backends

Do not auto-route among multiple backends based on WordPress categories, tags,
or other ambiguous content metadata.

When multiple backends are configured:

- direct-to-backend mode: require explicit destination confirmation before any
  video bytes are sent to a remote backend;
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

AWVP must expose connection health without exposing secrets.

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

Useful when destination is not yet selected or when direct backend upload is
not available.

The staged source remains governed by explicit retention and cleanup rules.

### 11.2 Direct backend upload

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
- credentials/tokens;
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

### Tranche 2.0-7 — direct/resumable browser-to-backend upload

- safe authorization/session mechanism;
- no long-lived browser credentials;
- progress/recovery;
- fallback to WordPress staging where required.

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
