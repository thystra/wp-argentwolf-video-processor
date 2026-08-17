# AWVP release validation

This directory is the reusable AWVP release-validation framework.

## Run from a self-contained bundle

```bash
bash tests/release-validation/run.sh 0.3.2
```

The bundle root must contain `artifacts/` with the exact filenames and hashes
declared by the payload.

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
