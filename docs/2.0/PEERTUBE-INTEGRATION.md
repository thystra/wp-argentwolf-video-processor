# AWVP 2.0 PeerTube Integration Contract

Status: scope revision derived from PeerTube installation/integration testing
Applies to: initial AWVP 2.0 PeerTube implementation
Supersedes: conflicting earlier assumptions that make AWVP the PeerTube-side
transcoder or make browser-to-PeerTube upload the initial/default architecture

## 1. Core responsibility split

For PeerTube-backed media:

**AWVP stages and orchestrates. PeerTube processes and serves.**

AWVP owns temporary source staging, source inspection, human orientation
confirmation, destination/backend/channel selection, WordPress-side metadata and
publication intent, server-to-server transfer, processing-state tracking, editor
integration, authenticated PeerTube library browsing, and unmanaged external
PeerTube embedding.

PeerTube owns CPU-heavy transcoding, configured runners, bitrate/resolution and
HLS generation, thumbnails, storyboards, podcast/audio derivatives where
enabled, durable PeerTube serving/storage lifecycle, and federation/publication
behavior.

The local AWVP backend remains separate and continues to use the existing local
FFmpeg/HLS engine.

## 2. Default new-upload path: stage then push

```text
Browser
  -> authenticated WordPress/AWVP upload
  -> temporary AWVP staging
  -> inspect source
  -> orientation confirmation when needed
  -> select configured PeerTube backend/channel
  -> AWVP server pushes source to PeerTube
  -> PeerTube accepts ingest
  -> PeerTube processes/transcodes
  -> AWVP reconciles returned identity/state
  -> WordPress renders/inserts the managed PeerTube video
```

Persistent PeerTube credentials remain server-side.

The staged original stays unchanged until AWVP has enough inspection/orientation
information to choose the transfer object. It is not deleted until PeerTube has
positively accepted the source according to the verified upload protocol.

This staging-cleanup gate is distinct from later destructive source-retention
cleanup. Positive upload acceptance does not authorize removal of every other
authoritative/local source.

## 3. AWVP does not pre-transcode for PeerTube by default

AWVP sends the best suitable source rather than generating a PeerTube-specific
delivery rendition first. AWVP-side FFmpeg work should normally be limited to
ffprobe inspection, representative-frame extraction, orientation previews, safe
metadata/container normalization, and lightweight remuxing where sufficient.

Do not create a second normal full transcoding pipeline on the WordPress host.
If a required correction genuinely needs pixel decoding/re-encoding, make that
cost/state explicit rather than silently treating WordPress as the normal
PeerTube transcoder.

## 4. Absolute visual orientation contract

Rotation/display metadata is a hint, not final authority.

For a staged source requiring orientation confirmation:

1. preserve the original staged source;
2. extract a representative frame with autorotation disabled;
3. generate four previews from the same stored pixel frame: 0, 90 clockwise,
   180, and 90 counterclockwise;
4. display all four simultaneously;
5. show metadata-suggested orientation only as a hint;
6. the user selects the actually upright presentation;
7. record that absolute intended orientation;
8. plan the lightest safe correction;
9. transfer corrected/remuxed content or the untouched source when no correction
   is needed;
10. retain the original staging source until PeerTube acceptance is confirmed.

Do not model this merely as relative rotate-left/rotate-right arithmetic.

## 5. Direct browser-to-PeerTube upload is deferred

Browser -> PeerTube is not the initial/default architecture. It may be
reconsidered later for very large files if a verified PeerTube version provides
an appropriately scoped authorization and the cross-origin/retry/security model
is acceptable.

Initial resumability work prioritizes **AWVP server -> PeerTube resumable upload**
where useful.

## 6. Three editor workflows

### Upload New

Select source -> AWVP staging -> inspect/orient -> choose backend/channel -> set
supported privacy/publication metadata -> push -> monitor -> insert managed
PeerTube video.

### PeerTube Library

AWVP queries a configured backend with server-side credentials. The editor can
browse/search videos visible/manageable by the authenticated account/channel,
including thumbnail, title, channel, date, duration, privacy, and processing
state where available. Selection creates/reuses an AWVP Video plus managed
remote-asset reference and uploads nothing.

### PeerTube URL

The editor may paste a PeerTube watch URL from any origin instance. AWVP
validates/normalizes the URL, queries public metadata where available, previews
it, verifies embed usability as far as the origin permits, creates an AWVP Video
external-reference record, and renders the embed. Successful embedding grants no
management authority.

## 7. Managed asset versus external reference

A configured-backend video is a **managed backend asset**. An arbitrary PeerTube
URL is an **external reference**.

Do not invent a fake `backend_id` for an unconfigured origin. The current
remote-assets table is for assets tied to configured backend identity and
management/reconciliation state. External references need a separate bounded
persistence representation before block/editor implementation. AWVP Video remains
the stable WordPress-side identity in both cases.

## 8. Multi-PeerTube behavior

Multiple independent PeerTube connections remain first-class. Each configured
instance owns its own server URL, credential reference, health/API capability
state, channels/destinations, quotas/limits, and publication/storage defaults.
Backend selection and channel selection are separate.

