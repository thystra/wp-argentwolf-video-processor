=== ArgentWolf Video Processor ===
Contributors: thystra
Tags: video, ffmpeg, hls, adaptive streaming, media
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Processes WordPress video locally or publishes selected videos to configured PeerTube servers.

== Description ==

ArgentWolf Video Processor keeps WordPress video sources by default and creates
smaller derivatives suitable for browser playback on connections ranging from
slow DSL to broadband. Version 2.0 can also publish selected videos to a
configured PeerTube server.

The default configuration creates:

* an adaptive H.264/AAC HLS ladder at 360p, 480p, and 720p where the source resolution permits;
* a VP9/Opus WebM progressive source;
* an H.264/AAC MP4 progressive fallback with fast-start indexing.

Generated outputs strip embedded GPS, device, chapter, and other metadata by
default and normalize rotation metadata into the encoded pixels. Local processing
does not modify the original source. Generated derivatives are stored under the
active WordPress uploads directory in the plugin-owned
`argentwolf-video-processor/<attachment-id>/` subtree.

Native HLS is used where the browser supports it. Other compatible browsers use
the locally bundled, pinned hls.js player. Progressive sources remain available
as fallbacks.

The plugin stores work in a database queue and processes one video at a time.
Its recurring WordPress event only starts a detached WP-CLI worker; FFmpeg does
not run inside the WP-Cron callback or an administrator web request.

The public WordPress.org 1.0 release processes video locally. Version 2.0
retains that local destination and adds opt-in publishing to an administrator-
configured PeerTube service. Settings > ArgentWolf Video Processor provides one
tabbed interface for local processing, PeerTube servers, publishing defaults,
video migration, and local retention. Adding a PeerTube server walks the
administrator through server setup, PeerTube sign-in, account verification,
channel selection, and activation. No manually generated API key is required.
Connection actions are administrator-only, nonce-protected, and do not upload
video merely by configuring the server.

The authorized bootstrap sends the entered PeerTube username and password plus
an optional six-digit OTP only to that exact origin. The password, OTP, and
instance-local OAuth client response are not retained or reflected into the
page, redirect, or notice. Returned access and refresh tokens are
authenticated-encrypted in a non-autoloaded server-side option. No video, media
metadata, or telemetry is sent by connection bootstrap. The configured service
can observe ordinary request metadata, including the WordPress server network
address and plugin/version User-Agent.

== Installation ==

1. Install current, security-maintained FFmpeg, FFprobe, and WP-CLI binaries on the WordPress server.
2. Confirm PHP permits `proc_open()` and, for automatic dispatch, `exec()`.
3. Upload the release ZIP through Plugins > Add New > Upload Plugin.
4. Activate ArgentWolf Video Processor.
5. Open Settings > ArgentWolf Video Processor and review Local Processing and PeerTube settings.
6. Upload a video or use Process existing videos to queue the current Media Library backlog.

This plugin requires server-administration access and may not work on restricted
shared hosting.

== Frequently Asked Questions ==

= Are original videos deleted or changed? =

Local processing does not modify the original source, and retention defaults to
keeping all local copies. In version 2.0 an administrator may separately opt
into delayed post-cutover cleanup. Deleting the physical WordPress
source requires an explicit non-WordPress master-authority decision, a 1-365 day
grace period, and current verified PeerTube serving; the WordPress attachment
record itself is preserved.

= Does metadata stripping sanitize the original? =

No. Metadata stripping applies to generated derivatives and HLS renditions. The
original upload may retain its original metadata.

= What does adaptive streaming do? =

The plugin produces multiple HLS renditions. The player can move among available
360p, 480p, and 720p renditions as bandwidth and player size change.

= Can I process videos already in the Media Library? =

Yes. Settings > ArgentWolf Video Processor > Local Processing provides Smart queue, Add adaptive HLS only,
and Force reprocess all operations, with an optional upload-date range.

= Does the plugin run FFmpeg during a web request? =

No. Web and WP-Cron requests only queue or dispatch work. A detached WP-CLI
worker performs the encode.

= Which codecs are used? =

Adaptive HLS uses H.264/AAC fragmented MP4. The default progressive fallbacks
use VP9/Opus WebM followed by H.264/AAC MP4.

= Does the plugin check FFmpeg security advisories? =

Yes. Before starting a new transcode, the plugin checks the configured FFmpeg version and relevant compiled capabilities. Known-vulnerable or unverifiable builds are blocked. CVE-2026-8461 is checked against the MagicYUV decoder and linked from Diagnostics and Site Health: https://nvd.nist.gov/vuln/detail/CVE-2026-8461

= Does the plugin bundle FFmpeg? =

No. It uses administrator-configured system FFmpeg and FFprobe binaries and
checks their version, codecs, HLS muxer, and fragmented-MP4 support.

= What happens if PHP exec() is disabled? =

Automatic detached dispatch is unavailable. An operator may invoke
`wp argent-video worker --once` from a system scheduler. Encoding still requires
`proc_open()`.

