# ArgentWolf Video Processor 2.0 WordPress Compliance Contract

Status: required companion for all AWVP 2.0 implementation tranches
Checked against official WordPress developer documentation: 2026-09-01
Current implementation note: R38 adds a bounded administrator authorization
surface over the R37 password-grant service through encrypted token persistence
and credential-free reconciliation. Exact commit, CI, and Docker VM authority
belongs in checkpoint validation records, not this source contract.

This document converts WordPress/WordPress.org requirements into stable
engineering boundaries so compliance is designed in rather than repaired at
release time.

It supplements `AGENTS.md`, `wordpress-development.md`, and
`docs/2.0/ARCHITECTURE.md`.

## 1. Validation-control maturity

Natural-language documentation is not a stable machine API.

Machine validation must target stable structure or observable behavior, for
example:

- exact Git branch/commit/tree;
- changed-path allowlists;
- PHP syntax;
- registered post types/meta/routes;
- database schema/indexes;
- capability and nonce behavior;
- filesystem confinement;
- HTTP API usage;
- package manifests;
- test outcomes.

Do not prove English policy meaning with line-oriented `grep`, whitespace,
Markdown wrapping, paragraph order, or other editorial formatting.

If a policy truly requires automated enforcement, create a machine-readable
contract, schema, constant, manifest, or executable test for that requirement.

False failures, brittle anchors, and redundant implementation checks are
operational risks because they cause avoidable rework and can obscure real
failures.

Fail closed at consequential boundaries such as authentication, authorization,
destructive actions, filesystem ownership, migration identity, remote
publication, and data integrity.

## 2. WordPress version compatibility

Current plugin declaration:

- Requires WordPress: 6.4+
- Requires PHP: 8.1+

Do not use newer WordPress APIs unconditionally merely because they are current
best practice.

For blocks:

- `block.json` is canonical metadata;
- use Block API version 3 where appropriate (available before the 6.4 minimum);
- register blocks server-side;
- while WordPress 6.4 remains the minimum, use
  `register_block_type()` per block;
- the newer WordPress 6.8+ metadata-collection registration optimization may be
  used only behind a compatibility guard or after the declared minimum is
  raised and tested.

Any minimum-version increase is a deliberate release decision, not an
accidental dependency.

## 3. WordPress-native data first

WordPress guidance prefers post/meta storage when it is practical.

Therefore:

- the stable AWVP Video editorial/media identity is a non-public custom post
  type;
- register the CPT with explicit `supports`, `capability_type`, and
  `map_meta_cap` behavior rather than relying on incidental defaults;
- use object-aware meta capabilities such as `edit_post` for existing AWVP
  Video objects, plus operation-specific capabilities such as `upload_files`;
- post-related/default/index data uses post meta where practical;
- site setup/preferences use Options/Settings APIs;
- custom tables are reserved for genuinely relational/high-churn operational
  data such as remote assets and asynchronous task coordination.

Do not create custom tables merely for convenience.

AWVP deliberately retains the established `_argent_video_` prefix for its
plugin-internal post meta. WordPress treats leading-underscore post meta as
protected/hidden from the ordinary Custom Fields UI. This is a documented
project-specific exception for post metadata, not permission to invent
WordPress-core `wp_*` globals or unnamespaced identifiers.

## 4. Database contract

Custom tables must:

- use `$wpdb->prefix`;
- use `$wpdb->get_charset_collate()`;
- use WordPress-compatible `dbDelta()` formatting where appropriate;
- have an explicit plugin schema-version option; a tiny version scalar checked
  on every request may be deliberately autoloaded, while large or sensitive
  configuration remains non-autoloaded;
- upgrade idempotently;
- avoid portable-schema-hostile SQL features unless deliberately justified;
- use WordPress database APIs and parameterized queries;
- store 2.0 custom-table timestamps consistently in UTC;
- keep indexed textual widths compatible with WordPress's conservative
  utf8mb4/index-length conventions unless a deliberate database minimum is
  declared and tested;
- be covered by clean-install and upgrade tests;
- never perform destructive data conversion simply because schema installation
  runs.

Use `longtext` for portable structured JSON payload storage unless the minimum
database contract is deliberately changed.

Do not add SQL foreign keys unless a separately reviewed WordPress/database
compatibility decision justifies them.

Where correctness depends on uniqueness or a state transition (for example
attachment adoption, per-post sequence allocation, task claiming, or primary
remote-asset promotion), use an atomic/locked repository operation and test the
supported concurrency behavior. Do not rely on a read-then-write sequence that
can race.

