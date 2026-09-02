# AWVP 2.0 Backend Registry and Local Backend Adapter Contract

Status: Tranche 2.0-2 design contract
Scope: backend registry, backend capability model, and local AWVP backend adapter
Out of scope: PeerTube API/authentication, remote upload, direct browser upload,
publication sync, migration, destructive retention cleanup, and block/editor UI

## 1. Purpose

ArgentWolf Video Processor 2.0 treats WordPress as the authoring/control plane
and processing/delivery systems as backends.

The 1.x local FFmpeg/HLS implementation remains a supported backend. Tranche
2.0-2 must put that proven local workflow behind a backend abstraction without
rewriting it, changing its public compatibility identifiers, or forcing
PeerTube-specific assumptions into the local path.

This contract is intentionally narrower than the eventual full remote-backend
API. PeerTube protocol details remain deferred until the PeerTube client tranche
can be designed against the actual supported API.

PeerTube integration testing adds one important distinction: **configured
managed backends and external embeds are different object classes**. A
configured PeerTube backend can be authenticated, queried, uploaded to, and
managed. A PeerTube watch URL from an arbitrary origin may be embeddable without
being a configured/manageable backend at all.

The backend registry therefore models only configured/manageable backend
instances. It must not create synthetic backend descriptors merely to represent
an arbitrary external PeerTube embed.

## 2. Inventory-derived boundary

The current local workflow is attachment-centric:

1. `Queue` accepts a WordPress video attachment.
2. `Job_Repository` persists one mutable local processing job per attachment in
   `argent_video_jobs`.
3. `Worker` claims jobs and delegates processing to `Transcoder`.
4. `Transcoder` uses the existing probe, FFmpeg security, command, runner,
   storage, naming, and HLS components.
5. validated local delivery outputs are written to established attachment meta
   such as `_argent_video_outputs`;
6. `Renderer` reads those attachment outputs and preserves existing core/video
   and shortcode behavior.

Tranche 2.0-2 does not replace that engine.

The backend adapter sits **above** the existing queue/worker/transcoder path.
For a 2.0 AWVP Video that has a linked WordPress attachment, the local adapter
delegates processing to the existing queue and reads the existing local state
and delivery metadata back through a normalized backend-facing view.

The existing 1.x hooks, media-library actions, CLI commands, attachment queue,
worker, and renderer remain valid compatibility paths and must continue to work
without an AWVP Video object.

## 3. Layering

### 3.1 Backend registry

`Backend_Registry` owns non-secret operator configuration and stable backend
descriptors.

It does not contact a backend, probe FFmpeg, inspect live backend health, store
authoritative remote asset state, store access tokens/passwords, enqueue video
work, or perform media/file deletion.

### 3.2 Adapter factory/resolver

`Backend_Adapter_Factory` resolves a descriptor's backend `type` to an installed
adapter implementation.

Registry presence and implementation availability are different facts.

A descriptor may exist while its implementation is unavailable. Such a backend
is not eligible for new work, but its descriptor must not be silently discarded.

### 3.3 Backend adapter

`Backend_Adapter` is the narrow common contract shared by installed backend
implementations in this tranche.

It reports backend type, structural capabilities, and operational
health/availability. It must not expose long-lived credentials.

The common interface deliberately does **not** yet define PeerTube-specific
upload/create/update/delete methods. Capability-specific operational interfaces
may be added in later tranches once the remote API semantics are verified.

### 3.4 Local backend adapter

`Local_Backend_Adapter` is the compatibility wrapper over the existing local
engine.

It may delegate to `Queue`, `Job_Repository`, existing attachment metadata,
`Diagnostics`, and local output interpretation where useful.

It must not duplicate FFmpeg/transcoding/storage logic already owned by
`Transcoder`, `Storage`, `Command_Builder`, `Probe`, `Adaptive_HLS`,
`Process_Runner`, or `FFmpeg_Security`.

## 4. Backend identity

Backend IDs use the canonical form:

`[a-z0-9][a-z0-9_-]{0,63}`

Supplied IDs must already be canonical. Invalid values are rejected rather than
rewritten into a different identity.

The canonical validator belongs in a backend-neutral component so registry,
destination metadata, task code, and future adapters cannot drift. Existing
`Video_Meta::sanitize_backend_id()` may delegate to it.

The built-in local backend ID is `local` and its backend type is `local`.

