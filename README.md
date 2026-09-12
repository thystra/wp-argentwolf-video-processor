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

**Settings > ArgentWolf Video Processor** provides:

- queue and worker status;
- smart, adaptive-only, and force-reprocess backlog operations;
- diagnostics for binaries, codecs, HLS, and the browser player;
- output, path, and process-priority settings;
- manual worker launch;
- WP-CLI examples;
- a link to the GitHub project for support and development;
- bounded database-backed worker diagnostic history and retention controls.

The 2.0 line consolidates administration into the same **Settings > ArgentWolf
Video Processor** page with tabs for **Overview**, **Videos & Routing**, **Local Processing**, **PeerTube Servers**,
**Publishing**, **Video Migration**, and **Local Retention**. The PeerTube Servers
tab is available only to authenticated administrators with `manage_options`; its
connection and credential actions are nonce-protected and explicitly initiated.
Active PeerTube servers also expose an upload-segment tuning control: the default
is 128 MiB, `0` means one streamed resumable segment containing all remaining
bytes, and the accepted range is 0–8192 MiB. Saving this policy does not itself
start a transfer.

The R46 development plan treats video destination and PeerTube publication
metadata as explicit per-video state. Existing/legacy videos with no destination
metadata resolve to WordPress/local regardless of later site-default changes.
PeerTube-bound videos retain a local serving fallback until remote readiness and
the intended visibility are verified. PeerTube tags are reviewed independently
from WordPress post tags, and early/scheduled uploads remain private on PeerTube
until the WordPress post actually reaches its publication state. See
`docs/2.0/VIDEO-DESTINATION-PUBLICATION.md` for the frozen development contract.

R46.2 publishing defaults are exposed on the **Publishing** tab for new-video
authoring defaults. Its upgrade-safe default destination is WordPress/local. The
page can select a default active PeerTube backend, final privacy, licence/category
IDs, language, comments/download policy, send timing, reusable Markdown support
presets, moderation prefills, and per-backend channel/provider overrides. These
values only prefill new/unfrozen videos: they never rewrite existing video state,
and moderation/tags still require explicit per-video review.

R46.3a adds an explicit **Refresh choices** action for each active PeerTube
backend on that publishing page. It performs only bounded read-only discovery of
the authenticated account's owned channels plus the configured instance's public
privacy, licence, category, language, version, and moderation-capability
projection. Results are cached per backend as non-secret, non-autoloaded
last-known-good state bound to the configured origin and managed-credential
generation. A failed refresh never replaces provider data with an empty result:
the retained snapshot is persistently marked stale until a context-matching
refresh succeeds. Page load itself performs no PeerTube HTTP, and catalog
discovery does not create, update, publish, or delete a remote video.

R46.3b adds the first editor-facing foundation: one dynamic **ArgentWolf Video**
Gutenberg block. Selecting an existing WordPress video attachment binds it
idempotently to one hidden AWVP Video identity; the block itself serializes only
that stable AWVP Video ID. Its inspector can choose WordPress/local, resolve the
current site default, or select an active PeerTube backend as final destination
planning state. This checkpoint still renders the local WordPress attachment and
does not start a PeerTube upload, publish a remote video, or switch serving
authority. The detailed PeerTube publication/review wizard follows separately.

R46.7 migration controls are exposed on the **Video Migration** tab for planning existing local AWVP Videos. The active migration review queue appears before the longer discovery list. The planner can select individual videos or a bounded select-all batch, choose an owned PeerTube channel from the last-known-good catalog, and review per-video publication metadata. WordPress tags are suggestions only and more than five are never silently truncated. One explicit review acknowledgement covers the title, channel, tags, privacy, and sensitive-content declaration while the durable plan retains those individual review fields. Once a review is saved as ready, the one-way acknowledgement and **Start migration** action are offered inline. Planning writes only inert migration state; it does not change the live destination/publication plan, enqueue PeerTube work, or switch frontend serving.

The **Videos & Routing** tab is a read-only inventory of every AWVP Video, its Media Library attachment, every boundedly discovered WordPress post that references it, its configured primary destination, and its current serving source. The view also shows the site default primary destination for new videos and local source/retention state. Existing videos retain their concrete stored destination rather than following later default changes. A Backups column is present as a future-facing matrix surface, but this release still configures only one publication destination per video and does not enable multi-backend publishing.

