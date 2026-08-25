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
