=== ArgentWolf Video Processor ===
Contributors: thystra
Tags: video, ffmpeg, hls, adaptive streaming, media
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Queues WordPress videos and creates local adaptive HLS and progressive derivatives with a detached FFmpeg worker.

== Description ==

ArgentWolf Video Processor preserves each original WordPress video attachment
and creates smaller derivatives suitable for browser playback on connections
ranging from slow DSL to broadband.

The default configuration creates:

* an adaptive H.264/AAC HLS ladder at 360p, 480p, and 720p where the source resolution permits;
* a VP9/Opus WebM progressive source;
* an H.264/AAC MP4 progressive fallback with fast-start indexing.

Generated outputs strip embedded GPS, device, chapter, and other metadata by
default and normalize rotation metadata into the encoded pixels. The original
attachment is not modified. Generated derivatives are stored under the active
WordPress uploads directory in the plugin-owned
`argentwolf-video-processor/<attachment-id>/` subtree.

Native HLS is used where the browser supports it. Other compatible browsers use
the locally bundled, pinned hls.js player. Progressive sources remain available
as fallbacks.

The plugin stores work in a database queue and processes one video at a time.
Its recurring WordPress event only starts a detached WP-CLI worker; FFmpeg does
not run inside the WP-Cron callback or an administrator web request.

No video, metadata, or usage information is sent to an external processing
service.

== Installation ==

1. Install current, security-maintained FFmpeg, FFprobe, and WP-CLI binaries on the WordPress server.
2. Confirm PHP permits `proc_open()` and, for automatic dispatch, `exec()`.
3. Upload the release ZIP through Plugins > Add New > Upload Plugin.
4. Activate ArgentWolf Video Processor.
5. Open Settings > ArgentWolf Video and review diagnostics and configured paths.
6. Upload a video or use Process existing videos to queue the current Media Library backlog.

This plugin requires server-administration access and may not work on restricted
shared hosting.

== Frequently Asked Questions ==

= Are original videos deleted or changed? =

No. The original WordPress attachment is preserved. Processing creates separate
derivatives.

= Does metadata stripping sanitize the original? =

No. Metadata stripping applies to generated derivatives and HLS renditions. The
original upload may retain its original metadata.

= What does adaptive streaming do? =

The plugin produces multiple HLS renditions. The player can move among available
360p, 480p, and 720p renditions as bandwidth and player size change.

= Can I process videos already in the Media Library? =

Yes. Settings > ArgentWolf Video provides Smart queue, Add adaptive HLS only,
and Force reprocess all operations, with an optional upload-date range.

= Does the plugin run FFmpeg during a web request? =

No. Web and WP-Cron requests only queue or dispatch work. A detached WP-CLI
worker performs the encode.

= Which codecs are used? =

Adaptive HLS uses H.264/AAC fragmented MP4. The default progressive fallbacks
use VP9/Opus WebM followed by H.264/AAC MP4.

= Does the plugin bundle FFmpeg? =

No. It uses administrator-configured system FFmpeg and FFprobe binaries and
checks their version, codecs, HLS muxer, and fragmented-MP4 support.

= What happens if PHP exec() is disabled? =

Automatic detached dispatch is unavailable. An operator may invoke
`wp argent-video worker --once` from a system scheduler. Encoding still requires
`proc_open()`.

= Does the plugin use an external service? =

No. Video processing occurs on the WordPress server. The pinned hls.js runtime
is bundled with the release and served locally.

== Privacy ==

The plugin creates derivative media files and stores queue state, processing
status, output paths, and error information in the local WordPress installation.

Generated derivatives strip source metadata when that setting is enabled. The
original attachment remains unchanged and may retain its original metadata.

The plugin contains no telemetry and does not send media or usage information to
a remote processing service.

== External software ==

The plugin requires operator-installed FFmpeg, FFprobe, and WP-CLI binaries.
These are local server programs, not remote services. Administrators are
responsible for installing security-maintained versions and configuring their
paths.

The release bundles a pinned hls.js browser runtime under its Apache-2.0 license.

== Developer notes ==

The existing settings keys, queue table, attachment metadata, hook names, cron
identifiers, Settings page slug, and `wp argent-video` command are retained for
upgrade compatibility.

The public plugin directory and main-file basename change in version 0.3.0.
Administrators upgrading from version 0.2.3 should use the normal WordPress
plugin-update workflow and confirm the plugin remains active.

== Upgrade Notice ==

= 0.3.1 =

Moves generated derivatives into a plugin-owned uploads subtree and removes
build-only hls.js integrity metadata from the runtime package. Original video
attachments remain unchanged.

= 0.3.0 =

Renames the public plugin and package to ArgentWolf Video Processor and prepares
the project for WordPress.org review while retaining existing data identifiers.

== Changelog ==

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
