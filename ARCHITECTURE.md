# ArgentWolf Video Processor architecture

## Purpose

ArgentWolf Video Processor converts WordPress video attachments into
streaming-friendly derivatives while preserving the original attachment. It
provides adaptive HLS plus progressive browser fallbacks and performs expensive
work outside normal web and WP-Cron requests.

## Components

### Bootstrap

`argentwolf-video-processor.php` defines the plugin metadata and constants, loads
runtime classes, registers activation/deactivation hooks, and boots the plugin.

The existing `ArgentVideo` namespace and `ARGENT_VIDEO_*` constants are retained
to minimize upgrade risk. They are implementation identifiers, not the public
product name.

### Settings

`Settings` owns configuration for:

- automatic queueing and dispatch;
- progressive profile and bitrate controls;
- adaptive HLS;
- metadata stripping;
- FFmpeg, FFprobe, and WP-CLI paths;
- CPU and I/O priority;
- stale-job recovery.

The existing option name is retained for upgrade compatibility.

### Queue and repository

`Job_Repository` owns the database-backed job queue. Queue operations must be
idempotent. A site may have only one active worker. Job claims must be atomic,
and stale processing jobs may be recovered after the configured interval.

`Queue` handles individual attachment lifecycle events. `Bulk_Queue` selects
existing attachments for smart, adaptive-only, or full reprocessing.

### Worker dispatch

The recurring WordPress event does not encode video. `Worker_Launcher` checks
the queue and starts a detached WP-CLI worker at configured low CPU and I/O
priority. When automatic detached launch is unavailable, an operator may invoke
`wp argent-video worker --once` from a system scheduler.

### Probe and transcoding

`Probe` and `Diagnostics` inspect the configured system binaries and available
codecs. `Command_Builder`, `Process_Runner`, `Transcoder`, and `Adaptive_HLS`
construct and execute FFmpeg/FFprobe operations. `Storage` owns plugin-created
filesystem paths, URL conversion, confinement checks, atomic promotion, and
destructive cleanup.

FFmpeg is not bundled. The administrator is responsible for installing and
maintaining the system binaries. Paths are configurable because shared-host and
open_basedir environments differ.

### Outputs

The default output set is:

1. an H.264/AAC fragmented-MP4 HLS ladder at available 360p, 480p, and 720p
   renditions;
2. a VP9/Opus WebM progressive fallback;
3. an H.264/AAC MP4 progressive fallback using fast-start indexing.

Generated outputs are created in temporary locations, probed and validated, and
then installed atomically. Failed temporary output must be removed.

The original WordPress attachment is never rewritten or deleted by processing.

### Rendering

`Renderer` and `Player` replace the source set only at render time for Gutenberg
Video blocks and WordPress video shortcodes. Stored post content remains
unchanged. Native HLS is preferred when available; otherwise the locally
vendored, pinned hls.js runtime is used. Progressive files remain fallbacks.

### Administration and CLI

The Settings page provides queue status, backlog actions, diagnostics, settings,
worker launch, CLI examples, and a link to the public GitHub project.

The CLI command remains `wp argent-video` for compatibility.

## Data ownership

The plugin stores:

- settings and worker state in WordPress options;
- job state in the `argent_video_jobs` table;
- processing status, errors, and output metadata in attachment post metadata;
- generated derivative files under
  `wp_upload_dir()['basedir']/argentwolf-video-processor/<attachment-id>/`.

Uninstall preserves data and derivative files by default. Destructive uninstall
requires an explicit operator-defined constant.

## Security model

- Administrative actions require capabilities and nonces.
- Queue and worker operations validate attachment IDs and local video MIME
  types.
- Shell commands must be constructed from validated settings and safely quoted
  arguments.
- Every plugin-created write, rename, directory creation, and deletion is
  validated against the plugin-owned uploads root before the filesystem
  operation.
- Public requests do not directly execute FFmpeg.
- No telemetry or remote processing service is used.
- hls.js is fetched only during controlled builds from the pinned official npm
  package, verified, and shipped locally.

## Privacy model

Generated derivatives strip metadata when enabled. The original attachment
remains untouched and may retain its original metadata. The plugin sends no
video, metadata, or usage information to an external service.

## Scheduling

The plugin defines a five-minute WordPress schedule. The callback is a
lightweight dispatcher only. WordPress installations with front-end WP-Cron
disabled must invoke due events through a system scheduler.

## Compatibility and renaming

Version `0.3.0` changes the public name, directory slug, main filename, text
domain, and release package root to `argentwolf-video-processor`.

Persisted option names, table names, post-meta keys, hook names, cron names,
namespace, settings-page slug, and CLI command remain unchanged. The basename
transition must be tested explicitly because WordPress stores active plugins by
relative directory and main-file path.

## Distribution

The source repository contains development documentation, tests, CI, and build
tools. The release builder uses an allowlist and packages only:

- `argentwolf-video-processor.php`;
- `includes/`;
- `assets/`, including generated pinned hls.js files;
- `uninstall.php`;
- `LICENSE`;
- `readme.txt`.

WordPress.org approval and release through the directory are separate from
GitHub releases.
