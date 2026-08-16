# ArgentWolf Video Processor 2.0 Persistence Contract

Status: proposed Tranche 2.0-1 design contract
Source baseline: `develop-2.0` commit `02f779003a77a083750e08380a6854413ce98a15`
Purpose: define the durable 2.0 data model before schema or runtime implementation.

## 1. Design goals

The persistence model must support all of the following without breaking the
1.x local-processing workflow:

- a stable AWVP Video identity even when no local WordPress attachment exists;
- optional linkage to an existing WordPress attachment;
- multiple configured backend instances;
- a backend instance plus channel as a destination;
- more than one remote asset during safe copy/move/migration operations;
- desired state separate from actual remote state;
- WordPress-staging and direct-to-backend upload modes;
- per-video snapshots of operator-confirmed defaults;
- post-level default destinations and stable per-post video sequence numbers;
- asynchronous upload, processing, reconciliation, publication, and cleanup;
- resumable/idempotent operations;
- strict destructive-operation gates;
- a later migration wizard for existing attachment-based videos;
- rollback to the 1.x code line without destroying 1.x queue or attachment data.

## 2. Baseline persistence that must remain compatible

The 1.x line currently stores:

- local processing settings in the `argent_video_processor_settings` option;
- legacy schema version in `argent_video_processor_db_version`;
- worker/launch state in existing `argent_video_processor_*` options/transients;
- local processing jobs in `{$wpdb->prefix}argent_video_jobs`;
- local processing/output state in `_argent_video_*` attachment post meta;
- generated derivatives under the existing plugin-owned uploads boundary.

The existing queue table is intentionally a mutable worker queue, not a durable
video catalog. It enforces one row per `attachment_id`, stores a source path and
signature, and atomically moves jobs between queue states.

### Compatibility rule

Do not repurpose `argent_video_jobs` into the 2.0 durable media catalog.

Do not rename, truncate, or destructively rewrite existing
`argent_video_processor_*` options or `_argent_video_*` attachment metadata as
part of the initial 2.0 model installation.

Existing local video rendering and queue behavior must continue to work before
the operator runs any migration wizard.

## 3. WordPress-native AWVP Video object

### 3.1 Storage choice

Use a non-public WordPress custom post type as the durable AWVP Video object.

Proposed post type key:

`argent_video_asset`

The key is 18 characters, within WordPress's 20-character post-type limit, and
uses the project's established `argent_video_` compatibility prefix.

### 3.2 Registration contract

Initial registration intent:

- `public`: false
- `publicly_queryable`: false
- `exclude_from_search`: true
- `show_ui`: false
- `show_in_menu`: false
- `show_in_rest`: false initially
- `rewrite`: false
- `query_var`: false
- `hierarchical`: false
- `delete_with_user`: false
- `can_export`: false until a complete AWVP export/import contract exists
- `capability_type`: `post`
- `map_meta_cap`: true
- `supports`: title, editor/content, author, and custom-fields

AWVP will expose purpose-built admin/editor UI and REST endpoints rather than
making the internal object publicly queryable.

Object-aware authorization should use WordPress meta capabilities such as
`current_user_can( 'edit_post', $video_post_id )`, combined with operation-
specific capabilities such as `upload_files` for creating/uploading video.

If native REST exposure is later enabled, that is a separately reviewed
authorization/API decision.

### 3.3 Core-field meaning

- `ID`: stable internal AWVP Video ID.
- `post_title`: editable video title.
- `post_content`: editable video description.
- `post_author`: creator/owner for WordPress audit and authorization context.
- `post_status`: WordPress object lifecycle only; it must not be used as a
  surrogate for PeerTube privacy/publication state.
- `guid`: never stores a PeerTube UUID, remote URL, or other backend identity.

Remote publication state is intentionally separate from WordPress post status.

## 4. AWVP Video post metadata

Use narrowly registered, validated metadata with the established
`_argent_video_` prefix.

