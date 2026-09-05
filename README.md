# ArgentWolf Video Processor

ArgentWolf Video Processor is a self-hosted WordPress plugin that queues video
attachments and creates privacy-cleaned, streaming-friendly derivatives with
the server's FFmpeg and FFprobe binaries.

The original attachment remains untouched. Generated outputs can:

- strip GPS, device, chapter, and other embedded metadata;
- normalize display rotation into encoded pixels;
- reduce resolution and bitrate for practical web playback;
- produce an H.264/AAC adaptive HLS ladder;
- produce VP9/Opus WebM and H.264/AAC MP4 progressive fallbacks;
- place MP4 indexing data at the front of compatibility files;
- replace Video block and shortcode sources only at render time.

## Processing model

The plugin stores jobs in a WordPress database queue and runs one worker per
site. Its recurring WordPress event only starts a detached WP-CLI worker and
returns; FFmpeg does not run inside the WP-Cron callback or settings-page
request.

Backlog actions queue work but do not perform encoding synchronously.

## Default output

Where the source dimensions permit, the default configuration creates:

1. 360p, 480p, and 720p H.264/AAC fragmented-MP4 HLS renditions;
2. a 720p-bounded VP9/Opus WebM progressive fallback;
3. a 720p-bounded H.264/AAC MP4 progressive fallback.

Native browser HLS is used when available. Other compatible browsers use the
locally bundled and pinned hls.js runtime.

## Managed generated-media storage

Plugin-created media is stored below the active WordPress uploads directory at:

```text
wp_upload_dir()['basedir']/argentwolf-video-processor/<attachment-id>/
```

Temporary and final outputs stay inside that plugin-owned boundary so validated
promotion can remain same-filesystem and atomic. The original Media Library
attachment remains in its normal WordPress-managed location and is not moved or
rewritten.

Version 0.3.1 introduces this storage model for generated derivatives. Legacy
installations may require a separately reviewed one-time operator migration;
that migration utility is not part of the public plugin runtime or release ZIP.

## Requirements

- WordPress 6.4 or newer.
- PHP 8.1 or newer.
- WP-CLI.
- A current, security-maintained FFmpeg and FFprobe installation.
- `libx264` and `aac`.
- `libvpx-vp9` and `libopus` when WebM output is enabled.
- The FFmpeg HLS muxer with fragmented-MP4 support when adaptive HLS is enabled.
- PHP `proc_open()` for encoding.
- PHP `exec()` for automatic detached dispatch.

When `exec()` is disabled, an operator may run
`wp argent-video worker --once` from a system scheduler.

This plugin is intended for operators who can install and maintain server-side
media software. It does not bundle FFmpeg and may not be suitable for restricted
shared hosting.

## Administration

**Settings > ArgentWolf Video** provides:

- queue and worker status;
- smart, adaptive-only, and force-reprocess backlog operations;
- diagnostics for binaries, codecs, HLS, and the browser player;
- output, path, and process-priority settings;
- manual worker launch;
- WP-CLI examples;
- a link to the GitHub project for support and development;
- bounded database-backed worker diagnostic history and retention controls.

The unreleased 2.0 development line also adds a separate **Settings > PeerTube
Connection** page. It is available only to authenticated administrators with
`manage_options`; loading it is read-only, while its explicit connection/lifecycle
POST actions are nonce-protected and advance at most one reviewed step. Active
PeerTube backends also expose an upload-segment tuning control: the default is
128 MiB, `0` means one streamed resumable segment containing all remaining bytes,
and the accepted range is 0–8192 MiB. Saving this policy does not itself start a
transfer.

## WP-CLI

```bash
wp argent-video diagnose
wp argent-video jobs
wp argent-video jobs --status=failed
wp argent-video enqueue 123 --force
wp argent-video scan --mode=smart
wp argent-video scan --mode=adaptive
wp argent-video scan --mode=all --after=2026-01-01 --through=2026-07-31
wp argent-video worker --once
wp argent-video worker --limit=3
```

The `argent-video` command name is retained for compatibility.

The unreleased 2.0 development line also has an explicit PeerTube task worker
boundary:

```bash
wp argent-video peertube-task-worker --once
wp argent-video peertube-task-worker --drain
```

