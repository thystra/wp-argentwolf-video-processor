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
into delayed cleanup. A cleanup delay of 0 means Never automatically delete;
selected videos may instead snapshot a finite 1-365 day delay. When WordPress is
not the Archive of Record, AWVP can remove an original while keeping verified
local HLS delivery, or remove both original and generated local copies after a
verified remote publication. The WordPress attachment record itself is
preserved.

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
If an administrator explicitly marks WordPress as NOT the Archive of Record and
selects a source-deleting retention policy, the physical source may later be
deleted only after the documented archive-authority, finite-delay, retained-delivery
(remote or local HLS), identity, ownership, and quiescence checks pass.

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
are controlled by the operator of that selected server.

== Developer notes ==

The existing settings keys, queue table, attachment metadata, hook names, cron
identifiers, Settings page slug, and `wp argent-video` command are retained for
upgrade compatibility.

This Forgejo release-candidate package identifies itself as `2.0.0-rc13.5` in the
plugin header while `Stable tag: 1.0.0` deliberately continues to identify the
public WordPress.org release. RC packages are not published to WordPress.org SVN.
The Stable tag moves to `2.0.0` only when the final release is promoted.

The public plugin directory and main-file basename change in version 0.3.0.
Administrators upgrading from version 0.2.3 should use the normal WordPress
plugin-update workflow and confirm the plugin remains active.

== Upgrade Notice ==

= 2.0.0-rc13.5 =
RC13 series build 5. Completes opt-in destructive uninstall for all current AWVP database/state ownership while preserving ordinary attachments, local media files, external archives, and remote PeerTube assets. WordPress.org remains on 1.0.0.

= 2.0.0-rc13.4 =
RC13 series build 4. Adds guarded adoption of freshly verified existing remotes, a consolidated serving-backend inventory, effective retention status, manual cleanup-now controls, and a fresh-install manual-only retention default. WordPress.org remains on 1.0.0.

= 2.0.0-rc13.3 =
RC13 series build 3. Fixes Local Retention Plugin Check nonce-analysis findings and makes canonical validation collect independent failures before returning one aggregate result. WordPress.org remains on 1.0.0.

= 2.0.0-rc13.2 =
RC13 series build 2. Adds video/routing and migration UX, flexible retention, verified remote recovery, local-delivery rebuild, publication history, and scheduled pre-publication visibility. WordPress.org remains on 1.0.0.

= 2.0.0-rc13.1 =
RC13 series build 1. Fixes scheduled Send-now generation races: stale finalizers cannot mutate current state, publish transitions recover the existing verified remote, and stranded finalization stays visible/recoverable. WordPress.org remains on 1.0.0.

= 2.0.0-rc12 =
Twelfth controlled 2.0 release candidate. Fixes multiline PeerTube publication metadata, safe same-generation finalizer retry, event timestamps, and editor copy found by RC11 live testing. WordPress.org remains on 1.0.0.

= 2.0.0-rc11 =
Eleventh controlled 2.0 release candidate. Adds visitor-facing remote health/failover, processing-aware readiness, health alerts, daily backend maintenance, restart-safe republish, backend priority/language defaults, and scalable retention. WordPress.org remains on 1.0.0.

= 2.0.0-rc10 =
Tenth controlled 2.0 release candidate. Repairs RC9 live PeerTube recovery/credentials, provider semantics, routing and diagnostics; adds legacy 1.x migration/serving, responsive player CSS, and site-wide archive-of-record retention. WordPress.org remains on 1.0.0.

= 2.0.0-rc9 =
Ninth controlled 2.0 release candidate. Makes PeerTube work event-driven with bounded recovery, publishes the original source without redundant FFmpeg, verifies publication state before/after mutation, and adds human-readable operational status. WordPress.org remains on 1.0.0 until final 2.0.0.

= 2.0.0-rc8 =
Eighth controlled 2.0 release candidate. Prevents WordPress revisions/autosaves or copied non-anchor blocks from revoking PeerTube publication authority, and alphabetizes PeerTube category choices. WordPress.org remains on 1.0.0 until final 2.0.0.

= 2.0.0-rc7 =
Seventh controlled 2.0 release candidate. Fixes stock PeerTube resumable-upload Location handling, local HLS player integration, publishing-option labels, tag entry, review flow, and setup guidance found in RC6 live testing. WordPress.org remains on 1.0.0 until final 2.0.0.

= 2.0.0-rc6 =
Sixth controlled 2.0 release candidate. Sanitizes the read-only Local Retention notice at the request boundary to satisfy Plugin Check while preserving existing nonce and retention behavior. WordPress.org remains on 1.0.0 until final 2.0.0.

