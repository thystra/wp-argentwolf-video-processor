# AWVP 2.0 PeerTube Connection, Authentication, and API Contract

Status: tranche 2.0-3 design contract; R34 authenticated API primitives, R35
local persistence foundation, R36 local-only pre-grant coordination, R37
password-grant/encrypted-token persistence, R38 explicit administrator
authorization, R39 authenticated identity/owned-destination selection, R40 local
backend activation, and R41 token refresh/revoke/disconnect implemented through
that checkpoint
Reviewed runtime baselines: PeerTube 8.1.8 and 8.2.4, 2026-08-22
Applies to: configured/manageable PeerTube backends

## 1. Purpose and scope

Tranche 2.0-3 introduces the first AWVP runtime boundary that may contact a
configured PeerTube instance.

This tranche defines:

- canonical configured PeerTube origins;
- an origin-bound WordPress HTTP client;
- public PeerTube instance detection;
- login/bootstrap, token lifecycle, refresh, and revoke behavior;
- a dedicated server-side secret-store abstraction;
- authenticated identity verification;
- authenticated channel/destination discovery;
- bounded connection health, quota, and capability observations;
- read-only API primitives needed by later library/external-preview work;
- error, retry, rate-limit, and redaction behavior.

This tranche does not upload media, create/delete videos, change remote
publication/privacy state, migrate videos, run PeerTube-side management
operations, expose credentials to browser JavaScript, or build editor UI.

Actual staged source transfer/upload belongs to tranche 2.0-4. Editor workflows
belong to tranche 2.0-6.

## 2. Verified PeerTube API baseline

The initial compatibility floor is PeerTube 8.1.8, the security-fixed 8.1
release. PeerTube 8.2.4 is the primary current integration target. The OpenAPI
document shipped with both releases still identifies its API schema as 8.1.0;
that value is not the running instance version. Read the running version only
from the bounded `serverVersion` field returned by `GET /api/v1/config`.

Relevant current primitives include:

- `GET /api/v1/config`;
- `GET /api/v1/oauth-clients/local`;
- `POST /api/v1/users/token`;
- `GET /api/v1/users/me`;
- `POST /api/v1/users/revoke-token`;
- `GET /api/v1/accounts/{name}/video-channels`;
- read-only account/video endpoints for later library work;
- public video GET endpoints for later external-preview work.

PeerTube documents API rate limiting, with a more restrictive login/token
limit. `429 Too Many Requests` may carry `Retry-After`,
`X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset`.
The status and headers are authoritative because the rate limiter may return a
plain-text body rather than RFC7807 JSON.

PeerTube errors use normal HTTP status codes and support RFC7807-style
`application/problem+json`. Prefer `detail` over deprecated legacy `error`.

The current built-in login flow fetches the local OAuth client, then posts
credentials to `/api/v1/users/token`. A successful response contains access
and refresh tokens plus expiry information. Two-factor login may require the
`x-peertube-otp` header.

PeerTube's maintained tests prove multiple simultaneous login sessions. AWVP
must not assume that a new login invalidates older sessions. A dedicated
PeerTube account remains the least-privilege recommendation and avoids mixing
AWVP operations with an administrator's everyday account.

Do not infer all future capabilities solely from a PeerTube version string.

## 3. Managed backend descriptor

A configured PeerTube connection is a normal AWVP backend descriptor.

Example:

```php
array(
    'id'                  => 'peertube-primary',
    'type'                => 'peertube',
    'label'               => 'Primary PeerTube',
    'state'               => 'active',
    'default_destination' => 'opaque-channel-id',
    'secret_ref'          => 'secret-record-id',
    'config_version'      => 1,
    'config'              => array(
        'origin' => 'https://video.example.org',
    ),
)
```

Rules:

1. backend `id` remains immutable and uses the existing canonical ID contract;
2. `type` is exactly `peertube`;
3. `origin` is non-secret configuration;
4. `secret_ref` is opaque and contains no credential;
5. `default_destination` is an opaque PeerTube destination identifier;
6. account labels, server version, quota, health, and token expiry are dynamic
   observations, not authoritative descriptor fields;
7. arbitrary external PeerTube watch URLs never create backend descriptors.

## 4. Canonical PeerTube origin

A managed PeerTube origin is an origin, not an arbitrary URL.

Ordinary production configuration must:

- use `https`;
- contain a DNS hostname;
- contain no username/password;
- contain no path other than `/`;
- contain no query;
- contain no fragment;
- contain no embedded `/api/v1`;
- contain no control characters/whitespace;
- use a port allowed by the reviewed HTTP safety policy.

Canonical storage examples:

```text
https://video.example.org
https://video.example.org:8443
```

Canonicalization lowercases scheme/host, removes a root slash, and removes
default HTTPS port 443. It must not silently change host or non-default port.

Changing the logical PeerTube instance after durable references exist should
normally create a new backend ID.

## 5. Private/insecure development origins

Production UI must not expose a casual "disable SSRF/TLS safety" checkbox.

Private, loopback, or otherwise WordPress-unsafe origins are rejected by
default.

Controlled development/private installations may explicitly allow exact
canonical origins in `wp-config.php`:

```php
define(
    'ARGENT_VIDEO_PEERTUBE_DEV_ORIGINS',
    array(
        'http://127.0.0.1:9000',
        'http://peertube.test:9000',
    )
);
```

Requirements:

- exact origins only;
- no wildcard/CIDR/all-private-network mode;
- not sourced from an ordinary option or HTTP request;
- HTTPS origins still verify TLS;
- cross-origin redirects remain forbidden;
- any temporary WordPress HTTP safety filter is exact-origin scoped and removed
  immediately afterward;
- no permanent global relaxation of `http_request_host_is_external` or
  `http_allowed_safe_ports`.

## 6. Origin-bound HTTP client

The PeerTube HTTP client must not expose a generic arbitrary-URL fetch method.

It is constructed with one validated canonical origin. Callers provide only a
reviewed method, internal API path, bounded query/body data, and optional auth
context.

All user/operator-configured origin requests use WordPress safe HTTP behavior
equivalent to `wp_safe_remote_request()`.

Initial metadata/auth request policy:

```php
array(
    'blocking'            => true,
    'redirection'         => 0,
    'sslverify'           => true,
    'limit_response_size' => /* reviewed bound */,
)
```

API redirects are disabled. Any 3xx is a failure.

Suggested initial body ceilings:

- normal JSON/config/identity: 1 MiB;
- channel-list page: 2 MiB;
- retained error diagnostic: small bounded redacted excerpt.

These are metadata ceilings, not media-upload limits.

## 7. Public detection and authenticated verification

Connection setup has two phases.

Public detection may call:

```text
GET {origin}/api/v1/config
```

It stores no credential and performs no login retry loop.