R46.8 adds the explicit **Start migration** step for a `ready` plan. Starting is a one-way local commitment: AWVP revalidates current source/provider/default/thumbnail evidence, journals the exact migration plan, promotes its publication plan and target destination, and then invokes the existing durable publication synchronizer. Remote upload/publication and verified serving cutover continue through the already-qualified R46.5/R46.6 paths; R46.8 adds no parallel uploader or frontend path.

R46.9 retention controls are exposed on the **Local Retention** tab. WordPress is the Archive of Record by default, so automatic deletion of original Media Library videos is blocked unless an administrator explicitly changes that site-wide authority policy. The tab provides one **AWVP Default Policy** plus a compact searchable/filterable video list for bounded bulk application instead of requiring a full policy form for every video. An operator may keep all local copies, delete only AWVP-managed derivatives after the site grace period, or—only when WordPress is NOT the Archive of Record—also allow delayed original-source deletion after all currently required remote publications are positively verified. Destructive work still runs only in the detached durable worker after archive authority, grace period, serving evidence, local-job quiescence, and exact filesystem identity are revalidated. Physical-source cleanup never deletes the WordPress attachment record; any uncertainty keeps local data.

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

The 2.0 release-candidate line also has an explicit PeerTube task worker
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
launcher uses `--drain`. RC9 wakes that reviewed launcher when an owned durable
PeerTube task is created; a separate one-minute PeerTube recovery event is only a
missed-wake/incomplete-lifecycle safety net. The existing five-minute
`argent_video_processor_dispatch` event now remains local-FFmpeg-only. Neither
scheduler performs PeerTube media HTTP inline, and no administrator transfer-launch
action is added. The bounded
drain checkpoint is qualified at commit
`33bdd109da2f452afb2058ce0d044d10a729c669` (Forgejo CI 122 plus its retained
real-WordPress matrices), and durable failure notification is qualified at
`96fe661682accaa63e2860dc236cb9c1f4733950` / tree
`7f938a05c446e000b0d45db76e03e703432a10dc` with Forgejo CI 123 and the retained
notification/no-replay, drain, one-shot, and R44 matrices.

## Privacy

Metadata removal applies to generated derivatives and adaptive renditions. Local
processing does not alter the original source, which may retain its original
metadata. R46.9 retention defaults to keeping all local copies; physical source
deletion is available only after an explicit non-WordPress master-authority
decision, delayed verified PeerTube cutover, and the fail-closed cleanup checks.
The WordPress attachment record itself is preserved.

The public WordPress.org 1.0 release remains local and the plugin contains no
telemetry. The 2.0 release-candidate line adds an opt-in, operator-configured
PeerTube connection. Public instance detection contacts only that configured origin and
sends no credentials. An authenticated administrator with `manage_options` may
explicitly start, advance, or reconcile a durable connection operation and may
authorize one password-grant attempt per explicit submission from the
**PeerTube Servers** tab. Each connection/lifecycle action is POST-only and
nonce-protected; loading the page is read-only, and there is no AJAX, REST, cron,
activation, or automatic
connection invocation. The detached PeerTube media-task path does not bootstrap or refresh credentials. Before credentials are
sent, the administrator must explicitly authorize the displayed external
service. An allowlisted development-only plaintext HTTP origin requires a second
transport-risk acknowledgement. Private/split-DNS PeerTube origins that cannot
pass the public-origin rule must be explicitly allowlisted by exact canonical
origin in `wp-config.php` with
`ARGENTWOLF_VIDEO_PROCESSOR_PEERTUBE_PRIVATE_ORIGINS` (array of origins). RC-era
`ARGENT_VIDEO_PEERTUBE_DEV_ORIGINS` remains a compatibility alias; new
installations and documentation use the longer canonical constant.

The explicit grant sends the entered PeerTube username and password plus an
optional six-digit OTP only to that same exact origin. The instance-local OAuth
client is used transiently; the password, OTP, and OAuth client response are not
retained or reflected into the page, redirect, or notice. Returned access and
refresh tokens are authenticated-encrypted in a non-autoloaded server-side
option before the operation can advance. No media, selected media metadata, or
telemetry is sent by the connection bootstrap itself. The detached PeerTube media-task path can send an
explicitly staged source plus the selected private upload metadata only to the
configured PeerTube origin. Source bytes are transferred through PeerTube's
resumable protocol using the backend's configured segment policy; no telemetry
is added. A failed/held upload requiring human attention is represented by a
separate durable local notification task; detached drain execution sends a
sanitized email through the site's normal WordPress mail path to the initiating
user, with post-author fallback. The notification excludes credentials, secret
references, filesystem paths, and raw remote response bodies. The configured
service can observe ordinary HTTP transport metadata,
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