= 2.0.0-rc5 =
Fifth controlled 2.0 release candidate. Resolves the two Plugin Check findings from canonical RC4: the WordPress.org upgrade-notice length limit and a reviewed Local Retention nonce-analysis warning. WordPress.org remains on 1.0.0 until final 2.0.0.

= 2.0.0-rc4 =
Fourth controlled 2.0 release candidate. Consolidates AWVP administration into one tabbed settings page, clarifies PeerTube setup and validation errors, and uses the WordPress-configured timezone for administrator-facing times. WordPress.org remains on 1.0.0 until final 2.0.0.

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

= 2.0.0-rc13.5 =
* Completes explicit destructive uninstall across all six current AWVP-owned tables, fixed/namespaced options and managed secrets, cron/transient state, plugin-owned post metadata, and AWVP Video objects.
* Keeps destructive uninstall local-only: no PeerTube calls, no ordinary attachment deletion, no external archive deletion, and no recursive derivative/staging file deletion.
* Adds source-manifest drift coverage and exact-package uninstall validation after normal clean/upgrade assertions on every release matrix fixture.

= 2.0.0-rc13.4 =
* Consolidate Videos & Routing into an ordered Serving backends inventory with remote verification and local artifact availability; intentional local-source cleanup is not an error while verified remote serving remains active.
* Let Check now followed by Use verified remote now establish distinct operator-verified serving authority for an already-known healthy remote without replaying or rewriting a failed historical finalizer; automatic cutover remains finalizer-proof strict.
* Establish provisional authority before restoring an ineligible remote's serving eligibility, so failed authority adoption cannot enable remote serving.
* Show effective Local Retention as Server default or Override, including Manual cleanup only for destructive zero-day policies, instead of Not set.
* Add Clean up selected now, bypassing only the waiting period while retaining archive-of-record, serving-proof, processing, exact-source, policy, and confined WordPress deletion gates.
* Default fresh/unconfigured retention grace to 0/manual-only while preserving explicitly saved existing nonzero settings and existing queued version-1 retention task payloads.
* Preserve RC13.3 as immutable base evidence at Forgejo commit `f65d9ab9cb973b3bd30788ae9c453b873325725c`, tree `15f6a7c92d17e678c020b00f93f69391f90d1c50`.

= 2.0.0-rc13.3 =
* Keep Local Retention grace-period request reads inside the nonce-verified administrator actions and pass only sanitized values into the shared parser, resolving canonical Plugin Check nonce-analysis warnings without changing retention semantics.
* Continue independent Plugin Check modes and disposable clean/upgrade assertion phases after individual findings, then fail once with an aggregate summary; global candidate/harness identity gates remain fail-closed.
* Preserve rejected canonical RC13.2 source/package identity as immutable evidence rather than rebuilding under the same prerelease version.

= 2.0.0-rc13.2 =
* Add a read-only Videos & Routing matrix for AWVP videos, attachments, referencing WordPress posts, configured primary destination, actual serving source/fallback, and local source/retention state.
* Reserve the Backups matrix column for future ordered multi-backend routing without enabling multi-backend publication in this build.
* Add bounded shared post-reference discovery for AWVP/Core Video blocks, supported video shortcodes, and direct video/source markup, while always retaining the stored origin-post link.
* Move the Migration review queue above discovery, show referencing-post links, consolidate publication review into one acknowledgement while preserving the five durable review flags, and show Start migration inline once review is ready.
* Let Local Retention use 0 as Never, and let selected videos snapshot the site delay, Never, or a finite 1-365 day cleanup delay.
* Add a local-backend retention mode that removes the WordPress original while retaining and re-verifying AWVP-managed HLS delivery; no PeerTube publication is required for this mode.
* Keep existing version-1 cleanup journals readable while new version-2 journals distinguish remote-serving proof from local-HLS proof.
* Add Check now for exact visitor-facing remote serving verification and Restore remote serving now only after a fresh successful check; no blind health override, upload, republish, or PeerTube publication change is performed by these controls.
* Add Rebuild local delivery from a retained WordPress source through the existing detached FFmpeg/HLS worker while preserving remote publication history and routing.
* Send recovery email only when a remote publication is serving-eligible again and label advisory Overview timing as Estimated readiness.
* Add a read-only History & Logs tab for bounded retained publication events, with current serving state, backend/outcome filtering, safe remote identity links, and expandable seven-step details.
* Add reviewed scheduled Send-now pre-publication visibility: Private by default or explicitly Unlisted while the WordPress post is actually scheduled; final visibility and serving cutover still require the actual publish transition.
* Replace normal event copy about durable boundaries/publication authority with concrete checks, waits, superseded-task results, and next actions.
* Preserve qualified RC13.1 commit `d27a76f80128bf522a878722b251e40c2c4af957`, tree `a0f6caa9bb767a3279bbeaf1f4ce59bd3e748d89`, and ZIP SHA-256 `05f0909fcd4756650a91281f5634552c10d1bc0e6b01ed8139e75319934ed744` as immutable evidence.