Do not advance a schema-version option merely because `dbDelta()` was invoked.
Verify the required tables and critical indexes first; failed or partial schema
installation must remain visibly retryable.

Foreign/durable identifiers must be validated in canonical form. Do not use
"sanitization" that can turn one invalid identifier into a different valid
attachment, post, backend, channel, or remote asset ID.

## 5. REST/API contract

Every custom REST endpoint must:

- use the AWVP-specific namespace;
- register on `rest_api_init`;
- define an explicit `permission_callback`;
- check the capability appropriate to the action/object;
- validate against the narrowest accepted schema/domain;
- sanitize only after/where validation cannot fully constrain the value;
- return escaped/encoded output appropriate to the response surface;
- treat remote-backend responses as untrusted input.

Nonce success is not authorization.

Long-lived backend credentials must never be returned through REST.

Arbitrary external PeerTube metadata/embed lookups are SSRF-sensitive. Use the
WordPress safe HTTP APIs intended for arbitrary URLs (for example
`wp_safe_remote_get()` / `wp_safe_remote_request()`), which validate the
requested URL and redirects with WordPress's HTTP URL safety checks. Treat all
returned metadata, URLs, and HTML-facing values as untrusted input and apply
contextual escaping at output.


Structured registered meta should use explicit WordPress meta types and
sanitization/auth callbacks. If native REST exposure is later enabled for
array/object meta, provide the required REST schema and keep the CPT's
`custom-fields` support enabled.

Editor cookie/nonce authentication and object-level capability logic must be
tested in the implementing tranche.

## 6. PeerTube / external-service contract

PeerTube is an operator-configured external video-processing/hosting service.

WordPress.org permits plugins that interface with external services such as
video hosting, but the service must provide substantive functionality and be
clearly documented.

Before a runtime tranche that contacts PeerTube is merged:

- the administrator must explicitly configure/authorize the connection;
- server-side calls use the WordPress HTTP API unless a reviewed technical
  reason requires otherwise;
- `readme.txt`, README/help/privacy text must disclose what service is contacted,
  what media/metadata is sent, and the service's terms/privacy implications;
- no telemetry, tracking, or unrelated remote asset loading is introduced;
- no remote executable code is downloaded/executed;
- transport failures, malformed responses, auth failures, and rate/quota
  responses are treated as ordinary untrusted failure states.

Stable 1.x behavior remains local. The R37 source updates `README.md` and
`readme.txt` in the same source tranche as its first internal credential-contact
service. The disclosure identifies the exact configured PeerTube service,
username/password and optional OTP transfer, non-retention of those bootstrap
values and the local OAuth-client response, authenticated-encrypted server-side
token storage, ordinary network-address/User-Agent exposure, the absence of
media/metadata/telemetry transfer, and the absence of an administrator-facing
or automatic invocation. The disclosure text is not, by itself, evidence of an
R37 CI run, VM validation, integration, package, or release.

R37 deliberately registers no administrator form/action, AJAX/REST endpoint,
WP-CLI command, cron callback, or activation hook. Its service can be invoked
only by explicit trusted server-side code. It therefore does not yet provide
the future `manage_options` plus nonce authorization surface; such a surface
must be separately implemented and tested before ordinary administrators can
initiate a connection.

R38 provides that separately reviewed boundary through a dedicated settings
page and only four authenticated `admin_post` actions: start, resume, grant, and
credential-free reconcile. Each handler requires POST, `manage_options`, an
action-specific or operation-bound nonce, the exact expected scalar fields, and
at most one state-changing coordinator or grant invocation before a fixed local
`303` redirect.
Loading the page is read-only. R38 registers no `nopriv`, AJAX, REST, WP-CLI,
cron, activation, automatic retry, upload, or media hook.

R39 adds only two more authenticated `admin_post` actions to that same settings
boundary: explicit identity verification and owned-destination selection. The
aggregate R39 browser boundary therefore has six authenticated actions. Identity
and channel reads remain explicit, capability- and nonce-bound requests; ordinary
page GET is read-only. Destination selection repeats current remote authority, and
a final explicit verification must prove the selected channel before the operation
may reach `activation_ready`. R39 still registers no `nopriv`, AJAX, REST, WP-CLI,
cron, automatic retry, activation, refresh/revoke, upload, or media-mutation path.

Before a grant, the administrator must explicitly authorize sending the entered
credentials to the displayed exact external service. An allowlisted
development-only HTTP origin requires a second acknowledgement that the
credential and token transport is plaintext and lacks TLS protection.

