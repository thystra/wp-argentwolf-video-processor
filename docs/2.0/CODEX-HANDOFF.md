# AWVP 2.0 Codex Handoff

This document records the repository state prepared for continuation of
ArgentWolf Video Processor 2.0 development.

## Active branch model

- `main` is the stable/public line. The stable 1.0 release remains identified by
  `v1.0.0`; later `main` commits are documentation/maintenance history unless a
  new release is deliberately prepared.
- `develop-2.0` is the next-major integration line.
- `feature/2.0-peertube-connection-coordinator` preserves the reviewed R36
  source and validation history. New continuation work branches from
  `develop-2.0`.

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

## Recommended continuation

R36 is integrated and its source, local, CI, two-case WordPress VM, prospective,
and post-merge gates are complete. Next:

1. keep administrator actions and remote password-grant mutation in a separate
   reviewed slice with explicit indeterminate-outcome reconciliation;
2. review refresh/revoke separately and preserve the no-upload boundary until
   tranche 2.0-4 state-machine work.

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
