# AWVP 2.0 Codex Handoff

This document records the repository state prepared for continuation of
ArgentWolf Video Processor 2.0 development.

## Active branch model

- `main` is the stable/public line. The stable 1.0 release remains identified by
  `v1.0.0`; later `main` commits are documentation/maintenance history unless a
  new release is deliberately prepared.
- `develop-2.0` is the next-major integration line and contains the validated
  R39 identity/destination checkpoint plus the qualified prebuilt FFmpeg CI
  toolchain.
- `feature/2.0-peertube-identity-destination` preserves the reviewed R39 source
  and validation history. Earlier feature branches preserve their corresponding
  R33-R38 checkpoints.
- New continuation work branches from the documented `develop-2.0` closure after
  the R39 integration and CI-image qualification.

Do not develop unfinished 2.0 runtime changes directly on `main`.

## Stable 1.0 authority

- released source commit:
  `f656cdaba54fa63771187ca8b4fa6e19a20989f6`;
- annotated tag object:
  `e6987a8f272ed8aa05aed614823d7bf8959f4f15`;
- canonical/public Forgejo 1.0.0 ZIP SHA-256:
  `7bbafd11c4d1f2805cfe66bb448ddac656eecc8bb2d2d12adf23a7173225468e`;
- stable-main closure tip prepared for handoff:
  `ed3982586d78f10fbb46aaf938d4478eabd322d1`.

The 1.0 runtime/review fixes were already forward-ported into `develop-2.0` and
passed Forgejo CI run 43.

## 2.0 integration authority at handoff

The CI-43 integration tip before this handoff-document commit is:

`df50cca258d4ff29c5b54ce6266a48243b60ae59`

That line contains the existing 2.0 architecture, persistence skeleton, backend
registry/local adapter, PeerTube connection contract, and the deliberate stable
1.0 synchronization.

The runtime version is intentionally still `1.0.0`; synchronizing the foundation
did not itself create a 2.0 release.

## Preserved PeerTube connection-foundation checkpoint

Reviewed R32 work was committed without altering its reviewed tree:

- branch: `feature/2.0-peertube-connection-foundation`;
- checkpoint commit: `4432a91db2f4ec0174b2684b1b4e16f6ad3ec999`;
- checkpoint tree: `ca6b22885a9bed3563939c1afb07b000002f36c0`;
- checkpoint parent / original PeerTube contract:
  `76909b3afc652e8506c66f395ab475013f95f76f`.

The checkpoint adds:

- `Backend_Secret_Crypto`;
- `Backend_Secret_Store`;
- `Managed_Backend_Secret_Store`;
- `PeerTube_Api_Error`;
- `PeerTube_Origin`;
- bootstrap wiring and focused connection-foundation tests.

The R32 contract intentionally does **not** claim same-secret atomic CAS.
Same-secret mutation serialization was deferred to the connection service.
The checkpoint performs no live PeerTube HTTP/API action by itself and does not
change the schema/model version.

Before the checkpoint was committed for handoff it was revalidated with PHP
lint, the focused PeerTube connection-foundation test, backend-local and model
persistence tests, the dependency-free suite, restricted `open_basedir`
regression, smoke load, FFmpeg security tests, storage-boundary testing when
present, and FFmpeg integration when the binaries were available.

## R33 read-only HTTP/API checkpoint

Continuation on 2026-08-22 re-fetched Forgejo and verified that the handoff refs
had not moved. `origin/develop-2.0` was prospectively merged without rewriting
R32:

- merge commit: `cfe4493644b58a1af2b81fd4acf55199ecfb7cde`;
- first parent / preserved R32 checkpoint:
  `4432a91db2f4ec0174b2684b1b4e16f6ad3ec999`;
- second parent / integration authority:
  `c0045ac5491e334c0e5cd7fa6b093bb773715023`;
- merge tree: `32848d50c424fd8e316b753c069bc396f593d490`.

The next reviewed feature checkpoint is:

- commit: `14fae6956fefedc60bc5adc1129ff90c44215e3f`;
- tree: `e82d2b34e4d1d5d4c27ce099633f2f7248e96949`;
- subject: `Add bounded PeerTube instance detection`.

R33 adds only an origin-bound WordPress safe-HTTP GET for
`/api/v1/config` and bounded instance detection. It exposes no arbitrary URL,
follows no redirect, sends no body/credential/cookie, performs no retry or
remote mutation, persists no response, and has no administrator-facing action.
Authentication, token lifecycle, channel discovery, backend registration, and
uploads remain unimplemented.

The complete source lint/focused/regression/FFmpeg/vendor/JavaScript suite
passed at R33. Focused tests were added to Forgejo/GitHub CI and release-test
workflows.

A real-WordPress Docker development smoke is ready at
`tests/integration/peertube-http-smoke.sh`. It exports exact clean `HEAD`, uses
the digest-pinned official WordPress 7.1.0/PHP 8.3, WP-CLI, and MariaDB images,
creates an internal-only network with no host ports, and contacts only the
isolated mock PeerTube fixture. Run it on `ubuntuzfstest` with:

```bash
bash tests/integration/peertube-http-smoke.sh
```

The first VM attempt against the earlier WordPress 7.0.2 runner completed
database readiness, WordPress installation, and plugin activation, then stopped
before PeerTube detection because the disposable WP-CLI process did not receive
the debug and isolated-network configuration used by the WordPress container.
Cleanup passed. This is classified as a harness-configuration failure, not a
product failure.

The corrected runner passes the same configuration to both runtimes, verifies
it along with exact WordPress 7.1 and PHP 8.3 versions before plugin activation,
and verifies resource ownership before use and cleanup.

The corrected run completed successfully on `ubuntuzfstest` at
`2026-08-23T12:40:07Z`:

- tested source commit:
  `c9f0949a659984c8502adee6e4b66d98f77bb26f`;
- tested source tree:
  `638955f4fab53ee426e3840f19d3befc00724f07`;
- report:
  `/tmp/awvp-r33-report.AcCg6f/peertube-http-smoke-20260823T124007Z-35191.log`;
- report SHA-256:
  `2b452d03d7cc75b97d28889a18d14a47696815eee54b3b70bccb4ab765750c09`;
- observed runtime: WordPress 7.1 and PHP 8.3.33;
- exact committed-source export, network/volume ownership, internal network,
  no-host-port, database consumer path, runtime configuration, plugin
  activation, WordPress PeerTube assertions, and cleanup gates: `PASS`;
- isolated PeerTube requests: exactly one `GET /api/v1/config`;
- WordPress debug diagnostics: none;
- final result: `PEERTUBE_HTTP_SMOKE=PASS`.

Forgejo CI run 48 also passed at the tested source commit. The VM report digest
is recorded above; copy the raw report outside its disposable `/tmp` location
if it must remain available after VM cleanup. This smoke is focused
development-checkpoint evidence, not a 2.0 release-artifact, exact-ZIP, minimum
WordPress 6.4/PHP 8.1, MySQL, upgrade, Plugin Check, real-PeerTube, TLS,
authentication, channel, administrator-action, or upload gate.

## R33 integration closure

The documentation-only feature closure
`a591daa6c39ea5072315797a0fda4406ec241fc2` passed Forgejo CI run 49. R33 was
then integrated into `develop-2.0` without rewriting its tested history:

- merge commit before this handoff-closure commit:
  `9e9fbfa2d9d118c9cbd0ef929669e7ac7b3899ce`;
- first parent / prior `develop-2.0` authority:
  `c0045ac5491e334c0e5cd7fa6b093bb773715023`;
- second parent / validated feature closure:
  `a591daa6c39ea5072315797a0fda4406ec241fc2`;
- merge tree:
  `6f318b724cdef59b4dd0f74f6f808003a793e2e4`.

The prospective and committed merge trees exactly matched the feature tree,
and only this handoff document changed between the VM-tested `c9f0949` tree and
the merge tree. The complete post-merge PHP lint, focused, dependency-free,
storage, restricted `open_basedir`, smoke-load, FFmpeg security/integration,
vendor-fetch, JavaScript syntax, workflow-YAML, and diff checks passed.

## R34 authenticated API checkpoint

R34 began from the exact R33 integration authority without rewriting it:

- branch: `feature/2.0-peertube-auth-identity`;
- parent / `origin/develop-2.0` authority:
  `31692d841311b054214070f8b61358a07e15514b`;