= Does the plugin use an external service? =

The public WordPress.org 1.0 release does not use a remote processing service:
video processing occurs on the WordPress server and the pinned hls.js runtime is
served locally. Version 2.0 can contact only an
operator-configured PeerTube origin. Public instance detection sends no
credentials. The explicitly administrator-authorized connection bootstrap
exchanges an entered PeerTube username/password and optional OTP for reusable
tokens at that same origin; it retains only authenticated-encrypted
access/refresh tokens. Connection bootstrap sends no media, media metadata, or
telemetry. Videos explicitly configured for PeerTube may later send source or
AWVP-managed media plus the reviewed publication metadata to that service from
the detached worker. PeerTube is self-hostable software; the administrator must
review the terms and privacy information published by the operator of the chosen
instance.

== Privacy ==

The plugin creates derivative media files and stores queue state, processing
status, output paths, and error information in the local WordPress installation.

Generated derivatives strip source metadata when that setting is enabled. Local
processing leaves the source unchanged and it may retain its original metadata.
If an administrator explicitly enables the 2.0 post-cutover `delete_all` retention
policy, the physical source may later be deleted only after the documented
master-authority, grace-period, serving, identity, and quiescence checks pass.

The plugin contains no telemetry. The public WordPress.org 1.0 release sends no
media or usage information to a remote processing service. The 2.0 release
candidate reads public configuration only from the exact operator-configured
PeerTube origin. Its explicitly administrator-authorized bootstrap sends the
entered PeerTube username/password and optional OTP only to that origin. The
password, OTP, and transient local OAuth client response are not stored or
reflected into the page, redirect, or notice; returned access/refresh tokens are
stored authenticated-encrypted in a non-autoloaded server-side option. Bootstrap
sends no media, media metadata, or telemetry. Videos explicitly configured for
PeerTube may send media and reviewed publication metadata to that configured
service through the detached task worker. That operator can observe the
requesting server network address and plugin/version User-Agent.

Worker diagnostic history is stored locally in the WordPress database with bounded retention. Detached-process capture files are temporary and removed after their useful output has been persisted.

== External software ==

The plugin requires operator-installed FFmpeg, FFprobe, and WP-CLI binaries.
These are local server programs, not remote services. Administrators are
responsible for installing security-maintained versions and configuring their
paths.

The release bundles a pinned hls.js browser runtime under its Apache-2.0 license.

PeerTube is optional, self-hostable video-platform software. The 2.0 release
candidate targets an exact service origin selected by the administrator:
https://joinpeertube.org/. Service terms, privacy practices, and data location
are controlled by the operator of that selected instance.

== Developer notes ==

The existing settings keys, queue table, attachment metadata, hook names, cron
identifiers, Settings page slug, and `wp argent-video` command are retained for
upgrade compatibility.

This Forgejo release-candidate package identifies itself as `2.0.0-rc3` in the
plugin header while `Stable tag: 1.0.0` deliberately continues to identify the
public WordPress.org release. RC packages are not published to WordPress.org SVN.
The Stable tag moves to `2.0.0` only when the final release is promoted.

The public plugin directory and main-file basename change in version 0.3.0.
Administrators upgrading from version 0.2.3 should use the normal WordPress
plugin-update workflow and confirm the plugin remains active.

== Upgrade Notice ==

= 2.0.0-rc3 =
Third controlled 2.0 release candidate. Corrects Plugin Check suppression scope for reviewed nonce/query/hook cases found by canonical RC2; no runtime behavior change. Keep WordPress.org production users on 1.0.0 until final 2.0.0 publication.

= 2.0.0-rc2 =
Second controlled 2.0 release candidate. Resolves WordPress Plugin Check findings found in canonical RC1 while retaining the same 2.0 feature scope; keep WordPress.org production users on 1.0.0 until final 2.0.0 publication.

= 2.0.0-rc1 =
Release candidate for controlled 2.0 validation. Keep WordPress.org production users on 1.0.0 until the final 2.0.0 release is published.

= 1.0.0 =
First stable WordPress.org release; no functional or runtime behavior changes from 0.3.3.

= 0.3.3 =
WordPress 7.1 compatibility metadata update; no functional or runtime changes from 0.3.2.

= 0.3.2 =

Stores bounded worker diagnostic history in the local WordPress database and uses only short-lived WordPress temporary files for detached-process capture. Adds administrator-configurable retention for successful and error runs.

= 0.3.1 =

Moves generated derivatives into a plugin-owned uploads subtree and removes
build-only hls.js integrity metadata from the runtime package. Original video
attachments remain unchanged.

= 0.3.0 =

Renames the public plugin and package to ArgentWolf Video Processor and prepares
the project for WordPress.org review while retaining existing data identifiers.

== Changelog ==

= 2.0.0-rc3 =
* Correct narrow PHPCS suppression scopes for action-specific nonce seed reads, read-only query selectors, and the already-prefixed publication-plan hook; runtime behavior and nonce enforcement are unchanged.
* Preserve canonical RC2 run 154 as failed release evidence after exact package identity passed but static Plugin Check stopped on five nonce-analysis warnings and two hook-prefix false positives.
* Advance to a new immutable RC3 candidate; RC1 and RC2 bytes are never rebuilt or reused.