## 7. Secrets contract

Backend secrets are server-side secrets.

Never put a password, access token, refresh token, client secret, or equivalent
credential in:

- block attributes/post content;
- HTML data attributes;
- JavaScript localization/global objects;
- browser-visible REST responses;
- admin notices;
- Site Health output;
- ordinary logs/reports;
- asynchronous task payload/error fields;
- autoloaded general settings.

The ordinary backend descriptor stores only a secret reference.

Support advanced external secret sourcing (`wp-config.php`/environment) where
practical.

The managed provider uses the separately reviewed authenticated-encryption
boundary and has no plaintext fallback. R37 may persist a successful password
grant only by preparing an exact encrypted generation-one `secret_commit`,
journaling bounded non-secret mutation evidence first, and applying that same
request-local plan while token authority is still present. Password, OTP,
OAuth-client fields, token plaintext, and raw HTTP responses must not enter
AWVP options, journals, diagnostics, public projections, or logs. Request-local
plan objects and their raw before/after values must not enter the journal,
diagnostics, projections, or logs either. WordPress option hooks emitted for
AWVP-owned mutations therefore receive only bounded non-secret journal data or
the managed secret's authenticated ciphertext and bounded metadata, never the
bootstrap plaintext or a raw HTTP response.

The credential request and remote responses necessarily pass transiently
through the WordPress HTTP API. Site code attached to hooks such as
`http_request_args`, `pre_http_request`, or `http_api_debug` can therefore
inspect request arguments or response objects transiently. AWVP does not
persist or copy that plaintext into its own options, diagnostics, projections,
or logs, and its disclosure must not imply that independently installed
WordPress HTTP observers are unable to see it. Later reconciliation may confirm
the exact encrypted after-state but must never rebuild or replay a token write
from hash evidence.

At the browser boundary, AWVP unslashes its owned credential fields exactly
once, validates the username/password byte limits and the OTP as either empty or
exactly six digits, requires those six digits in `awaiting_otp`, and does not
apply text sanitization or another transformation to the accepted credential
bytes. Password and OTP fields are never repopulated; password/OTP never enter
page projections, redirect arguments, or admin notices.

## 8. Filesystem/media contract

Use WordPress APIs to discover upload locations.

Plugin-generated/staging data lives only beneath the centralized AWVP-managed
uploads root.

Before every mutation:

- prove path ownership/confinement;
- reject traversal/sibling-prefix/symlink escapes;
- validate an existing parent for paths that do not yet exist;
- use `wp_delete_file()` where appropriate;
- keep shell/filesystem boundaries explicit.

Deleting a physical source under a retention policy is not equivalent to
deleting the WordPress attachment object.

Destructive retention requires:

1. operator policy permits it;
2. remote asset exists;
3. required processing is complete;
4. delivery/playback is positively verified;
5. remote identity/state is durable;
6. cleanup job owns the correct file;
7. the path passes confinement immediately before deletion.

Any uncertainty means KEEP.

Retention policy and declared master authority must be consistent. If WordPress
is marked as the authoritative master, ordinary cleanup must not delete that
source merely because a remote derivative has been verified.

## 9. Block-editor contract

AWVP introduces its own block rather than silently mutating historical
`core/video` content.

Requirements:

- canonical `block.json`;
- server-side registration;
- WordPress-provided JS packages/default libraries;
- no duplicate bundled WordPress framework libraries;
- purpose-built capability-aware REST endpoints;
- block stores stable AWVP Video ID, not backend secrets;
- file-drop/staged-upload behavior obeys the multi-backend destination gate;
- any future direct browser-to-backend upload must obey the same destination
  gate before upload authority is delegated;
- existing `core/video` remains renderable and is not mass-converted on plugin
  activation.

A migration/transform may be offered explicitly later.

## 10. Settings/options contract

All privileged settings writes use the Settings/Options APIs plus:

- appropriate capability checks;
- nonce/Settings API protection;
- narrow validation;
- consistent sanitization;
- safe contextual output escaping.

Do not append unknown 2.0 fields to the legacy
`argent_video_processor_settings` option without deliberately extending and
testing its sanitizer. The current sanitizer reconstructs a known-key array, so
separate versioned 2.0 options reduce upgrade/review risk.

Large lists, profiles, backend descriptors, and secret storage should not be
autoloaded merely for convenience.