`local` is reserved by AWVP and may not be reassigned to another backend type.

The logical local descriptor exists whenever the local adapter implementation is
installed, even when the backend-registry option has never been written. Reading
the registry must not write an option merely to materialize defaults.

This guarantees that upgrading an existing 1.x installation does not make its
historical local media conceptually ownerless.

The operator may disable `local` as a destination for **new** videos. Disabling
it does not unregister the local adapter, break historical local rendering,
remove local derivatives, delete or alter `argent_video_jobs`, or delete the
descriptor identity.

The built-in local backend is not hard-deletable and is not converted to a
different backend type.

## 5. Backend registry option

The registry uses:

`argent_video_processor_backends`

It is explicitly non-autoloaded.

WordPress should not load a potentially growing descriptor registry on every
ordinary page request. Code paths that need backend routing or administration
load it deliberately.

Initial logical shape:

```php
array(
    'version' => 1,
    'backends' => array(
        'local' => array(
            'id'             => 'local',
            'type'           => 'local',
            'label'          => 'Local AWVP',
            'state'          => 'active',
            'config_version' => 1,
            'config'         => array(),
        ),
    ),
)
```

The map key and descriptor `id` must match exactly.

Core v1 fields:

- `id`: immutable stable backend ID;
- `type`: backend implementation type;
- `label`: human-readable operator label;
- `state`: `active`, `disabled`, or `retired`;
- `default_destination`: optional backend-specific destination/channel ID;
- `secret_ref`: optional opaque secret-record reference; no secret material;
- `config_version`: positive integer for type-specific configuration;
- `config`: bounded type-specific non-secret configuration map.

The built-in local backend uses no secret reference and no channel destination.

Dynamic state is not authoritative descriptor configuration. Do not persist
current reachability, FFmpeg probe result, temporary errors, quotas, processing
state, or negotiated remote capabilities as descriptor truth.

`active` means the operator intends the descriptor to be usable subject to
implementation, capability, destination, and health checks.

`disabled` keeps the descriptor known but excludes it from new routing.

`retired` keeps the descriptor for historical references but excludes new work.

A referenced descriptor must never be silently hard-deleted or have its ID
reused for another logical backend.

## 6. Registry read/write behavior

`Backend_Registry::all()` returns a normalized logical registry.

If the option is **absent**, the logical result contains the built-in `local`
descriptor in its upgrade-compatible default `active` state. This preserves the
existing 1.x local-processing behavior on first use after upgrade. A read does
not persist that synthesized default.

If the option exists but is malformed, or a stored version that is expected to
contain `local` unexpectedly omits it, the logical result still resolves the
built-in `local` identity for historical compatibility but marks it unavailable
for new routing. The registry reports a stable malformed-registry diagnostic
rather than silently treating corrupted configuration as an active destination.

If a valid stored `local` descriptor explicitly has state `disabled` or
`retired`, that state is preserved.

Malformed stored non-local descriptors must not become eligible. The registry
may expose a bounded diagnostic for them, but must not invent a corrected
identity.

`Backend_Registry::get($id)` performs exact canonical-ID validation and returns
the normalized descriptor or `null`.

Eligibility is computed, not stored as one authoritative boolean. A backend is
eligible for new work only when all required conditions hold:

1. descriptor state is `active`;
2. a matching adapter implementation is installed;
3. the adapter structurally supports the requested operation;
4. required destination information is valid;
5. current operational health has no blocking condition.

Historical rendering/inspection is not governed by the new-work eligibility
test.

Registry writes reconstruct known core fields and validate every descriptor.
Backend IDs are exact; map key and descriptor ID match; `local` is forced to type
`local`; `local` cannot be silently removed; labels are bounded plain text;
state is enumerated; destination IDs are opaque and rejected if sanitization
would rewrite them; secret material is forbidden; type-specific config is
sanitized by the registered backend type; unavailable backend types are never
made eligible merely by being present.

An unavailable/unknown backend type must not be silently rewritten into a known
type. If a future/downgraded registry contains a descriptor whose type this code
cannot validate, that descriptor is retained only as non-eligible historical
configuration or the attempted write fails closed; a generic settings save must
not erase or reinterpret it merely because the current code lacks its adapter.

A settings sanitization pass must be idempotent and side-effect free: no network
calls, backend probes, media operations, or secret migration.

## 7. WordPress Settings/Options integration