- implementation checkpoint:
  `93bb5df54507c8a40a85d5fd5a97e29d610a7c7e`;
- implementation tree:
  `87bb1e0c3949cf8718116a9a54c064dd572b5f59`;
- subject: `Add bounded PeerTube auth identity primitives`.

The checkpoint adds explicit origin-bound primitives for the local OAuth-client
read, password/OTP token exchange, bearer-authenticated `/users/me`, and public
account-channel discovery. Token lifetimes, identity fields, PeerTube machine
names, pagination, channel owner IDs, and channel locality are bounded and
validated. `owned_channels()` derives authority from its own bearer identity
lookup and never accepts a caller-built identity. Public channel reads receive
no bearer token. Credentialed endpoint errors discard remote text and accept
only endpoint-specific reviewed machine codes.

The backend-registry addition is deliberately read-only: it can preflight a
prospective disabled PeerTube-v1 descriptor while retaining current-version
unknown/future state in memory, but exposes no PeerTube writer. A writer remains
deferred until byte-exact compare-and-swap, conditional rollback, classified
partial outcomes, option-cache/hook behavior, and real WordPress 6.4/7.1
database behavior are validated.

R34 registers no administrator action or production connection service,
persists no OAuth client/password/OTP/token/identity/channel response, performs
no refresh/revoke/upload/media action, and does not register a PeerTube adapter.
This boundary avoids combining the password grant's live remote-session
creation with incomplete local persistence/reconciliation semantics.

The complete PHP lint, focused model/backend/PeerTube tests, dependency-free,
storage, restricted `open_basedir`, smoke-load, FFmpeg security/integration,
vendor-fetch, JavaScript syntax, workflow-YAML, diff, and deterministic package
gates passed locally. Forgejo CI run 51 passed the exact implementation
checkpoint.

The separate authenticated-API Docker smoke then passed on `ubuntuzfstest` at
`2026-08-24T01:46:29Z`:

- tested source commit:
  `93bb5df54507c8a40a85d5fd5a97e29d610a7c7e`;
- tested source tree:
  `87bb1e0c3949cf8718116a9a54c064dd572b5f59`;
- report:
  `/tmp/awvp-r34-report.mSdUmZ/peertube-auth-api-smoke-20260824T014629Z-6724.log`;
- report SHA-256:
  `9ac95c11192253f0285712a56797fce57c4a29e263520e1a52ae4de58facb079`;
- observed runtime: WordPress 7.1 and PHP 8.3.33;
- exact committed-source export, image pins, owned internal network/volume,
  no-host-port, database consumer path, runtime configuration, plugin
  activation, and cleanup gates: `PASS`;
- local OAuth client, OTP-required header, password-plus-OTP token, current
  identity, 101 owned channels across two pages, exact request sequence, public
  no-bearer reads, no AWVP option persistence, and no managed-upload mutation:
  `PASS`;
- WordPress debug diagnostics: none;
- final result: `PEERTUBE_AUTH_API_SMOKE=PASS`.

This is a focused development-checkpoint gate, not a real-PeerTube, TLS,
release-ZIP, Plugin Check, minimum-version, MySQL, upgrade,
administrator-action, refresh/revoke, persistence, adapter, upload, or
publication gate. Preserve the raw report outside disposable `/tmp` storage if
it is needed beyond the VM's lifetime.

## R34 integration closure

The documentation-only validation record
`d65c51e1de0e648e799ed887baf82e71371125cf` passed Forgejo CI run 52. R34 was
then integrated into `develop-2.0` without rewriting either parent:

- merge commit before this handoff-closure commit:
  `86434431c8a63cb5fbc77dd4fda11bb6db42ebd1`;
- first parent / prior `develop-2.0` authority:
  `31692d841311b054214070f8b61358a07e15514b`;
- second parent / validated feature closure:
  `d65c51e1de0e648e799ed887baf82e71371125cf`;
- merge tree:
  `9ce44cd3b8cc89518c3592155b7fc1abe0393f49`.

The prospective and committed merge trees matched exactly. Only this handoff
document changed between the VM-tested implementation tree
`87bb1e0c3949cf8718116a9a54c064dd572b5f59` and the merge tree, so the
integrated runtime is byte-identical to the WordPress 7.1 VM-tested runtime. The
complete post-merge PHP lint, focused,
dependency-free, storage, restricted `open_basedir`, smoke-load, FFmpeg
security/integration, vendor-fetch, JavaScript syntax, workflow-YAML, shell
syntax, and diff gates passed.

## R35 durable connection-persistence checkpoint

R35 began from the exact R34 integration authority without rewriting it:

- branch: `feature/2.0-peertube-connection-persistence`;
- parent / `origin/develop-2.0` authority:
  `cf40c16a2becb66c3fd03d9d9b1955cd41d42035`;
- implementation checkpoint:
  `2549740888339d3be4004c49793d9879b459454d`;
- implementation tree:
  `b47508dd35bfa4abfbe52f2e6cd67448c9e2d465`;
- subject: `Add durable PeerTube connection persistence`.

The checkpoint adds an exact raw-option compare-and-swap boundary, classified
mutation results, atomic disabled-backend registry append, managed secret
manifest and generation-bound pending/ready records, a bounded connection
operation journal, and a pure connection state machine. It validates both the
WordPress 6.4 `autoload=no` and modern `autoload=off` representations, rejects
autoload repair and unsafe serialized/reference values, invalidates option
caches on conflicts and after definite or possible mutations, and preserves
unknown future registry state.

The state machine requires durable journal, pending-secret, and disabled-registry
evidence before a future password grant, then bound encrypted-secret evidence
before identity/destination verification and activation. It treats an
indeterminate grant as terminal and permits only explicit, mutation-ID-changing
replans after definite local conflicts. The journal never evicts unresolved
work and allows at most one unresolved operation per backend.

R35 deliberately registers no production connection service, administrator
action, AJAX/REST endpoint, PeerTube adapter, activation writer, password/OTP
collector, refresh/revoke path, upload path, or runtime PeerTube HTTP action.
The model schema and plugin version remain unchanged; `main` is untouched.

The complete PHP lint, focused model/backend/PeerTube/persistence tests in both
autoload modes, dependency-free, storage, restricted `open_basedir`, smoke-load,
FFmpeg security/integration, vendor-fetch, JavaScript syntax, workflow-YAML,
shell-syntax, diff, and deterministic package gates passed locally. Forgejo CI
run 54 passed the exact implementation checkpoint.

The first two-case Docker attempt used the exact clean implementation commit
and reached every WordPress persistence assertion in the WordPress 6.4 case,
including zero WordPress HTTP requests and zero upload mutations. Its final
debug gate then correctly failed on WordPress-core diagnostics caused by the
test environment: WP-CLI lacked a stable request host, while ambient cron/admin
bootstrap paths were permitted to invoke unrelated WordPress.org update checks
against intentionally blocked external HTTP. This is classified as a harness
failure, not a product failure. Cleanup passed.

- failed report:
  `/tmp/awvp-r35-report.CEhutr/peertube-persistence-smoke-20260824T041006Z-28773.log`;
- failed report SHA-256:
  `e2507a3d37853c64fa983e95dedd60de288f263c08b5edba4f36a2ad28a9c29b`;
- failed final result: `PEERTUBE_PERSISTENCE_SMOKE=FAIL`.

Two non-rewriting follow-up commits corrected and hardened only the R35 smoke
fixture, runner, and integration documentation:

- `a2cab741cad219d19ba80d554318c08cf1204642` establishes global WP-CLI URL
  context, disables ambient cron, and uses explicit CLI context for plugin
  activation;
- `a2cab741cad219d19ba80d554318c08cf1204642` tree:
  `373e9e9bb80e3d5af658f01ce9cf37e716f8407f`;
- `8dfca147feaf05abb4d12c81d289ff053b0b2f75` preserves complete debug
  diagnostics, detects WordPress database/incorrect-call diagnostics, and
  checks synthetic secrets before replaying a failing log;
- corrected tested tree:
  `b342ee4b579ebc2be1ee319a9e240f2f27d1cdc4`.

The correction does not clear, truncate, suppress, or allowlist diagnostics.
It changes no production plugin source or installable runtime content. The full
local non-Docker suite passed again, and Forgejo CI run 55 passed the exact
corrected commit.

The corrected matrix completed successfully on `ubuntuzfstest` at
`2026-08-24T10:39:52Z`:

- tested source commit:
  `8dfca147feaf05abb4d12c81d289ff053b0b2f75`;
- tested source tree:
  `b342ee4b579ebc2be1ee319a9e240f2f27d1cdc4`;
- report:
  `/tmp/awvp-r35-report.6Ma2an/peertube-persistence-smoke-20260824T103952Z-33509.log`;
- report SHA-256:
  `97aaabb7c703e7720ce780adacdd6d6ba524c9471a75981ab3963cc760d1673d`;
- minimum case: WordPress 6.4.2, PHP 8.1.34, MariaDB 10.6.27;
- current case: WordPress 7.1, PHP 8.3.33, MariaDB 10.11.18;
- exact committed-source export, read-only runtime mount, image pins, owned
  internal networks/volumes, no host ports, fresh sites, database consumer
  paths, runtime configuration, plugin activation, authoritative CAS, cache
  hooks, autoload refusal, disabled registry append, future-state preservation,
  journal CAS, pending reconciliation, encrypted-secret CAS, synthetic-secret
  exclusion, clean debug logs, and per-case cleanup: `PASS`;
- WordPress HTTP requests in each case: `0`;
- upload mutations in each case: `0`;
- final results: `PEERTUBE_PERSISTENCE_WORDPRESS_ASSERTIONS=PASS`,
  `PEERTUBE_PERSISTENCE_MATRIX_ASSERTIONS=PASS`,
  `RESOURCE_CLEANUP=PASS`, and `PEERTUBE_PERSISTENCE_SMOKE=PASS`.

This remains focused committed-source development evidence, not a release ZIP,
upgrade, MySQL, Plugin Check, real-PeerTube, TLS, administrator-action,
refresh/revoke, adapter, activation, upload, or publication gate. Preserve the
raw reports outside disposable `/tmp` storage if they are needed beyond the
VM's lifetime.

## R35 integration closure

The documentation-only feature evidence closure
`c751a0dbbd0dd0ad104fd1bb1d683ab328e45117` passed Forgejo CI run 56. Its only
change from the VM-tested `8dfca147feaf05abb4d12c81d289ff053b0b2f75`
tree is this handoff document. The installable runtime object set remains
identical to both the VM-tested correction and the implementation checkpoint.

R35 was then integrated into `develop-2.0` without rewriting either parent:

- merge commit before this handoff-closure commit:
  `c4b09374d0dd71d25136b7c07d48d319054e46dc`;
- first parent / prior `develop-2.0` authority:
  `cf40c16a2becb66c3fd03d9d9b1955cd41d42035`;
- second parent / validated feature closure:
  `c751a0dbbd0dd0ad104fd1bb1d683ab328e45117`;
- merge tree:
  `2d6d90ea860390c75bbe57686745a6998529732c`.

Immediately before integration, Forgejo refs were re-fetched and frozen;
`origin/main` remained `ed3982586d78f10fbb46aaf938d4478eabd322d1`.
The prior develop authority was the exact merge base and strict ancestor of the
feature closure. The conflict-free prospective tree, staged merge tree,
committed merge tree, and feature-closure tree matched exactly.

The complete PHP lint, focused model/backend/PeerTube/persistence tests in both
autoload modes, dependency-free, storage, restricted `open_basedir`, smoke-load,
FFmpeg security/integration, vendor-fetch, JavaScript syntax, workflow-YAML,
all integration shell-syntax, and diff gates passed on both the prospective and
committed merge trees. An exact prospective-tree validation package passed its
root/content/exclusion and `SHA256SUMS` checks with ZIP SHA-256
`4e59ce97885690ebb70431b57a5166a7fb7621368198b70da51926d19cc2835a`.
That newly built ZIP was noncanonical validation evidence, was not promoted or
published, and was removed after inspection. Forgejo CI run 57 passed the exact
merge commit.

Only this handoff document changes after the committed merge tree, so the
integrated installable runtime remains byte-for-byte the runtime exercised by
the successful WordPress 6.4/7.1 VM matrix. R35 is integrated only on
`develop-2.0`; `main`, tags, releases, and publication surfaces remain
untouched.

## R36 restart-safe connection-coordinator checkpoint

R36 began from the exact R35 integration authority without rewriting it:

- branch: `feature/2.0-peertube-connection-coordinator`;
- parent / `origin/develop-2.0` authority:
  `1c17e39bb7afb27cacc68f45057043bc53057f06`;
- implementation checkpoint:
  `eeb33df9c801a3d82887b089597f34a36f69ab5e`;
- implementation tree:
  `afe99376baf9e8e2be266d1dddf8fb729a9db2b3`;
- subject: `Add restart-safe PeerTube connection coordination`.

The checkpoint adds prospective, single-use atomic-option mutation plans;
classified plan results; authoritative option, operation-journal,
managed-secret, and backend-registry probes; and a restart-safe local PeerTube
connection coordinator. Raw prospective snapshots are held outside normal
object debug/export state, plans reject serialization and reconstruction, and
every apply path validates its bounded evidence before reaching a mutation.

`start()` creates only a bounded local journal entry. Each `resume()` call
crosses at most one local persistence boundary while reserving a managed secret,
recording its confirmation, appending an exact disabled PeerTube descriptor,
and confirming semantic readiness across fresh processes. Replanning is limited
to a definite SQL-phase conflict with no mutation and a fresh authoritative
read. Pre-action hook mutations, stale probes, anomalous post-write evidence,
manifest read failures, occupied identities, malformed journals, credential
input, and ambiguous writes retain explicit refused or indeterminate outcomes.

R36 registers no administrator action, AJAX/REST endpoint, password/OTP grant,
PeerTube HTTP request, adapter, activation writer, refresh/revoke path, upload
path, or publication action. It persists no credentials or remote identity and
does not change the model schema or plugin version. `main` remains untouched.

The complete PHP lint, focused planning/coordinator/persistence tests in both
autoload modes, dependency-free, storage, restricted `open_basedir`, smoke-load,
FFmpeg security/binary/integration, vendor-fetch, JavaScript syntax,
workflow-YAML, all integration shell-syntax, and diff gates passed locally.
Forgejo CI run 59 passed the exact implementation checkpoint.

The two-case coordinator matrix completed successfully on `ubuntuzfstest` at
`2026-08-25T09:22:22Z`:

- tested source commit:
  `eeb33df9c801a3d82887b089597f34a36f69ab5e`;
- tested source tree:
  `afe99376baf9e8e2be266d1dddf8fb729a9db2b3`;
- report:
  `/tmp/awvp-r36-report.5v3mQg/peertube-connection-coordinator-smoke-20260825T092222Z-15123.log`;
- report SHA-256:
  `5e35fd51891abebd7ab3c2efb6402a1beb29363734d7a232f8dec314136db42e`;
- minimum case: WordPress 6.4.2, PHP 8.1.34, MariaDB 10.6.27;
- current case: WordPress 7.1, PHP 8.3.33, MariaDB 10.11.18;
- exact committed-source export, read-only runtime mount, digest-pinned images,
  owned internal networks/volumes, no host ports, fresh sites, database consumer
  paths, runtime configuration, plugin activation, eleven fresh WP-CLI
  coordinator processes per case, restart sequencing, single-target persistence
  boundaries, semantic readiness after an unrelated registry append, occupied
  identity refusal, credential-input refusal, synthetic-secret exclusion, clean
  debug logs, and per-case cleanup: `PASS`;
- WordPress HTTP requests in each case: none;
- upload mutations in each case: none;
- final results: `PEERTUBE_CONNECTION_COORDINATOR_MATRIX_ASSERTIONS=PASS`,
  `RESOURCE_CLEANUP=PASS`, and
  `PEERTUBE_CONNECTION_COORDINATOR_SMOKE=PASS`.

This remains focused committed-source development evidence, not a release ZIP,
upgrade, MySQL, Plugin Check, real-PeerTube, TLS, administrator-action,
password-grant, refresh/revoke, adapter, activation, upload, or publication
gate. Preserve the raw report outside disposable `/tmp` storage if it is needed
beyond the VM's lifetime.

## R36 integration closure

The documentation-only feature evidence closure
`171917459b80c18da8dcf0928858fbed953cbc26` passed Forgejo CI run 60. Its only
change from the VM-tested `eeb33df9c801a3d82887b089597f34a36f69ab5e`
tree is this handoff document. The installable runtime object set remains
identical to the VM-tested implementation checkpoint.

