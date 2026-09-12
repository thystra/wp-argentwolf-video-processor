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
- stale-job recovery;
- worker diagnostic retention.

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

Each detached launch has a database-backed diagnostic record. Bootstrap/output capture uses a short-lived WordPress temporary file; useful bounded output is persisted into the run record before the temporary file is removed, and stale captures are reconciled before later launches can discard evidence.

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

The Settings page also exposes bounded worker diagnostic history, administrator-configurable successful/error retention limits, and a protected clear-history action.

## Data ownership

The plugin stores:

- settings and worker state in WordPress options;
- job state in the `argent_video_jobs` table;
- 2.0 remote assets, publication tasks, operator events, and public-serving health in the `argent_video_remote_assets`, `argent_video_tasks`, `argent_video_events`, and `argent_video_publication_health` tables;
- processing status, errors, and output metadata in attachment post metadata;
- bounded worker diagnostic history in the `argentwolf_video_processor_logs` table;
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
- No telemetry service is used. Remote publication occurs only to operator-configured backends (currently PeerTube) and follows explicit per-video publication authority.
- hls.js is fetched only during controlled builds from the pinned official npm
  package, verified, and shipped locally.

## Privacy model

Generated derivatives strip metadata when enabled. The original attachment
remains untouched and may retain its original metadata. The plugin sends no telemetry or unrelated usage information to external services. When an operator configures a remote publication backend and authorizes a video for that backend, AWVP sends the selected video and reviewed publication metadata required for that publication.

## Scheduling

The plugin defines separate lightweight schedules: five-minute local-processing dispatch, one-minute PeerTube incomplete-work recovery, hourly remote-publication public-serving health, and daily backend credential/catalog maintenance. Detached workers perform expensive provider/upload work. WordPress installations with front-end WP-Cron disabled must invoke due events through a system scheduler.


## 2.0 remote publication, health, and failover

### Publication authority versus serving health

Durable publication authority answers whether a remote publication was legitimately reviewed, executed, and verified. `argent_video_publication_health` separately records the latest provider-independent observation of whether an ordinary unauthenticated visitor can actually consume the serving URL. Health degradation never erases publication history or serving provenance. Frontend rendering performs no provider HTTP; it reads only durable local authority/health state.

The final serving qualification is the visitor-facing public/embed URL. A provider API may explain `processing`, missing/private state, or another failure, but API `published` state cannot override a non-viable public URL. Normalized health includes healthy, processing, missing, private/restricted, embed-disallowed, temporarily unavailable, and indeterminate probe states. Expected processing is not a broken-publication incident and does not start email escalation.

### Serving priorities and failover

Local WordPress is an implicit serving backend at priority 0. Configured remote backends have positive serving priorities (default 500). For each video, the resolver considers only publications that have verified authority and current eligible health, then selects the highest-priority viable candidate. If a preferred backend fails, a lower-priority verified remote may serve; if none remain and the original Media Library source still exists, AWVP falls back to Local. Recovery requires two consecutive healthy observations before automatic failback so a flapping backend does not repeatedly switch the player. Priority controls serving preference only; it does not imply replication or retention authority.

### Health cadence, ETA, and incidents

Successful remote publications are normally rechecked about every six hours by an hourly bounded cron. First failure removes the publication from serving eligibility immediately and appears on Overview. Retry cadence is progressively bounded so a transient failure can recover before delayed email escalation. Backend-wide transport/server outages are represented once per backend rather than generating one email per affected video. Definite single-publication conditions such as missing/private/embed-disabled remain per-video incidents.

While a new public URL is still processing, `Backend_Processing_Estimator` derives an advisory readiness estimate from source bytes plus recent backend history. It retains at most 10 successful upload-accepted-to-publicly-playable observations and ignores samples older than 90 days; this is advisory scheduling information, never publication authority.

### Notifications and maintenance

Remote-health email policy is independently configurable for administrator, publishing user, and origin post author, each Off / delayed / immediate. Recipient addresses are deduplicated, and recovery messages are sent only for roles that actually received the incident alert. Daily backend maintenance reconciles PeerTube credentials and publication catalogs and reports server-level failures on Overview independently from per-video serving health.

### Republish

Republish is an explicit recovery operation available only when the authoritative WordPress original still exists. It records an exact durable request, advances to one exact new publication generation, and creates new upload/publication work for the same or another configured backend. Historical remote assets, tasks, events, upload journals, and prior serving authority remain audit evidence; replacement serving does not cut over until the new publication passes visitor-facing verification.

### Local retention at scale

WordPress is the archive of record by default, and automatic original-source deletion is prohibited while that policy is active. Serving choice and source retention are separate. Local Retention provides one site-wide AWVP Default Policy plus a searchable/filterable compact video list for bounded bulk application. A cleanup delay of 0 means Never; selected videos may snapshot a finite 1-365 day override. RC13.2 can also remove only the original while preserving positively verified AWVP-managed HLS delivery, so destructive execution proves either the remote delivery that will remain or the exact local HLS delivery set. Every destructive application still passes through archive-of-record, finite-delay, source-confinement, ownership, retained-delivery, and execution-journal safety checks.

## Compatibility and renaming

Version `0.3.0` changed the public name, directory slug, main filename, text
domain, and release package root to `argentwolf-video-processor`.

Version `0.3.1` confines all generated derivatives to the plugin-owned uploads
root, `wp_upload_dir()['basedir']/argentwolf-video-processor/<attachment-id>/`,
while leaving original Media Library attachments untouched. Legacy output
migration is an operator-controlled one-time maintenance action and is not part
of the normal public runtime.

Persisted option names, table names, post-meta keys, hook names, cron names,
namespace, settings-page slug, and CLI command remain unchanged. The basename
transition must be tested explicitly because WordPress stores active plugins by
relative directory and main-file path.

## Distribution

The source repository contains development documentation, tests, CI, and build
tools. The release builder uses an allowlist and packages only:

- `argentwolf-video-processor.php`;
- `includes/`;
- `assets/`, including the verified pinned `hls.min.js` runtime and
  `hls.LICENSE`, but excluding build-only `hls.VERSION` and `hls.SHA256`;
- `uninstall.php`;
- `LICENSE`;
- `readme.txt`.

WordPress.org approval and release through the directory are separate from
GitHub releases.
