# AWVP 2.0 PeerTube Connection, Authentication, and API Contract

Status: tranche 2.0-3 design contract; R34 authenticated API primitives and
R35 local persistence foundation implemented; no production connection action
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

These R34 primitives have no registered administrator action and no production
connection orchestrator. Successful password grant creates a live remote
session before identity and channel verification can finish. Discarding the
token after a later failure would leave an untracked session; persisting it
without a durable pending/reconciliation protocol would create a different
partial-mutation hazard. R34 therefore keeps the result ephemeral and defers
production connection persistence, refresh, and revoke until that protocol,
per-secret serialization, and explicit indeterminate-outcome handling are
implemented. The focused Docker fixture may invoke the primitives against an
isolated mock, but normal plugin execution does not invoke them. No R34 path
sends media or performs upload/import/video mutation.

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

Credential bootstrap uses purpose-built server-side actions; password/OTP do not
go into backend options.

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

## 36. Adapter factory evolution

Factory may register PeerTube alongside local.

Descriptor presence alone never establishes eligibility. Eligibility still
requires installed implementation, structural capability, destination where
required, and non-blocking health.

## 37. No schema bump by default

A non-autoloaded option-based secret provider does not require a custom table.

Tranche 2.0-3 therefore should not advance the model schema merely for
connection/auth. Any table proposal requires separate persistence review and
real WordPress/database matrix.

## 38. Implementation acceptance gates

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
42. the pending secret slot and disabled descriptor are durable before grant;
43. unresolved connection operations are bounded and never evicted;
44. an indeterminate grant is never retried automatically;
45. stale secret replacement/deletion cannot affect a newer generation;
46. the option/cache/hook contract is exercised against real WordPress 6.4 and
    7.1 databases;
47. local adapter behavior unchanged;
48. no direct GitHub push;
49. main and 1.x lines remain outside this tranche;
50. feature work staged/reviewed before commit/push;
51. runtime service/privacy disclosure lands before first PeerTube network-contact
    implementation merge.

## 39. Recommended implementation order

1. origin validator;
2. HTTP/error normalization;
3. origin-bound safe HTTP client;
4. secret-store abstraction/crypto tests;
5. managed encrypted provider;
6. exact option compare-and-swap and disabled registry append;
7. durable connection journal, reserved secret slots, and pure transitions;
8. optional external provider;
9. low-level PeerTube API client and public instance detection;
10. production login/bootstrap coordinator with OTP handling;
11. `/users/me` verification and destination discovery;
12. activation writer and adapter/factory integration;
13. refresh/revoke lifecycle;
14. admin connection actions;
15. diagnostics/Site Health;
16. service/privacy/help disclosure before runtime PeerTube contact;
17. full regression/security review.

Actual upload remains tranche 2.0-4.

## 40. Source authority

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