R36 was then integrated into `develop-2.0` without rewriting either parent:

- merge commit before this handoff-closure commit:
  `86af405c27c72b042376eb52e0d4d6dd6c1d40c8`;
- first parent / prior `develop-2.0` authority:
  `1c17e39bb7afb27cacc68f45057043bc53057f06`;
- second parent / validated feature closure:
  `171917459b80c18da8dcf0928858fbed953cbc26`;
- merge tree:
  `1e59a6af4ecdbb009efd71b57c110bb5c1764b1b`.

Immediately before integration, Forgejo refs were re-fetched and frozen;
`origin/main` remained `ed3982586d78f10fbb46aaf938d4478eabd322d1`.
The prior develop authority was the exact merge base and strict ancestor of the
feature closure. The conflict-free prospective tree, staged merge tree,
committed merge tree, and feature-closure tree matched exactly.

The complete PHP lint, focused model/backend/PeerTube tests, autoload-sensitive
CAS/planning/registry/operation-store/managed-secret/coordinator tests in both
modes, dependency-free, storage, restricted `open_basedir`, smoke-load, FFmpeg
security/binary/integration, vendor-fetch, JavaScript syntax, workflow-YAML, all
integration shell-syntax, and diff gates passed on both the prospective and
committed merge trees. An exact
prospective-tree validation package passed its root/content/exclusion and
`SHA256SUMS` checks with ZIP SHA-256
`c24d858ee778aa3523812298af86dc71714dea1b3cbff3e3e0d8a763793d6b00`.
That newly built ZIP was noncanonical validation evidence, was not promoted or
published, and was removed after inspection. Forgejo CI run 61 passed the exact
merge commit.

Only this handoff document changes after the committed merge tree, so the
integrated installable runtime remains byte-for-byte the runtime exercised by
the successful WordPress 6.4/7.1 VM matrix. R36 is integrated only on
`develop-2.0`; `main`, tags, releases, and publication surfaces remain
untouched.

## R37 password-grant bootstrap

R37 began from the exact R36 integration authority without rewriting it:

- branch: `feature/2.0-peertube-grant-bootstrap`;
- parent / `origin/develop-2.0` authority:
  `a21274b1faa5c739f57f90c976cae7e30cb35fd5`;
- implementation checkpoint:
  `daa90ba86ec7042fd9c922ada67e9d849b846ea0`;
- implementation tree:
  `4c43cfe042dc1d90d645c275a99a0580c4ed0a48`;
- subject: `Add restart-safe PeerTube password grant bootstrap`.

The current slice adds a narrow `PeerTube_Password_Grant_Api` interface and an
explicit `PeerTube_Password_Grant_Service`. The service is loaded by the plugin
bootstrap but registers no administrator form/action, AJAX/REST endpoint,
WP-CLI command, cron callback, activation hook, adapter, or upload path. It can
run only when explicitly invoked by trusted server-side code; an ordinary
WordPress request does not initiate a PeerTube connection.

For one bounded username/password/optional six-digit OTP attempt, the service:

1. re-proves the exact operation, pending generation-zero secret reservation,
   and disabled PeerTube descriptor;
2. fetches the exact-origin local OAuth client before claiming an attempt, so a
   failed read does not consume or strand a grant;
3. takes a fresh non-regressing post-OAuth timestamp, then re-proves the journal
   and local prerequisites after that read;
4. generates a request-local 256-bit attempt capability, persists only its
   domain-separated 128-bit commitment, and authoritatively confirms that
   grant-attempt claim at the fresh time;
5. rechecks both local targets and the exact claimed journal record;
6. immediately before HTTP, capability-authenticates and confirms an exact
   `mark_grant_request`, refreshing it if local hooks consume more than half of
   the stale window and classifying bounded exhaustion as grant-not-sent;
7. after the mark's option hooks, read-only re-proves both prerequisites and
   the exact marked journal, then invokes at most one credential-bearing
   password-token POST;
8. validates a successful bounded token result, prepares an exact
   authenticated-encrypted generation-one `secret_commit`, journals only its
   bounded mutation evidence, and applies that same request-local plan while
   plaintext token authority still exists.

A prerequisite change after the claim but before the POST is capability-proved,
durably classified as grant-not-sent, and returns to credential waiting. Definite OTP,
credential, invalid-client, permission, and rate-limit token responses first
enter bounded `otp_result_pending` or `credential_result_pending` journal
phases. Those phases are not grant-eligible and remain non-retryable until a
second `confirm_grant_result` supplies the request-local capability whose
commitment owns the attempt. The capability is never persisted, returned, or
placed in option-hook values. Therefore an observer cannot promote a pending
result from an uncertain request, while an applied but temporarily unobservable
authentic confirmation remains retry-safe. Fresh observations immediately
before and after the POST prevent request latency from backdating the outcome.
The post-response time anchors token lifetime validation and the journal
`updated_at`; bounded `Retry-After` is measured from that response observation.
Once the POST is invoked, a transport/TLS/5xx/malformed-success or otherwise
uncertain outcome becomes terminal `grant_indeterminate`; no automatic retry
is allowed. The returned mutation classification is cumulative across the
call, with unknown mutation dominating applied mutation and applied mutation
dominating none.

Credential-free `reconcile()` performs no HTTP and accepts no password or OTP.
It may probe and confirm an already-applied encrypted secret, advancing the
journal to `secret_stored` / `ready_for_verification`. It never reconstructs or
reapplies token material from journal hashes. A still-running grant or planned
secret write is not classified as interrupted/lost until the 30-second safety
interval has elapsed, longer than the reviewed 15-second HTTP timeout. The
final durable grant-request mark immediately before the POST refreshes that
timer so request latency is measured from the last confirmed outbound boundary.

A stale planned write is terminalized only after reconciliation has
prospectively replaced either an absent target or the exact empty
generation-zero reservation with an exact persistent, non-secret `fenced`
marker and then confirmed that marker. The marker is not deletion: it prevents
both an older absent-to-pending reservation plan and an older pending-to-ready
secret plan from regaining authority through ABA recreation. A fresh probe
resolves a concurrent ready-write-versus-fence compare-and-swap: an exact
generation-one winner is confirmed, while only confirmed `fenced` permits
terminal `local_persistence_unknown`. The target is re-proved after the terminal
journal hook; a late exact ready winner recovers the journal to `secret_stored`,
and later terminal reconciliation repeats the same proof/fence protocol. Ready,
newer, foreign, or indeterminate state is neither overwritten nor treated as
fenced.

The managed-secret provider now has prospective prepare/apply/probe operations
for this exact pending-to-ready transition. Plans are request-local and
single-use; durable evidence contains no password, OTP, OAuth client, token,
raw response, or ciphertext. State-machine support distinguishes a confirmed
pre-POST grant-not-sent path from post-invocation uncertainty and permits
terminal local-persistence uncertainty after a planned secret write.

`README.md` and `readme.txt` are updated in the same source slice to disclose the
configured PeerTube contact, credential fields sent, transient OAuth-client
handling, authenticated-encrypted token retention, normal transport metadata,
no media/metadata/telemetry transfer, and the lack of an administrator-facing
or automatic action. This satisfies the source-disclosure prerequisite only;
it is not validation evidence.

R37 deliberately stops before `/users/me` identity verification, channel or
destination discovery, activation of the disabled descriptor, refresh/revoke,
full operation closure, upload/media mutation, model-schema changes, and plugin
or release version changes. `main`, tags, releases, and publication surfaces
remain outside this work.

The complete PHP lint, focused model/backend/PeerTube/grant tests,
autoload-sensitive CAS/planning/registry/operation-store/managed-secret/
coordinator/grant tests in both modes, dependency-free, storage, restricted
`open_basedir`, smoke-load, FFmpeg security/binary/integration, vendor-fetch,
JavaScript syntax, workflow-YAML, all integration shell-syntax, and diff gates
passed locally. An exact prospective implementation-tree validation package
passed its single-root, content, exclusion, and `SHA256SUMS` checks with ZIP
SHA-256 `0dfffb8742c7a28fe7c524f372410a92d54e5edcf89c34971f4a9d49868d3544`.
That ZIP was noncanonical, was not promoted or published, and was removed after
inspection. The first Forgejo run for the implementation checkpoint, run 63,
lost its `forgejo-pilot` runner when its runner-host VM crashed during the
FFmpeg build and is infrastructure-failure evidence, not a source-test result.
The workflow-only commit `01f391a057d5d2b82c9b958082f85b382a293333`
moved ordinary Forgejo CI to `forgejo-workstation`; run 64 passed that exact
commit. Its installable runtime object set is identical to `daa90ba`.