R35's fixed-scope backend-registry, connection-journal, and managed-secret
mutations require an exact raw database compare-and-swap that the ordinary
Options API does not expose. This narrowly reviewed repository primitive may
use parameterized direct queries only for AWVP-owned, bounded, canonical array
options. It must:

- take the expected token from an authoritative raw row, including exact
  serialized bytes and autoload state;
- make one conditional mutation attempt rather than retry a stale decision;
- classify applied, conflict, indeterminate, and refused outcomes;
- use exact conditional rollback/delete so concurrent state cannot be erased;
- invalidate individual, `alloptions`, and `notoptions` caches after a definite
  or possible mutation and emit the applicable option actions;
- refuse malformed, oversized, object-bearing, or unsupported-autoload rows;
- require the caller to supply the final prospectively validated value rather
  than pretending to reproduce arbitrary Settings API filters.

These private options remain non-autoloaded. Repairing an already autoloaded row
is a separate deliberate Options API operation followed by a fresh raw
snapshot; the compare-and-swap must not reuse a pre-repair observation or make
rollback capable of restoring an autoloaded sensitive value.

R36 adds a prospective form of this private primitive for the local pre-grant
coordinator. It creates a request-local, single-use exact mutation plan without
writing, journals only bounded non-secret evidence, and applies no target
mutation until that evidence is durable. Each coordinator call crosses at most
one persistence boundary. An indeterminate outcome keeps the same evidence and
mutation ID for reconciliation; it does not authorize retry with a fresh plan.

The sole replan exception is an exact registry-link compare-and-swap that
returns a SQL-phase zero-row definite no-mutation conflict and whose
authoritative probe does not find the semantic after-state. The replacement
plan has a fresh mutation ID and must be journaled before use. The semantic
after-state requires a valid preservable shared registry with the reserved ID
mapped to the exact disabled descriptor; unrelated valid descriptors may
remain. A read-only occupied-ID preflight prevents the initial journal write,
but never weakens the later exact compare-and-swap.

The coordinator stops at `disabled`, followed by a separate mutation-free
recheck of the exact pending secret and disabled descriptor. It has no
credential inputs, HTTP path, admin/AJAX/REST registration, activation hook,
upload mutation, schema bump, or plugin/release version change.

R37 keeps the credential-bearing service separate from that R36 coordinator.
The exact-origin local OAuth-client GET occurs before the durable grant claim;
after it returns, the journal and both local prerequisites are re-proved. Only a
definitely confirmed commitment to a request-local 256-bit attempt capability followed by another exact prerequisite
check may continue toward one password-token POST. Immediately before HTTP, an
exact journal event for the same attempt must durably mark the grant request as
starting and refresh `grant_started_at` / `updated_at`; only confirmation of
that capability-authenticated mark plus read-only reproof of both prerequisites and the exact marked
journal after its option hooks authorizes the POST. An aged mark is refreshed;
bounded refresh exhaustion sends no credentials. A pre-POST prerequisite
change is classified as grant-not-sent. Once the POST has been invoked, an
uncertain outcome is terminal and is never automatically retried.

The caller's timestamp is only a floor. A fresh non-regressing observation
after the OAuth-client GET timestamps the claim, and fresh observations around
the password POST timestamp the request/response boundary. The post-response
observation governs token-lifetime validation and the journaled remote outcome;
a bounded `Retry-After` delay is therefore measured from that response
observation through the record's `updated_at`, not from a pre-request time.
Every returned mutation classification is cumulative for the whole service
call: unknown mutation dominates definitely applied mutation, which dominates
no mutation, so a later conflict or refusal cannot hide an earlier local write.

Definite OTP-required and credential-class responses use two local journal
boundaries. Their bounded evidence first enters `otp_result_pending` or
`credential_result_pending`, neither of which authorizes another grant. Only a
second `confirm_grant_result` transition bearing the matching request-local
capability may expose `awaiting_otp` or `awaiting_credentials`. Only the
domain-separated capability commitment is durable; the capability does not
enter records, results, or option-hook values. The service attempts the
confirmation without another HTTP request; if it is not applied,
credential-free reconciliation does not
promote the pending result and may terminalize it only as
`local_persistence_unknown` after the stale interval.

The credential-free reconciliation path performs no HTTP. It may confirm an
already-applied authenticated-encrypted token record. It cannot apply the
journaled plan after restart because the plaintext token authority no longer
exists. In-flight and unresolved secret-plan states wait through a 30-second
safety interval, longer than the reviewed 15-second HTTP timeout, before stale
interruption/local-persistence classification. The confirmed grant-request mark
immediately before HTTP refreshes the in-flight timer.

