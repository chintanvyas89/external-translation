# Developer Guide

Service reference, extension points, and conventions for working on this codebase. Read [architecture.md](architecture.md) first for the system-level design this reference assumes.

## Service reference

### `tmgmt_external_translation` (core)

| Service | Class | Purpose |
|---|---|---|
| `tmgmt_external_translation.translation_api_client` | alias → `.mock_client` | The provider contract. Swap the alias target to point at a real vendor — see [Adding a real translation provider](#adding-a-real-translation-provider). |
| `tmgmt_external_translation.hash_manager` | `TranslationHashManager` | Deterministic hashing used throughout for "has this value changed" comparisons. |
| `tmgmt_external_translation.provenance_manager` | `TranslationProvenanceManager` | Reads/writes `translation_provenance`. See [data-model.md](data-model.md). |
| `tmgmt_external_translation.field_value_reader` | `TranslationFieldValueReader` | Reads a field slot's *live* value off a loaded entity, for conflict comparison and UI display. |
| `tmgmt_external_translation.conflict_detector` | `TranslationConflictDetector` | The single source of truth for "is this safe to overwrite" — see [architecture.md](architecture.md#the-conflict--proposal-model). |
| `tmgmt_external_translation.moderation_gate` | `ModerationGate` | See [architecture.md](architecture.md#moderation-gating). |
| `tmgmt_external_translation.entity_writer` | `TranslationEntityWriter` | Generic "create the target translation if missing, then write" — used for referenced-entity translation (Media, etc.), not for blocks. |
| `tmgmt_external_translation.proposal_manager` | `TranslationProposalManager` | Create/resolve proposals; the single `resolveProposal()` entry point every review UI calls. |
| `tmgmt_external_translation.request_helper` | `TranslationRequestHelper` | Backs the "Also request machine translation" checkbox on the Add Translation form. |
| `tmgmt_external_translation.job_item_batch_completer` | `JobItemBatchCompleter` | Applies a completed `kind=job_item` async batch. |
| `tmgmt_external_translation.polling_service` | `TranslationPollingService` | `processAllPending()` — the one method both `hook_cron()` and every "Refresh now" button call. |

### `tmgmt_external_translation_layout_builder`

| Service | Class | Purpose |
|---|---|---|
| `tmgmt_external_translation_layout_builder.entity_collector` | `LayoutEntityCollector` | Reads/writes Layout Builder section config: `collectInlineBlocks()`, `getComponentLabel()`/`setComponentLabel()`, `repointComponent()`, `findComponentForBlock()`. The one class that touches raw Section/SectionComponent API. |
| `tmgmt_external_translation_layout_builder.block_mapper` | `LayoutBlockMapper` | Pairs source/target blocks — id-first, then positional fallback. See [layout-builder-integration.md](layout-builder-integration.md#identity-aware-block-pairing). |
| `tmgmt_external_translation_layout_builder.translation_service` | `LayoutBlockTranslationService` | The orchestrator: `submitInlineBlocks()` / `completeInlineBlocks()`, plus nested-block and referenced-entity recursion, plus `checkSourceBlockForStaleness()`. |

### `tmgmt_external_translation_interface`

| Service | Class | Purpose |
|---|---|---|
| `tmgmt_external_translation_interface.manager` | `InterfaceTranslationManager` | The whole module's logic — see [interface-string-translation.md](interface-string-translation.md). |

## Core value objects

### `TranslationFieldContext`

[`src/Translation/TranslationFieldContext.php`](../web/modules/custom/tmgmt_external_translation/src/Translation/TranslationFieldContext.php) — identifies one field slot ([glossary](glossary.md#field-slot)). Immutable, `readonly` properties: `sourceEntityType`/`sourceEntityId`/`sourceLangcode`, `targetLangcode`/`targetEntityType`/`targetEntityId`, `fieldName`/`fieldDelta`/`fieldProperty`. Every provenance/proposal/conflict lookup takes one of these as its key. `toArray()`/`fromArray()` exist so a context can be serialized into `translation_pending_batch.context_data` across separate PHP processes (submit and complete can be different requests).

### `TranslationResponse`

Wraps a provider's translated-text results, keyed by the same opaque item id the request was submitted with. `getTranslation($key): ?string`.

## Trackable field properties

**The single most important extension point for supporting a new field type.** [`tmgmt_external_translation_trackable_field_properties()`](../web/modules/custom/tmgmt_external_translation/tmgmt_external_translation.module) is an explicit allow-list, not a heuristic:

```php
function tmgmt_external_translation_trackable_field_properties(): array {
  return [
    'string' => ['value'],
    'string_long' => ['value'],
    'text' => ['value'],
    'text_long' => ['value'],
    'text_with_summary' => ['value'],
    'image' => ['alt', 'title'],
  ];
}
```

Every field-iterating code path in the system — `_tmgmt_external_translation_classify_save()`, `LayoutBlockTranslationService`'s block/nested-block/referenced-entity collectors — reads this same function rather than hardcoding a field-type list. **To add support for a new field type** (e.g. `link`'s `title` property, or a custom field type), add an entry here; nothing else needs to change for it to start flowing through classification, conflict detection, and translation everywhere in the system. Deliberately kept narrow for this POC — anything not listed is left alone entirely, never read or written.

## Adding a real translation provider

Everything depends only on [`TranslationApiClientInterface`](../web/modules/custom/tmgmt_external_translation/src/Client/TranslationApiClientInterface.php):

```php
interface TranslationApiClientInterface {
  public function translate(string $sourceLanguage, string $targetLanguage, array $items): TranslationResponse;
  public function submit(string $sourceLanguage, string $targetLanguage, array $items): string;
  public function checkStatus(string $requestId): ?TranslationResponse;
  public function getProviderName(): string;
  public function getProviderVersion(): string;
}
```

1. Implement the interface against the real vendor's API. `submit()`/`checkStatus()` are the primary methods (used by content and Layout Builder blocks); `translate()` is a synchronous convenience path used only by interface strings (no job-item/entity lifecycle to defer against there).
2. Register it as a service.
3. Change the alias in [`tmgmt_external_translation.services.yml`](../web/modules/custom/tmgmt_external_translation/tmgmt_external_translation.services.yml):
   ```yaml
   tmgmt_external_translation.translation_api_client:
     alias: your_module.your_real_client   # was: tmgmt_external_translation.mock_client
   ```
   (Or override the alias in `settings.php` per environment, without touching the module.)

No other code changes anywhere in the system — every caller depends on the interface, never the mock class directly. `checkStatus()` **must** be idempotent (safe to call repeatedly, returning the same response once ready) — `TranslationPollingService` may call it more than once across passes/cron runs before a batch is marked `completed`.

## Reused TMGMT services

- **`tmgmt.data`** — TMGMT's own field-flattening service. `filterTranslatable($job_item->getData())` produces the `['title][0][value]' => ['#text' => ...]]` shaped array `ExternalApiTranslator` submits; `unflatten()` reverses it for `addTranslatedData()`.
- **`tmgmt_job_create()`** / job/job-item API — used by `TranslationRequestHelper` and the "Request translation" submit handler to create jobs programmatically, matching what `tmgmt_content`'s own form does.
- **`JobCheckoutManager::checkoutMultiple()`** — used instead of `checkoutAndRedirect()` to skip TMGMT's batch-page redirect for jobs that don't need manual checkout settings (this translator has none — see [`ExternalApiTranslator::hasCheckoutSettings()`](../web/modules/custom/tmgmt_external_translation/src/Plugin/tmgmt/Translator/ExternalApiTranslator.php), which deliberately returns `FALSE`).

## State API tunables

No admin UI for these (POC scope) — set via `drush state:set` or `\Drupal::state()->set()`:

| Key | Default | Purpose |
|---|---|---|
| `tmgmt_external_translation.mock_client.ready_after_checks` | `1` | How many `checkStatus()` calls before the mock client reports ready. Raise this in a test to exercise genuine multi-poll delay. |
| `tmgmt_external_translation_layout_builder.reference_max_depth` | `5` | Max `entity_reference` hops followed when translating a block's referenced entities (e.g. block → Media → taxonomy term). |
| `tmgmt_external_translation_layout_builder.block_nesting_max_depth` | `5` | Max nested-inline-block recursion depth. |

## Permissions reference

Defined in [`tmgmt_external_translation.permissions.yml`](../web/modules/custom/tmgmt_external_translation/tmgmt_external_translation.permissions.yml), shared across all three modules:

| Permission | Gates |
|---|---|
| `manage translation providers` | The "Also request machine translation" checkbox; the "Request translation" button flow; the interface-string request form. Marked `restrict access: true`. |
| `accept translation proposals` | Accept/Edit actions on any proposal review surface. |
| `reject translation proposals` | Keep (reject) action. |
| `view translation audit` | Access to the per-entity "Machine translation status" page (`/node/{node}/translation-status`). |

## Routes

| Route | Path | Form/Controller |
|---|---|---|
| `tmgmt_external_translation.proposal_dashboard` | `/admin/content/translation-proposals` | `ProposalDashboardForm` — site-wide, filterable. |
| `tmgmt_external_translation.proposal_review` | `/translation-proposals/{entity_type}/{entity_id}/{langcode}` | `TranslationProposalReviewForm` — per-entity. |
| `tmgmt_external_translation.node_translation_status` | `/node/{node}/translation-status` | `EntityTranslationStatusForm` — standalone status page (also embedded on the Translate overview via the controller decorator below). |
| `tmgmt_external_translation_interface.request` | `/admin/config/regional/translate/request-machine-translation` | `RequestInterfaceTranslationForm`. |
| `tmgmt_external_translation_interface.proposal_review` | `/admin/config/regional/translate/proposals/{langcode}` | `InterfaceProposalReviewForm`. |

`entity.node.content_translation_overview` (core's own `/node/{node}/translations` route) is **altered**, not newly defined — [`RouteSubscriber`](../web/modules/custom/tmgmt_external_translation/src/Routing/RouteSubscriber.php) repoints its controller to [`TranslationStatusOverviewController`](../web/modules/custom/tmgmt_external_translation/src/Controller/TranslationStatusOverviewController.php), which extends `tmgmt_content`'s own override (not core's base controller — preserving its "Request translation" checkboxes) and appends the status form as a **sibling**, not a nested child, render array. (Nesting one `<form>` render array inside another produces invalid HTML — browsers silently fold the inner form's hidden fields into the outer form, which was a real, confusing bug during development: "Request translation" submissions were misrouted to the status form's own submit handler.) Must run *after* `tmgmt_content`'s own route subscriber (priority `-300`, lower/later than `tmgmt_content`'s `-211`).

## Extending the proposal system for a new non-entity category

Follow the pattern `tmgmt_external_translation_layout_builder` (component labels) and `tmgmt_external_translation_interface` (interface strings) both use:

1. Give your category a way to be recognized from a proposal row — either a distinct `target_entity_type` (like `interface_string`) or a distinct `field_name` (like `layout_component_label`, since `block_content` is a real entity type shared with ordinary block fields).
2. Write your own accept/reject/edit methods against whatever storage your category actually uses.
3. Subscribe to `ProposalActionEvent`, check the discriminator from step 1, call your own methods, and call `$event->setHandled($success)`.
4. Nothing else — `TranslationProposalManager::resolveProposal()` already dispatches the event before falling back to its generic entity-field write, and the dashboard already renders any category generically via `ProposalReviewElementsTrait` as long as `getAllPendingProposals()`'s joined row has enough columns to identify it.

## Testing

No PHPUnit suite — this POC uses standalone `drush php:script` fixtures under [`scripts/`](../scripts), each a self-contained scenario asserting expected values via printed output (`phaseN_log()`-style helpers), run against a real ddev/MySQL-backed Drupal install rather than mocks. Naming follows `phaseN_<description>.php`, roughly chronological by when each capability was built:

| Range | Covers |
|---|---|
| `phase2`–`phase2_test_accept_reject` | Basic field translation, accept/reject. |
| `phase3` | Layout Builder inline blocks (first pass). |
| `phase4` | Referenced-entity (paragraph-style) translation. |
| `phase5` | Interface strings. |
| `phase6_*` | Source-copy classification; the "Also request machine translation" checkbox flow. |
| `phase7` | Proposal dashboard. |
| `phase8` | Async submit/poll/complete. |
| `phase9` | Staleness detection. |
| `phase10`–`phase15` | Shared-by-default blocks, fork-on-write, nested blocks, independent structure. |
| `phase16` | Layout field backfill migration (see [deployment-and-migration.md](deployment-and-migration.md)). |

Run one with `ddev drush php:script scripts/phaseN_....php`. When changing shared machinery (provenance, conflict detection, moderation gate), re-run the full range — each phase script deliberately re-exercises scenarios earlier ones already covered, as a regression check, not just its own new capability.

**Known environment gotcha:** Docker Desktop's file sync can lag behind a file edit — a script or module change can execute stale code inside the ddev container even though the change is correctly on disk. If a script's output doesn't reflect a just-made edit, `ddev restart` (not just `ddev drush cr`) before re-running.

## Coding conventions specific to this codebase

- **No comments explaining *what*** — identifiers are expected to carry that. Comments exist only for non-obvious *why*: a confirmed core quirk, a bug this exact code prevents, a deliberate scope boundary. Several docblocks in this codebase cite the specific empirical test (`drush php:eval`) that confirmed a piece of core behavior rather than assuming it — follow that pattern when documenting a similarly non-obvious constraint.
- **Interfaces for every injectable service**, even single-implementation ones (`TranslationProvenanceManagerInterface`, `ModerationGateInterface`, etc.) — consistent with Drupal's own service-design convention and what makes the [extension pattern above](#extending-the-proposal-system-for-a-new-non-entity-category) swappable.
- **Explicit `setNewRevision(TRUE)`** before any write to a `RevisionableInterface` entity that might currently be shared/unforked (see [layout-builder-integration.md](layout-builder-integration.md#fork-on-write)) — never assumed.
- **Read a live value from the entity actually in hand**, not a fresh `loadUnchanged()`, whenever the surrounding code is reasoning about "did the source change" — a fresh load can return your own just-written revision and cause a self-triggered cascade (see the staleness-detection bug notes in [layout-builder-integration.md](layout-builder-integration.md#staleness-detection)).

## See also

- [architecture.md](architecture.md) — event catalog, design principles, sequence diagrams.
- [data-model.md](data-model.md) — schema this reference builds on.
- [layout-builder-integration.md](layout-builder-integration.md) — the most complex subsystem, in depth.