R33 implements only this public detection boundary. `PeerTube_Http_Client`
exposes no arbitrary URL or other endpoint, redirects are disabled, the body is
requested with identity encoding and rejected at or above the 1 MiB returned
body limit, and `PeerTube_Api_Client::detect_instance()` retains only a small
normalized observation. No administrator action invokes it yet; R33 sends no
media, credentials, or telemetry. Like any direct HTTP request, the configured
PeerTube operator can observe the WordPress server's network address and the
bounded AWVP product/version User-Agent.

WordPress applies its transport byte limit before decompression in at least one
supported transport. `Accept-Encoding: identity`, disabled requested
decompression, post-return size checks, and rejection of encoded responses are
the R33 defenses, but the WordPress limit is not an absolute pre-decompression
memory guarantee against a noncompliant malicious server. Revisit a streamed
transport boundary before handling larger or less trusted responses.

R34 extends the origin-bound client with explicit, non-generic primitives for
the local OAuth-client read, password/OTP token exchange, `/users/me`, and the
public account-channel listing. It validates exact methods, paths, form fields,
headers, content types, status codes, response shapes, token lifetimes, identity
binding, deterministic pagination, owner identity, and channel locality. It
returns only reviewed projections; raw responses, password, OTP, and the local
OAuth client do not enter WordPress options or diagnostics.

`owned_channels()` accepts the bearer token, performs its own `/users/me`
verification, and only then issues the public account-channel reads without the
bearer. It does not accept a caller-supplied identity array as authority.
Credential-bearing endpoint failures discard all remotely supplied textual
diagnostics except exact allowlisted PeerTube machine codes; numeric HTTP/rate
metadata remains available.

At the R34 checkpoint these primitives had no registered administrator action
or production connection orchestrator. Its focused Docker fixture could invoke
them against an isolated mock, but normal plugin execution did not. R34 kept
the token result ephemeral because a successful password grant creates a live
remote session before identity and channel verification can finish; discarding
that result after a later failure would leave an untracked session, while
persisting it without a durable pending/reconciliation protocol would create a
different partial-mutation hazard. R35 and R36 subsequently established the
required local journal, exact pending secret reservation, disabled descriptor,
and mutation planning boundaries. R37's bounded use of these primitives
is described below. No connection path in these tranches sends media or
performs upload/import/video mutation.

After explicit `manage_options` administrator authorization, authenticated
verification may:

1. fetch `/api/v1/oauth-clients/local`;
2. exchange bootstrap credentials at `/api/v1/users/token`;
3. verify identity with `/api/v1/users/me`;
4. discover manageable channels;
5. persist only the approved non-secret backend descriptor plus `secret_ref`.

A failed test must not leave a false-good active backend eligible for work.

## 8. Authentication bootstrap

Initial managed auth flow:

1. fetch the local OAuth client;
2. receive bounded `client_id` and `client_secret`;
3. submit `application/x-www-form-urlencoded` login to `/api/v1/users/token`;
4. send username/password only for immediate bootstrap;
5. send OTP through `x-peertube-otp` when required;
6. validate token response;
7. persist reusable token material only through the secret store;
8. discard password and OTP from durable state;
9. verify `/api/v1/users/me`;
10. discover destinations.

Password is never persisted after successful bootstrap.

OTP is never persisted.

The local OAuth client response is not ordinary backend configuration. Prefer
re-fetching the current instance-local client when needed instead of treating
its returned client secret as operator-managed durable configuration.

R37 implements only the password-grant and encrypted-token-persistence prefix
as an explicit, unregistered server-side service. It fetches the local OAuth
client before claiming a grant attempt, then re-proves the exact journal,
pending secret, and disabled descriptor. A request-local 256-bit capability is
committed into the attempt ID; only the commitment is durable. Only a definitely confirmed claim may
continue toward one password-token POST. After the final prerequisite and claim
recheck, a second exact journal event marks the same grant attempt as starting
its request and refreshes the stale timer immediately before HTTP. Only a
capability-authenticated mark followed by a read-only reproof of the local prerequisites and
exact marked journal authorizes the POST; this catches mutations made by the
mark's own WordPress option hooks. A mark that ages beyond the 15-second
pre-POST allowance is refreshed, with bounded exhaustion becoming a definite
no-send result. If a post-claim local prerequisite changes
before HTTP, the journal records that the grant was not sent and returns to an
explicit credential-waiting state.

After the POST is invoked, transport/TLS/5xx/malformed-success or other
uncertain outcomes become terminal `grant_indeterminate`; the service never
silently repeats the request. A valid token response is encrypted into a
request-local prospective `secret_commit` plan. Only bounded before/after hash
evidence enters the journal, the exact plan is applied in the same request, and
a later credential-free reconciliation call may confirm the authenticated
generation-one record. Journal evidence alone is never authority to recreate
or replay lost token material.

A definite OTP-required or credential-class response is also journaled in two
steps. Its bounded evidence first enters a pending, non-grant-eligible phase;
only a second transition bearing the request-local capability may expose
`awaiting_otp` or `awaiting_credentials`. The capability never enters the
journal, result, or option-hook values. An observer therefore cannot promote a
pending state created during an uncertain request; an authentic applied but
temporarily unobservable confirmation remains safe for explicit retry.

External-auth-plugin token flows require separate reviewed support.

## 9. Dedicated account recommendation

Admin UX should recommend:

> Use a dedicated PeerTube account for ArgentWolf Video Processor.

The account should have only the permissions/channel access needed by AWVP.

Multiple simultaneous sessions are supported, but AWVP should still warn that
using an everyday account expands credential exposure and mixes unrelated
session lifecycles.

## 10. Backend secret-store abstraction

The backend registry remains non-secret.

Introduce a `Backend_Secret_Store` abstraction or equivalent. The descriptor
contains only `secret_ref`.

A secret record may contain:

- access token;
- refresh token;
- access expiry timestamp;
- refresh expiry timestamp;
- record format/version;
- monotonic generation/revision;
- backend-ID binding.

It must not contain:

- password after successful bootstrap;
- OTP;
- raw unbounded API responses;
- remote video metadata;
- task payloads.

## 11. Managed WordPress secret provider

Suggested option:

```text
argentwolf_video_processor_backend_secrets
```

Requirements:

- versioned;
- explicitly non-autoloaded;
- never exposed via Settings API/REST;
- never shown in diagnostics, Site Health, CLI diagnose, logs, or reports;
- never copied into task payload/error fields;
- backend retirement does not silently destroy still-referenced secrets;
- uninstall preserves user data by default.

Plaintext token persistence is not an acceptable fallback.

## 12. Encryption at rest

Managed secret payloads must use authenticated encryption before database
storage.

Initial provider preference:

1. libsodium authenticated encryption when required sodium functions exist;
2. OpenSSL AES-256-GCM when required OpenSSL functions exist.

If neither reviewed provider is available, managed secret storage is
unavailable. Connection setup fails closed or requires an external provider.
There is no plaintext fallback.

Key material is derived from WordPress installation secret material plus an
AWVP-specific context. Raw WordPress salts/keys are not stored in the secret
option.

Threat model:

