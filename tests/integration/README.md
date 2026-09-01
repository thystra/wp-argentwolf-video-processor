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