A stale secret-write resolver prospectively replaces only an absent target or
the exact empty generation-zero reservation with an exact persistent,
non-secret `fenced` marker. It then re-probes the ready-write-versus-fence
compare-and-swap: a concurrent exact generation-one write is confirmed, while
only confirmed fenced state permits terminal `local_persistence_unknown`. The
marker prevents older absent-to-pending reservation plans and
pending-to-ready commit plans from regaining authority through ABA recreation.
A ready, newer, foreign, or indeterminate target is never overwritten or
treated as fenced. Target and registry authority are re-proved after journal
hooks; terminal reconciliation may restore the fence or recover an exact ready
commit to `secret_stored` without HTTP. R37 remains unregistered and stops before
identity/destination verification, activation, refresh/revoke, operation
closure, upload, schema, or version changes.

Backend IDs referenced by durable video/remote records are immutable identifiers.
Removing a backend from active use should retire/tombstone its non-secret
descriptor while references remain; it must not silently delete/reuse the ID and
orphan historical remote state.

## 11. Capabilities

Use the narrow capability for the operation.

Initial direction:

- ordinary video upload/create/use: `upload_files` or more precise object-aware
  checks;
- site-wide backend/profile/credential administration: `manage_options`;
- destructive remote operations: separately confirmed and capability checked.

Do not elevate ordinary authoring actions to `manage_options` solely because it
is easy.

Do not treat a nonce as a capability.

The R38 connection page and all four actions require `manage_options`. The nonce
proves request intent but does not replace that capability check. Operation
actions use operation-bound nonce actions, and rendering the page performs only
bounded, non-secret reads.

## 12. External input

Treat all of the following as untrusted:

- browser request data;
- saved WordPress database data;
- block attributes;
- attachment metadata;
- filenames and paths;
- backend URLs entered by administrators;
- user-supplied external PeerTube/watch/embed URLs and origin-derived URLs;
- PeerTube/API JSON;
- channel IDs;
- remote URLs/embed URLs;
- quota/status/version fields;
- callback/webhook data if introduced later.

Validate domains/enums/IDs before use.

Escape at the final output context.

Authenticated browser mutations must reject non-POST requests, unexpected or
non-scalar fields, invalid canonical values, and tampered nonces before invoking
the service boundary. Post/redirect/get responses use only fixed local redirect
targets and allowlisted notice identifiers; remote or user-supplied text never
becomes an admin notice.

## 13. Build/package contract

The WordPress.org ZIP remains a deterministic runtime package, not a repository
archive.

Repository-only 2.0 design documents, reports, applicators, tests, CI metadata,
and WordPress.org artwork source stay out of the release ZIP unless they acquire
a genuine runtime/user purpose.

Before release:

- inspect exact ZIP manifest;
- verify one plugin root;
- verify version/Stable Tag/requirements;
- verify third-party licenses/provenance;
- run Plugin Check against the exact artifact;
- perform independent manual WordPress security/filesystem/database/remote-
  service/privacy/uninstall review after Plugin Check;
- clean-install test;
- prior-version upgrade test;
- `WP_DEBUG` test;
- confirm external-service disclosure matches actual behavior.

## 14. Uninstall contract

Default uninstall preserves data.

An explicitly destructive local uninstall may remove AWVP-owned local data
after confinement checks.

Uninstall must not silently:

- delete ordinary WordPress source attachments;
- delete an external archive;
- contact PeerTube to destroy remote assets;
- interpret "remove local plugin data" as permission for remote destruction.

Remote cleanup is an explicit pre-uninstall operational action.

If destructive local-data removal is enabled while live remote assets remain,
the administrator must be warned that those remote assets will become unmanaged
unless they are deleted, transferred, or their identities are exported first.

## 15. Official WordPress references

Re-check these at implementation/release time because WordPress guidance can
change:

- Detailed Plugin Guidelines:
  `https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/`
- Creating Tables with Plugins:
  `https://developer.wordpress.org/plugins/creating-tables-with-plugins/`
- Custom Post Types:
  `https://developer.wordpress.org/plugins/post-types/registering-custom-post-types/`
- REST custom endpoints:
  `https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/`
- Block registration:
  `https://developer.wordpress.org/block-editor/getting-started/fundamentals/registration-of-a-block/`
- Block metadata:
  `https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/`

This document is a project contract, not an immutable substitute for the
current official documentation.
