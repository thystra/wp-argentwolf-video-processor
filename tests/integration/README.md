# Development integration smokes

These repository-only smokes validate focused development checkpoints. They do
not replace `tests/release-validation/run.sh`, exact-package installation,
Plugin Check, upgrade coverage, or the release compatibility matrix.

## PeerTube read-only HTTP checkpoint

Run from a clean Git checkout on a disposable Docker host:

```bash
bash tests/integration/peertube-http-smoke.sh
```

The runner pulls the reviewed digest-pinned WordPress 7.1/PHP 8.3 image
(`7.1.0-php8.3-apache`), WP-CLI PHP 8.3, and MariaDB 10.11.18 images before
creating an internal-only network.
The runner requires a clean checkout, exports the exact committed Git tree, and
mounts that export read-only as the plugin. No ignored or untracked checkout
file enters the container, and no container port is published. The test
activates the plugin and calls the real WordPress safe HTTP API against an
isolated PHP fixture at the one explicitly allowed development origin,
`http://peertube.test:9000`.

The WordPress and WP-CLI containers receive the same debug and isolated-network
configuration. The runner verifies the exact WordPress version and configuration
before activating the plugin so configuration failures remain distinguishable
from product failures.

By default, the preserved report is created under `/tmp`. To choose another
location outside the repository, set an absolute directory:

```bash
AWVP_R33_REPORT_DIR=/absolute/report/path \
    bash tests/integration/peertube-http-smoke.sh
```

The test requires Docker access for the invoking account and network access to
pull the pinned images. Runtime containers themselves have no external route.
It covers only the R33 public `/api/v1/config` detection boundary. It does not
test TLS, credentials, tokens, refresh/revocation, uploads, a real PeerTube
server, a built plugin ZIP, or release/upgrade behavior.

## PeerTube authenticated API checkpoint

After the R34 source checkpoint is committed, run its separate smoke from a
clean checkout on the disposable Docker host:

```bash
bash tests/integration/peertube-auth-api-smoke.sh
```

This runner retains the R33 isolation and digest-pinned WordPress 7.1/PHP 8.3,
WP-CLI, and MariaDB fixture pattern, but uses a separate internal PeerTube
fixture. It verifies the local OAuth client, the exact OTP-required response
header classification, password-plus-OTP token exchange, bearer-authenticated
`/api/v1/users/me`, and 101 owner/local-bound channels over two public account
channel pages. Discovery re-verifies `/api/v1/users/me` from the bearer token
inside the authority boundary rather than trusting a caller-built identity.
The fixture rejects a bearer token on either public page and validates the
exact methods, paths, query ordering, token form, and OTP header.

The smoke snapshots AWVP-owned options and managed upload storage before the
API calls and requires them to remain unchanged. It performs no refresh,
revocation, upload, backend registration, secret persistence, or other remote
mutation beyond the requested disposable login session. This is focused
development-checkpoint evidence, not real-PeerTube, exact-ZIP, release,
upgrade, Plugin Check, or TLS coverage.

To preserve its report outside the checkout, set an absolute directory:

```bash
AWVP_R34_REPORT_DIR=/absolute/report/path \
    bash tests/integration/peertube-auth-api-smoke.sh
```

## PeerTube local persistence checkpoint

After the R35 source checkpoint is committed, run the two-case persistence
smoke from a clean checkout on the disposable Docker host:

```bash
bash tests/integration/peertube-persistence-smoke.sh
```

The runner exports exact committed `HEAD` once and mounts that export read-only
for both cases:

- WordPress 6.4.2 / PHP 8.1 / MariaDB 10.6.27;
- WordPress 7.1 / PHP 8.3 / MariaDB 10.11.18.

All six official images are digest-pinned. Each case receives its own owned
internal-only network, WordPress volume, database, and WordPress container; no
host port is published. The fixture exercises authoritative raw option
compare-and-swap, version-correct non-autoload storage, WordPress cache/action
behavior, conditional rollback, concurrent-winner preservation, disabled
registry append, the bounded connection journal, pending-secret reconciliation,
authenticated encryption, and generation-bound secret replacement/deletion.
It also proves that an already-autoloaded target is refused rather than silently
repaired.