= 2.0.0-rc13.1 =
* Fence publication finalizers to fresh lifecycle/post state before provider mutation, local execution writes, public-health qualification, and serving cutover so a superseded generation cannot mutate current publication state.
* Recover a scheduled `future` -> `publish` transition by adopting the existing verified upload/remote asset and recreating the exact missing current-generation finalizer without re-uploading or advancing another generation.
* Keep ready-but-unserved finalization gaps visible in Status & Needs Attention; automatically reconstruct only a wholly missing finalizer, while terminal/indeterminate provider outcomes remain non-replayable.
* Do not record the reviewed final manifest as applied while a Send-now publication is only in its pre-publication Private staging state.
* Adopt dotted RC build identities (`rcN.M`) so every source/package rebuild has a unique increasing version instead of reusing one RC number for multiple candidate byte sets.

= 2.0.0-rc12 =
* Preserve qualified RC11 candidate #4 (commit `c56090eb9b5fd6f92010b7c00a6bf47154607659`, tree `e6a53bb1a412ab9f1558c0e41c778385a4481ec0`, ZIP SHA-256 `0d555201cb1a77f8b515d923f888d88e9421c4b6579d506a8de0940c08e14af6`) as immutable live-test evidence.
* Allow LF/CRLF in reviewed PeerTube description/support Markdown and an empty description without relaxing unrelated request validation.
* Add explicit same-generation Retry publication recovery for finalizers that prove no provider mutation was transmitted; no source re-upload or new publication generation is created.
* Correct operator-event `created_at` writes and remove the historical `R46.3c` label from editor help copy.

= 2.0.0-rc11 =
* Preserve canonical RC10 candidate #2 (commit `215e7b12582d01ea27f4e1c08b965812f3f13d72`, tree `22892bcbc2449d4e0d31fa8bfff5701ff5feb85c`, ZIP SHA-256 `3d4ce93bac8611524d03057ecf270e8e309222b0314f5006ba3520c14afac160`) as immutable qualification/live-test evidence. RC11 is a new package identity.
* Add model DB schema 3 visitor-facing publication health, unauthenticated public/embed URL qualification, backend serving priorities/failover, and two-success failback hysteresis without render-time provider requests.
* Treat provider processing as expected/non-broken and estimate readiness from source size plus at most 10 recent successful backend observations from the last 90 days.
* Add backend-outage deduplication, immediate Overview visibility, configurable administrator/publishing-user/origin-author email timing, recovery notices, and daily PeerTube credential/catalog maintenance.
* Add restart-safe Republish from a retained WordPress source to the same or another configured PeerTube server while preserving historical remote/task/event/upload evidence until replacement cutover.
* Add reviewed/removed Overview presentation state for still-current issues, per-server serving-priority/default-language administration, and compact scalable Local Retention with one AWVP Default Policy plus searchable/filterable bulk application.

= 2.0.0-rc10 =
* Preserve canonical RC9 source commits `878304ba8cbaf24e5017263a2f7f5c6a79f6fbfb` and `854014d03b4fd55940ab6e9bc49fa2b13035c3ba`, plus exact RC9 package SHA-256 `d28b56e9eaf0ca482559244753677eca10ec0bb4a7bc45e382c42cefc8239c23`, as immutable live-test evidence. RC10 is a new package identity.
* Automatically reconcile expired/near-expiry PeerTube credentials and generation-bound publication catalogs, force fresh durable authority reads in long-lived workers, defer dependency waits without spending task attempts, and keep pre-upload watchers short until real upload work begins.
* Classify local validation and definite provider rejections separately from truly indeterminate consequential mutations; allow valid multi-word tags, verify tag sets without order dependence, and accept authenticated same-origin PeerTube short embed identifiers while retaining no-blind-replay safety.
* Add bounded seven-step `argent_video_events` operator diagnostics and Test-5 local-only recovery that can establish verified serving authority after upgrade without another upload or publication PUT. Ship responsive no-autoplay frontend CSS and require it in canonical package inspection.
* Route PeerTube-bound media before local FFmpeg enqueue; discover completed AWVP 1.x attachments read-only and adopt them only through explicit migration planning; bridge eligible historical Core Video/shortcode rendering to verified PeerTube authority without rewriting stored post content.
* Replace repetitive per-video source-deletion confirmations with a fail-closed site-wide WordPress archive-of-record policy and grace period. Original deletion is possible only when WordPress is not the archive of record and all currently required remote publication/serving evidence is verified at execution time.
* Standardize operator copy on **PeerTube server** and explain **Backend ID (internal identifier)** as the stable correlation key used in logs, diagnostics, and error messages.