- protects against database-only disclosure/backups;
- does not claim protection if attacker also controls PHP/wp-config/runtime;
- WordPress salt rotation may make ciphertext unreadable;
- unreadable/tampered records become `reauthentication_required`, not silent
  corruption/deletion.

## 13. External secret provider

Advanced operators may source reusable credential material from reviewed
`wp-config.php` constants/environment variables.

Rules:

- descriptor still stores only `secret_ref`;
- reference names may be stored, secret values may not;
- resolved external values are not copied into the database;
- missing external material fails closed;
- provider abstraction must not force database storage as the only mode.

## 14. Token lifetime

Normalize returned lifetimes to absolute server-side expiry timestamps:

```text
access_expires_at  = received_at + expires_in
refresh_expires_at = received_at + refresh_token_expires_in
```

Apply a clock-skew margin. Do not begin a state-changing operation with a token
known to be near expiry.

Tokens are opaque strings; do not parse them as JWTs without a future reviewed
contract.

## 15. Refresh and reauthentication

PeerTube returns refresh-token material for normal login sessions. Refresh uses
the same `/api/v1/users/token` endpoint with form fields `client_id`,
`client_secret`, `grant_type=refresh_token`, and `refresh_token`. It sends no
bearer, username, password, or OTP. Successful refresh rotates both tokens.

Refresh must:

- remain server-side and origin-bound;
- use current reviewed OAuth-client bootstrap;
- atomically replace access/refresh material;
- preserve a newer record if another request already refreshed it;
- never log old/new tokens;
- never loop indefinitely on 401;
- treat HTTP 400 `invalid_grant` as `reauthentication_required` when the refresh
  token is expired, revoked, reused, or bound to a different client;
- classify a timeout, lost response, or malformed success after refresh as an
  indeterminate partial mutation. PeerTube may already have revoked the old
  token, so blindly retrying it is unsafe.

A failed refresh does not delete the backend descriptor.

## 16. Refresh concurrency

Avoid refresh storms.

Minimum discipline:

1. load secret generation;
2. acquire backend/secret-specific short-lived lock;
3. refresh;
4. re-read generation before write;
5. write only if source generation is still current;
6. otherwise discard stale result and use newer stored record.

Lock contains no token and expires after crashes.

## 17. Logout/disconnect

Explicit administrator disconnect may call:

```text
POST /api/v1/users/revoke-token
```

The current bearer authorizes revocation; this endpoint accepts no token body.
Success is HTTP 200 with `{"success":true}`. PeerTube 8.1.8 does not await token
deletion, so compatibility tests allow bounded propagation before proving that
the access and associated refresh token are invalid. PeerTube 8.2.4 awaits the
deletion.

Disconnect/revoke does not delete remote videos, AWVP Videos, remote-asset
records, or automatically hard-delete the backend descriptor.

Backend retirement and token revocation are distinct actions.

Uninstall does not make outbound PeerTube API calls.

## 18. Authenticated identity

`GET /api/v1/users/me` is authority for current authenticated identity.

Cache/store only bounded non-secret observations needed for UI/routing, such as
user/account IDs/names, permission indicators, and quota fields where exposed.

Never persist the entire raw response.

## 19. Destination discovery

Discover destinations through the authenticated account/channel API, not global
federated search.

Authenticate with `/api/v1/users/me` first, bind the account-channel request to
the returned `account.name`, use deterministic `sort=id`, and verify each
candidate has `ownerAccount.id` equal to the authenticated account ID and
`isLocal === true`. The account-channel endpoint itself is public, so a
successful listing does not prove token validity or management authority.

Normalized destination data is bounded and non-secret:

```php
array(
    'id'           => 'opaque-id',
    'name'         => 'machine-name',
    'display_name' => 'Human Name',
    'authority'    => 'owned',
)
```

Destination IDs are opaque. If normalization would rewrite an ID, reject it.

PeerTube 8.x collaboration listings do not automatically prove upload
authority. Verify authority for the intended operation; otherwise mark the
channel read-only/non-eligible.

`default_destination` is never silently rewritten when a channel disappears.

## 20. Read-only video primitives

The API layer may expose read-only methods for later editor work without
building the UI in this tranche.

Authenticated methods may cover account/channel video listing, bounded search,
privacy/processing observations, and pagination.

Public external-preview methods may cover bounded video metadata needed later
for URL verification.

An external pasted PeerTube URL from an unconfigured origin never gains
management authority or creates a configured backend/secret.

## 21. Upload boundary

Tranche 2.0-3 may document/test upload endpoint compatibility but sends no media
bytes.

No connection test may call:

- video upload;
- resumable upload initialization;
- video import;
- video create/update;
- publication/privacy mutation;
- remote delete.

Actual upload begins in tranche 2.0-4.

## 22. HTTP/error normalization

Normalize responses into bounded machine states such as:

```text
ok
transport_error
unsafe_origin
tls_error
invalid_response
authentication_required
otp_required
permission_denied
not_found
rate_limited
remote_error
unsupported_api
```

May retain bounded non-secret status/type/code/detail/retry information.

Never retain:

- Authorization header;
- password form body;
- OTP;
- access token;
- refresh token;
- raw OAuth-client response;
- unbounded body.

## 23. Rate limiting and retries

`429` is not automatically "bad password".

Honor bounded `Retry-After`/rate-limit observations.

Interactive connection tests report delay rather than hammering token endpoint.

Automatic work uses bounded exponential backoff and hard attempt ceilings.
There are no background password retries.

## 24. Content type and JSON validation

Expected JSON endpoints must return supported JSON/problem JSON or pass a
narrow reviewed compatibility rule.

Parsing must check HTTP status, bound body size, validate JSON decoding, verify
required fields/types, ignore unknown optional fields safely, and reject
missing required token/identity fields.

Never render arbitrary remote HTML in admin notices.

## 25. Health and capabilities

Structural capability remains separate from live health.

PeerTube may structurally support staged/server-push ingest, processing,
account-library listing, existing-asset selection, embed, and verified
publication/retention operations.

Live health may report reachability, auth validity, API compatibility,
destination availability, rate limiting, or quota state.

Temporary outage does not erase structural capability.

## 26. Stable connection diagnostics

Suggested stable codes:

```text
peertube.origin.invalid
peertube.origin.unsafe
peertube.instance.invalid
peertube.connection.failed
peertube.response.invalid
peertube.auth.invalid
peertube.auth.otp_required
peertube.auth.reauthentication_required
peertube.auth.rate_limited
peertube.api.unsupported
peertube.channels.none
peertube.channels.unauthorized
peertube.secret.unavailable
peertube.secret.decrypt_failed
peertube.connection.ok
```

Human-readable messages are not machine contracts.

## 27. Quotas and caching

Quota is dynamic observation, not descriptor truth. Missing quota means unknown,
not unlimited.

Bounded non-secret dynamic observations may use transients/cache. Tokens do not.

Stale channel cache cannot authorize a state-changing operation when fresh
authority is required.

## 28. Admin/CSRF/browser boundary

