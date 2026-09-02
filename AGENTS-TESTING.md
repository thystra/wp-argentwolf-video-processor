# AGENTS-TESTING.md

## Purpose

This file defines the durable testing and release-validation contract for
ArgentWolf Video Processor (AWVP). It complements `AGENTS.md` and
`wordpress-development.md`.

Testing scripts, fixtures, reports, payload definitions, and this document are
repository-only material. They are not runtime plugin files and must remain
outside the WordPress.org plugin ZIP.

## 1. Release artifact authority

For a release candidate, the exact installable ZIP bytes are authoritative.

Before runtime validation:

- identify the exact candidate ZIP;
- record and verify its SHA-256;
- inspect the ZIP manifest and require one plugin root;
- verify the main plugin Version and `readme.txt` Stable Tag;
- preserve the candidate bytes unchanged once validation begins.

Do not silently rebuild a candidate after Plugin Check, clean-install, or
upgrade validation. A rebuilt ZIP is a new artifact and must be revalidated.

Repository-only documentation or tests may be changed without invalidating an
existing candidate only when all of the following are true:

- the release build contract excludes those paths;
- no distributed/runtime file changed;
- the exact candidate ZIP remains byte-for-byte unchanged;
- the release record preserves the candidate SHA-256 and the source/runtime
  relationship.

## 2. Durable release-validation architecture

Release validation is split into a reusable engine and release payloads:

```text
tests/release-validation/
  run.sh
  build-bundle.sh
  php/
    common.php
  payloads/
    <release-id>/
      payload.sh
      lib.php
      seed-*.php
      assert-*.php
```

`run.sh` owns reusable infrastructure:

- artifact/hash/version gates;
- Plugin Check acquisition/hash verification;
- pinned Docker image pulls;
- per-case Docker isolation;
- database readiness;
- WordPress/WP-CLI setup;
- clean/upgrade/plugin-check orchestration;
- payload phase execution;
- `WP_DEBUG` inspection;
- resource cleanup;
- reports and final summaries.

A release payload owns release-specific truth:

- candidate/base artifact filenames and SHA-256 values;
- candidate/base versions and schema versions;
- Plugin Check version/hash;
- compatibility matrix;
- ordered clean-install test phases;
- ordered pre-upgrade seed/assertion phases;
- ordered post-upgrade test phases;
- release-specific schema/data/settings assertions.

Do not fork or duplicate the runner for each release. Add or revise a payload
unless the shared orchestration contract itself must change.

Each payload must be complete enough that `run.sh <payload-id>` can execute the
canonical release gate from artifact verification through the declared test
matrix without ad-hoc shell commands inserted between cases.

`build-bundle.sh` creates a self-contained VM test bundle from the repository
engine/payload plus exact candidate/base ZIPs. The bundle is testing evidence,
not a replacement release artifact.

## 3. Test host

`ubuntuzfstest` is the preferred disposable AWVP WordPress release-validation
host.

Use containerized WordPress, WP-CLI, and database images rather than installing
a standing WordPress test site on the host.

Host PHP is not required for the VM runner.

Do not use production WordPress sites for Plugin Check or destructive release
matrix testing when the disposable VM is available.

## 4. Docker isolation contract

For runtime matrix cases:

- create a fresh Docker network, database container, WordPress volume, and
  WordPress site for every case;
- use an internal Docker network;
- publish no host ports;
- use short scoped network aliases such as `db` and `wp`;
- set `WP_DEBUG=true`;
- set `WP_DEBUG_LOG=true`;
- set `WP_DEBUG_DISPLAY=false`;
- set `WP_HTTP_BLOCK_EXTERNAL=true`;
- mount only the exact release artifacts and read-only test harness;
- remove case containers, networks, volumes, and work directories on success or
  failure.

Network access needed to pull pinned Docker images or retrieve a pinned Plugin
Check package occurs before isolated WordPress runtime cases begin. Record and
verify the retrieved Plugin Check version and SHA-256.

No test may contact PeerTube, production WordPress, or another production
service.

### Database readiness and consumer-path proof

Database readiness requires two distinct gates:

1. prove the database process is ready inside its own container;
2. prove the actual WP-CLI image on the case network can resolve the `db`
   alias, open TCP/3306, authenticate with the WordPress credentials, select
   the WordPress database, and execute `SELECT 1`.