The first two-case Docker attempt used the exact clean `01f391a` commit and
completed every password-grant state, request-sequence, no-retry, persistence,
and cleanup assertion reached in the WordPress 6.4 case. Its final transport
fixture proof correctly failed because PHP was the container's PID 1:
`posix_kill()` reported success, but Linux PID-namespace init semantics
suppressed the self-generated signal and produced an empty response rather
than a real connection drop. The WordPress 7.1 case therefore did not run.
This is classified as a harness failure, not successful transport evidence.

- failed tested source commit:
  `01f391a057d5d2b82c9b958082f85b382a293333`;
- failed tested source tree:
  `a8ce8f4494bb301a3f7bcf66f2cafe90534c376b`;
- failed report:
  `/tmp/awvp-r37-report.2Ipu1J/peertube-password-grant-smoke-20260831T032725Z-4772.log`;
- failed report SHA-256:
  `c130f9fadc80f855da8074a35b49aa854948012ca3611945052e4125a38b3522`;
- failed final result: `PEERTUBE_PASSWORD_GRANT_SMOKE=FAIL`.

The non-rewriting correction
`b15e9881bf857c0658e11f1ca7e05a34093591f4` changes only the R37 fixture,
integration runner, and integration documentation. It puts Docker init ahead
of PHP, refuses readiness when PHP is PID 1, requires the exact persisted
zero-status transport classification, and proves bounded fixture termination.
Its tree is `c26a17e669711a1e6bc1d0669e5982fd592203d7`. It changes no production
plugin source or installable runtime content. The full local non-Docker suite
passed again, a pinned-image isolated replay proved a client-side transport
failure, durable redacted marker, init-backed signal exit, and complete
cleanup, and Forgejo CI run 65 passed the exact corrected commit. The earlier
package hash remains evidence for the inspected prospective archive only; no
claim is made that a later timestamped rebuild would have identical ZIP bytes.

The corrected matrix completed successfully on `ubuntuzfstest` at
`2026-08-31T10:09:24Z`:

- tested source commit:
  `b15e9881bf857c0658e11f1ca7e05a34093591f4`;
- tested source tree:
  `c26a17e669711a1e6bc1d0669e5982fd592203d7`;
- report:
  `/tmp/awvp-r37-report.6KpWXB/peertube-password-grant-smoke-20260831T100924Z-12274.log`;
- report SHA-256:
  `af4cef4442ed744f470cf81bf76510589ac4a76c907906a680b4857d7e76ba3c`;
- minimum case: WordPress 6.4.2, PHP 8.1.34, MariaDB 10.6.27;
- current case: WordPress 7.1, PHP 8.3.33, MariaDB 10.11.18;
- exact committed-source export, read-only runtime mount, locally cached
  digest-pinned images, owned internal networks/volumes, no host ports, fresh
  sites, database consumer paths, runtime configuration, plugin activation,
  35 restart-isolated WP-CLI processes per case, exact local OAuth and token
  request sequence, successful grant, OTP-required response and explicit retry,
  real transport drop, terminal no-retry reconciliation, authenticated-encrypted
  secret persistence, plaintext/public-output secret exclusion, no registered
  service callback, no upload mutations, clean debug logs, and per-case cleanup:
  `PASS`;
- PeerTube requests in each case: four local-OAuth GETs and four token POSTs,
  including exactly one success-scenario POST and no retry after the dropped
  transport request;
- final results: `PEERTUBE_PASSWORD_GRANT_MATRIX_ASSERTIONS=PASS`,
  `RESOURCE_CLEANUP=PASS`, and `PEERTUBE_PASSWORD_GRANT_SMOKE=PASS`.

The runner classified this as `DEVELOPMENT_CHECKPOINT_NOT_RELEASE_GATE`. It
remains focused committed-source development evidence, not a release ZIP,
upgrade, MySQL, Plugin Check, real-PeerTube, TLS, administrator-action,
identity/channel, activation, adapter, refresh/revoke, upload, or publication
gate. Preserve the raw reports outside disposable `/tmp` storage if they are
needed beyond the VM's lifetime.

## R37 integration closure

The documentation-only feature evidence closure
`228b17fa173185d1a51a2c49f008e1dcd9cf0774` passed Forgejo CI run 66. Its only
change from the VM-tested `b15e9881bf857c0658e11f1ca7e05a34093591f4`
tree is this handoff document. The installable runtime object set remains
identical to both the VM-tested correction and the implementation checkpoint.

R37 was then integrated into `develop-2.0` without rewriting either parent:

- merge commit before this handoff-closure commit:
  `c7706dea8cff152db8e49e40146b261c4cdef3db`;
- first parent / prior `develop-2.0` authority:
  `a21274b1faa5c739f57f90c976cae7e30cb35fd5`;
- second parent / validated feature closure:
  `228b17fa173185d1a51a2c49f008e1dcd9cf0774`;
- merge tree:
  `3ad4a0496ab49b0c968822ac2e3e15ce2f3dd304`.

Immediately before integration, Forgejo refs were re-fetched and frozen;
`origin/main` remained `ed3982586d78f10fbb46aaf938d4478eabd322d1`.
The prior develop authority was the exact merge base and strict ancestor of the
feature closure. The conflict-free prospective tree, staged merge tree,
committed merge tree, and feature-closure tree matched exactly. An independent
read-only audit found no conflict, unstaged path, private operator detail, or
integration-policy issue.

The complete PHP lint, focused model/backend/PeerTube/grant tests,
autoload-sensitive CAS/planning/registry/operation-store/managed-secret/
coordinator/grant tests in both modes, dependency-free, storage, restricted
`open_basedir`, smoke-load, FFmpeg security/binary/integration, vendor-fetch,
JavaScript syntax, workflow-YAML, all build/test shell-syntax, and diff gates
passed on both the prospective and committed merge trees. An exact
prospective-tree validation package containing 62 entries passed its
single-root, version/tag, tracked-runtime-content, hls.js 1.6.16,
repository-material exclusion, and `SHA256SUMS` checks with ZIP SHA-256
`f6ec9fc8ffd24b3c8771e7053bbcf34b7c672a2b88ee29d2c423b10cb8d5004b`.
That newly built ZIP was noncanonical development-validation evidence, was not
promoted or published, and was removed after inspection. Forgejo CI run 67
passed the exact merge commit in 297 seconds.

Only this handoff document changes after the committed merge tree, so the
integrated installable runtime remains byte-for-byte the runtime exercised by
the successful WordPress 6.4/7.1 VM matrix. No additional VM run is required
for this merge-and-documentation closure. R37 is integrated only on
`develop-2.0`; `main`, tags, releases, and publication surfaces remain
untouched.

## R38 administrator authorization checkpoint

R38 adds a separate **Settings > PeerTube Connection** page over only the
reviewed R36/R37 local preparation, password-grant, encrypted-token-persistence,
and credential-free reconciliation boundaries. The page slug is
`argentwolf-video-processor-peertube`. It requires `manage_options`, and its GET
render is mutation-free.

The checkpoint registers exactly four authenticated, POST-only `admin_post`
actions for start, resume, grant, and reconcile. It registers no `nopriv`, AJAX,
REST, WP-CLI, cron, activation, automatic retry, upload, or media hook. Each
handler proves capability and an action-specific or operation-bound nonce,
requires the exact expected scalar field set and domain, invokes at most one
state-changing coordinator/grant boundary, and returns through a fixed local
`303` with an allowlisted notice identifier.

The grant form discloses the exact configured external service, ordinary
transport metadata, transient WordPress HTTP-hook visibility, encrypted
non-autoloaded token storage, and the remaining incomplete connection work. It
requires explicit external-service authorization. An allowlisted
development-only HTTP origin requires a second explicit acknowledgement that
credentials and returned tokens lack TLS protection in transit. Accepted
username/password values are unslashed once and validated without text
transformation; OTP is empty or exactly six digits and those digits are required
in `awaiting_otp`. Password and OTP values are never repopulated or copied into
projections, redirects, notices, options, or logs.