Backend credential administration requires `manage_options` and nonce
protection.

Future REST endpoints require real `permission_callback`.

R38 registers a separate settings page with slug
`argentwolf-video-processor-peertube` and exactly four authenticated
`admin_post` actions:

- `argentwolf_video_processor_peertube_connection_start`;
- `argentwolf_video_processor_peertube_connection_resume`;
- `argentwolf_video_processor_peertube_connection_grant`;
- `argentwolf_video_processor_peertube_connection_reconcile`.

It registers no `nopriv`, AJAX, REST, WP-CLI, cron, activation, upload, or media
hook. Loading the settings page is read-only and exposes only a bounded
non-secret projection of open operations. Each action requires POST,
`manage_options`, an action-specific or operation-bound nonce, and the exact
expected scalar fields before it invokes at most one state-changing coordinator
or grant service boundary. The response uses a fixed local `303`
post/redirect/get target and an allowlisted notice identifier; user or remote
text cannot become a notice or redirect value.

Credential bootstrap uses the purpose-built grant action; password/OTP do not go
into backend options, operation projections, page re-renders, redirect
arguments, or notices. The browser fields are unslashed exactly once and
validated without text transformation before their exact accepted bytes reach
the service.

The grant form requires explicit authorization to contact the exact displayed
external service. An allowlisted development-only HTTP origin also requires a
second explicit acknowledgement that the credential and token transport is
plaintext and has no TLS protection.

No persistent PeerTube credential is sent to browser JavaScript.

Later editor calls use WordPress/AWVP authorization rather than PeerTube bearer
tokens in the browser.

## 29. Logging/redaction

Treat secrets as tainted from entry.

Do not log token request bodies, Authorization headers, tokens, password, OTP,
or decrypted secret payload.

Debug mode does not relax this.

## 30. Failure behavior

Fail closed for new managed PeerTube work:

- invalid/unsafe origin -> no request;
- invalid PeerTube response -> no verified connection;
- bad credentials -> no active secret reference;
- OTP required -> explicit state, no persistence of password/OTP;
- secret crypto unavailable -> no plaintext fallback;
- `/users/me` invalid -> not verified;
- no eligible channel -> no upload destination;
- refresh expired -> reauthentication required;
- 429 -> bounded retry/report;
- remote 5xx -> transient health failure, not backend deletion.

Historical local behavior remains unaffected.

## 31. Service/privacy disclosure

The first merged runtime PeerTube-contact tranche must disclose in
administrator-facing/readme/privacy/help text that PeerTube is an
operator-configured external service and that connection tests contact it.
Later uploads send media/metadata to that configured service and its own
operator policies apply. The disclosure must also state that the connection
request exposes normal transport metadata, including the requesting server's
network address and AWVP product/version User-Agent, to the configured service.

The R37 source updates `README.md` and `readme.txt` alongside this
credential-contact implementation. The disclosure states
that an explicitly invoked internal bootstrap sends username/password and an
optional six-digit OTP only to the exact configured PeerTube origin; does not
retain those values or the instance-local OAuth-client response; stores returned
tokens only as authenticated-encrypted, non-autoloaded server-side state; sends
no media, media metadata, or telemetry; and registers no administrator-facing
or automatic connection action. This disclosure text is not, by itself, a
claim of a validated, integrated, or released R37 artifact.

R38 updates those disclosures in the same source slice as the first registered
administrator boundary. They now identify the separate capability- and
nonce-gated page, explicit service authorization, the second development-HTTP
transport acknowledgement, blank/non-reflected credential fields, and the
absence of any AJAX, REST, WP-CLI, cron, activation, upload, or automatic
connection invocation. This source disclosure is not, by itself, commit, CI,
Docker VM, integration, package, or release evidence.

## 32. Uninstall

Default uninstall remains non-destructive.

Uninstall makes no outbound PeerTube API calls. Remote deletion or token
revocation is not an uninstall side effect.

## 33. Responsibility boundaries

Possible focused classes:

```text
PeerTube_Origin
PeerTube_Http_Client
PeerTube_Api_Client
PeerTube_Connection_Service
Backend_Secret_Store
Managed_Backend_Secret_Store
External_Backend_Secret_Store
PeerTube_Backend_Adapter
PeerTube_Api_Error
```

Names may change; boundaries do not:

- origin validator does not log in;
- HTTP client accepts no arbitrary full URL;
- API client persists no secrets;
- secret store performs no HTTP;
- connection service orchestrates bootstrap/verify/store;
- backend registry stores only non-secret descriptor data;
- adapter reports structural capabilities and bounded live health.

## 34. Backend registry evolution

Tranche 2.0-2 intentionally allowed only local writes.

Tranche 2.0-3 extends known writing for `peertube` config version 1 without
weakening downgrade safety.

Initial PeerTube config v1:

```php
array(
    'origin' => 'https://video.example.org',
)
```

Requirements:

- known PeerTube config has type-specific sanitizer;
- secret-like ordinary config remains recursively rejected;
- future PeerTube config versions fail closed;
- unknown future descriptor fields are not erased;
- unknown/future registry descriptors remain preserved/fail-closed;
- local descriptor semantics remain unchanged.

R34 added only a read-only preflight for a prospective PeerTube-v1 append. R35
adds the corresponding bounded writer, but still does not connect it to the
OAuth primitives or any production action. The writer accepts a new
**disabled** descriptor only, preserves existing current-version
unknown/future fields and descriptors exactly, and refuses a future or
malformed top-level registry.

R35's private option primitive reads an authoritative raw database snapshot and
performs one exact compare-and-swap attempt. It reports applied, conflict,
indeterminate, or refused outcomes; uses conditional rollback/delete that
cannot erase concurrent state; invalidates the relevant option caches; and
emits the matching WordPress option actions around the committed value. Inputs
are already prospectively validated, canonical arrays for fixed-scope
AWVP-owned options. The primitive deliberately does not reproduce arbitrary
Settings API filters or sanitizers.

All R35 registry, journal, and managed-secret options are non-autoloaded. An
existing target option whose raw row is autoloaded is refused rather than
silently transformed. Any deliberate autoload repair is a separate operation
followed by a fresh authoritative snapshot; a stale pre-repair snapshot is
never reused.

## 35. Durable local connection operations

R35 introduces a local-only connection operation journal at:

`argentwolf_video_processor_peertube_connection_operations`

The journal is non-autoloaded, versioned, bounded to 32 records, and permits at
most one unresolved operation per backend. Completed records can be removed by
an exact journal compare-and-swap; unresolved records are never evicted to make
room. Each operation durably reserves its operation ID, managed-secret
reference, and provisioning ID before any later caller may contact PeerTube.
Records contain bounded non-secret evidence only. Passwords, OTPs, OAuth client
secrets, access/refresh tokens, authorization values, raw responses, request
bodies, response bodies, and arbitrary remote detail are structurally refused.