## Current release lines

Public WordPress.org stable release: `1.0.0`.

Current controlled development candidate: `2.0.0-rc13.2`. RC packages are built
from reviewed Forgejo commits and are not published to WordPress.org SVN. The
public Stable tag remains `1.0.0` until final `2.0.0` promotion.

Install public releases from WordPress.org or use the exact canonical ZIP
attached to the corresponding Forgejo release. Automatically generated source
archives are not canonical installable artifacts.

### RC10 live-test hardening

RC10 followed controlled RC9 live testing. It preserved RC9 package bytes and hardened the live PeerTube path: automatic credential/catalog authority repair, fresh reads in long-lived workers, dependency deferral without attempt exhaustion, semantic tag and short-embed verification, local-only recovery of already-applied publications, seven-step operator diagnostics, route-before-FFmpeg behavior, responsive no-autoplay rendering, explicit legacy 1.x migration/adoption, and a site-wide WordPress archive-of-record policy. Canonical RC10 candidate #2 was qualified and installed live; its bytes remain immutable historical evidence.

### RC13.1 scheduled-publication generation fencing (development)

RC13.1 is the first build in the RC13 series and fixes the release-blocking race preserved by live Test 8. Long-lived workers now force fresh WordPress lifecycle/post reads at publication authority boundaries; a superseded finalizer is fenced before PeerTube mutation, execution-state writes, public-health qualification, and serving cutover. When a scheduled post advances from `future` to `publish`, the current generation can adopt the already-verified upload/remote asset and recreate the exact missing finalizer without re-uploading or advancing another generation. Ready-but-unserved finalization gaps remain visible in **Status & Needs Attention**, and only a wholly missing finalizer is reconstructed automatically; ambiguous or terminal mutation outcomes remain fail-closed. A Private pre-publication staging pass no longer marks the reviewed final manifest as applied.

The RC naming policy now permits `X.Y.Z-rcN.M`: `N` identifies the RC series and `M` identifies each immutable source/package build within that series. Any source change after an RC13.1 package is frozen must become RC13.2 rather than another unlabeled candidate under RC13.1.

### RC12 live-publication finalizer repair (development)

RC12 preserves qualified RC11 as immutable live-test evidence and fixes defects exposed by the first fresh ~380 MB PeerTube publication. Reviewed description/support Markdown may contain LF/CRLF line breaks and must survive the bounded multipart PUT; RC11 incorrectly rejected those line breaks locally before transmission. RC12 also exposes that definite no-mutation finalizer boundary as an explicit, same-generation **Retry publication** action that requeues the exact failed finalizer without creating another upload or publication generation. Operator event timestamps now use the complete database format map, and the editor no longer exposes the historical `R46.3c` label in dispatch-timing help text.

### RC11 remote-health and recovery hardening (development)

RC11 carries the post-RC10 live findings into a new package identity: model-schema-3 visitor-facing publication health, provider-independent serving priority/failover, processing-aware readiness estimates, remote-health notifications, daily backend authority/catalog maintenance, restart-safe Republish, Overview reviewed/dismissed state, per-server default language, and scalable Local Retention default/bulk administration.

The post-qualification live-fix tranche adds provider-independent publication health and serving failover. AWVP periodically checks the actual unauthenticated public/embed URL that a visitor would receive; provider API state is supplementary diagnosis and cannot by itself make a publication eligible to serve. Health is stored independently from publication authority in model DB schema 3. Verified healthy remote publications are ranked by backend serving priority (local WordPress is priority 0), and the frontend resolves the highest-priority currently viable source without performing network requests during page rendering. Expected provider processing is a distinct non-failure state, with bounded size/history-based readiness estimates using at most the 10 most recent successful samples from the last 90 days.

Remote health incidents appear immediately on Overview. Backend-wide outages are deduplicated from individual-video failures, while missing/private/embed-disabled publications can notify the administrator, publishing user, and/or origin author according to Off / delayed / immediate policy. Daily PeerTube maintenance refreshes credential/catalog authority separately from per-video serving health. Republish can create a new publication generation from a retained WordPress original on the same or another configured PeerTube backend without rewriting historical task/asset/audit evidence.

Legacy discovery is read-only. A completed 1.x attachment becomes a 2.0 AWVP Video only when an administrator explicitly includes it in migration planning; historical post content is not rewritten. Verified serving authority can bridge supported historical Core Video/shortcode output at runtime. Original Media Library deletion remains forbidden while WordPress is the configured archive of record. Local Retention provides one site-wide AWVP Default Policy plus a searchable, filterable bulk list instead of requiring a full policy form for every video.