The persistence fixture installs no HTTP mock because the checkpoint must make
zero WordPress HTTP requests. Every WP-CLI bootstrap receives the isolated
`http://wp` request context, and ambient WordPress cron is disabled so a fresh
site cannot initiate unrelated WordPress.org update checks. Plugin activation
uses WP-CLI's explicit CLI context so the command does not synthesize an admin
page and fire unrelated `admin_init` update checks. The runner still blocks
external HTTP and fails on any PHP diagnostic in the complete debug log; it
also detects WordPress database errors and incorrectly-called-function
diagnostics. Synthetic-secret checks run before any failing log is replayed,
and the runner does not discard or allowlist bootstrap diagnostics. It
additionally requires no upload-tree mutation.
This remains focused source-checkpoint evidence, not an exact-ZIP, release,
upgrade, MySQL, Plugin Check, real-PeerTube, TLS, administrator-action, adapter,
refresh/revoke, or upload gate.

To preserve its report outside the checkout, set an absolute directory:

```bash
AWVP_R35_REPORT_DIR=/absolute/report/path \
    bash tests/integration/peertube-persistence-smoke.sh
```

## PeerTube local connection coordinator checkpoint

After the R36 source checkpoint is committed, run the two-case coordinator
smoke from a clean checkout on the disposable Docker host:

```bash
bash tests/integration/peertube-connection-coordinator-smoke.sh
```

The runner retains the R35 committed-source, read-only plugin mount and pinned
WordPress 6.4.2/PHP 8.1/MariaDB 10.6.27 plus WordPress 7.1/PHP 8.3/MariaDB
10.11.18 matrix. Every case uses its own owned internal-only network, volume,
database, and WordPress container, with no published host ports.

Each coordinator boundary runs in a fresh WP-CLI container. The fixture proves
the ordered `prepared` -> `secret_reserve_planned` -> `secret_reserved` ->
`link_planned` -> `disabled` progression across separate PHP processes. It
requires planning and application to occur in different invocations and
compares authoritative raw option snapshots so each invocation changes at most
its one declared persistence target. A separate append adds an unrelated
disabled backend after the primary link is confirmed; the following read-only
resume must still report semantic `ready_for_grant`. A final fresh process
proves that the occupied primary identity and credential-bearing start intents
are refused without opening another journal record.

This checkpoint accepts no credentials, performs no grant, makes no WordPress
HTTP request, and changes no managed upload path. External HTTP is blocked,
ambient cron is disabled, and synthetic credential canaries are checked before
any failing debug log is replayed. It is source-checkpoint evidence only, not a
real-PeerTube, exact-ZIP, release, upgrade, MySQL, Plugin Check, TLS,
administrator-action, adapter, refresh/revoke, or upload gate.

To preserve its report outside the checkout, set an absolute directory:

```bash
AWVP_R36_REPORT_DIR=/absolute/report/path \
    bash tests/integration/peertube-connection-coordinator-smoke.sh
```

## PeerTube password-grant checkpoint

Run the exact clean R37 source checkpoint's two-case smoke on the disposable
Docker host:

```bash
bash tests/integration/peertube-password-grant-smoke.sh
```

The runner exports exact committed `HEAD` once and mounts that plugin tree
read-only in the same digest-pinned WordPress 6.4.2/PHP 8.1/MariaDB 10.6.27
and WordPress 7.1/PHP 8.3/MariaDB 10.11.18 cases used by the R36 checkpoint.
Digest-matching images already present in Docker's local cache are used without
a registry request; only a missing pinned image is pulled. Each case owns a
fresh internal-only network, WordPress volume, database, WordPress container,
and isolated PeerTube-shaped fixture. No host port is published.

Every local preparation boundary, credential-bearing attempt, and
credential-free reconciliation runs in a fresh WP-CLI process. The fixture
proves one successful OAuth-client GET and password-token POST, prospective
encrypted secret planning/application followed by fresh-process confirmation,
an OTP-required response plus an explicitly supplied retry, and a dropped
token connection that becomes terminal indeterminate state without another
HTTP request. Its redacted request log is compared with the exact expected
sequence. The mock runs behind Docker's init process and rejects readiness if
PHP is the container's PID 1, so its intentional self-termination proves a
real connection drop independently of host daemon PID-1 behavior.

The fixture invokes the service explicitly; the plugin registers no
administrator, AJAX, REST, CLI, cron, activation, or upload entry point for it.
It checks exact AWVP option deltas, non-autoload storage, plaintext credential
and token exclusion, authenticated secret round-trips, unchanged attachment
and managed-upload state, and a clean `WP_DEBUG` log. This remains focused
source-checkpoint evidence, not a real-PeerTube, TLS, exact-ZIP, release,
upgrade, MySQL, Plugin Check, administrator-action, identity/channel,
activation, adapter, refresh/revoke, or upload gate.

To preserve its report outside the checkout, set an absolute directory:

```bash
AWVP_R37_REPORT_DIR=/absolute/report/path \
    bash tests/integration/peertube-password-grant-smoke.sh
```