The managed secret provider keeps existing version-1 ready records readable.
Its `argentwolf_video_processor_backend_secrets` option is only the provider
version manifest; each credential has a separate non-autoloaded
`argentwolf_video_processor_backend_secrets_<managed_ref>` record option. New
version-2 records distinguish a reserved `pending` slot from an encrypted
`ready` slot and bind the provisioning ID into the authenticated-encryption
context. Stale replacement and delete operations use exact generation/state
comparisons so they cannot overwrite or remove a newer credential.
If manifest creation succeeds before a later reservation step fails, the
composite result retains that applied partial mutation as indeterminate rather
than misreporting a no-mutation conflict or refusal.

Registry link and activation plans may be replaced with fresh evidence and a
new mutation ID only after a classified, definite no-mutation conflict. An
indeterminate mutation retains its existing plan for authoritative
reconciliation; it is never re-planned merely because the caller timed out.

The required future orchestration order is:

1. create the durable journal record and reserve stable IDs;
2. create the exact empty pending secret slot;
3. append the exact disabled backend descriptor;
4. only then begin a remote password grant;
5. commit reusable credentials into the reserved encrypted slot;
6. verify current identity and discover bounded destinations;
7. persist activation intent and re-verify the durable prerequisites;
8. activate through a separately reviewed registry transition and then close
   the journal record.

The pure R35 transition model includes an explicit `grant_indeterminate` phase.
That phase has no automatic outbound transition: an uncertain remote grant is
not silently retried. R35 does not yet implement the production connection
coordinator, activation writer, admin/AJAX/REST action, or any runtime HTTP
invocation.

## 36. Local-only pre-grant coordinator

R36 adds a restart-safe coordinator for only the local preparation prefix:

```text
prepared
  -> secret_reserve_planned
  -> secret_reserved
  -> link_planned
  -> disabled
  -> separate read-only ready_for_grant recheck
```

`start()` creates only the durable journal record after a read-only
backend-ID availability preflight. An already occupied ID is refused before an
operation record is created. The preflight does not replace the later exact
registry compare-and-swap, which remains authoritative if the registry changes
concurrently.

Each `resume()` call crosses at most one local persistence boundary. Shared
managed-secret manifest initialization, journaling prospective-plan evidence,
applying its target option mutation, and confirming the journal phase are
separate boundaries. Reaching `disabled` is also separate from returning
`ready_for_grant`: a subsequent mutation-free call must re-prove the exact
pending secret reservation and exact disabled descriptor.

Before a consequential target-option write, R36 builds a request-local,
single-use prospective plan from an authoritative raw snapshot. Planning
performs no mutation. The plan binds the option, mutation kind, fresh mutation
ID, exact before/after values, and bounded non-secret evidence. Only that
evidence is copied into the journal; the request-local values are not durable
authority and must not expose secret material through diagnostics or debug
output.

After interruption or an indeterminate outcome, reconciliation uses the same
journaled evidence and mutation ID. It does not silently mint a replacement
plan. A disabled-registry link may be replanned only after the exact target
compare-and-swap reports a SQL-phase, zero-row, definite no-mutation conflict
and an authoritative probe does not find the semantic postcondition. The new
plan must use a fresh mutation ID and must itself be journaled before use.

The registry postcondition is semantic: the whole registry must remain a valid,
preservable version-1 registry and the backend ID must map to the exact planned
disabled PeerTube descriptor. Unrelated valid descriptors added concurrently
do not invalidate that postcondition. A ready secret record, foreign pending
reservation, occupied backend ID, malformed/future registry, autoloaded private
option, or indeterminate read fails closed and is never overwritten.

R36 accepts no password, OTP, OAuth secret, access token, refresh token,
authorization value, or remote response. It performs no PeerTube HTTP, exposes
no admin/AJAX/REST action, registers no activation path, mutates no uploads, and
does not change the model schema, plugin version, or release version. Remote
grant, credential commit, identity verification, destination selection,
activation, and journal closure remain separately reviewed work.

### R37 unregistered password-grant bootstrap

R37 adds a narrow `PeerTube_Password_Grant_Api` boundary and an explicit
`PeerTube_Password_Grant_Service`. Loading the service registers no
administrator form, AJAX/REST route, WP-CLI command, cron callback, activation
hook, or upload path. A future authorized caller remains separate work.

`submit()` accepts one bounded username/password/optional six-digit OTP attempt
from a trusted server-side caller. It fetches the exact-origin local OAuth
client before the durable grant claim so a failed read cannot consume or strand
an attempt. It then re-proves the journal and both durable local prerequisites,
persists and confirms only the domain-separated commitment to a fresh
request-local attempt capability, rechecks the prerequisites once more,
and re-proves the exact claim. Immediately before HTTP it must persist and
confirm a capability-authenticated `mark_grant_request`. That final durable mark
refreshes `grant_started_at` and `updated_at`. The service then read-only
re-proves both prerequisites and the exact marked journal after the mark's
option hooks. If the mark has consumed more than half of the 30-second stale
window, it is refreshed and re-proved; three aged marks produce a definite
grant-not-sent outcome. Only then may it invoke at most one credential-bearing
token POST. Once the POST has been invoked, uncertain outcomes are terminal and are
never retried automatically.

The caller-supplied time is only a floor. The service obtains a fresh
non-regressing timestamp after the OAuth-client GET for the grant claim and
fresh timestamps immediately before and after the password-token request. The
post-response observation anchors token-lifetime checks and the journaled
response state. A bounded `Retry-After` is consequently enforced from that
response observation through the record's `updated_at`, so request latency
cannot backdate the wait. Result mutation classification is cumulative across
the entire call: unknown mutation dominates applied mutation, which dominates
no mutation, including when a later persistence step conflicts or fails.

Definite OTP-required, credential, invalid-client, permission, and rate-limit
results do not move directly to a retryable phase. Their bounded evidence first
enters `otp_result_pending` or `credential_result_pending`. A second
`confirm_grant_result` journal event must supply the request-local capability
matching the durable attempt commitment before the operation becomes
`awaiting_otp` or `awaiting_credentials`; the pending phases are not
grant-eligible and permit no credential retry. The service attempts the second
transition without another HTTP request. If it is not applied, the
pending phase remains; credential-free reconciliation does not promote that
uncertain result and may terminalize it only as `local_persistence_unknown`
after the stale interval. If it is applied but its confirming read fails, the
capability proof makes the resulting retryable phase authoritative and safe.

Successful token material is validated, held only request-locally, and used to
prepare an authenticated-encrypted generation-one `secret_commit`. The journal
stores only bounded mutation evidence before the exact target plan is applied.
`reconcile()` takes no credentials and performs no HTTP. It may confirm an
already-applied encrypted record and advance to `ready_for_verification`; it
never reconstructs or applies a secret from evidence. A still-in-flight grant
or unresolved planned secret write is not classified as an interrupted/unknown
outcome until the 30-second safety interval has elapsed, which is longer than
the reviewed 15-second HTTP timeout. The confirmed grant-request mark
immediately before HTTP refreshes the timestamp from which in-flight staleness
is measured.

