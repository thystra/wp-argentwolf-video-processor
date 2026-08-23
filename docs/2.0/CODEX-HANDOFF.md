# AWVP 2.0 Codex Handoff

This document records the repository state prepared for continuation of
ArgentWolf Video Processor 2.0 development.

## Active branch model

- `main` is the stable/public line. The stable 1.0 release remains identified by
  `v1.0.0`; later `main` commits are documentation/maintenance history unless a
  new release is deliberately prepared.
- `develop-2.0` is the next-major integration line.
- `feature/2.0-peertube-connection-foundation` is the active implementation
  feature branch preserved for the next PeerTube tranche.

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
- observed runtime: WordPress 7.1 and PHP 8.3.33;
- exact committed-source export, network/volume ownership, internal network,
  no-host-port, database consumer path, runtime configuration, plugin
  activation, WordPress PeerTube assertions, and cleanup gates: `PASS`;
- isolated PeerTube requests: exactly one `GET /api/v1/config`;
- WordPress debug diagnostics: none;
- final result: `PEERTUBE_HTTP_SMOKE=PASS`.

Forgejo CI run 48 also passed at the tested source commit. Preserve and hash the
VM report before its `/tmp` location is cleaned. This smoke is focused
development-checkpoint evidence, not a 2.0 release-artifact, exact-ZIP,
minimum WordPress 6.4/PHP 8.1, MySQL, upgrade, Plugin Check, real-PeerTube, TLS,
authentication, channel, administrator-action, or upload gate.

## Recommended continuation

The R33 focused source/CI/VM gate is complete. Before authentication or other
runtime mutation:

1. preserve and hash the successful VM report outside the disposable `/tmp`
   location;
2. fetch Forgejo and verify exact feature/integration ref authority;
3. prospectively verify and merge reviewed R33 work into `develop-2.0` without
   rewriting the tested feature history;
4. run post-merge source and CI validation;
5. implement OAuth bootstrap/OTP/identity/channel work as separately reviewed
   prospective slices, preserving the no-upload tranche boundary.

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
