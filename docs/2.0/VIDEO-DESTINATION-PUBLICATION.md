# Video destination and publication contract

This document freezes the R46 destination/publication behavior before editor,
migration, or remote-publication mutation is enabled.

## 1. Destination semantics

Each AWVP Video has one selected final destination. The built-in local WordPress
backend is a real destination (`local`); PeerTube and future remote backends use
stable backend IDs plus any backend-specific channel/destination identifier.

A site-wide default is an authoring convenience for **new** videos only. It is
never a live pointer consulted when resolving an existing video's destination.
Changing the site default therefore cannot reroute existing videos.

Legacy/upgraded content is fail-safe:

- if an existing video has no `_argent_video_destination` metadata, its effective
  destination is WordPress/local;
- malformed *present* destination metadata is an error and must not silently
  fall back to the current site default or another remote backend;
- later migration may explicitly select another backend, but changing defaults
  alone is never a migration.

One AWVP video block is preferred over separate Local/PeerTube block types. A
future block inspector may select `Use site default`, `WordPress / local`, or a
specific configured remote backend. Once work is created, the resolved backend
is frozen for that operation.

## 2. Selected destination is not current serving authority

A PeerTube-bound video may continue serving its local WordPress copy while the
remote upload, processing, moderation/publication state, and readiness are being
prepared. R46 must not equate `destination = PeerTube` with `serve PeerTube now`.

The intended handoff is:

1. local copy remains playable;
2. upload/processing occurs in the background;
3. remote readiness and intended publication state are positively verified;
4. serving authority changes to the PeerTube asset;
5. local copies become eligible for a separately configured retention/cleanup
   policy.

Remote readiness alone never blocks WordPress publication. If remote work fails,
the post may remain published and the local copy continues serving while AWVP's
durable failure notification reports the problem.

## 3. PeerTube publication metadata is independent

PeerTube publication metadata is not a projection of WordPress post taxonomy.
In particular, WordPress post tags may be offered as suggestions, but PeerTube
video tags are an independent list and must be explicitly reviewed by the
editor/migration wizard. Zero tags is valid only after explicit review. No more
than five PeerTube tags are permitted in the R46 plan.

The per-video PeerTube publication plan covers at least:

- backend and channel;
- title;
- Markdown description;
- up to five explicitly reviewed tags;
- support text selection (`none`, a later site preset, or custom Markdown);
- final privacy selected from the target backend's vocabulary;
- licence, category, and language where selected/supported;
- optional WordPress attachment to use as the remote thumbnail/cover source;
- download-enabled policy and original-publication timestamp where applicable;
- comments policy;
- sensitive-content declaration and review, including explanatory text and the
  supported violent/sexually-explicit indicators;
- optional embed-domain restriction;
- dispatch timing and WordPress publication anchor.

Backend vocabulary identifiers are retained as opaque provider values until the
backend-default/capability tranche resolves and displays their labels. AWVP must
not invent or silently remap a PeerTube channel/licence/category/privacy value.

Captions and chapters are intentionally deferred from the initial R46 editor
scope. Their eventual model must be additive rather than an opaque field hidden
inside the initial publication plan.

## 4. Review gates versus remote readiness

A PeerTube-bound block may prevent a post from being published while required
**editorial decisions** remain unresolved. Required review includes title,
channel, PeerTube tags, final privacy, and moderation/sensitive-content status.

This is different from remote readiness:

- unresolved required metadata/review: may block publication and must explain the
  exact missing fields;
- remote upload/transcoding/visibility still in progress after review: does not
  block publication; serve local until cutover succeeds.

## 5. Dispatch timing

After required PeerTube metadata is reviewed, the author chooses one of two
policies:

### Send now

AWVP may create/freeze the remote upload operation while the WordPress post is
still a draft. This is useful for large videos that should finish processing
well before publication.

### Send when scheduled or published

Ordinary draft/pending edits perform no remote media mutation. The upload is
created when the anchor post becomes:

- `future` (scheduled);
- `publish`; or
- `private` where the selected publication policy permits it.

Scheduling starts upload at the time the post becomes scheduled, not at the
future publication instant. This gives the remote backend time to process while
the post waits.