For a stale planned secret write, reconciliation first probes for the
authenticated exact ready record. Otherwise it may prospectively replace only
an absent target or the exact empty generation-zero reservation with a durable,
non-secret `fenced` marker. A fresh probe resolves the
ready-write-versus-fence compare-and-swap: if the generation-one write won, it
is confirmed; terminal `local_persistence_unknown` is permitted only after the
exact fenced marker is confirmed. Retaining that marker prevents both stale
absent-to-pending reservation plans and stale pending-to-ready commit plans from
regaining authority through an ABA return to earlier bytes. A ready, newer,
foreign, or indeterminate record is never overwritten or inferred fenced. The
secret target and disabled registry are re-proved after journal confirmation.
Terminal reconciliation repairs an absent/exact-pending target back to fenced
or recovers an exact ready winner to `secret_stored` without HTTP.

R37 stops after durable encrypted token persistence and reconciliation. It does
not verify `/users/me`, discover or select a destination, activate the disabled
descriptor, refresh or revoke tokens, close the full connection operation,
send media, mutate uploads, change the model schema, or change the plugin or
release version. This contract does not embed self-referential commit, Forgejo
CI, Docker VM, package, integration, or release evidence; establish those facts
from the exact repository refs and their associated validation records.

### R38 explicit administrator authorization boundary

R38 registers a server-rendered administrator surface over only the existing
R36/R37 preparation, password-grant, and credential-free reconciliation
boundaries. Starting an operation records the exact bounded backend ID,
canonical origin, label, current administrator ID, and time through the
coordinator. Resume advances at most one local preparation boundary. Grant
submits at most one explicitly authorized username/password/optional OTP
attempt. Reconcile accepts no credentials and performs no HTTP.

The page GET performs no coordinator, grant, or reconciliation mutation.
Operation listings and detail views validate and render only the operation ID,
backend ID, origin, label, phase, record revision, grant attempt count, bounded
retry time, and creation/update times. Credential fields are always blank and
are not repopulated after OTP, credential, rate-limit, conflict, or uncertain
results.

An indeterminate remote grant remains terminal and offers no further credential
submission. It may still offer credential-free, no-HTTP reconciliation to
confirm an exact encrypted-token write that won before the local result became
uncertain. Retryable OTP or credential states require a fresh explicit form
submission and authorization; the OTP field is mandatory in the OTP-required
phase, and there is no loop or automatic resubmission. A single request never
hides an earlier partial mutation behind a later result, and fixed notices
direct the administrator to inspect durable state when the outcome may have
changed.

R38 stops before `/users/me` verification, channel or destination discovery,
activation of the disabled descriptor, refresh, revoke, disconnect, operation
closure, upload/media mutation, model-schema or runtime-version changes, and any
release or `main`-branch work.

### R39 authenticated identity and owned-destination boundary

R39 adds a dedicated `PeerTube_Identity_Destination_Api` boundary and a
restart-safe `PeerTube_Identity_Destination_Service` over the credential already
committed by R37/R38. It never accepts a bearer token from the browser. It reads
only the exact managed-secret generation bound to the open operation, requires
the exact disabled descriptor to remain present, applies a 60-second access-
token skew margin, and re-proves the operation, secret generation, and registry
after WordPress HTTP hooks have run. An applied journal transition is confirmed
only after the same prerequisites and exact journal record are re-proved after
its WordPress option hooks; a changed prerequisite is an indeterminate partial
mutation, never a clean conflict or successful checkpoint.

Verification is two explicit administrator requests. The first request journals
`verification_in_flight` without contacting PeerTube. A later request in that
phase invokes `owned_channels()`, which performs one authenticated `/users/me`
read followed by deterministic public account-channel pages without the bearer.
It journals only the reviewed user/account identity projection or an allowlisted
bounded failure. The ephemeral destination list, raw response, bearer, and
arbitrary remote detail never enter the operation journal or backend registry.

An ordinary settings-page GET makes no PeerTube request. In
`awaiting_destination`, an explicit nonce-bound administrator GET may refresh
the current destination chooser. The chooser validates at most 500 strictly
ordered, unique, local owned-channel projections and escapes all rendered text.
Submitting a destination does not trust the browser's prior list: the service
repeats the complete identity/channel read, requires the exact submitted opaque
decimal ID in that current owned set, and only then journals selection intent.
That transition deliberately clears the earlier identity and returns to
`verification_in_flight`. A final explicit verification must prove the selected
channel still belongs to the current bearer identity before the operation may
reach `activation_ready`.

No missing or changed channel silently rewrites `selected_destination`. A
selected channel that disappears produces `peertube.channels.unauthorized` and
retains the exact selection for operator review. An account with no eligible
owned channel cannot advance. Identity and channel reads are safe to repeat but
are never automatic, and rate-limit evidence blocks an early explicit retry.

R39 registers only two additional authenticated `admin_post` actions, for
verification and selection. Discovery is a capability- and nonce-bound,
read-only settings request. There is still no `nopriv`, AJAX, REST, WP-CLI,
cron, automatic retry, activation, refresh/revoke, upload, or media mutation
path. The backend descriptor remains disabled with an empty
`default_destination`; activation is the next separately reviewed tranche.

### R40 explicit local backend activation boundary

R40 consumes only a durable `activation_ready` operation produced by R39. It
adds no PeerTube HTTP endpoint or remote mutation. The administrator must submit
a seventh authenticated POST action,
`argentwolf_video_processor_peertube_connection_activate`, for every continuation.
Each request requires `manage_options`, an operation-bound nonce, and the exact
operation ID.

The restart-safe sequence deliberately separates four local boundaries:

1. prove the current managed-secret generation, exact disabled descriptor,
   verified destination, and installed PeerTube adapter, then journal an exact
   `registry_activate` compare-and-swap plan;
2. apply or reconcile that exact shared-registry plan, changing only target
   `state` to `active` and `default_destination` to the already re-verified ID;
3. on a later explicit request, confirm the authoritative active descriptor in
   the journal;
4. independently re-prove secret generation, exact active descriptor, adapter
   registration, conservative capability, and non-blocking descriptor-aware
   health, then close the operation as `complete`.

An unrelated shared-registry winner is never overwritten. A definite stale-plan
conflict may be replanned only after a fresh semantic probe proves the exact
target descriptor is still disabled and unchanged. Unknown mutation authority,
malformed/future target state, missing adapter, changed secret generation, bad
destination, unreadable secret, or an expired/near-expiry access token fails
closed.

The registered R40 PeerTube adapter exposes only `delivery.embed`; every
media-ingest, processing, managed-library, publication, retention, and delete
capability remains false. Usable local credential state reports a warning because
token refresh/live operational health remain R41 work. The activation action
performs no PeerTube HTTP request, upload, processing, remote mutation, refresh,
revoke, or disconnect.

## 37. Adapter factory evolution

Factory may register PeerTube alongside local.