If/when the registry becomes administrator-editable through WordPress, register
it through the Settings API on `admin_init` with explicit `type => 'array'`, an
explicit sanitize callback, and `show_in_rest => false`.

Backend administration requires `manage_options`.

`register_setting()` describes/sanitizes the setting but is not the mechanism
that guarantees the option's autoload policy. Registry persistence therefore
uses a controlled registry write path:

- first creation uses `add_option(..., false)` for autoload;
- updates use `update_option(..., false)`;
- because this plugin's WordPress minimum is 6.4, `wp_set_option_autoload()` may
  be used to enforce `false` even when the stored value itself does not change.

A future standard `options.php` Settings API form must either ensure the option
already exists with explicit non-autoload state before submission or route the
actual persistence through the controlled registry write path. Do not rely on
WordPress's default autoload heuristics for this option.

Saving backend configuration must not implicitly test remote credentials, upload
media, migrate videos, delete remote assets, delete local media, or change
historical destinations. Connection testing is a separately authorized explicit
action in the PeerTube connection tranche.

## 8. Capability model

Capabilities answer:

> What operations does this adapter implementation know how to perform?

Health answers:

> Can this configured backend perform the required operation now?

These are separate.

For example, the local adapter structurally supports local processing even when
the configured FFmpeg binary is unavailable. The unavailable binary is a
blocking health condition, not proof that the adapter does not support
processing.

The PeerTube scope revision also separates upload/management capabilities from
external embedding. A configured PeerTube adapter may manage owned videos,
while an arbitrary PeerTube URL can be represented as an unmanaged external
reference without creating a backend descriptor.

Stable v1 capability keys:

- `ingest.wordpress_attachment`
- `ingest.awvp_staging`
- `ingest.server_push`
- `ingest.direct_browser`
- `processing.video`
- `library.account_videos`
- `asset.select_existing`
- `delivery.embed`
- `publication.privacy`
- `publication.schedule`
- `source.backend_retention`
- `asset.remote_delete`

Capability values are booleans describing implementation support, not current
operator preference. Unknown capability keys are ignored safely by older
consumers.

The local adapter reports:

- `ingest.wordpress_attachment`: true
- `ingest.awvp_staging`: false
- `ingest.server_push`: false
- `ingest.direct_browser`: false
- `processing.video`: true
- `library.account_videos`: false
- `asset.select_existing`: false
- `delivery.embed`: true
- `publication.privacy`: false
- `publication.schedule`: false
- `source.backend_retention`: false
- `asset.remote_delete`: false

The initial PeerTube adapter is expected to support AWVP-staged source ingestion,
server-to-server upload, backend-side processing, authenticated account/channel
library listing, selecting an existing managed video, embedding managed videos,
and verified privacy/publication/source-retention operations where supported by
the actual API. Remote delete remains separately gated as a destructive action.

Direct browser-to-PeerTube upload is not a required initial capability. It is a
future optimization even if a PeerTube version can technically support it.

Whether HLS/progressive output is requested on the local backend remains a
profile/settings decision. For PeerTube, AWVP does not pre-encode local delivery
derivatives as the normal path; it lets PeerTube own the heavy transcoding and
delivery-derivative lifecycle.

A normalized health result contains at least overall status (`ok`, `warning`,
`blocking`, or `unknown`), stable check codes, bounded human-readable messages,
and optional non-secret diagnostic data.

The local adapter may translate existing `Diagnostics`/FFmpeg-security results
into this form. PeerTube health may include authenticated connectivity, API
version/capability negotiation, channel availability, and quotas/limits once
those calls are implemented. Health is not written into the descriptor as
authoritative configuration.

## 9. Local adapter operational compatibility

For a 2.0 AWVP Video queued locally:

1. authorize at the application/service boundary;
2. resolve `_argent_video_attachment_id`;
3. require a valid linked WordPress video attachment;
4. resolve the snapshotted processing profile/policy;
5. delegate queueing to the existing `Queue`;
6. return normalized local job/state information.

Do not copy queue SQL or FFmpeg orchestration into the adapter.

An AWVP Video without a usable local attachment is not locally queueable.

Existing 1.x attachment-only videos continue using current hooks and metadata
without requiring immediate AWVP Video creation.

This tranche does not auto-create AWVP Video posts, backfill relations, rewrite
core/video blocks, alter historical shortcodes, or migrate local outputs into
remote-asset rows.