## 6. Prepublication PeerTube visibility

Early upload must not spoil scheduled content. A video whose final PeerTube
privacy will expose it publicly is uploaded/kept in a private prepublication
state while the WordPress post is draft, pending, or scheduled.

The actual WordPress post-status transition is authoritative for reveal. A
scheduled timestamp by itself must not expose the PeerTube video because
WordPress cron/publication may be delayed.

At actual WordPress publication:

1. enqueue a durable remote-visibility update; do not call PeerTube inline from
   a post-status hook;
2. keep serving the local copy during the visibility mutation;
3. verify the remote video has the intended final privacy and is ready;
4. only then switch serving authority to PeerTube.

Rescheduling or reverting a scheduled post to draft must cancel/supersede any
pending reveal intent. A WordPress-private post must never be made public on
PeerTube merely because a site default says Public.

## 7. Existing-video migration

Upgrading from 1.x does not migrate media. Legacy videos remain local until an
operator explicitly starts migration.

The migration wizard will support individual selection and select-all of
eligible videos, choose a target backend/channel, and require publication
metadata review for each video. Ambiguous or invalid items enter a **Needs
Review** set rather than silently discarding metadata (for example, choosing five
from more than five suggested WordPress tags).

Local-to-PeerTube migration is logically one-way. AWVP does not promise that the
original upload can later be reconstructed from PeerTube transcoded renditions.
Operators should maintain original masters/archives outside the blog platform.

The local copy remains serving authority until remote readiness/publication
verification succeeds. Only after cutover may the local source/derivatives enter
retention cleanup. PeerTube-to-local restoration is not part of R46; any future
remote rendition import is a new import/copy operation, not reversal of the
migration.

## 8. R46 checkpoint sequence

- **R46.1** — destination + publication-plan model and legacy-local resolution;
  no editor, migration, upload, or publication mutation authority.
- **R46.2** — site/backend defaults: default destination, channel, privacy,
  licence, language, category, comments/moderation defaults, and reusable support
  presets.
- **R46.3a** — explicit read-only publication-choice discovery for the selected
  PeerTube backend, with a bounded non-secret last-known-good catalog.
- **R46.3b** — one AWVP Gutenberg block foundation and destination selector.
- **R46.3c** — PeerTube publication wizard with independent tags,
  metadata/moderation review, and `Send now` / `Send when scheduled or published`
  choice.
- **R46.4** — WordPress editorial validation for unresolved required metadata;
  remote readiness remains non-blocking.
- **R46.5** — prepublication private visibility lifecycle and durable
  WordPress-authoritative reveal.
- **R46.6** — local-first serving and verified PeerTube cutover.
- **R46.7** — existing-video migration planner and Needs Review workflow.
- **R46.8** — one-way local-to-PeerTube migration execution.
- **R46.9** — post-cutover local retention/cleanup policy.

## 9. Current R46.1-R46.3c implementation boundary

R46.1 introduces the per-video model foundation:

- `Video_Destination` defines the canonical binding and the legacy missing-value
  rule (`missing => local`, malformed-present => invalid);
- `_argent_video_peertube_publication_plan` stores an editable, versioned
  PeerTube publication plan;
- `PeerTube_Publication_Plan` strictly validates the plan and separately reports
  whether required review is complete;
- zero reviewed tags are valid, but more than five or duplicate tags are not;
- sensitive-content/moderation review is explicit;
- the only release policy is `when_wordpress_published`.

R46.2 adds authoring defaults without changing any existing video:

- `argent_video_processor_video_publishing_defaults` is non-autoloaded and
  versioned; an absent option resolves to WordPress/local plus conservative
  authoring defaults without writing the database;
- a present malformed/future option fails closed and is not overwritten by the
  current administrator form;
- site defaults include final PeerTube privacy, optional licence/category,
  language, comments/download policy, send timing, support preset, and a
  sensitive-content *prefill*;
- backend overrides can select a channel and override provider privacy/licence/
  category values for an active PeerTube backend;
- reusable support presets have stable IDs, labels, and Markdown; resolving a
  preset produces a value snapshot so a later preset edit cannot mutate an
  already-frozen operation;