Descriptor presence alone never establishes eligibility. Eligibility still
requires installed implementation, structural capability, destination where
required, and non-blocking health.

## 38. No schema bump by default

A non-autoloaded option-based secret provider does not require a custom table.

Tranche 2.0-3 therefore should not advance the model schema merely for
connection/auth. Any table proposal requires separate persistence review and
real WordPress/database matrix.

## 39. Implementation acceptance gates

Before runtime implementation merges, prove at minimum:

1. exact clean `develop-2.0` base;
2. existing backend/local and persistence regressions pass;
3. no unapproved model schema change;
4. canonical HTTPS origin accepted;
5. path/query/fragment/userinfo rejected;
6. private/unsafe origin rejected by default;
7. exact dev origins require `wp-config.php` allowlist;
8. HTTP client cannot request arbitrary full URL;
9. WordPress safe HTTP behavior used;
10. redirects disabled;
11. TLS verification not silently disabled;
12. response sizes bounded;
13. invalid JSON/required fields fail closed;
14. RFC7807 errors normalize without secrets;
15. 429 preserves bounded retry info;
16. password never stored after bootstrap;
17. OTP never stored;
18. access/refresh tokens never enter backend descriptors;
19. managed secret option is non-autoloaded;
20. managed secret payload is authenticated-encrypted;
21. no plaintext fallback;
22. tampered ciphertext fails closed;
23. key/salt change becomes reauthentication-required;
24. secrets absent from diagnostics/logs/errors/reports;
25. bootstrap verifies `/users/me`;
26. failed verification cannot create false-good backend;
27. destinations are bounded/normalized;
28. collaboration without proven authority is non-eligible;
29. default destination is not silently rewritten;
30. token expiry uses absolute time plus skew margin;
31. refresh is bounded/concurrency-safe;
32. stale refresh cannot overwrite newer secret;
33. refresh failure may require reauthentication;
34. disconnect/revoke deletes no remote media;
35. connection test performs no upload/create/update/delete;
36. external pasted URL creates no configured backend/secret;
37. no persistent PeerTube secret reaches browser JS;
38. `manage_options` plus nonce/permission gates admin actions;
39. PeerTube writer preserves future/unknown registry state;
40. raw option compare-and-swap distinguishes conflict from indeterminate;
41. rollback/delete is conditional and cannot erase concurrent state;
42. every consequential local target write has a prospectively validated,
    request-local plan whose bounded evidence is journaled before use;
43. each pre-grant coordinator call crosses at most one local persistence
    boundary;
44. an indeterminate local mutation retains the same evidence and mutation ID;
45. a registry-link plan is replaced only after a SQL-phase zero-row definite
    no-mutation conflict, and its replacement has a fresh mutation ID;
46. registry reconciliation accepts the exact disabled descriptor within a
    shared valid registry without erasing unrelated descriptors;
47. occupied backend IDs are refused before the initial journal write, while
    the later compare-and-swap remains authoritative;
48. the `disabled` journal transition and mutation-free `ready_for_grant`
    prerequisite recheck are separate calls;
49. the pending secret slot and disabled descriptor are durable before grant;
50. unresolved connection operations are bounded and never evicted;
51. an indeterminate grant is never retried automatically;
52. stale secret replacement/deletion cannot affect a newer generation;
53. the local pre-grant coordinator accepts no credentials and performs no
    HTTP, admin registration, activation, upload, schema, or version mutation;
54. the option/cache/hook contract is exercised against real WordPress 6.4 and
    7.1 databases;
55. local adapter behavior unchanged;
56. no direct GitHub push;
57. main and 1.x lines remain outside this tranche;
58. feature work staged/reviewed before commit/push;
59. runtime service/privacy disclosure lands before first PeerTube network-contact
    implementation merge.
60. the local OAuth-client read occurs before the durable grant claim;
61. only a confirmed claim and freshly re-proved local prerequisites authorize
    one password-token POST;
62. no uncertain post-claim grant outcome is retried automatically;
63. encrypted secret-commit evidence is journaled before the exact request-local
    plan is applied, and later reconciliation never recreates token authority;
64. in-flight/planned reconciliation waits beyond the reviewed HTTP timeout
    before classifying a stale process interruption or lost local persistence;
65. the R37 service remains unregistered and stops before identity,
    destination, activation, refresh/revoke, operation closure, or upload work;
66. the grant claim and response states use fresh post-OAuth/post-response
    timestamps rather than backdating remote latency to the caller's input;
67. `Retry-After` begins at the fresh response observation;
68. one call reports cumulative partial-mutation classification instead of
    letting its final persistence attempt hide an earlier mutation;
69. stale secret-write terminalization prospectively replaces only absent or
    exact empty generation-zero state with a persistent marker and re-probes a
    concurrent ready-write-versus-fence race;
70. an exact confirmed grant-request-start mark refreshes the stale timer
    immediately before the sole credential-bearing POST;
71. definite OTP/credential-class outcomes remain in non-grant-eligible pending
    phases until a second journal transition proves the request-local attempt
    capability; only its domain-separated commitment is durable;
72. a stale secret plan replaces absent or exact pending state with a persistent
    non-secret fenced marker, and only confirmed fenced permits terminal local
    persistence uncertainty, preventing absent-to-pending and pending-to-ready
    ABA resurrection;
73. grant-request marks are refreshed when hook/reproof latency consumes more
    than half the stale window, and bounded refresh exhaustion sends no
    credentials;
74. secret/registry targets are freshly re-proved after journal hooks, with an
    exact ready winner recoverable from terminal commit evidence without HTTP.
75. only the nine reviewed authenticated `admin_post` hooks (the four R38
    connection/grant actions, R39 verification and selection, the R40 local
    activation continuation, and the two R41 refresh/disconnect actions) and the separate `manage_options` settings page are
    registered; there is no `nopriv`, AJAX, REST, WP-CLI, cron, automatic upload,
    processing, or media hook;
76. page GET is read-only and renders only a validated bounded non-secret
    operation projection;
77. each action rejects non-POST requests and fails capability, nonce, unexpected
    field, non-scalar, and domain validation before invoking a state-changing
    service boundary; grant may first read its bounded non-secret operation
    projection to validate phase and exact origin;
78. resume, grant, and reconcile nonces bind the exact operation ID;
79. accepted username/password bytes are unslashed once but not sanitized or
    transformed, and OTP is either empty or exactly six digits, with six digits
    required in `awaiting_otp`;
80. credential submission requires explicit external-service authorization and
    allowlisted development HTTP requires a separate plaintext-transport
    acknowledgement;
81. each POST invokes at most one coordinator/grant boundary and returns through
    a fixed local `303` with an allowlisted notice identifier;
82. password/OTP and arbitrary remote/user text never enter page projections,
    re-rendered fields, redirects, notices, options, or logs;
83. an indeterminate or exhausted operation offers no further credential form,
    and no browser path automatically retries a grant;
84. the browser authorization boundary is exercised against real WordPress 6.4
    and 7.1 before the R38 checkpoint is accepted;