Initial logical fields:

### `_argent_video_attachment_id`

Optional integer link to the WordPress attachment used as the local source or
legacy media identity.

A corresponding `_argent_video_asset_id` reverse pointer may be written to the
attachment after a reviewed idempotent association operation.

The attachment-to-AWVP association must be created idempotently. If concurrent
requests attempt to adopt the same attachment, the implementation must converge
on one AWVP Video object rather than silently creating duplicate durable
identities.

Deleting only the physical source file must not require deleting either object.

### `_argent_video_origin_post_id`

Optional integer ID of the WordPress post/page in which the AWVP Video was
originally created.

This is the default source for generated-title context and, when the publication
policy is "publish with post", the default publication anchor. Reusing the video
in another post does not silently change this value.

### `_argent_video_origin_sequence`

Optional positive integer assigned once for the origin post, used for stable
generated names such as `Video 01`.

This value is durable on the AWVP Video object. It is not reconstructed from
current block order.

### `_argent_video_ingest_kind`

Validated enum describing the ingest path used to create/populate the AWVP
Video:

- `wordpress_attachment`
- `wordpress_staging`
- `direct_backend_upload`
- `existing_remote`
- `unknown`

Ingest path and archival authority are deliberately separate concepts. A video
may be uploaded directly to PeerTube while its authoritative master is stored
in an external archive.

### `_argent_video_master_authority`

Validated enum describing where the operator considers the authoritative master
to live:

- `external_archive`
- `wordpress_source`
- `backend_source`
- `none`
- `unknown`

This field is descriptive policy input, not deletion authorization. Cleanup
still requires the retention-profile and positive-verification gates.

If `wordpress_source` is the declared master authority, a policy that would
delete that source is a conflict and must fail closed unless the operator
explicitly changes the master-authority decision or completes a separately
designed destructive-loss confirmation.

### `_argent_video_source_state`

Validated enum describing local/source availability:

- `present`
- `uploading`
- `verified_remote`
- `cleanup_pending`
- `removed`
- `missing`
- `error`

This field does not by itself authorize deletion.

### `_argent_video_destination`

Versioned structured snapshot containing the desired backend ID and
backend-specific channel/destination ID.

This is the destination selected for this video, not proof that a remote asset
exists there.

### `_argent_video_profile_snapshot`

Versioned structured copy of the effective storage/processing profile at video
creation or explicit later override.

The snapshot stores resolved values. It is not merely a pointer to the current
global profile.

### `_argent_video_publication_policy`

Versioned structured desired-publication policy, for example:

- publish immediately;
- publish with an explicitly identified WordPress anchor post;
- remain private/unlisted;
- manual publication.

A `publish_with_post` policy must persist an `anchor_post_id`; it must not infer
the controlling post from whichever association is queried first. The origin
post is the default anchor when the video is created inside a post.

Reusing the same AWVP Video in additional posts does not silently replace the
publication anchor. Changing the anchor is an explicit policy edit.

Actual backend privacy belongs to the remote-asset record.

### `_argent_video_metadata_origin`

Versioned structured flags identifying fields that are still generated/default
versus manually edited.

Manual fields must not later be overwritten by title/category/profile changes.

### `_argent_video_cleanup_state`

Validated enum for cleanup coordination:

- `none`
- `pending`
- `eligible`
- `running`
- `complete`
- `blocked`
- `failed`

Deletion authorization must be proven independently of this display/state
field.

### `_argent_video_last_error`

Human-readable bounded last error for the durable video object.

Detailed task/remote errors remain on their corresponding records.

## 5. Content-post relationship metadata

Do not create a relationship table in the initial 2.0 model unless scale or
query evidence proves post meta inadequate.

### Repeated `_argent_video_asset_id`

On a WordPress post/page containing AWVP Video blocks, maintain repeated
integer meta values for the unique AWVP Video IDs referenced by that content.