## PeerTube administrator authorization checkpoint

After the R38 source checkpoint is committed, run its two-case browser-boundary
smoke from a clean checkout on the disposable Docker host:

```bash
bash tests/integration/peertube-admin-authorization-smoke.sh
```

The runner retains R37's exact committed-source export, read-only plugin mount,
cache-first digest-pinned images, and WordPress 6.4.2/PHP 8.1/MariaDB 10.6.27
plus WordPress 7.1/PHP 8.3/MariaDB 10.11.18 matrix. Each case owns a fresh
internal-only network, WordPress volume, database, WordPress container, and
isolated PeerTube-shaped fixture. No host port is published. An exact image
already present in Docker's local cache causes no registry request; only a
missing pinned digest is pulled.

A disposable PHP client reaches WordPress only through the internal Docker
network and authenticates through the real `wp-login.php` boundary. It proves
the absence of an unauthenticated `admin_post_nopriv` action, POST-only method
enforcement, `manage_options` authorization before nonce handling, form- and
operation-bound nonces, exact field rejection, read-only page GETs, and fixed
local 303 post/redirect/get responses. It performs one explicit start, seven
separate one-boundary resume requests, one explicit credential attempt, and
one credential-free reconciliation. The client also requires the external
service disclosure, the separate plaintext-HTTP acknowledgement, and blank
credential inputs; invalid acknowledgements must make no PeerTube request.
Cookies, nonces, credentials, and response bodies remain in memory and are
never written to the report.

Before the first authenticated administrator request, the runner seeds complete
recent core, plugin, and theme update-check transients in the disposable site
and verifies their exact round trip. This lets WordPress's normal `admin_init`
update hooks use a deterministic cache baseline instead of attempting
WordPress.org requests on the internal-only network. External HTTP remains
blocked and the debug-log gate continues to reject every PHP or WordPress
diagnostic; the harness does not filter or allowlist update warnings.

The R37 success-only PeerTube mock is reused without changing its historical
fixture. Its redacted log must contain exactly one OAuth-client GET and one
password-token POST, proving that rejected browser submissions, page reads,
and reconciliation neither call PeerTube nor retry automatically. A fresh
WP-CLI process then verifies the exact disabled backend and journal state,
administrator attribution, encrypted token round-trip, non-autoload private
options, plaintext-canary exclusion, unchanged attachments and managed upload
storage, and a clean `WP_DEBUG` log.

This remains focused source-checkpoint evidence, not a real-PeerTube, TLS,
exact-ZIP, release, upgrade, MySQL, Plugin Check, identity/channel,
destination, activation, adapter, refresh/revoke, or upload gate.

To preserve its report outside the checkout, set an absolute directory:

```bash
AWVP_R38_REPORT_DIR=/absolute/report/path \
    bash tests/integration/peertube-admin-authorization-smoke.sh
```

## PeerTube identity/destination checkpoint

After the R39 source checkpoint is committed, run its two-case browser and
state matrix from a clean checkout on the disposable Docker host:

```bash
bash tests/integration/peertube-identity-destination-smoke.sh
```

The wrapper reuses the R38 clean-commit export, cache-first digest-pinned image
matrix, internal-only network, update-cache baseline, real WordPress login, and
unfiltered debug-log gates. The exact commit's fixture parent is mounted
read-only in the browser container so the R39 continuation can require its
declared R38 support fixture; both paths are checked before container startup
and again after commit export. It first proves the complete R38 bootstrap, then
journals verification intent without HTTP. A later explicit POST performs one
bearer `/users/me` read and two public channel pages containing exactly 101
strictly ordered owned channels. Ordinary page GETs remain local; only the
nonce-bound discovery GET repeats that read-only sequence.

Selection of channel `101` repeats current authority before journaling the
exact ID. A final explicit verification repeats it again and stops at
`activation_ready`. The redacted request log must therefore contain exactly one
OAuth-client GET, one password-token POST, and four identical one-identity/two-
page channel sequences. Bearer material is never logged and public channel
requests must carry no authorization header.

A fresh WP-CLI process proves the exact selected ID and bounded identity,
generation/time binding, unchanged disabled descriptor and empty
`default_destination`, absence of a persisted channel-list cache, encrypted
non-autoloaded token storage, no attachment or managed-upload mutation, and a
clean `WP_DEBUG` log. This remains development-checkpoint evidence, not a real-
PeerTube, TLS, exact-ZIP, release, upgrade, MySQL, Plugin Check, activation,
adapter, refresh/revoke, or upload gate.

To preserve its report outside the checkout, set the generic runner directory:

```bash
AWVP_ADMIN_REPORT_DIR=/absolute/report/path \
    bash tests/integration/peertube-identity-destination-smoke.sh
```

## PeerTube backend activation checkpoint

After the R40 source checkpoint is committed, run its two-case browser and
state matrix from a clean checkout on the disposable Docker host:

```bash
bash tests/integration/peertube-backend-activation-smoke.sh
```

The wrapper reuses the complete R39 browser/bootstrap fixture and its exact
redacted PeerTube request transcript. R40 then advances only local activation
state through four explicit nonce-bound administrator POSTs: journal the exact
registry mutation plan, apply the disabled-to-active shared-registry CAS, confirm
the already-active descriptor into `active_pending_close`, and independently
re-prove secret generation, descriptor/destination, adapter capability, and
non-blocking health before closing the journal at `complete`.

No R40 step owns a PeerTube HTTP client. The request-log gate must remain byte-
for-byte equivalent to R39: one OAuth-client GET, one password-token POST, and
four identical identity/channel-discovery sequences. Any additional remote
request fails the checkpoint. A fresh WP-CLI process then proves the active
PeerTube descriptor, selected destination, encrypted/non-autoloaded credential,
`delivery.embed` eligibility, continued denial of `processing.video`, the
refresh-pending health warning, unchanged attachments and managed upload
storage, and a clean `WP_DEBUG` log.

This remains development-checkpoint evidence, not a real-PeerTube, TLS,
exact-ZIP, release, upgrade, MySQL, Plugin Check, refresh/revoke, upload, remote
media mutation, or publication gate.

To preserve its report outside the checkout, set the generic runner directory:

```bash
AWVP_ADMIN_REPORT_DIR=/absolute/report/path \
    bash tests/integration/peertube-backend-activation-smoke.sh
```

## PeerTube token lifecycle checkpoint

After the R41 source checkpoint is committed, run its two-case real-WordPress
browser/state matrix from a clean checkout:

```bash
bash tests/integration/peertube-token-lifecycle-smoke.sh
```

The wrapper first reproduces the reviewed R38→R39→R40 connection and activation
sequence. R41 then authorizes only three additional PeerTube requests: one
OAuth-client GET, one exact refresh-token grant POST, and one empty-body bearer
revoke POST. The redacted request transcript must match exactly; token/client
values are never logged.

Refresh is driven through separate explicit administrator POSTs for lifecycle
initialization, the single claimed remote mutation, and independent persisted
result confirmation. Disconnect similarly separates initialization, one claimed
revoke, registry-retirement planning/application/confirmation, and exact secret
deletion. The final WP-CLI state gate requires the descriptor to be `retired`,
the lifecycle to be `disconnect_complete`, refreshed generation 2 to have been
the disconnect fence, the managed secret record to be absent, `delivery.embed`
to be ineligible, attachments/uploads unchanged, and no plaintext credential
canary in WordPress options or `WP_DEBUG`.

This is development-checkpoint evidence, not a real-PeerTube/TLS, exact-ZIP,
release, upgrade, MySQL, Plugin Check, media-upload, processing, publication, or
remote-media gate.

To preserve the report outside the checkout:

```bash
AWVP_ADMIN_REPORT_DIR=/absolute/report/path \
    bash tests/integration/peertube-token-lifecycle-smoke.sh
```

## PeerTube staged-upload execution checkpoint

After the R43 source checkpoint is committed, run the first executable upload
matrix from a clean feature checkout:

```bash
AWVP_ADMIN_REPORT_DIR=/absolute/report/path \
  bash tests/integration/peertube-staged-upload-smoke.sh
```

The wrapper runs the established two-case WordPress matrix (WordPress 6.4/PHP 8.1
with MariaDB 10.6, and WordPress 7.1/PHP 8.3 with MariaDB 10.11) on an internal
Docker network with no published host ports. It reproduces the reviewed connection,
identity/destination, and activation sequence before invoking the otherwise-unwired
R43 staged-upload service from a fresh WP-CLI process.

The isolated mock accepts only the reviewed resumable contract. R43 adds exactly:

```text
POST /api/v1/videos/upload-resumable auth=bearer privacy=private bytes=16 body=metadata
PUT /api/v1/videos/upload-resumable upload_id=r43fixture0001 auth=bearer range=bytes=0-15/16 bytes=16 body_sha256=9945ffa5f7037a4a5de4c330f9773720e5880ab4de9b6ed6a72ac19b47fc2867
```