85. verification intent is durable before the first authenticated identity
    read, and starting that intent performs no PeerTube HTTP;
86. identity/destination HTTP uses only the exact decrypted access-token
    generation bound to the operation and re-proves the disabled descriptor and
    secret generation after HTTP hooks, then re-proves those prerequisites and
    the exact journal record after every applied journal transition;
87. an ordinary administrator page GET performs no PeerTube request;
88. explicit destination discovery is `manage_options` and nonce bound, is
    read-only, and returns only a bounded normalized projection;
89. the public account-channel pages never receive the bearer token;
90. destination observations are not persisted as an unbounded/stale cache;
91. selection repeats current remote authority and refuses a missing,
    noncanonical, nonlocal, foreign-owner, duplicate, unordered, or otherwise
    malformed destination before journaling the ID;
92. selection clears prior identity evidence and a separate explicit
    verification must bind the selected channel to the same current credential
    generation before `activation_ready`;
93. a disappeared selected channel is retained exactly and fails closed rather
    than silently rewriting the default or selection;
94. R39 leaves the registry descriptor disabled with an empty
    `default_destination` and performs no activation, upload, refresh, revoke,
    disconnect, schema, runtime-version, or release mutation;
95. the complete R39 browser and state boundary is exercised against real
    WordPress 6.4 and 7.1 before the checkpoint is accepted;
96. R40 activation accepts only a valid `activation_ready`, `activation_planned`,
    or `active_pending_close` journal state and never invents missing R39 proof;
97. an activation plan is durable before the registry CAS and contains exact
    `registry_activate` evidence; planning does not change the registry;
98. the registry CAS preserves unrelated/future v1 state and may change only the
    exact target descriptor from disabled/empty destination to active/the exact
    re-verified destination;
99. each explicit activation request crosses at most one consequential local
    persistence boundary, and a successful registry write is confirmed in the
    journal only by a later request;
100. shared-registry conflict replan requires definite no-mutation authority plus
    a fresh proof that the exact target remains disabled and unchanged;
101. final close independently re-proves current secret generation, active
    descriptor/destination, adapter installation, capability, and non-blocking
    descriptor-aware health;
102. missing/unreadable/expired credential state or an unavailable adapter blocks
    operation closure without falsely claiming completion;
103. the R40 PeerTube adapter claims only `delivery.embed`; upload, processing,
    managed-library, publication, retention, and remote-delete capabilities remain
    false;
104. the activation browser sequence performs no PeerTube HTTP request beyond the
    exact R39 request log and creates no attachment/upload/media mutation;
105. the complete R40 browser/state boundary is exercised against real WordPress
    6.4 and 7.1 before R40 is accepted.

## 40. Recommended implementation order

1. origin validator;
2. HTTP/error normalization;
3. origin-bound safe HTTP client;
4. secret-store abstraction/crypto tests;
5. managed encrypted provider;
6. exact option compare-and-swap and disabled registry append;
7. durable connection journal, reserved secret slots, and pure transitions;
8. local-only pre-grant coordinator through a separate ready recheck;
9. optional external provider;
10. low-level PeerTube API client and public instance detection;
11. production login/bootstrap coordinator with OTP handling (R37 feature
    slice through encrypted token persistence only);
12. bounded administrator start/resume/grant/reconcile actions through encrypted
    token persistence only (R38 feature slice);
13. `/users/me` verification and destination discovery;
14. activation writer and adapter/factory integration (R40 feature slice);
15. refresh/revoke lifecycle;
16. diagnostics/Site Health;
17. service/privacy/help disclosure before runtime PeerTube contact;
18. full regression/security review.

Actual upload remains tranche 2.0-4.

## 41. Source authority

API behavior was rechecked on 2026-08-22 against official PeerTube 8.1.8 and
8.2.4 release source, runtime controllers, and maintained integration tests.
The shipped OpenAPI schema still reports 8.1.0 and contains known discrepancies,
including declaring `/api/v1/users/me` as an array even though runtime returns a
single object. Runtime behavior and primary tests govern those discrepancies.

WordPress HTTP behavior was reviewed against current official documentation for
`wp_safe_remote_request()`, `wp_safe_remote_get()`,
`wp_http_validate_url()`, and `WP_Http::request()` including
`limit_response_size`, plus current Options/autoload APIs.

Re-check primary documentation during implementation for API details that may
have changed.

### R41 explicit token refresh, revoke, and disconnect boundary

R41 operates only on an already-active managed PeerTube descriptor produced by
R40. Token refresh is explicit administrator work: it reads the current OAuth
client, durably claims `refresh_in_flight`, performs at most one reviewed
`grant_type=refresh_token` POST, replaces exactly the expected encrypted secret
generation, and requires a later explicit request to observe generation `N+1`
before closing `refresh_complete`. A 429 during the read-only OAuth-client
preflight is journaled as bounded `refresh_wait`; expiry of that wait only returns
to `refresh_ready` and does not combine that transition with remote I/O. An
observed unresolved `refresh_in_flight` state is classified indeterminate and is
never replayed automatically.

Disconnect is also explicit. It durably claims `disconnect_revoke_in_flight`
before at most one bearer-authorized `/api/v1/users/revoke-token` POST. A later
request plans an exact shared-registry active-to-retired CAS, another request may
apply that CAS, a later observation confirms retirement, and only then is the
exact expected managed-secret generation deleted. An uncertain revoke is never
automatically retried; local retirement may continue explicitly without claiming
remote revocation was proven. The non-secret lifecycle journal contains no OAuth
client secret, access token, refresh token, password, or OTP material.

The settings boundary therefore contains nine authenticated `admin_post` actions.
Refresh and disconnect require `manage_options`, a backend-scoped nonce, the exact
canonical backend ID, and fixed local redirects. Ordinary settings-page GET,
cron, AJAX, REST, and WP-CLI own none of the lifecycle mutations.

R41 does not upload media, create or alter PeerTube videos, process local media,
publish, manage remote libraries, retain/delete media, or add background token
refresh. The PeerTube adapter continues to claim only `delivery.embed`.

### R42 tranche 2.0-4 pre-upload boundary

R42 begins the next tranche without extending the connection API or granting
media-mutation authority. The connection/credential contracts above remain the
authority for the active backend. A staged-upload operation additionally freezes
the current canonical origin and selected destination alongside an immutable
managed-source commitment. Before a later uploader may use an active credential,
it must re-prove that local binding; a descriptor that was retired, repointed to
another origin, or given another destination is not interchangeable with the
recorded upload intent.

R42 also reserves a durable `upload_in_flight` claim and an
`upload_indeterminate` outcome specifically so the later media-creation POST is
not treated like an ordinary retryable read. This checkpoint supplies no media
POST implementation, no upload administrator action, and no background worker
for PeerTube transfer. The nine R38-R41 authenticated connection/lifecycle
`admin_post` actions therefore remain the complete PeerTube mutation surface.