Do not treat `docker exec <db> ...admin ping` alone as proof that the consumer
path is ready.

Durable regression lesson from the 0.3.2 release-validation r1 harness:

- failure signature: DB-local ping passed, then the first WP-CLI WordPress
  bootstrap failed with `Error establishing a database connection`;
- root cause: readiness was validated only from inside the database container,
  not from the separate WP-CLI container/network/credential path;
- prevention rule: use short per-network aliases and an end-to-end consumer
  probe before WordPress setup;
- regression guard: every case must print `DB_CONSUMER_PATH_READY=PASS` before
  `wp core install`.

## 5. WordPress/WP-CLI matrix

The 0.3.x/1.x release line begins with the established six-case compatibility
matrix:

1. WordPress 6.4.2 / PHP 8.1 / MariaDB 10.6.27 — upgrade;
2. WordPress 6.4.2 / PHP 8.1 / MariaDB 10.6.27 — clean;
3. WordPress 7.1 / PHP 8.3 / MariaDB 10.11.18 — upgrade;
4. WordPress 7.1 / PHP 8.3 / MariaDB 10.11.18 — clean;
5. WordPress 7.1 / PHP 8.3 / MySQL 8.0 — upgrade;
6. WordPress 7.1 / PHP 8.3 / MySQL 8.0 — clean.

The matrix itself belongs in the payload. A future release may add or update
fixtures after review, but must not silently remove the declared minimum-version
fixture or all coverage for either MariaDB or MySQL.

Pin Docker images by digest when a reviewed successful fixture is available.
Record resolved image IDs/digests in every run report.

### Repeated heavyweight CI toolchains

When routine CI repeatedly compiles the same substantial dependency or
toolchain, and that compilation is not itself the behavior under test, move the
reviewed build into a project-owned CI image rather than spending every runner
cycle rebuilding identical inputs. The image build remains a separate validated
supply-chain operation.

For AWVP, `build/ci/ffmpeg/` owns the routine CI image. Its FFmpeg layer must be
built through `build/install-ci-ffmpeg.sh`, retain official release-signature and
key-fingerprint verification, and preserve the decoder/encoder capabilities
required by security and integration tests. Publish versioned image tags once,
never overwrite them, and pin the ordinary CI workflows to the reviewed registry
digest after the first successful image qualification.

Recompile in ordinary CI only when there is an explicit reason, such as testing
the compiler/toolchain, source-build reproducibility, upstream provenance, or a
new image/FFmpeg definition. A mutable cache is not a substitute for a reviewed
immutable image.

Historical release payloads retain the exact WordPress versions and image
digests that formed their release evidence. Update the active/future payload;
do not rewrite an already-closed payload merely because the current-version
matrix advances.

## 6. Exact-package installation

Clean cases must install and activate the exact candidate ZIP with WP-CLI.

Upgrade cases must:

1. install and activate the exact public prior-version ZIP;
2. execute every declared pre-upgrade payload phase;
3. replace the plugin using the exact candidate ZIP;
4. start a fresh WP-CLI bootstrap so normal plugin upgrade hooks execute;
5. execute every declared post-upgrade payload phase.

Do not substitute a source-tree copy for either package when validating release
bytes.

## 7. Payload phase contract

Payload phase arrays are ordered and fail closed.

Typical payload variables:

```bash
UPGRADE_PRE_PHASES=(seed-upgrade.php)
UPGRADE_POST_PHASES=(
    assert-upgrade.php
    assert-diagnostics.php
    assert-repeat-repair.php
)
CLEAN_PHASES=(
    assert-clean.php
    assert-diagnostics.php
    assert-repeat-repair.php
)
```

Each phase must:

- live inside its payload directory;
- be mounted read-only into the WP-CLI container;
- be executed with `wp eval-file ... --use-include`;
- print a unique PASS token after all of its own assertions succeed;
- terminate nonzero on any failed invariant.

The runner must print the phase name before execution. There must be no hidden
manual test step between declared phases.

## 8. Upgrade invariants

### Preserve insert IDs before further database writes

`$wpdb->insert_id` is mutable connection state. After a successful insert,
copy it immediately into a local variable before calling `update_option()`,
performing another insert, or executing any other operation that can replace
the connection's last-insert ID.

Durable regression lesson from the 0.3.2 release-validation r3 payload:

- failure signature: a fresh queue sentinel reported an implausible high
  `job_id`, then the post-upgrade assertion claimed that row disappeared;
