# AWVP release validation

This directory is the reusable AWVP release-validation framework.

## Run from a self-contained bundle

```bash
bash tests/release-validation/run.sh 0.3.2
```

Reports default to `<AWVP project parent>/release-evidence/awvp/<payload-id>/`, never directly under the operator home directory.

The bundle root must contain `artifacts/` with the exact filenames and hashes
declared by the payload. `build-bundle.sh` pins the already-verified canonical RC
candidate identity into the bundled payload, so the disposable VM does not need
the repository-only `AWVP_RC_CANDIDATE_SHA256` environment variable.

## Run from a repository checkout

Set `ARTIFACT_DIR` when exact release ZIPs are stored elsewhere:

```bash
ARTIFACT_DIR=/path/to/release-zips \
bash tests/release-validation/run.sh 0.3.2
```

## Build a VM bundle

```bash
bash tests/release-validation/build-bundle.sh \
    0.3.2 \
    /path/to/argentwolf-video-processor-0.3.2.zip \
    /path/to/argentwolf-video-processor-0.3.1.zip \
    /path/to/output
```

Optionally set `PLUGIN_CHECK_SOURCE=/path/to/plugin-check.<version>.zip` to
embed the exact payload-pinned Plugin Check package.

## Add a future release

Do not copy `run.sh`.

Create `payloads/<release-id>/payload.sh` and the PHP phases required by that
release. Declare the complete ordered clean and upgrade phase arrays in the
payload. Reuse prior assertion files only after reviewing that their contracts
still apply.

The canonical runner owns Docker/network/WordPress orchestration and must not
contain release-number-specific assertions.

## Exact package identity

The runner installs the candidate/base ZIP with WP-CLI and then compares every
installed plugin file byte-for-byte with the corresponding ZIP entry before
release assertions or Plugin Check execute.

Plugin Check is invoked against the installed plugin slug after that identity
proof. The canonical runner does not rely on a local ZIP filename being
accepted as a Plugin Check target.

## Plugin Check gating

The payload declares the canonical Plugin Check output format. Release payloads
normally use a strict format so reported findings fail the shell gate rather
than merely appearing in otherwise-successful command output.

Upgrade seed phases must copy `$wpdb->insert_id` immediately after the insert
whose identity they intend to preserve.

## Findings and capture-read gates

The runner independently fails on tabular Plugin Check `ERROR` or `WARNING`
rows; command exit status is not the sole findings gate.

Worker-diagnostic phases prove newly written capture evidence can be read
immediately before completion and then survives database persistence.

## 2.0 RC validation

The active `2.0.0-rc13.8` payload upgrades from the exact public `1.0.0` package and
requires the exact candidate SHA-256 at invocation time until the canonical
Forgejo RC artifact is selected. RC13.7 carries forward the RC13.3 clean/upgrade
contract while exercising the RC9 live-test fixes, model DB schema version 3 /
`argent_video_events` + `argent_video_publication_health`, explicit legacy 1.x
migration adoption, visitor-facing public-URL serving qualification, and runtime
serving cutover/failover foundations. Earlier RC packages remain immutable qualification or
live-test evidence; creating RC13.8 does not rewrite their payloads, including the frozen RC13.3, RC13.2, and RC10 payloads.

```bash
AWVP_RC_CANDIDATE_SHA256=<sha256-of-exact-candidate-zip> \
ARTIFACT_DIR=/path/to/release-zips \
bash tests/release-validation/run.sh 2.0.0-rc13.8
```

The upgrade fixture creates a real WordPress `core/video` block while 1.0 is
active, backed by a real uploads-tree attachment and an existing AWVP-managed
local derivative. The RC13.8 upgrade must preserve the block's stored
`post_content`, attachment relationship and legacy processing metadata,
source/derivative bytes, and completed legacy queue row. Merely upgrading must
not mass-convert the Core Video block, create an AWVP Video object for it,
enqueue PeerTube work, or create a remote asset. Read-only legacy discovery must
then expose that exact fixture as eligible. A separate explicit planning phase
adopts only the selected attachment, proves that adoption/planning creates no
FFmpeg or PeerTube task, and finally installs a simulated positively verified
remote publication so the unchanged historical Core Video block must render the
verified short-ID PeerTube iframe at runtime without altering stored content or
the WordPress original source.

RC13.8 carries forward the RC13.7 packaged operator-facing routing/history surfaces, zero-day manual-only retention default, source-prune/keep-HLS mode, cleanup-now with legacy queued-task compatibility, distinct operator-verified serving authority, explicit Check now / Use verified remote now / Rebuild local delivery recovery actions, registered durable recovery metadata, and separate Private/Unlisted pre-publication visibility. These assertions are runtime/package contracts only; the release harness does not contact PeerTube or production WordPress. RC13.8 also requires the verified-remote refresher interfaces/classes used by live operator adoption; live provider reconciliation itself remains a production acceptance test because the disposable harness intentionally performs no PeerTube action. RC13.8 additionally requires source-retirement tombstone metadata, direct attachment-reference discovery, completed-cleanup attachment reconciliation, and WordPress attachment-lifecycle retirement while preserving remote-only AWVP Video rendering. The disposable harness performs no destructive production cleanup; live acceptance uses an already-cleaned RC13.7 fixture.

The shared runner now collects independent Plugin Check modes and disposable case assertion failures through the end of the viable matrix, then exits nonzero with an aggregate summary. Global artifact/harness identity failures still stop immediately, and a case-local setup failure aborts only that disposable case.

The shared runner also supports optional payload `UNINSTALL_PHASES`. For clean and upgrade cases these execute only after normal final-state and WP_DEBUG checks, so destructive-uninstall validation cannot mask or replace the ordinary release assertions. RC13.5 uses that phase to seed the plugin-owned persistence surface, execute the installed package's real `uninstall.php`, and prove all owned database/state is removed while ordinary attachments and local media files remain.


The candidate-only phase separately creates and renders the 2.0 dynamic
`argentwolf-video-processor/video` block. This proves the new block is packaged,
registered and locally renderable without conflating it with the legacy 1.0
compatibility fixture.