Open-operation reads expose only validated bounded non-secret fields. Resume
advances at most one local preparation boundary, grant performs at most one
credential-bearing attempt, and reconcile takes no credentials and performs no
HTTP. An indeterminate operation exposes only credential-free reconciliation
that may confirm an exact late encrypted-token winner; indeterminate and
attempt-exhausted operations expose no further grant form, and no browser action
loops or resubmits automatically.

R38 stops before `/users/me`, channel/destination discovery, activation,
refresh, revoke, disconnect, operation closure, upload/media mutation,
model-schema/runtime-version changes, release preparation, or `main`.

The first R38 implementation checkpoint is exact commit
`71e6991e869187b85dec1e9abacaf06d06a6bdcb`, tree
`a566e4ebd5796b9e3a3fa8f78e98d28919108439`, with sole parent
`03419494b528e8335fff5f6cb10fbec6a99eec7f`. Its complete local PHP lint,
focused and regression suites, both autoload modes, storage/restricted-path/
smoke-load/FFmpeg/vendor/JavaScript gates, workflow YAML, integration shell
syntax, and diff checks passed.

Forgejo CI run 69 used that exact commit on `forgejo-workstation`. PHP lint,
all source tests, whitespace validation, and ZIP construction passed. The final
ZIP inspection alone exited 141: under `pipefail`, an early successful
`grep -q` manifest match closed its pipe while `unzip -Z1` was still writing the
now-larger archive. This is classified as a workflow-inspection defect, not a
source-test or package-build success. The corrected workflows materialize the
ZIP listing once and perform the same inclusion/exclusion assertions against
that complete temporary manifest. A temporary clean export of exact `71e6991`
then built from SHA-512-verified local npm cache content, passed the corrected
single-root/content/exclusion/`SHA256SUMS` inspection, and was removed. It is
noncanonical development evidence and no artifact hash is asserted here.

The workflow correction is exact commit
`54c03198fced4fa7f2b8eb1643c6d03a539b9189`, tree
`2fbf2ad802ad279275e672976efde86f31aa0a3c`, with sole parent
`71e6991e869187b85dec1e9abacaf06d06a6bdcb`.
It changes only the three workflows and this handoff; its installable runtime
object set remains the implementation checkpoint. Forgejo CI run 70 passed the
exact correction commit on `forgejo-workstation` in 219 seconds, including the
corrected ZIP inspection and artifact upload.

The first R38 VM attempt used exact clean commit
`54c03198fced4fa7f2b8eb1643c6d03a539b9189`, exact tree
`2fbf2ad802ad279275e672976efde86f31aa0a3c`, a commit export mounted read-only,
and all six pinned images from local Docker cache. The WordPress 6.4.2 / PHP
8.1.34 / MariaDB 10.6.27 case passed network/volume ownership, internal-network,
database-local and consumer-path readiness, fresh-site, runtime-configuration,
version, activation, no-host-port, and HTTP-ready gates. Its browser fixture
then stopped before the authenticated administrator action flow because it
required the administrator `/wp-admin/` login landing for the subscriber too.
Both exact local WordPress 6.4.2 and 7.1 cores deliberately redirect a successful
subscriber that lacks `edit_posts` to `/wp-admin/profile.php`. WordPress 7.1 did
not run. This is classified as a browser-harness failure, not successful R38 VM
evidence or a plugin-source failure.

- failed report:
  `peertube-admin-authorization-smoke-20260901T153342Z-4774.log`;
- failed report SHA-256:
  `1aff0f1d396b9b4ccca80b66d7eb12f89fc8a450403152fef3b70ec54b3725c7`;
- cleanup: `PASS`.

The login-fixture correction is exact commit
`90704b7653b5cdb6edc2b032e6a3544c8db6a810`, tree
`276a6214a87e85a8573b2e4d5889f384be8d83a0`, with sole parent
`54c03198fced4fa7f2b8eb1643c6d03a539b9189`. Forgejo CI run 71 passed that
exact commit on `forgejo-workstation` in 219 seconds.

The second R38 VM attempt used that exact clean commit and tree, a read-only
commit export, and all six pinned images from local Docker cache. The WordPress
6.4.2 case passed both role-specific login boundaries, the complete browser
flow and administrator HTTP boundary, post-request state assertions, the exact
one-GET/one-POST PeerTube request sequence, encrypted-secret persistence,
plaintext-canary exclusion, and no automatic retry or upload mutation. It then
failed the unfiltered debug-log gate because the fresh site had no recent core,
plugin, or theme update-check transient. Core's normal `admin_init` hooks tried
the three WordPress.org APIs on the intentionally internal-only network and
logged the expected blocked-connection warnings. WordPress 7.1 did not run.
This is classified as a deterministic browser-harness isolation failure, not a
plugin-source failure or successful R38 matrix evidence.

- failed report:
  `peertube-admin-authorization-smoke-20260901T155148Z-9071.log`;
- failed report SHA-256:
  `7db136616e319ad528f3c7b3a58daf0e313285d335426c0dfa3196877cd39b1a`;
- cleanup: `PASS`.

The update-baseline correction is exact commit
`cfed42b9c01a1c30e79172bec9a11213c61d2563`, tree
`5992735674c1dd0ec5a758907f7d4cff4a30f9fe`, with sole parent
`90704b7653b5cdb6edc2b032e6a3544c8db6a810`. It changes only repository test
infrastructure and documentation; the installable runtime object set remains
the implementation checkpoint. Forgejo CI run 72 passed that exact commit on
`forgejo-workstation` in 403 seconds. The slower runner spent 363 seconds in
the verified FFmpeg build; PHP lint, the complete test suite, ZIP construction
and inspection, and artifact upload all passed.

The replacement R38 VM matrix used that exact clean commit and tree, a
read-only commit export, and all six pinned images from local Docker cache. The
WordPress 6.4.2 / PHP 8.1.34 / MariaDB 10.6.27 and WordPress 7.1 / PHP 8.3.33 /
MariaDB 10.11.18 cases each passed infrastructure ownership/isolation,
fresh-site and exact-version gates, activation, the asserted update-cache
baseline, both role-specific login boundaries, the full browser/HTTP/state
flow, the exact one-OAuth-GET/one-token-POST request sequence, no automatic
retry, plaintext-canary exclusion, encrypted-secret persistence, no upload
mutation, no gated PHP/WordPress debug diagnostics, case assertions, and
per-case cleanup. The matrix and global cleanup passed with final
`PEERTUBE_ADMIN_AUTHORIZATION_SMOKE=PASS`.

- successful report:
  `peertube-admin-authorization-smoke-20260901T194705Z-14213.log`;
- successful report SHA-256:
  `efe82a25f264171173f3c1ffcb028316519a50cd928c4a400149ae4481082f8b`;
- cleanup: `PASS`.

This closes R38 source, local, exact-commit CI, and two-case Docker development
checkpoint validation. It is not a real-PeerTube, TLS, exact-ZIP, release,
upgrade, MySQL, Plugin Check, identity/channel, destination, activation,
refresh/revoke, or upload gate.

## R38 integration closure

The documentation-only feature evidence closure
`a48bc8654162e84f44047048138cedadcca33dca`, tree
`d49c0d394e1acddeb2c813013b72ec871c0f8984`, passed Forgejo CI run 73 in
219 seconds. Its only change from the VM-tested
`cfed42b9c01a1c30e79172bec9a11213c61d2563` checkpoint is this handoff
document, so the installable runtime object set remains identical to the
successful two-case Docker matrix.

R38 was then integrated into `develop-2.0` without rewriting either parent:

- merge commit before this handoff-closure commit:
  `57daf38b29817ecd9d98e5da16b00bd0bf530be4`;
- first parent / prior `develop-2.0` authority:
  `03419494b528e8335fff5f6cb10fbec6a99eec7f`;
- second parent / validated feature closure:
  `a48bc8654162e84f44047048138cedadcca33dca`;
- merge tree:
  `d49c0d394e1acddeb2c813013b72ec871c0f8984`.

Immediately before integration, Forgejo refs were re-fetched and frozen;
`origin/main` remained `ed3982586d78f10fbb46aaf938d4478eabd322d1`.
The prior develop authority was the exact merge base and strict ancestor of the
feature closure. The conflict-free disposable prospective merge, staged real
merge, committed merge, and feature-closure trees matched exactly.