- root cause: `seed-upgrade.php` read `$wpdb->insert_id` only after an
  `update_option()` call had replaced it with an options-table insert ID;
- prevention rule: capture and validate the queue insert ID immediately after
  `$wpdb->insert()`;
- regression guard: the seeded job ID stored in the fixture must be the local
  value captured before any subsequent database write.

For the 0.3.1 -> 0.3.2 payload, require at minimum:

- `argent_video_jobs` survives unchanged;
- the seeded queue row survives;
- seeded attachment metadata survives;
- existing saved settings remain byte/logically equivalent;
- new settings are supplied by defaults without rewriting old saved settings;
- `argent_video_processor_db_version` advances from `1` to `2`;
- `argentwolf_video_processor_logs` is created;
- the plugin remains active;
- repeated schema repair is idempotent.

Compatibility identifiers such as `argent_video_jobs` are not renamed merely to
match newer naming conventions.

## 9. Worker-diagnostic invariants

The 0.3.2 payload exercises the database-backed worker diagnostic repository
against real WordPress/database APIs.

### Read changing temporary captures from the opened handle

PHP stat-family calls can return cached metadata when the same path is checked
and then modified during one PHP request. Worker captures have exactly that
lifecycle: WordPress creates an empty temporary file, the repository validates
it, output is written, and the repository reads it later in the same request.

Open the capture first and obtain its current size with `fstat()` on that
handle rather than trusting a path-level `filesize()` result. The release
fixture must prove a just-written unique marker is readable before completion
and again after database persistence.

Require:

- a temporary capture is created through WordPress temporary-file facilities;
- captured evidence is persisted into the diagnostics table;
- the temporary capture is removed only after persistence succeeds;
- stale/incomplete captures are reconciled without losing diagnostic evidence;
- successful runs and error/job-error runs use the correct retention buckets;
- configurable retention limits are enforced;
- completed records do not retain live temporary-file paths;
- cleanup leaves no test-created temporary captures behind.

Dependency-free unit tests remain necessary but do not replace this real
WordPress/database test.

## 10. Schema repair/idempotence

A payload may deliberately damage a disposable test schema to prove the normal
upgrade path repairs it.

For the 0.3.2 payload:

- create a durable diagnostics sentinel;
- remove the reviewed `completed_at` index;
- force the schema-version option stale;
- invoke the normal upgrade path;
- verify the index is repaired;
- verify the schema version returns to current;
- verify the sentinel and any upgrade queue sentinel remain;
- invoke the upgrade path again and verify no data loss.

Never run destructive repair fixtures on a production site.

## 11. Plugin Check

Plugin Check is a required release gate but not a substitute for manual review.

A payload must pin the Plugin Check version and expected SHA-256 used for the
canonical run.

The runner may retrieve the matching official WordPress.org package when the
local cache is empty, but it must reject a version/hash mismatch.

For a new WordPress.org submission/review correction:

- install the exact candidate ZIP;
- prove the installed plugin tree is byte-for-byte identical to the ZIP before
  Plugin Check runs;
- run static checks against the installed plugin slug;
- run runtime checks against that same installed plugin slug;
- use the payload-declared `--mode` values;
- enable runtime checks with Plugin Check's early `cli.php` `--require`
  mechanism;
- treat an unexpected nonzero result as a release-gate failure.

Do not assume that a local ZIP filename is a stable `wp plugin check` target.

Durable regression lesson from the 0.3.2 release-validation r2 harness:

- failure signature: the exact candidate installed and activated and Plugin
  Check 2.1.0 installed and activated, then
  `wp plugin check /path/candidate.zip` failed with `Invalid plugin slug`;
- root cause: the harness relied on ambiguous local-ZIP CLI behavior instead
  of the installed-plugin contract;
- prevention rule: canonical checks target the installed slug only after a
  package-to-installed-tree byte identity proof;
- regression guard: every candidate Plugin Check case must print
  `AWVP_RELEASE_INSTALLED_PACKAGE_IDENTITY_PASS` before the first
  `wp plugin check` command.

### Plugin Check findings must fail the gate

Ordinary Plugin Check output is reporting output and must not be assumed to
make a shell harness fail merely because it prints an `ERROR` or `WARNING`.

The runner must independently parse canonical tabular Plugin Check output and
fail when any result row contains type `ERROR` or `WARNING`, in addition to
failing on a nonzero command exit status. Static and runtime checks must use
the same payload-declared format.