= 2.0.0-rc2 =
* Resolve WordPress Plugin Check 2.1.0 findings discovered by the first canonical RC1 release-validation pass without relaxing the established WordPress uploads/filesystem or PeerTube streaming boundaries.
* Keep RC validation reports inside the AWVP project tree and permit only the intentional prerelease Stable-tag mismatch while all other Plugin Check ERROR/WARNING findings remain blocking.
* Preserve the failed canonical RC1 bytes as release evidence; RC2 is a new candidate identity and package.

= 2.0.0-rc1 =
* Begin the controlled 2.0 release-candidate line while WordPress.org Stable tag remains 1.0.0.
* Add opt-in PeerTube connection, reviewed per-video publication, detached resumable upload/reconciliation, verified serving cutover, migration planning/promotion, and fail-closed post-cutover local retention.
* Retain the local 1.x processing backend and existing WordPress data identifiers for upgrade compatibility.

= 1.0.0 =
* Promoted the WordPress.org-approved 0.3.3 codebase to the first stable 1.0.0 release.
* No functional or runtime behavior changes from 0.3.3.

= 0.3.3 =
* Updated WordPress compatibility metadata for WordPress 7.1.
* No functional or runtime behavior changes from 0.3.2.

= 0.3.2 =

* Replace the persistent system-temp worker log with bounded database-backed diagnostic history in `{$wpdb->prefix}argentwolf_video_processor_logs`.
* Use short-lived WordPress temporary captures for detached worker bootstrap/output and recover stale captures before later launches can replace evidence.
* Add configurable retention defaults of 10 successful runs and 100 error/job-error runs, with a fixed 512 KiB diagnostic-output cap per run.
* Add recent worker history and a nonce/capability-protected clear-history action to the Settings page.
* Use WordPress temporary-file facilities with failure-safe cleanup for FFmpeg/FFprobe stdout and stderr captures.
* Retain the legacy `argent_video_jobs` table for compatibility while using the canonical `argentwolf_video_processor_*` identifier for newly introduced tables.

= 0.3.1 =

* Store generated MP4, WebM, HLS, and temporary output under `wp_upload_dir()['basedir']/argentwolf-video-processor/<attachment-id>/`.
* Centralize generated-media path confinement, URL conversion, atomic promotion, and cleanup in the Storage service.
* Reject traversal, sibling-prefix, and unsafe symlink escapes before managed filesystem mutations.
* Make attachment cleanup derive the managed attachment directory instead of trusting stored output paths.
* Keep hls.js version/checksum records as build-time integrity evidence while excluding `hls.VERSION` and `hls.SHA256` from the installable ZIP.
* Add storage-boundary regression coverage for custom uploads, path escapes, symlinks, HLS writes, and cleanup.

= 0.3.0 =

* Resolve WordPress Plugin Check findings with identifier placeholders, WordPress file-deletion APIs, and narrowly documented worker, queue, and atomic-filesystem exceptions.
* Standardize the public name as ArgentWolf Video Processor.
* Change the directory slug, main filename, package root, and text domain to `argentwolf-video-processor`.
* Retain existing options, attachment metadata, queue table, hooks, cron identifiers, namespace, admin page slug, and WP-CLI command.
* Remove private operator and production material from the public repository.
* Add public architecture, agent, milestone, privacy, and WordPress.org submission documentation.
* Add Settings and GitHub project links to the plugin action row.
* Add a Support development section to the settings page.
* Change release packaging to an explicit runtime allowlist.

= 0.2.3 =

* Fix binary diagnostics and detached worker launch under per-site PHP `open_basedir` restrictions.
* Probe configured executables through safely quoted shell commands instead of PHP filesystem stat calls.
* Report PHP SAPI and active `open_basedir` in diagnostics.

= 0.2.2 =

* Fix release ZIP builds when the npm package license text differs from the repository snapshot.
* Validate the package SPDX identity and substantive Apache-2.0 license text.
* Ship the license from the verified package and remove generated vendor files after packaging.

= 0.2.1 =

* Validate the exact hls.js npm package and runtime version.
* Include the vendored player version and SHA-256 record.
* Add an offline regression test for player vendoring.

= 0.2.0 =

* Add adaptive HLS with 360p, 480p, and 720p fragmented-MP4 renditions.
* Add native-HLS playback and a pinned local hls.js player.
* Add administrator backlog operations and upload-date filtering.
* Add system-binary, codec, HLS, and player diagnostics.

= 0.1.1 =

* Fix FFmpeg autorotation compatibility.
* Improve failed-job output and required-codec diagnostics.
* Add a real FFmpeg integration test.

= 0.1.0 =

* Initial queue, detached worker, FFmpeg processing, validation, metadata stripping, render substitution, administration, CLI, and release workflow.