The fixture never records the bearer token or source contents. The final WP-CLI
state gate requires upload phase `remote_created`, revision 5, confirmed length 16,
exact projected PeerTube id/UUID, the staged source still present and byte-identical,
no new `argent_video_remote_assets` row, encrypted credential persistence, staged
and processing capability bits still false, no automatic retry/probe, and no
`WP_DEBUG` diagnostics. The generic runner also requires exactly one init POST, one
byte-bearing PUT, and zero zero-byte probes for this happy-path case.

This is development-checkpoint evidence against an isolated PeerTube-shaped mock,
not a live-PeerTube/TLS, exact-ZIP, release, upgrade, Plugin Check, publication,
processing-completion, remote-asset-commit, cleanup, or retention gate.


## PeerTube remote-asset persistence/readiness checkpoint

After the R44 source checkpoint is committed, run:

```bash
AWVP_ADMIN_REPORT_DIR=/absolute/report/path \
  bash tests/integration/peertube-remote-asset-reconciliation-smoke.sh
```

The wrapper reproduces the R43 private staged upload, then invokes the otherwise
unwired R44 reconciliation service from WP-CLI. In addition to the exact R43
request transcript, the mock permits exactly two bearer-authenticated read-only
video observations of UUID `12345678-1234-4abc-9def-1234567890ab`: first
processing, then ready. The durable processing wait is exercised between those
GETs and must suppress an early read.

The final state gate requires exactly one secondary/private remote-asset row,
operation phase `ready_verified`, the exact channel/UUID/embed authority, source
bytes still present and identical, two video GETs, no automatic retry, no
plaintext credential/raw-response canaries, all staged-ingest/server-push/
processing capability bits false, and no gated `WP_DEBUG` diagnostics. This is a
development-checkpoint mock gate, not live-PeerTube/TLS, production upload wiring,
publication, source cleanup, retention, remote deletion, or release qualification.

## PeerTube one-shot task-worker CLI checkpoint

After the R45 one-shot WP-CLI execution boundary is committed, run its exact
clean-source matrix on the disposable Docker host:

```bash
bash tests/integration/peertube-task-cli-smoke.sh
```

The runner reuses the qualified administrator setup only to establish an exact
active PeerTube backend and managed encrypted credential. It then seeds one
local staged-upload operation and `peertube_upload_advance` task without remote
HTTP. Every `wp argent-video peertube-task-worker --once` call runs in a fresh
WP-CLI container/process. The sequence proves separate bounded init, byte PUT,
remote-asset commit, processing observation, durable wait, ready observation,
and terminal idle boundaries. An immediate invocation during the journaled
processing wait must be idle and must make no additional PeerTube request.

The exact committed tree is exported once and mounted read-only. Both supported
matrix edges are exercised: WordPress 6.4.2/PHP 8.1/MariaDB 10.6.27 and
WordPress 7.1/PHP 8.3/MariaDB 10.11.18. The isolated mock request transcript is
compared exactly, the staged source must remain present, task payload/error
storage must contain no managed-token canaries, the final remote asset must be
ready/private, and the PeerTube ingest/processing capability bits must remain
disabled. This is development-checkpoint evidence, not a release gate or a
real-PeerTube/TLS test.

To preserve its report outside the checkout:

```bash
AWVP_R45_REPORT_DIR=/absolute/report/path \
    bash tests/integration/peertube-task-cli-smoke.sh
```


## PeerTube one-shot task-worker indeterminate-chunk checkpoint

After the happy/wait R45 CLI matrix passes, run the uncertainty gate:

```bash
bash tests/integration/peertube-task-cli-indeterminate-smoke.sh
```

This matrix starts from the same exact active-backend and staged-source
prerequisites, establishes one resumable session in a fresh WP-CLI process, and
then arms the isolated mock to durably record the first byte-bearing PUT before
terminating its own HTTP process without a response. The worker must persist
`upload_indeterminate`, fail/hold the upload task after exactly two claims, and
leave the staged source intact. A later fresh `peertube-task-worker --once`
invocation must be idle while the mock remains offline, proving that the task
worker does not automatically issue a zero-byte offset probe, replay the chunk,
or create a replacement resumable session.

The exact request transcript requires one resumable-init POST, one byte-bearing
PUT with the fixture's transport-drop marker, zero zero-byte probes, zero remote
video GETs, and no replacement upload initialization. No remote-asset row or
reconciliation task may exist, and the PeerTube ingest/processing capability
bits remain disabled. This remains development-checkpoint evidence, not an
authorization for automatic offset reconciliation or a release gate.

To preserve its report outside the checkout:

```bash
AWVP_R45_REPORT_DIR=/absolute/report/path \
    bash tests/integration/peertube-task-cli-indeterminate-smoke.sh
```