This is a derived index for reverse lookup and operations UI. The block content
remains the authoritative embed/reference.

The index must be rebuildable by parsing content; stale index data must never
cause remote deletion or publication.

### `_argent_video_next_sequence`

Integer counter used to allocate stable per-post numbers:

- Video 01
- Video 02
- Video 03

The counter only advances. Moving or deleting blocks does not renumber existing
videos.

Sequence allocation must be concurrency-safe. A simple read/increment/write
cycle is not sufficient if two uploads can be created concurrently for the same
post. The implementation tranche must use an atomic/locked allocation strategy
and prove that duplicate origin-sequence numbers cannot be issued under the
supported concurrency model.

### `_argent_video_default_destination`

Versioned structured post-level default selected by the editor, corresponding
to:

"Use this destination for the rest of this post's videos."

Changing this value applies to subsequently created videos only. It does not
migrate existing videos.

## 6. Backend configuration

Backend configuration is site setup information, not content.

Keep it in WordPress-managed options behind a dedicated repository/service
rather than mixing it into the legacy encoder settings option.

### `argent_video_processor_backends`

Autoload: false.

Versioned map of configured backend descriptors keyed by a stable backend ID.

Descriptor fields include only non-secret configuration such as:

- stable backend ID;
- backend type (`local`, `peertube`, future provider);
- operator label;
- canonical/base URL where applicable;
- lifecycle state (`active`, `disabled`, or `retired`);
- whether eligible for new uploads;
- configured upload mode;
- default channel/destination ID;
- authentication source/reference, but not the credential itself.

A backend ID is immutable once referenced by an AWVP Video or remote asset.
Renaming a label does not change the ID. Replacing one logical server/account
with another creates a new backend ID.

A referenced backend descriptor must not be silently hard-deleted from the
configuration map. "Remove" operations retire/tombstone the descriptor while
remote/video records still reference it, preserving enough non-secret identity
to diagnose historical assets. A later purge requires an explicit referential
check.

Dynamic health, quota, channel lists, and capabilities should be refreshed from
the backend and may use bounded transient/cache data. They are not authoritative
configuration.

### `argent_video_processor_workflow_settings`

Autoload: false.

Versioned site-level workflow defaults such as:

- confirmed default storage profile;
- default destination when unambiguous;
- direct versus WordPress-staging preference;
- default publication timing/privacy;
- profile confirmation metadata.

### `argent_video_processor_profiles`

Autoload: false.

Versioned operator-defined and built-in profile overrides.

### Secret separation

Long-lived backend tokens/credentials must not be stored in
`argent_video_processor_backends`, legacy settings, block attributes, rendered
HTML, diagnostics, logs, or general REST responses.

Tranche 2.0-3 must define a dedicated secret-store abstraction supporting:

- managed WordPress-side secret persistence with autoload disabled;
- optional `wp-config.php` / environment-backed secret references;
- token/bootstrap flows that discard the user's PeerTube password when reusable
  tokens are sufficient.

The encryption/key-management decision is intentionally deferred to the
authentication tranche and must not be guessed into this schema.

## 7. Backend eligibility and destination ambiguity

"Backend implementation available" and "eligible destination for a new upload"
are separate concepts.

The local backend remains capable of rendering/processing historical local
videos even if the operator disables it as a destination for newly added
videos.

Destination selection counts only enabled destinations eligible for new
uploads:

- exactly one eligible destination: AWVP may proceed automatically with the
  confirmed site/post defaults;
- more than one eligible backend and no post-level choice: explicit selection is
  required before any direct remote transfer;
- WordPress-staging mode may receive the local staging upload first, but cannot
  forward it remotely until destination selection is complete.

No category/tag-based automatic multi-backend routing is part of the initial
2.0 contract.

## 8. Remote asset table

Remote assets are relational and high-churn operational data. A custom table is
appropriate because:

- one AWVP Video may temporarily have multiple remote assets;
- operations must efficiently filter by backend and processing state;
- synchronization updates are frequent;
- remote identities must be uniquely indexed;
- serialized post meta would make reconciliation and problem views inefficient.

Proposed table:

`{$wpdb->prefix}argent_video_remote_assets`

Logical schema:

```sql
id                      bigint(20) unsigned NOT NULL AUTO_INCREMENT
video_post_id           bigint(20) unsigned NOT NULL
backend_id              varchar(64) NOT NULL
channel_id              varchar(191) DEFAULT NULL
remote_id               varchar(191) DEFAULT NULL
role                    varchar(24) NOT NULL DEFAULT 'secondary'
state                   varchar(32) NOT NULL DEFAULT 'creating'
desired_privacy         varchar(32) DEFAULT NULL
actual_privacy          varchar(32) DEFAULT NULL
remote_processing_state varchar(64) DEFAULT NULL
remote_url              text DEFAULT NULL
embed_url               text DEFAULT NULL
last_synced_at          datetime DEFAULT NULL
last_verified_at        datetime DEFAULT NULL
error_code              varchar(64) DEFAULT NULL
error_message           text DEFAULT NULL
created_at              datetime NOT NULL
updated_at              datetime NOT NULL
```

Required indexes:

- primary key on `id`;
- unique key on `(backend_id, remote_id)`; NULL remote IDs are allowed while a
  backend identity is not yet known;
- key on `(video_post_id, role)`;
- key on `(video_post_id, state)`;
- key on `(backend_id, state)`;
- key on `(state, last_synced_at)` for reconciliation scans.

All timestamps in 2.0 custom tables are stored as UTC WordPress/MySQL datetime
values. Presentation converts them to the site's/user's timezone.

No SQL foreign-key constraints are required. Referential integrity is enforced
by the repository/service layer in WordPress-compatible fashion.

### Remote asset roles

Initial role vocabulary:

- `primary`
- `secondary`
- `migration_source`
- `migration_target`
- `retired`

Only one asset should be primary for normal rendering. Because MySQL does not
provide a portable partial unique index for this invariant, primary promotion
must be an atomic application-level operation (for example, one carefully
bounded SQL update or an explicitly reviewed transactional/locking strategy).
The repository must verify the invariant after promotion and fail closed rather
than rendering an ambiguous primary set.

### Remote asset state

Initial lifecycle vocabulary:

- `creating`
- `uploading`
- `processing`
- `ready`
- `failed`
- `missing`
- `deleting`
- `deleted`

Backend-specific raw states are retained separately in
`remote_processing_state`.

## 9. Generic asynchronous task table

Do not overload the attachment-centric `argent_video_jobs` table for remote
publishing work.

Proposed table:

`{$wpdb->prefix}argent_video_tasks`

Logical schema:

```sql
id              bigint(20) unsigned NOT NULL AUTO_INCREMENT
task_type       varchar(64) NOT NULL
video_post_id   bigint(20) unsigned DEFAULT NULL
remote_asset_id bigint(20) unsigned DEFAULT NULL
backend_id      varchar(64) DEFAULT NULL
idempotency_key char(64) NOT NULL
status          varchar(20) NOT NULL DEFAULT 'queued'
priority        smallint(5) unsigned NOT NULL DEFAULT 100
run_after       datetime NOT NULL
attempts        int(10) unsigned NOT NULL DEFAULT 0
max_attempts    int(10) unsigned NOT NULL DEFAULT 5
lock_token      char(36) DEFAULT NULL
locked_at       datetime DEFAULT NULL
started_at      datetime DEFAULT NULL
completed_at    datetime DEFAULT NULL
payload_json    longtext DEFAULT NULL
error_message   text DEFAULT NULL
created_at      datetime NOT NULL
updated_at      datetime NOT NULL
```

Required indexes:

- primary key on `id`;
- unique key on `idempotency_key`;
- key on `(status, run_after, priority)`;
- key on `locked_at`;
- key on `(video_post_id, task_type)`;
- key on `(backend_id, status)`.