The local adapter may normalize established attachment/job state for 2.0 callers,
but existing local records remain authoritative for the legacy engine.

Expected sources include `argent_video_jobs`, `_argent_video_status`,
`_argent_video_outputs`, `_argent_video_last_error`, `_argent_video_job_id`,
`_argent_video_source_signature`, `_argent_video_processed_at`,
`_argent_video_processor_version`, and `_argent_video_profile`.

Do not create a duplicate local job table.

The local adapter may provide a normalized delivery view derived from validated
`_argent_video_outputs` and the linked attachment. The existing `Renderer`
remains the compatibility renderer for historical core/video blocks and
shortcodes.

Do not rewrite `Renderer` around the AWVP Video model merely to satisfy the
abstraction.

## 10. Bootstrapping and dependency injection

The current `Plugin::boot()` manually constructs the local object graph.

This tranche may introduce `Backend_Registry`, `Backend_Adapter_Factory`,
`Backend_Capabilities`, `Backend_Health`, `Local_Backend_Adapter`, and shared
backend-identity validation.

Existing `Job_Repository`, `Queue`, `Bulk_Queue`, `Worker`, `Transcoder`,
`Renderer`, `Admin`, and `CLI_Command` should not be gratuitously rewritten.

Prefer injecting existing queue/diagnostics dependencies into the local adapter
over constructing a parallel local stack. No service-container framework is
required.

## 11. Managed PeerTube assets versus external PeerTube references

Three editor operations are now architectural requirements:

1. **Upload New** — AWVP stages a source and transfers it to a configured
   PeerTube backend/channel.
2. **PeerTube Library** — AWVP queries a configured authenticated backend and
   selects an existing video without uploading.
3. **PeerTube URL** — AWVP accepts an embeddable PeerTube URL from an arbitrary
   origin instance, without requiring that origin to be a configured backend.

The first two operations create/reference **managed backend assets** and may use
the backend registry plus remote-assets table.

The third operation is an **external reference**, not proof of ownership or
management authority. It must not synthesize a configured backend descriptor,
imply credentials exist for the origin, permit management/delete/publication
actions merely because embedding succeeds, or be forced into a remote-assets
row whose `backend_id` would falsely imply a configured/manageable backend.

AWVP Video remains the stable WordPress-side identity for all three workflows.
Before editor/block implementation, the persistence model must gain a bounded,
validated representation for unmanaged external references containing only the
canonical origin/watch identity, embed identity/URL, non-secret cached metadata,
and verification state. The exact representation is a persistence-contract
revision and is not implemented in this tranche.

## 12. Secrets and PeerTube deferral

This tranche stores no PeerTube password, access token, refresh token, client
secret, or equivalent secret material.

`secret_ref` is only an opaque future reference.

The first PeerTube adapter/client is tranche 2.0-3 and must verify the supported
PeerTube API before expanding operational interfaces.

Do not add speculative browser-upload credentials or remote-delete behavior here.

## 13. No schema or migration work

No database schema version increase is required.

The persistence skeleton remains unchanged: AWVP Video CPT/meta,
`argent_video_remote_assets`, `argent_video_tasks`, and model schema version `1`.

No activation-time migration, backfill, media move, or destructive cleanup is
introduced.

The unmanaged external-reference requirement is intentionally **not** implemented
by changing schema in this tranche. Before editor/block work begins, the
persistence contract must be revised to add a bounded external-reference
representation without overloading configured `backend_id` semantics.

## 14. Failure behavior

Registry/configuration failures fail closed for new routing:

- absent registry option on upgrade -> synthesize the built-in active `local`
  descriptor without writing it;
- malformed registry or unexpected missing built-in `local` -> keep `local`
  resolvable for historical compatibility but disable new routing and report a
  stable diagnostic;
- invalid backend ID -> descriptor not eligible;
- missing adapter implementation -> not eligible;
- disabled/retired backend -> not eligible for new work;
- malformed destination -> not eligible;
- blocking health -> do not start work requiring the failed capability;
- multiple eligible destinations with no explicit/post default -> ask; do not
  guess.

A registry/configuration problem must not break historical local rendering.

Human-readable diagnostic messages are not machine contracts. Stable diagnostic
codes may identify registry malformed, implementation unavailable, backend
disabled, FFmpeg unavailable/security-blocked, storage unavailable, and
destination ambiguous conditions.

## 14.1 R40 PeerTube activation adapter boundary