= 2.0.0-rc9 =
* Preserve canonical RC8 source `57ba6de225fee3bca200c2faaaf214086eaa83c7`, tree `49ffd65ccffdecc850bce89a4d7ff1b538391951`, package SHA-256 `943b729de982c48a8019cf0dddda5a746e59d0d5be1ab0900784592981d215dd` as immutable qualification/live-test evidence.
* Wake detached PeerTube workers from durable task creation, keep a one-minute recovery-only schedule, recheck future work in short watcher slices, and bound incomplete-publication recovery to 24-hour windows inside a 168-hour hard cap with explicit Resume.
* Stage the confined WordPress original for PeerTube with its validated video MIME type, cancel redundant local FFmpeg once PeerTube owns the destination, render verified PeerTube authority before requiring local bytes, and clean only AWVP-generated/staging derivatives after verified cutover.
* Omit blank optional PeerTube publication fields, verify full remote publication state before/after consequential updates, avoid replay when the desired state is already proven, and keep uncertain mutations held.
* Add Overview / Status & Needs Attention with Media Library/post/author identity and upload progress; document `ARGENTWOLF_VIDEO_PROCESSOR_PEERTUBE_PRIVATE_ORIGINS` while retaining the legacy RC alias.

= 2.0.0-rc8 =
* Preserve canonical RC7 package SHA-256 `89bf6d73eb0e6466eb587eabbb01d822d980e1ecd84492475033aa7c27b2e10a` after its exact-package matrix and live web-UI player/finalizer gates passed.
* Ignore WordPress revision/autosave status transitions and require immutable origin-anchor ownership before a post transition may advance or revoke PeerTube publication lifecycle state; copied/reused non-anchor blocks remain display-only.
* Sort PeerTube category choices alphabetically by human-readable label without changing provider identifiers or cached catalog authority.

= 2.0.0-rc7 =
* Preserve canonical RC6 run 169 (internal ID 444), commit `7f8f7da2647d56a458d33367b36e4fe622281afd`, tree `d70134d0486fea81553f2dfe6e06b52dfc4c1ed4`, package SHA-256 `56800942972df47970208d2a14f93650252a9702abce747c1d45ce1304430324`; controlled live testing exposed the RC7 fixes below.
* Accept stock PeerTube/UploadX protocol-relative resumable `Location` responses without weakening same-origin/path/query validation; normalize default ports, classify rejected locations safely, and stop finalization polling at an explicit upload-indeterminate intervention boundary.
* Restore AWVP-owned native HLS playback for the dynamic video block, keep adaptive HLS primary, use generated MP4 only as an emergency compatibility fallback, and explicitly prohibit autoplay.
* Make PeerTube setup/publishing progress and next steps clearer, show provider choices by name, preserve free-form tag entry while editing, and condense explicit publication review to one user-facing checkbox.

= 2.0.0-rc6 =
* Preserve canonical RC5 run 166, commit `e9b2f725b3f06c20550b59044d58456774e7f8a3`, package SHA-256 `b68212fdedf25d190535b3e9a65d26cd1a7cfe18c07792eaef50208f5cb83c08` as immutable failed release evidence.
* Unslash and sanitize the read-only Local Retention notice directly at the request boundary while preserving nonce and retention behavior.
* Add focused regression coverage for that input-boundary sanitization; remediation commit `1a108800145f53230ba57fc0c954eeb892035b90` is green in Forgejo CI 167.

= 2.0.0-rc5 =
* Preserve canonical RC4 run 163, commit `7bac80f43fdc91bdedff01d52615cf14a658878d`, package SHA-256 `544d16307eb8e087a5b11dcfe024b11c2157be48731f0a7b585d84c593ec6f22` as immutable failed release evidence.
* Keep every WordPress.org upgrade notice within the 300-character limit and add regression coverage for the limit.
* Narrowly cover the already-sanitized read-only Local Retention notice selector for Plugin Check without changing nonce enforcement or runtime behavior; remediation CI 164 is green.

= 2.0.0-rc4 =
* Consolidate Local Processing, PeerTube Servers, Publishing, Video Migration, and Local Retention under one ArgentWolf Video Processor settings page.
* Clarify PeerTube server setup, Backend ID requirements, sign-in/OTP/token behavior, channel selection, activation phases, and field-specific validation messages; no manually generated API key is required.
* Display administrator-facing timestamps with WordPress timezone/date/time preferences and replace raw internal connection/lifecycle wording with friendly status labels.
* Preserve canonical RC3 qualification evidence; RC4 is a new candidate because the live RC3 administrator walkthrough identified user-interface defects requiring package changes.

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