The implementation must use atomic claiming comparable to the proven 1.x job
repository. Browser/admin requests and recurring WP-Cron callbacks must not
perform large uploads, remote polling loops, transcoding, or destructive
cleanup inline.

All task timestamps are UTC.

`payload_json` and `error_message` must never contain backend passwords, access
tokens, refresh tokens, client secrets, or equivalent long-lived credentials.
Tasks refer to backend/secret records by opaque identifiers.

Completed/failed task retention may later be bounded, but a task row must never
be the sole durable location of remote identity or publication state. Pruning
task history must not break rendering or reconciliation.

Initial task types may include upload coordination, remote polling,
reconciliation, publish synchronization, and cleanup. Exact task names are
runtime contracts and will be defined with the implementing tranche.

## 10. Schema versioning and downgrade safety

Do not make the new 2.0 model fight with the legacy queue's existing
`argent_video_processor_db_version = 1`.

Create a separate, non-autoloaded model schema option:

`argent_video_processor_model_db_version`

The new model starts at schema version `1`.

Benefits:

- 1.x code continues to own its queue-table schema version;
- reverting temporarily to 1.x does not cause its upgrader to downgrade/reset
  the new model schema version;
- 1.x simply ignores the 2.0 CPT, options, and supplemental tables;
- a later 2.0 reinstall can recognize its own schema independently.

Use `$wpdb->prefix`, `$wpdb->get_charset_collate()`, and `dbDelta()` with
WordPress-compatible SQL formatting.

Installation/upgrade must be idempotent.

Schema installation must never:

- upload media;
- contact PeerTube;
- create remote accounts/assets;
- backfill all historical attachments;
- delete or relocate existing files;
- rewrite post content.

## 11. Existing-video migration boundary

The 2.0 schema installation is not the migration wizard.

Existing 1.x attachments remain valid and continue rendering through the legacy
path.

The future migration wizard may create an `argent_video_asset` object and write
the attachment reverse pointer only after it has established an idempotent
one-attachment-to-one-AWVP-object mapping.

A migration must upload each unique source once, positively verify the target,
update references only where safe, and perform cleanup only after the complete
verification gate.

Unknown references remain review items.

## 12. Source deletion and WordPress attachment identity

Physical-source deletion and WordPress object deletion are separate operations.

A storage policy may remove the physical source only after the positive
backend-verification gate, while retaining:

- the AWVP Video object;
- the optional WordPress attachment object;
- the relation between them;
- backend/remote identity;
- enough state to diagnose, reconcile, and render the remote asset.

Retention cleanup must never call `wp_delete_attachment()` merely to reclaim
the physical source file.

If a human explicitly deletes the WordPress attachment object, the AWVP Video
object survives. Its source relation/state is updated and remote assets are not
implicitly deleted.

## 13. Delete, trash, and uninstall semantics

### Trashing an AWVP Video

Trashing the WordPress AWVP Video object must not automatically delete PeerTube
or other remote assets.

### Permanent AWVP Video deletion

Remote deletion is a separately authorized operation. The UI must make the
choice explicit and verification/audit rules apply.

A live remote asset must not be orphaned merely because a caller requests
permanent deletion of the AWVP Video object. The purpose-built delete workflow
must first choose and complete one of these outcomes:

- delete the remote asset(s) under the separately authorized remote-delete
  workflow, then purge the local AWVP Video;
- retain the local AWVP Video as a tombstone/retired record so the remote asset
  remains manageable;
- explicitly "forget" the remote asset after a high-friction warning/export
  path, accepting that AWVP will no longer manage it.

Core post-deletion hooks are recovery/backstop mechanisms, not permission to
silently destroy or forget remote media.

### Plugin uninstall

Default uninstall remains non-destructive.

Even when the existing local remove-data constant is explicitly enabled,
uninstall must not silently issue destructive remote API calls. Operators
should remove remote assets through explicit AWVP operations before uninstall
if desired.