R40 registers `PeerTube_Backend_Adapter` beside the local implementation only
after the R39 connection state can reach a freshly verified
`activation_ready` operation. Registration alone does not activate a backend.

The R40 adapter deliberately exposes only the non-mutating `delivery.embed`
capability. All ingest, processing, library-selection, publication, retention,
and remote-delete capability keys remain false. This is an eligibility surface,
not an upload API; the common adapter interface still contains no PeerTube media
mutation method.

Health is descriptor-aware in R40: `Backend_Adapter::health(array $descriptor)`
receives the exact configured descriptor. For PeerTube, health fails closed if
the descriptor is not the reviewed active v1 shape, the managed secret cannot
be decrypted for the exact backend ID, or the access token is expired/within
the fixed safety skew. A usable credential reports a non-blocking warning rather
than `ok`, because refresh and live operational health are intentionally not yet
implemented. No token value enters the health projection.

`Backend_Registry::eligible()` additionally requires a canonical non-empty
PeerTube destination ID before consulting capability and health. Consequently an
active-looking descriptor cannot become eligible merely because an adapter type
is installed.

The R40 activation writer is a shared-option compare-and-swap operation. It may
change only the exact target descriptor from `disabled`/empty destination to
`active`/the re-verified destination, while preserving unrelated and future
registry state. Planning, applying the registry CAS, confirming it in the
connection journal, and final eligibility/operation closure are separate explicit
local persistence boundaries. R40 performs no PeerTube HTTP request and no media
operation.

## 15. Implementation acceptance gates

The implementation tranche following this contract must prove at minimum:

1. exact clean `develop-2.0` base;
2. PHP syntax and existing project tests pass;
3. `git diff --check` passes;
4. no schema-version change and no new custom table;
5. reading an absent registry synthesizes active `local` without writing the
   option;
6. malformed registry or unexpected missing `local` fails closed for new
   routing while historical `local` remains resolvable;
7. local has exact immutable ID/type `local`;
8. invalid backend IDs are rejected rather than rewritten;
9. registry option writes are explicitly non-autoloaded, including correction
   of an existing wrong autoload value without requiring a value change;
10. registry sanitization is idempotent and side-effect free;
11. secret-like material is not accepted into ordinary descriptor fields;
12. disabled local is excluded from new-work eligibility but remains resolvable
    for historical/local compatibility;
13. unsupported adapter implementation is not eligible merely because a
    descriptor exists;
14. unknown/future descriptor types are not silently erased or reinterpreted by
    a generic save;
15. capability and health results are separate;
16. local capability keys match this contract;
17. local health delegates to existing diagnostics/security checks;
18. local 2.0 queue operation delegates to the existing `Queue`;
19. existing attachment-only queue/worker/render behavior remains unchanged;
20. no automatic AWVP Video creation/backfill occurs;
21. no PeerTube/network operation occurs;
22. no media/file deletion or relocation occurs;
23. no new REST exposure or long-lived credentials are introduced;
24. backend registry never synthesizes a configured backend for an arbitrary
    external PeerTube URL;
25. direct browser-to-PeerTube upload is not required for the initial adapter;
26. managed PeerTube library selection and unmanaged external PeerTube
    references remain distinct concepts;
27. `main`, review/1.x, GitHub direct push, tags, release, and deployment remain
    untouched during feature staging/review.

Machine gates should validate stable code/data contracts and observable
outcomes. Natural-language documentation formatting is not itself a machine
contract.

## R41 PeerTube credential lifecycle and health

R41 does not expand the PeerTube capability set. The adapter still exposes only
`delivery.embed`; all ingest/upload, processing, managed-library, publication,
retention, and remote-delete capabilities remain false.

For an active descriptor with a valid managed credential, health is derived from
the current encrypted token metadata rather than persisted as registry truth. A
usable access token is `peertube.auth.operational`. An access token at or inside
the bounded refresh horizon while its refresh credential remains usable is the
non-blocking warning `peertube.auth.refresh_required`. An unusable/expired refresh
credential is the blocking `peertube.auth.reauthentication_required`. A retired
descriptor is never eligible for new work regardless of adapter health.

Refresh/revoke/disconnect are administrator lifecycle operations, not backend
capabilities. They are not invoked by `eligible()`, page GET, cron, routing, or
media processing. Registry retirement uses an exact active-to-retired CAS that
preserves unrelated descriptors and fails closed on competing state.