The complete PHP lint, focused model/backend/PeerTube/administrator/grant tests,
autoload-sensitive CAS/planning/registry/operation-store/managed-secret/
coordinator/grant tests in both modes, dependency-free, storage, restricted
`open_basedir`, smoke-load, FFmpeg security/binary/integration, vendor-fetch,
JavaScript syntax, workflow-YAML, build/test shell-syntax, and diff gates passed
on the prospective merge tree. A temporary package built from the exact
SHA-512-matching hls.js 1.6.16 local npm-cache blob without a registry or curl
request passed its single-root, version/stable-tag, required hls.js/license,
repository-material exclusion, and `SHA256SUMS` checks. It was noncanonical
development evidence, was not promoted or published, had no artifact hash
recorded here, and was removed with its owned disposable worktree. Forgejo CI
run 74 passed the exact committed merge in 221 seconds.

Only this handoff document changes after the committed merge tree, so the
integrated installable runtime remains byte-for-byte the runtime exercised by
the successful WordPress 6.4/7.1 VM matrix. No additional VM run is required
for this merge-and-documentation closure. Establish the exact documentation-
closure commit from Git and require its final Forgejo CI before using it as the
next branch point. R38 is integrated only on `develop-2.0`; `main`, tags,
releases, and publication surfaces remain untouched.

## R39 authenticated identity and destination checkpoint

R39 adds the bounded identity/destination service and administrator flow
described in `docs/2.0/PEERTUBE-CONNECTION.md`. Verification and destination
selection are separate explicit requests. Each authenticated read uses only the
exact encrypted access-token generation bound to the open operation and
re-proves the operation, disabled descriptor, secret generation, and exact
journal state after WordPress hooks. Public owned-channel pages receive no
bearer token. Destination observations remain ephemeral; selection repeats the
current remote authority read, and a final explicit verification must prove the
selected channel before the operation reaches `activation_ready`.

The descriptor remains disabled and its `default_destination` remains empty.
R39 registers no automatic retry, activation, refresh, revoke, disconnect,
upload, media, cron, REST, AJAX, or WP-CLI mutation path. It does not change the
model schema, plugin version, release state, or `main`.

The implementation checkpoint is exact commit
`80011754beb78e05e95e33681be2b5f479ffd491`, tree
`0eeb6243d86e1475bcbce15f7bfb3d66f6fa3e80`, with sole parent
`8e2de3a9b67810ce93dadc6d7e837070fd794932`. Its complete local PHP lint,
focused and regression suites, both autoload modes, storage/restricted-path/
smoke-load/FFmpeg/vendor/JavaScript gates, workflow YAML, integration shell
syntax, and diff checks passed. A temporary noncanonical package containing 68
entries passed its single-root, runtime-content, repository-material exclusion,
and checksum checks with ZIP SHA-256
`bef00c1d9c5bea3312b519174b375387cf49f6592be05c1a028b3892d0426391`.
It was not promoted or published and was removed after inspection. Forgejo CI
run 76 passed the exact implementation commit in 219 seconds.

The first R39 VM attempt used that exact clean implementation commit and tree,
a read-only commit export, and all six pinned images from local Docker cache.
The WordPress 6.4.2 case passed infrastructure isolation, fresh installation,
exact runtime versions, activation, the update-check baseline, and HTTP
readiness. The browser fixture then failed before an R39 browser or API flow
because the wrapper required its sibling R38 support fixture while the runner
had mounted only the R39 fixture directory. WordPress 7.1 did not run. This is
classified as a deterministic harness fixture-mount failure, not a plugin
failure or successful R39 VM evidence.

- failed report: `peertube-r39-smoke-20260902T100559Z-24220.log`;
- failed report SHA-256:
  `9a2142919ebf7fb245cf45ff6cd093542be3d2359bcef569b92eec7ee729ce31`;
- cleanup: `PASS`.

The correction validates every selected fixture/support path against the exact
commit export, mounts that export's complete `tests/fixtures` directory
read-only, and invokes only the validated fixture basename. R39 declares its
R38 support fixture explicitly, while the R38 defaults continue through the
same generalized runner. This is the focused regression guard for the failure
class. The correction is exact commit
`b1ca597d0ca843257b9225f9daf59a330ca748d6`, tree
`16c0a7694b071f86fb8c57f8e4819046023ae9e3`, with sole parent the implementation
checkpoint. It changes only excluded repository test/documentation files, so
the installable runtime object set remains identical. Forgejo CI run 77 passed
that exact correction commit in 219 seconds.

The replacement R39 VM matrix used that exact clean correction commit and tree,
a read-only exact-commit export, and all six pinned images from local Docker
cache. The WordPress 6.4.2 / PHP 8.1.34 / MariaDB 10.6.27 and WordPress 7.1 /
PHP 8.3.33 / MariaDB 10.11.18 cases each passed infrastructure ownership and
isolation, no-host-port and database-consumer-path gates, fresh installation,
the exact runtime-version gates, activation, the update-check baseline, both
role-specific login boundaries, the R38 authorization plus R39 browser/state
flows, the exact isolated request sequence, encrypted-secret persistence,
plaintext-canary exclusion, no automatic retry or upload mutation, no gated
WordPress/PHP debug diagnostics, case assertions, and per-case cleanup. The
matrix and global cleanup passed with final
`PEERTUBE_IDENTITY_DESTINATION_SMOKE=PASS`.

- successful report: `peertube-r39-smoke-20260902T102529Z-27043.log`;
- successful report SHA-256:
  `fba950a267f50b63a2ac5258c812c75813405b22628122fd01eae76d41e0b82d`;
- cleanup: `PASS`.

This closes R39 source, local, exact-commit CI, and two-case Docker development
checkpoint validation. It is not a real-PeerTube, TLS, exact-ZIP, release,
upgrade, MySQL, Plugin Check, activation/adapter, refresh/revoke, or upload gate.

## R39 integration and prebuilt FFmpeg CI closure

The documentation-only R39 feature closure is exact commit
`6e99ac2ca48eb7f5d540f9d3c69bc7a589ecb0b6`. Forgejo CI run 79 passed that
feature closure in 3m39s. R39 was then integrated into `develop-2.0` without
rewriting either parent:

- merge commit: `d2a60ae5e4e63b12a5c4d360740fd88f2286279a`;
- first parent / prior `develop-2.0` authority:
  `8e2de3a9b67810ce93dadc6d7e837070fd794932`;
- second parent / validated R39 feature closure:
  `6e99ac2ca48eb7f5d540f9d3c69bc7a589ecb0b6`.

Forgejo CI run 80 passed the exact R39 merge in 3m39s. Product runtime behavior
remained the validated R39 tree; `main`, tags, releases, and publication surfaces
were untouched.

Routine CI was then moved from compiling FFmpeg on every runner execution to a
repository-owned image that still builds FFmpeg 9.0.1 from the signed upstream
source and verifies the required codec/security capabilities. The first
image-backed run exposed `python3` as an undeclared runner dependency in
`tests/vendor-fetch.sh`; this was an image/harness dependency defect rather than
an AWVP product failure. The image definition was corrected and published under
a new immutable `v2` tag rather than overwriting `v1`.

The qualified image authority is:

- immutable tag:
  `forgejo.argentwolf.org/alan/wp-argentwolf-video-processor/ci-ffmpeg:9.0.1-bookworm-v2`;
- OCI index digest:
  `sha256:bd97a501289e54169996c6ab6860719b09e09b99994d15bd809ecd6c2dfca74b`;
- Linux/amd64 manifest digest:
  `sha256:b57467f7d93cbaa5b3ba0ce328183379a15623a5cbfb6a6854c1997c022a2d47`.

Forgejo CI run 82 passed the complete image-backed validation in 13 seconds,
including the dependency-free suite, storage/restricted-path/smoke gates, FFmpeg
9.0.1 advisory binary check, real FFmpeg integration with adaptive HLS, vendor
fetch regression, and the remaining workflow checks. Routine Forgejo/GitHub CI
and the canonical release workflow are therefore pinned to the OCI index digest
rather than the versioned tag. Rebuilding FFmpeg remains a separate image
qualification operation rather than ordinary source-test work.

This documented closure is the branch authority for R40. R40 may activate the
verified PeerTube descriptor and make the adapter/factory eligible, but must
preserve the existing no-media-upload boundary.

## R40 backend activation feature slice — qualified and integrated