`--once` preserves the qualified one-task diagnostic/safety boundary. `--drain`
continues one logical upload/reconciliation operation only across immediately
runnable durable boundaries; it never sleeps through a future `run_after`. The
drain process uses a size-derived one-hour-to-six-hour safe-boundary budget and
streamed segment requests use the same size-derived timeout. The detached
launcher uses `--drain`, but the current R45 development line still does not
register a recurring PeerTube task scheduler or administrator transfer-launch
action.

## Privacy

Metadata removal applies to generated derivatives and adaptive renditions. The
original uploaded attachment is preserved and may retain its original metadata.

Stable 1.0 processing remains local and the plugin contains no telemetry. The
unreleased 2.0 development line adds an opt-in, operator-configured PeerTube
connection. Public instance detection contacts only that configured origin and
sends no credentials. An authenticated administrator with `manage_options` may
explicitly start, advance, or reconcile a durable connection operation and may
authorize one password-grant attempt per explicit submission from the separate
PeerTube Connection page. Each connection/lifecycle action is POST-only and
nonce-protected; loading the page is read-only, and there is no AJAX, REST, cron,
activation, or automatic
connection invocation. The separate unreleased R45 media-task path is explicit
WP-CLI-only and does not bootstrap or refresh credentials. Before credentials are
sent, the administrator must explicitly authorize the displayed external
service. An allowlisted development-only plaintext HTTP origin requires a second
transport-risk acknowledgement.

The explicit grant sends the entered PeerTube username and password plus an
optional six-digit OTP only to that same exact origin. The instance-local OAuth
client is used transiently; the password, OTP, and OAuth client response are not
retained or reflected into the page, redirect, or notice. Returned access and
refresh tokens are authenticated-encrypted in a non-autoloaded server-side
option before the operation can advance. No media, selected media metadata, or
telemetry is sent by the connection bootstrap itself. The unreleased R45
one-shot media-task path can send an
explicitly staged source plus the selected private upload metadata only to the
configured PeerTube origin. Source bytes are transferred through PeerTube's
resumable protocol using the backend's configured segment policy; no telemetry
is added. The configured service can observe ordinary HTTP transport metadata,
including the WordPress server's network address and the plugin product/version
User-Agent. Its operator terms and privacy policy apply.

hls.js is fetched only during controlled release builds, verified, and served
locally from the installed plugin. Build-time `hls.VERSION` and `hls.SHA256`
integrity records are not shipped in the runtime package.

Worker diagnostic history is stored locally in the WordPress database with bounded retention; detached-process capture files are temporary and removed after import.

## Development and releases

Repository-only documentation and tests are excluded from the installable ZIP.
The release package has one top-level `argentwolf-video-processor/` directory.

Use the ZIP attached to a tagged GitHub release or the WordPress.org package,
not GitHub's automatically generated source archive.

## Support development

Project source, issues, and funding links are available at:

`https://github.com/thystra/wp-argentwolf-video-processor`

## License

The plugin is GPL-2.0-or-later. The distributed hls.js runtime is provided under
the Apache-2.0 license included as `assets/vendor/hls.LICENSE`.


## FFmpeg security advisory gate

ArgentWolf Video Processor inspects the administrator-configured system FFmpeg
binary before starting new transcoding. Security checks are capability-aware:
a build can be unaffected by an advisory when the vulnerable decoder or encoder
is not compiled in, even when its version is otherwise old enough to be affected.
Known-vulnerable or unverifiable builds are blocked from starting new transcodes;
existing originals and generated media are left untouched.

The initial enforced advisory is
[CVE-2026-8461](https://nvd.nist.gov/vuln/detail/CVE-2026-8461), an out-of-bounds
write in the MagicYUV decoder that can permit remote code execution. The plugin
checks whether the `magicyuv` decoder is enabled, recognizes fixed upstream or
backported release lines, reports the CVE explicitly in Diagnostics and WordPress
Site Health, and links to the NVD record. Future FFmpeg CVEs should be added to
the same advisory registry with their own capability and NVD link.

## Current stable release

Current stable release: `1.0.0`, published through WordPress.org.

Install from WordPress.org or use the exact ZIP attached to the tagged Forgejo
release. Automatically generated source archives are not the canonical
installable release artifact.