- the sensitive-content default never carries a `reviewed` flag and therefore
  cannot satisfy the per-video moderation-review gate;
- Settings > AWVP Video Publishing is `manage_options` + nonce protected and
  performs no PeerTube HTTP.

R46.3a adds only explicit read-only provider-choice discovery:

- each active PeerTube backend has a separate administrator **Refresh choices**
  action; loading the settings page itself performs no PeerTube HTTP;
- the managed bearer is used only to establish the authenticated local identity;
  owned-channel discovery and public provider vocabularies/configuration use the
  existing reviewed read-only API boundary;
- only a bounded non-secret projection is cached per backend: owned channels,
  privacy/licence/category/language choices, server version, refresh time,
  managed-secret generation, and conservative moderation/privacy capability
  signals;
- catalog options are non-autoloaded and last-known-good. Context is bound to
  backend + canonical origin + managed-secret generation; a refused, failed, or
  malformed refresh preserves valid provider data and marks it stale rather than
  replacing it with an empty catalog;
- catalog discovery never creates, updates, publishes, or deletes a remote video
  and does not change backend capability advertisement.

R46.3a is qualified at commit prefix `0a13687`, tree
`3587e3d15fef45d2776f3273ad517b26dc1aebe6`; Forgejo CI run 127 is green.

R46.3b adds only the single-block editor and destination-selection foundation:

- `argentwolf-video-processor/video` is a dynamic block registered from canonical
  `block.json`; serialized block state contains only the stable AWVP Video ID;
- selecting an existing WordPress video attachment passes through a purpose-built
  REST application boundary and adopts/binds it to one hidden AWVP Video identity.
  A per-attachment non-autoloaded claim option serializes plugin-owned adoption so
  duplicate requests converge instead of intentionally creating multiple assets;
- the origin post is written only on first adoption. Reusing the same attachment
  from another post returns the established AWVP Video and never silently changes
  that origin;
- a new binding resolves the current site default once into concrete destination
  state. Existing bindings are identified before current defaults are consulted, so
  later default changes or malformed/future defaults cannot reroute or invalidate
  an already-bound video;
- the block inspector may store WordPress/local, the currently resolved site
  default, or an active PeerTube backend. The concrete backend channel currently
  comes from the qualified backend/default override; channel-level publication UI
  remains R46.3c work;
- frontend rendering still uses the local WordPress attachment through
  `wp_video_shortcode()`, retaining the established local `Renderer` compatibility
  path regardless of selected final destination;
- the editor REST/controller path performs no PeerTube HTTP and owns no task
  dispatch or publication transition authority.

R46.3b still does **not** add the PeerTube publication wizard, remote upload,
post-status hooks, visibility mutation, migration planner/execution, serving
cutover, or cleanup behavior.

### R46.3c implemented editor boundary

R46.3b is qualified at commit prefix `4905076`, tree
`67054ea387ec90c484da01e437010772aad08a13`; Forgejo CI run 128 is green.
R46.3c builds the publication wizard inside that one AWVP Video block without
changing its serialized attributes.

For a PeerTube destination, the inspector edits the existing strict publication
plan: title and Markdown description, owned channel, independent PeerTube tags,
support selection, privacy/licence/category/language, optional thumbnail, comments,
download policy, optional original-publication time, moderation/sensitive-content
state, and dispatch timing. Provider choices come from the backend-scoped R46.3a
catalog and are revalidated when saved. Advertised privacy modes that need an
unimplemented lifecycle, such as password protection, are shown as unsupported
rather than silently enabled. The current PeerTube core upload/update contract has
no reviewed per-video allowed-domain field used by this implementation, so the
frozen embed policy stays explicitly unrestricted rather than inventing provider
state.

Title, channel, tags, privacy, and moderation review remain explicit and are never
inherited from defaults. Zero PeerTube tags is valid after explicit tag review.
Changing a required reviewed field clears that review in the draft, and a concrete
destination/stored-plan channel mismatch clears channel review. Saving may make the
plan `ready_for_dispatch`, but this is editorial readiness only: R46.3c does not
start an upload, call PeerTube, queue a task, alter post status/remote visibility,
or change local serving authority.
