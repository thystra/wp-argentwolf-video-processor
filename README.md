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

The R46 development plan treats video destination and PeerTube publication
metadata as explicit per-video state. Existing/legacy videos with no destination
metadata resolve to WordPress/local regardless of later site-default changes.
PeerTube-bound videos retain a local serving fallback until remote readiness and
the intended visibility are verified. PeerTube tags are reviewed independently
from WordPress post tags, and early/scheduled uploads remain private on PeerTube
until the WordPress post actually reaches its publication state. See
`docs/2.0/VIDEO-DESTINATION-PUBLICATION.md` for the frozen development contract.

R46.2 adds a separate **Settings > AWVP Video Publishing** page for new-video
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

R46.7 adds **Tools > AWVP Video Migration** for planning existing local AWVP Videos. The planner can select individual videos or a bounded select-all batch, choose an owned PeerTube channel from the last-known-good catalog, and review per-video publication metadata. WordPress tags are suggestions only and more than five are never silently truncated. Planning writes only inert migration state; it does not change the live destination/publication plan, enqueue PeerTube work, or switch frontend serving.

R46.8 adds the explicit **Start migration** step for a `ready` plan. Starting is a one-way local commitment: AWVP revalidates current source/provider/default/thumbnail evidence, journals the exact migration plan, promotes its publication plan and target destination, and then invokes the existing durable publication synchronizer. Remote upload/publication and verified serving cutover continue through the already-qualified R46.5/R46.6 paths; R46.8 adds no parallel uploader or frontend path.

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
launcher uses `--drain`. R45.5 wires that reviewed launcher to the plugin's
existing five-minute `argent_video_processor_dispatch` event: the cron callback
performs only the due/stale task probe and detached WP-CLI launch, never PeerTube
media HTTP inline. No administrator transfer-launch action is added. The bounded
drain checkpoint is qualified at commit
`33bdd109da2f452afb2058ce0d044d10a729c669` (Forgejo CI 122 plus its retained
real-WordPress matrices), and durable failure notification is qualified at
`96fe661682accaa63e2860dc236cb9c1f4733950` / tree
`7f938a05c446e000b0d45db76e03e703432a10dc` with Forgejo CI 123 and the retained
notification/no-replay, drain, one-shot, and R44 matrices.

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

## Current stable release

Current stable release: `1.0.0`, published through WordPress.org.

Install from WordPress.org or use the exact ZIP attached to the tagged Forgejo
release. Automatically generated source archives are not the canonical
installable release artifact.

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

R46.5b adds the detached executor for that intent. The existing five-minute AWVP
dispatch event and detached `--drain` launcher own the new publication sync/finalize
task types; the qualified diagnostic `--once` set remains upload/reconciliation
only. Sync freezes a non-secret execution manifest from the strict reviewed plan,
current backend catalog/default/support context, and immutable thumbnail identity;
it stages or reuses an AWVP-managed MP4 and hands the operation to the existing
resumable uploader, whose creation request is privacy `3` (Private).

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