One genuinely unambiguous eligible destination may be automatic. Multiple
eligible configured destinations require explicit selection unless a valid
post-level default exists. Do not route by WordPress category/tag. External URL
embedding bypasses managed-destination selection because it is not an upload or
management operation.

## 9. PeerTube storage ownership

Treat PeerTube-generated HLS/media derivatives as PeerTube-owned outputs. Do not
edit generated playlists/media in place. PeerTube original-source retention is a
backend/storage-policy capability, not a reason to retain duplicate WordPress
staging forever. During integration, retaining PeerTube-side input/original is
useful for comparing exact transferred source with generated outputs. Production
retention belongs in the storage-profile tranche.

## 10. Publication/privacy

AWVP stores desired WordPress-side publication intent and reconciles actual
PeerTube state. PeerTube owns backend-side private/public transitions and
derivative serving. Upload should normally establish a safe non-public backend
state first when scheduled WordPress publication requires later synchronized
visibility. Exact privacy mappings belong to the verified PeerTube API tranche.

## 11. Revised tranche impact

### Tranche 2.0-2 — backend registry/local adapter
Still required. Add managed-backend versus external-reference distinction and
capabilities for staged ingest, server push, account library, and existing-asset
selection.

### Tranche 2.0-3 — PeerTube connection/API
Authenticated connection; API/version/capability negotiation; channels; quotas
where exposed; authenticated account/channel video listing/search; public video
metadata lookup for external URL preview/embed; verified upload protocol,
favoring resumable server-to-server transfer for large files.

### Tranche 2.0-4 — upload/state
Temporary AWVP staging; ffprobe/source inspection; absolute orientation
confirmation; correction/remux if needed; server-to-server upload;
accepted/processing/ready reconciliation; staging cleanup after positive
acceptance; no routine AWVP-side delivery transcode for PeerTube.

### Tranche 2.0-5 — profiles/retention
Distinguish temporary staging, optional WordPress/local source, PeerTube-retained
original, PeerTube delivery derivatives, and external embed with no owned source.

### Tranche 2.0-6 — block/editor/metadata
Add Upload New, PeerTube Library, and PeerTube URL plus persistence for unmanaged
external references.

### Former direct browser-upload tranche
No longer an initial-release milestone. Direct browser -> PeerTube is a later
optimization after staged server-push is proven.

## 11.1 R42 pre-mutation staged-upload state foundation

The first tranche 2.0-4 checkpoint intentionally stops before the first remote
video creation request. R42 introduces four runtime contracts that later upload
execution must use:

1. `PeerTube_Staged_Source_Identity` captures only a plugin-managed relative
   staging path, byte count, and SHA-256, and can re-prove that exact source
   before a consequential transfer;
2. `PeerTube_Staged_Upload_State_Machine` immutably binds the AWVP Video,
   backend, canonical origin, destination, and source into a durable intent and
   requires a committed attempt fence before any future upload request;
3. `PeerTube_Staged_Upload_Operation_Store` persists those records in a bounded,
   non-autoloaded, whole-option exact-CAS journal and rejects duplicate intent
   commitments;
4. `PeerTube_Staged_Upload_Guard` read-only re-proves that the configured
   descriptor is still active with the exact origin/destination and that the
   staged bytes still match the operation.

The state machine distinguishes `upload_in_flight`, explicitly classified retry-safe outcomes,
`retry_wait`, `upload_indeterminate`, `remote_created`, `remote_committed`,
processing, positive ready verification, and cleanup. There is deliberately no
"remote not found -> retry" transition from an indeterminate upload in R42.
Until a later checkpoint proves a reliable backend reconciliation/idempotency
mechanism, an uncertain creation request cannot be silently replayed.

A successful future upload response first records the exact PeerTube video ID
and UUID as `remote_created`. Only a separately persisted remote-asset row may
advance the operation to `remote_committed`. Source cleanup is still forbidden
until that committed asset is positively verified ready.

R42 performs no PeerTube upload request, creates no staging file, changes no
remote asset, launches no worker/task, and exposes no browser/admin transfer
authority. `ingest.awvp_staging`, `ingest.server_push`, and `processing.video`
remain false in the PeerTube adapter.

## 12. Security boundary

Persistent PeerTube management credentials stay on the AWVP server. Editor
JavaScript talks to authenticated WordPress/AWVP endpoints; AWVP server code talks
to PeerTube. External public metadata/embed lookup is untrusted remote input and
must use appropriate URL/SSRF validation and output escaping when implemented.

## 13. Initial acceptance principles

1. staging source is not destroyed before positive backend acceptance;
2. no persistent PeerTube credential is exposed to browser JavaScript;
3. orientation selection is absolute/visual, not relative metadata arithmetic;
4. PeerTube upload does not trigger a routine local AWVP full transcode;
5. server-to-server upload can be resumed/reconciled where the verified API
   supports it;
6. remote identity is durably captured after acceptance;
7. authenticated library selection performs no upload;
8. arbitrary external PeerTube embed creates no fake configured backend;
9. management/destructive actions are unavailable for unmanaged references;
10. multi-backend ambiguity fails closed before transfer;
11. staging cleanup, source retention, and delivery verification are separate
    lifecycle gates;
12. local AWVP processing remains independent and regression-tested.