### R46.3c PeerTube publication review (development)

The next editor layer uses the qualified R46.3b block to edit the existing strict
per-video PeerTube publication plan. Provider-backed channel, privacy, licence,
category, and language selections come only from the R46.3a cached catalog; a
backend/origin context mismatch cannot authorize a save, and provider values that
AWVP cannot safely author (such as password-protected privacy without a password
lifecycle) remain visible but unselectable. PeerTube tags remain independent from
WordPress tags and support an explicitly reviewed zero-tag state.

The wizard also covers support preset/custom Markdown, thumbnail, comments,
download policy, optional original-publication time, sensitive-content review, and
`Send now` versus `Send when scheduled or published`. Saving this checkpoint
persists editorial intent only. It performs no PeerTube HTTP, starts no upload,
changes no WordPress post status, reveals no remote video, and does not change
frontend serving authority.

### R46.4 editorial publication gate (development)

R46.3c is qualified at exact tree
`67444badccb625a458f3436cc596557cfce16817`; Forgejo CI run 129 is green. R46.4
uses only local WordPress/AWVP state to decide whether an anchored AWVP block has
completed the required editorial decisions. A local or legacy-missing destination
does not add a PeerTube review requirement. A concrete PeerTube destination must
have a valid, matching publication plan with explicit title, channel, independent
tags, final privacy, and moderation review.

Gutenberg locks only publication-oriented saves (`publish`, `future`, `private`)
while those decisions are unresolved; ordinary draft editing remains saveable. The
server independently enforces the same rule at the REST pre-insert boundary and
uses a conservative pre-write fallback for non-REST status changes. Reused AWVP
blocks remain display-only on non-origin posts. This gate never checks PeerTube
reachability, cached catalog freshness, upload/task/transcoding state, or remote
readiness, so a reviewed post may publish while PeerTube is unavailable and the
local video remains the serving fallback.

### R46.5 publication lifecycle synchronization (development)

R46.5a is qualified at commit `7b639d8`, tree
`867a13ba23495bfacb7e2e065061c2ce34637842`; Forgejo CI run 131 is green. Its
WordPress-side synchronizer records only generation-fenced local lifecycle intent:
`future` may authorize early upload but remains PeerTube private, and actual
WordPress `publish` is the only state that can authorize the reviewed final
privacy. A later draft/pending/private/trash/reschedule generation supersedes an
older reveal generation without destructive queue cancellation.

R46.5b adds the detached executor for that intent. RC9 wakes the detached `--drain`
watcher from durable PeerTube task creation and retains a one-minute recovery-only
schedule; the qualified diagnostic `--once` set remains upload/reconciliation only.
Sync freezes a non-secret execution manifest from the strict reviewed plan, current
backend catalog/default/support context, and immutable thumbnail identity. It stages
a confined copy of the WordPress original with its validated video MIME type and
hands that source to the existing resumable uploader, whose creation request is
privacy `3` (Private); local AWVP derivatives are not publication-source authority.

Finalization waits for the existing upload/reconciliation journal to reach
`ready_verified`. Immediately before mutation it revalidates the current lifecycle
generation, plan commitment, concrete destination, fresh provider authority, and
actual anchor post. After any non-private PeerTube update it checks WordPress again;
if publication authority changed during the network request, it immediately sends a
privacy-only correction to Private and positively verifies that correction. A
per-video execution lock serializes adjacent lifecycle generations' remote work.
Indeterminate mutation is held rather than replayed, and provider edits requiring
an ambiguous destructive clear fail closed. R46.5b is qualified at commit `8a7685b`, tree
`c6b0db36226bf17738840b3474afdb05af3529c5`; Forgejo CI run 132 is green.

### R46.6 verified serving cutover (development)

R46.6 adds no remote mutation. After publication finalization has already verified
an exact ready PeerTube asset in its intended public/unlisted privacy, a local-only
cutover writer freezes serving evidence on the AWVP Video. The frontend resolver
then independently re-checks the current lifecycle generation and plan hash,
concrete destination/channel, applied publication execution, actually published
anchor post, remote asset identity/state/privacy/verification timestamp, and exact
embed URL before rendering a PeerTube iframe. Any mismatch immediately uses the
existing local WordPress shortcode/Renderer path. Private and PeerTube-internal
privacy remain local because AWVP cannot assume WordPress viewers share PeerTube
authentication/audience membership. No provider HTTP occurs while rendering.
