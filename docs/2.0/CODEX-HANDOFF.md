# AWVP 2.0 Codex Handoff

This document records the repository state prepared for continuation of
ArgentWolf Video Processor 2.0 development.

## Active branch model

- `main` is the stable/public line. The stable 1.0 release remains identified by
  `v1.0.0`; later `main` commits are documentation/maintenance history unless a
  new release is deliberately prepared.
- `develop-2.0` is the next-major integration line.
- `feature/2.0-peertube-auth-identity` preserves the reviewed R34 source and
  validation history. New continuation work branches from `develop-2.0`.

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

## Recommended continuation

R34 is integrated and its bounded source/CI/WordPress 7.1 VM and post-merge
source gates are complete. Next:

1. define the durable pending/reconciliation protocol before registering a
   production password-grant action;
2. implement registry persistence only with the documented atomic/classified
   contract and real WordPress/database validation;
3. review refresh/revoke as separate partial-mutation slices;
4. preserve the no-upload boundary until tranche 2.0-4 state-machine work.

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