A destructive local uninstall may remove plugin-owned local tables/options,
managed secrets, AWVP Video objects/meta, and plugin-owned derivative/staging
files after confinement checks. It must not delete ordinary WordPress
attachments or arbitrary external archive files.

If live remote assets remain, the administrator must be warned before enabling
destructive local-data removal that deleting local AWVP records will leave those
remote assets unmanaged. Where practical, provide an export/report of remote
identities before local destructive uninstall.

## 14. Audit/event persistence

A detailed append-only event table is not required in the first schema
implementation.

For early 2.0 work:

- durable last-error/status fields live with the video, task, and remote asset;
- task attempts/timestamps provide operational evidence;
- migration tooling must produce external review reports as it does today.

Before the Status/Operations and migration tranches are considered complete,
re-evaluate whether a bounded `argent_video_events` table is justified by
actual audit/query requirements.

Do not create an unused event table speculatively.

## 15. WordPress REST boundary

The durable data model does not imply automatic REST exposure.

Purpose-built AWVP REST routes will use a unique namespace and every endpoint
will define an explicit `permission_callback`.

Initial capability policy direction:

- create/upload/use video: capability-aware, normally at least `upload_files`;
- configure backends/secrets/site profiles: `manage_options`;
- destructive remote operations: explicit elevated authorization plus nonce/
  REST authentication and confirmation;
- operations/status reads: capability appropriate to the data exposed.

Exact object ownership/collaboration rules are an editor/API tranche decision
and must be tested rather than inferred from nonce success.

No endpoint may return long-lived backend credentials.

## 16. Block persistence boundary

The future AWVP Video block stores the stable AWVP Video ID, not a PeerTube URL
or UUID.

The serialized block may also store presentation-safe, stable values such as
its assigned per-post sequence.

Remote URLs/state remain server-side and are resolved at render time.

The current minimum supported WordPress version is 6.4. Therefore the initial
implementation should use `block.json` as canonical metadata and server-side
`register_block_type()` registration compatible with WordPress 6.4.

Do not unconditionally use APIs introduced after the declared minimum
WordPress version.

## 17. Acceptance gates for persistence implementation

Before the persistence implementation is merged:

1. exact clean feature base proven;
2. PHP lint passes;
3. `git diff --check` passes;
4. custom post type registration/capability tests pass;
5. meta validation/registration tests pass;
6. dbDelta SQL is tested on clean install;
7. upgrade from 1.x queue schema/data preserves all existing rows/meta/options;
8. repeated schema upgrade is idempotent;
9. downgrade/re-upgrade behavior is documented/tested;
10. no network call occurs during activation/schema upgrade;
11. no source/post/attachment migration occurs automatically;
12. no new secret is autoloaded or exposed, including task payload/error fields;
13. no destructive filesystem operation is added by schema installation;
14. existing local rendering and queue tests continue to pass;
15. attachment adoption is idempotent under duplicate/concurrent requests;
16. per-post sequence allocation cannot issue duplicates under supported
    concurrency;
17. publish-with-post always has an explicit durable anchor post;
18. referenced backend IDs cannot be silently deleted/reused;
19. primary remote-asset promotion preserves exactly one unambiguous primary;
20. new custom-table timestamps are verified as UTC;
21. WordPress Plugin Check is run when an installable 2.0 package is eventually
    produced, followed by independent manual review.

## 18. Explicitly deferred decisions

The following are intentionally not guessed in Tranche 2.0-1:

- exact PeerTube OAuth/token bootstrap and refresh semantics;
- browser-direct/resumable upload authorization mechanics;
- token encryption/key management;
- exact PeerTube processing-state vocabulary;
- final remote-delete user capability;
- migration-batch persistence tables;
- event/audit table;
- backend-specific API version/capability negotiation.

Those decisions require their corresponding implementation-time API and
WordPress review.
