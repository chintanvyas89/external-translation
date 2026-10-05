# Glossary

Domain-specific terms used throughout this documentation set. If a term below links to another doc, that's where it's covered in depth.

---

**Content entity**
A Drupal entity with fields that can be translated per-language using Drupal core's own `content_translation` system (nodes, media, taxonomy terms, and — with a twist, see **shared block** — `block_content`).

**Field slot**
One specific translatable value: a single property (e.g. `value`, `alt`, `title`) of a single delta of a single field, on a single entity translation. The unit everything in this system tracks and protects individually. Represented in code as [`TranslationFieldContext`](developer-guide.md#translationfieldcontext).

**Provenance**
The recorded history of one field slot: where its current value came from (machine translation, editor edit, or an untouched copy of the source) and whether it has been reviewed. Stored in the `translation_provenance` table. See [data-model.md](data-model.md).

**Origin**
Provenance column: *how* a field slot's current value came to be — `machine_translated`, `machine_accepted`, `editor`, `editor_modified`, or `source_copy`.

**Status**
Provenance column: the field slot's *review state* — `machine_translated` (fresh, unreviewed), `approved`, `needs_review`, `stale`, or `copied_from_source`.

**Source copy**
A translation value that is byte-identical to the current source-language value — i.e. the untouched placeholder Drupal core seeds every new translation with. Treated as a translation *seed*, not protected editor content, so the first machine translation is free to overwrite it directly instead of being diverted into a proposal.

**Conflict**
What happens when a fresh machine translation would overwrite a field slot whose live value has diverged from the last known provider output (or has no baseline at all and isn't a source copy or empty). Conflicts never silently overwrite — they produce a **proposal** instead.

**Proposal**
A pending, not-yet-applied machine translation for a field slot that already has protected content. Stored in `translation_proposal`, resolved via Accept / Keep / Edit. See [architecture.md](architecture.md#the-conflict--proposal-model).

**Accept / Keep / Edit**
The three ways a human resolves a proposal: **Accept** writes the machine text as-is; **Keep** discards the proposal and leaves the current value untouched; **Edit** writes a human-supplied replacement (neither the old value nor the raw machine text).

**Moderation gate**
The rule that a machine or proposal-accept write to a currently-*published* moderated entity never republishes it directly — it's written into a new, non-default revision in a draft (or configured review) state instead, so nothing goes live without human review. See [architecture.md](architecture.md#moderation-gating).

**Async submit/poll/complete**
The two-phase translation lifecycle: a batch of text is *submitted* to the provider immediately (returns fast), and *completed* later once a poll (cron, or a manual "Refresh now") finds the provider ready. Modeled after how a real HTTP translation API behaves — never assumes an instant response. See [architecture.md](architecture.md#async-lifecycle-submit--poll--complete).

**Pending batch**
One outstanding async translation request, tracked in `translation_pending_batch` until a poll finds it ready. Two kinds: `job_item` (a TMGMT job item's ordinary fields) and `layout_inline_blocks` (a host entity's Layout Builder blocks/labels).

**Inline block**
A Layout Builder "block" that is actually a full `block_content` entity, created on the fly and stored per-placement rather than reused site-wide (as opposed to a *reusable* block, which behaves like any ordinary translatable content entity and needs no special handling here).

**Shared block**
Under this system's default model, a newly-added translation's Layout Builder layout starts out referencing the *exact same* `block_content` id/revision as its source language — not a copy. See [layout-builder-integration.md](layout-builder-integration.md#shared-by-default).

**Fork-on-write**
The moment a shared block actually diverges: the first time either a human hand-edits it through Layout Builder's own UI, or the machine-translation pipeline writes to it, in either case as a *new revision of the same block id*, with only the editing translation's own layout component repointed to it. See [layout-builder-integration.md](layout-builder-integration.md#fork-on-write).

**Component label**
The optional heading shown above a Layout Builder block ("Display title"). Stored as config on the *host* entity's own layout field, not as a field on the block — so it's translated through a separate, non-entity-field code path from the block's own content. See [layout-builder-integration.md](layout-builder-integration.md#component-labels).

**Staleness**
A field slot whose source value has changed since it was last translated. Detected automatically for Layout Builder blocks (a direct edit to the English source block triggers an automatic re-translation of every language that already translated it). See [layout-builder-integration.md](layout-builder-integration.md#staleness-detection).

**Nested inline block**
A block that itself has a Layout Builder layout field containing further inline blocks (a block inside a block, to a configurable depth). See [layout-builder-integration.md](layout-builder-integration.md#nested-inline-blocks).

**Referenced entity following**
Machine translation automatically following an `entity_reference` field on a block (e.g. to a Media item) to translate the *referenced* entity's own trackable fields (e.g. a Media item's `alt`/`title`), to a configurable depth. See [layout-builder-integration.md](layout-builder-integration.md#referenced-entity-translation).

**Interface string**
A Drupal UI string translated through core's own Locale system (`/admin/config/regional/translate`), not tied to any content entity. Covered by a separate submodule that reuses the same provenance/proposal engine against a pseudo entity type (`interface_string`). See [interface-string-translation.md](interface-string-translation.md).

**Trackable field properties**
The explicit allow-list of field type → property names this system will ever read or write (e.g. `text_long` → `value`; `image` → `alt`, `title`). Deliberately narrow: anything not listed is left alone. See [developer-guide.md](developer-guide.md#trackable-field-properties).

**Provider**
The external machine translation service, accessed through [`TranslationApiClientInterface`](developer-guide.md#translationapiclientinterface). The POC ships a deterministic `MockTranslationApiClient` (`"[fr] " + source text`); a real vendor is a drop-in replacement.