R40 is authorized from `develop-2.0` closure `090b85b` and is staged on
`feature/2.0-peertube-backend-activation`. The source slice adds an exact
disabled-to-active shared-registry CAS, a restart-safe activation service using
the already-defined activation journal phases, a conservative PeerTube adapter
registered in the backend factory, descriptor-aware health, and a seventh
authenticated administrator activation action.

The slice preserves the no-media boundary. The PeerTube adapter claims only
`delivery.embed`; ingest, processing, managed-library, publication, retention,
and remote-delete capabilities are false. Activation itself owns no PeerTube HTTP
client. It plans/applies/confirms local registry state and closes only after
re-proving the exact secret generation, destination, adapter/capability, and
non-blocking health. Refresh/revoke/disconnect remain outside R40.

Focused source tests cover exact shared-registry preservation/CAS conflict,
restart-safe activation boundaries, adapter/health eligibility, administrator
POST validation, and smoke-load registration in both supported autoload modes.
The R40 real-WordPress continuation reuses the R39 remote request transcript and
then performs four explicit local activation continuations; any additional
PeerTube request fails the request-log gate.

The initial implementation checkpoint is exact commit
`ce8d370b0aefb5598e8fd0843defa0629bc9ae3b`, tree
`b82bcbdcdb7ef39cbc0624eabc805221cafa7cc0`. Forgejo CI run 84 passed that
exact source in 9 seconds. The first isolated Docker matrix used that same clean
commit and read-only exact-commit export. It reached the R39 identity/destination
browser boundary successfully, then the R40 browser fixture rejected the new
`activation_advanced` / `backend_activated` redirect notices because the shared
reviewed-notice allowlist still reflected the R38/R39 boundary. Product activation
code was not the failing assertion. Cleanup passed.

- first failed report: `peertube-r40-smoke-20260902T160226Z-1200093.log`;
- first failed report SHA-256:
  `817ac615a15e417283cf5afc12cf6bec2fe4e313c69404536b429e088ead4b90`;
- first failed final result: `PEERTUBE_BACKEND_ACTIVATION_SMOKE=FAIL`.

The fixture-only correction is exact commit
`5ebffdc9f2b8c5aa21103dbea2292fe0f24a12df`, tree
`aefcaea63089b39fb01794ddff8ae3cd34b6c42e`. Forgejo CI run 85 passed that
exact correction in 13 seconds. The second Docker run progressed through the new
redirect-notice checks and completed activation, then found a second fixture-only
expectation defect: a subsequent plain settings-page GET incorrectly expected a
transient redirect notice that it had not requested. The durable page already
contained the persistent no-media-work warning. Cleanup passed.

- second failed report: `peertube-r40-smoke-20260902T161017Z-1205313.log`;
- second failed report SHA-256:
  `7091a032104c0576e60ca56b2bd8cf3cf4fbb42753871e69310f6ec93cc5947c`;
- second failed final result: `PEERTUBE_BACKEND_ACTIVATION_SMOKE=FAIL`.

The final fixture correction is exact commit
`1fcb8e45fd9b1aaeb4fe2aad1e31928327cc0d69`, tree
`d8c2dfa324e6bf2365740a39ae13ff4ab2edf2cb`. Forgejo CI run 86 passed that
exact feature state in 24 seconds. The replacement isolated Docker matrix used a
clean exact-commit export mounted read-only and passed both supported cases:
WordPress 6.4.2 / PHP 8.1.34 / MariaDB 10.6.27 and WordPress 7.1 / PHP 8.3.33 /
MariaDB 10.11.18. Both cases passed administrator/subscriber boundaries, the R38
authorization flow, R39 identity/destination flow, R40 HTTP/browser activation,
durable activation-state assertions, exact isolated request-sequence checks,
encrypted-secret persistence, plaintext-canary exclusion, no automatic remote
retry, no upload mutations, no gated `WP_DEBUG` diagnostics, and cleanup. The
matrix and global cleanup passed with final
`PEERTUBE_BACKEND_ACTIVATION_SMOKE=PASS`.

- successful report: `peertube-r40-smoke-20260902T161711Z-1209854.log`;
- successful report SHA-256:
  `dfe5dc7928d7e946e31e531ac3a4c94798c7da62d3f6ca505dce4230b3e6b605`;
- cleanup: `PASS`.

This closes R40 source, local, exact-commit CI, and two-case Docker development
checkpoint qualification. It is not a real-PeerTube, TLS, exact-ZIP, release,
upgrade, MySQL, Plugin Check, refresh/revoke/disconnect, upload, processing, or
remote-media-mutation gate.

### R40 `develop-2.0` integration closure

The documentation-only qualified R40 feature closure is exact commit
`e1819ecb83377bf97d03cd331fc31c6400ea1b41`. It was merged into `develop-2.0`
without rewriting either parent:

- merge commit: `67bb455f59450bab66cca1d59389e8fb637755ba`;
- merge tree: `8955bca16050546731080fa8e0420c779493d519`;
- first parent / prior `develop-2.0` authority:
  `090b85be4f48513efdc7b2582601c40cad9ffbd3`;
- second parent / qualified R40 feature closure:
  `e1819ecb83377bf97d03cd331fc31c6400ea1b41`.

Forgejo CI run 88 passed the exact R40 integration merge in 9 seconds. The merge
preserved the qualified R40 content tree and no product behavior was rewritten
at integration. `main`, tags, releases, and publication surfaces remain
untouched.

This `67bb455f59450bab66cca1d59389e8fb637755ba` integration is the branch
authority for R41. R41 may add bounded token refresh, revoke, and disconnect
lifecycle behavior, but must preserve the no-media-upload boundary until the
separate tranche 2.0-4 transfer/upload state-machine work is authorized.

## Recommended continuation

1. branch R41 from exact `develop-2.0` authority
   `67bb455f59450bab66cca1d59389e8fb637755ba`;
2. review and implement refresh/revoke/disconnect as a separately bounded
   lifecycle tranche with restart-safe and concurrency-safe tests;
3. preserve the no-upload boundary until tranche 2.0-4 state-machine work.

## Engineering policy

Read these before modifying behavior or release tooling:

- `AGENTS.md`
- `AGENTS-TESTING.md`
- `wordpress-development.md`
- `docs/2.0/ARCHITECTURE.md`
- `docs/2.0/PEERTUBE-CONNECTION.md`

Cross-project release/validator/shared-host lessons are maintained in the
`wp-plugin-template` repository. Project-specific rules in this repository may
be stricter.

Release artifacts are build-once authorities. Do not silently rebuild or
replace a validated/public artifact because a test host, verifier, credential,
or publication surface failed.

## Local checkout warning

Historical local branch pointers may lag Forgejo. Always `git fetch origin
--prune` and establish exact remote authority before mutation. A pre-cleanup
all-ref bundle and exact R32 staged patch were created before this handoff.

## R41 token lifecycle feature slice — implementation pending external qualification

R41 branches from the documented post-R40 `develop-2.0` authority and adds only
the bounded PeerTube credential lifecycle. The source slice introduces a
non-secret per-backend lifecycle journal, exact-generation encrypted token
refresh, bounded refresh-token and revoke API calls, exact shared-registry
active-to-retired CAS, managed-secret deletion after confirmed retirement,
health states for operational/refresh-required/reauthentication-required
credentials, and two additional authenticated administrator actions.

Remote mutation authority is fail-closed. A refresh POST occurs only after a
durable `refresh_in_flight` claim and is never replayed when an old generation
is later observed under that claim. A revoke POST occurs only after a durable
`disconnect_revoke_in_flight` claim and is never replayed after an uncertain
outcome. Rate limiting during the read-only OAuth-client preflight is durably
bounded without claiming the token mutation. Disconnect separates remote revoke,
registry retirement, retirement confirmation, and exact-generation secret
removal across explicit requests.

The PeerTube adapter still claims only `delivery.embed`; R41 adds no upload,
processing, publication, managed-library, retention, remote-delete, cron,
background refresh, AJAX, REST, or WP-CLI mutation path. The real-WordPress R41
continuation must preserve the complete R40 transcript and add only one OAuth
client GET, one refresh-token POST, and one revoke POST, then prove the descriptor
retired and the managed secret removed on both supported WordPress/PHP/MariaDB
cases.

This section records implemented/local source scope only. Do not mark R41
qualified or integrated until an exact feature commit passes Forgejo CI and the
clean exact-commit WordPress 6.4/7.1 Docker lifecycle matrix. Record those exact
commit/tree/report/checksum values in a later documentation-only closure.