The canonical payload must use a Plugin Check strict output format such as
`strict-table`, unless a reviewed payload explicitly defines another
machine-enforced findings policy. The selected format belongs in the payload
and must be reported at run start.

Durable regression lesson from the 0.3.2 release-validation r3 harness:

- failure signature: Plugin Check printed a source `ERROR` and `WARNING`, but
  the case continued to `CASE_RESULT=... PASS`;
- root cause: the harness used ordinary `--format=table` and treated command
  completion as equivalent to a clean Plugin Check result;
- prevention rule: use the payload-declared strict output format for every
  canonical static/runtime Plugin Check invocation;
- regression guard: a known Plugin Check finding must terminate the case
  before matrix execution continues.

Do not enable AI-based Plugin Check analysis for the canonical release gate.

## 12. WP-CLI eval-file lesson

When a harness PHP file defines or includes helper functions/classes and is
executed through WP-CLI, use:

`wp eval-file <file> --use-include`

This is a durable regression lesson from the prior AWVP DB-delta harness.
Do not remove `--use-include` merely because a simple eval fixture happens to
work without it.

## 13. Debug-log gate

Every disposable WordPress case runs with `WP_DEBUG` and `WP_DEBUG_LOG`
enabled.

At the end of each case, inspect `wp-content/debug.log`.

Fail the case on plugin-related:

- PHP warnings;
- PHP notices;
- PHP deprecations;
- PHP fatal errors;
- `doing_it_wrong` messages;
- WordPress database errors.

Preserve complete debug output in the run report when a failure is found.

## 14. Reports and failure behavior

Default VM reports live under:

`~/awvp-vm-test-reports/`

Each run records:

- payload ID;
- UTC run ID;
- host/kernel;
- Docker engine information;
- exact candidate/base package hashes;
- Plugin Check version/hash;
- Docker image identities;
- consumer-path DB readiness;
- WordPress/PHP/database versions;
- every payload phase;
- case PASS/FAIL;
- debug-log diagnostics;
- final matrix summary.

On failure:

- identify the exact failing case and phase;
- retain the report;
- clean disposable Docker resources;
- stop the canonical run;
- classify harness/validator defects separately from product defects.

## 15. Release sequence

For a WordPress.org candidate, the preferred sequence is:

1. source tests and CI;
2. build exact candidate ZIP;
3. inspect/hash exact candidate;
4. create/review the release-validation payload;
5. build the self-contained VM test bundle;
6. Plugin Check exact candidate;
7. clean-install matrix;
8. prior-release upgrade matrix;
9. focused real WordPress/database payload tests;
10. package/manual review;
11. tag/release only after all required gates pass;
12. promote already-validated bytes without rebuilding.

If a runtime/distributed source file changes after step 2, rebuild and restart
artifact validation.

## 16. Applicator and harness design

Testing infrastructure is part of the release-safety boundary.

Prefer stable contracts and observable outcomes over incidental text formatting.

For every harness defect, record:

- failure signature;
- root cause;
- prevention rule;
- regression guard.

A harness PASS is meaningful only when the harness itself has a reviewed,
repeatable contract.

## Durable 2.0 harness requirements

The successful 1.0 release closes the one-off WordPress 7.1 validator chain.
The 2.0 line should use the repository `tests/release-validation` framework from
the beginning rather than accumulating release-specific validator scripts as
the primary architecture.

For the next harness iteration:

* provision/authenticate newly supported WordPress core fixtures separately and
  consume a pinned/checksum-bound fixture during release validation;
* preserve exact prior public release -> exact candidate upgrade coverage;
* preserve installed-package byte-identity checks;
* pin Plugin Check package/version and parse ERROR/WARNING findings explicitly;
* retain static/new, runtime/new, and runtime/update checks where applicable;
* keep `WP_DEBUG` and isolated-network/no-host-port gates;
* preflight direct Docker access for the normal `ubuntuzfstest` account;
* print a ready-to-copy report retrieval command on both success and failure;
* remove the historical accidental inclusion of `AGENTS-TESTING.md` from the
  validation bundle and assert repository-only documentation is excluded;
* record source-framework commit and bundle/artifact SHA-256 in reports.

An environment/verifier failure after candidate identity gates have passed does
not by itself require rebuilding the candidate. Classify the failure first.
