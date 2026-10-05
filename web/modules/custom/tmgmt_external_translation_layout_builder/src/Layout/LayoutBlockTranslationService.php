<?php

namespace Drupal\tmgmt_external_translation_layout_builder\Layout;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_translation\ContentTranslationManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\tmgmt_external_translation\Client\TranslationApiClientInterface;
use Drupal\tmgmt_external_translation\Client\TranslationResponse;
use Drupal\tmgmt_external_translation\Translation\TranslationConflictDetectorInterface;
use Drupal\tmgmt_external_translation\Translation\TranslationEntityWriterInterface;
use Drupal\tmgmt_external_translation\Translation\TranslationFieldContext;
use Drupal\tmgmt_external_translation\Translation\TranslationFieldValueReaderInterface;
use Drupal\tmgmt_external_translation\Translation\TranslationHashManagerInterface;
use Drupal\tmgmt_external_translation\Translation\TranslationProposalManagerInterface;
use Drupal\tmgmt_external_translation\Translation\TranslationProvenanceManagerInterface;

/**
 * Translates the inline blocks referenced by a host entity's Layout
 * Builder override, reusing the exact same conflict-detection /
 * provenance / proposal machinery as ordinary field translation.
 *
 * Blocks are shared by default (plan Part 5): a new translation starts
 * out referencing the SAME block_content id/revision as the source
 * language (see hook_entity_presave() in this module's .module file),
 * and stay that way indefinitely unless an editor hand-edits the block
 * through Layout Builder's own UI - layout_builder_at's eager "copy
 * blocks into translation" duplication (a SEPARATE, independent
 * block_content entity from day one, forced to the target langcode) is
 * still available as an opt-in escape hatch, but is no longer the
 * default path.
 *
 * Neither machine translation (this class) nor a human hand-edit
 * (core's own InlineBlock::saveBlockContent(), unmodified - verified via
 * Spike B) ever creates a NEW block_content id for a still-shared block:
 * both write a new REVISION of the SAME id and repoint only the editing
 * translation's own SectionComponent, leaving every other translation's
 * component (and therefore its rendered content) completely untouched.
 * "Still connected to source" vs. "independently editor-owned" is
 * tracked the same way it already is for ordinary fields - via
 * translation_provenance's origin/status columns, not via entity
 * identity - so no separate fork-detection hook is needed for manual
 * edits; the existing conflict-detector protection already catches them
 * the next time MT attempts to touch that slot.
 *
 * This deliberately does not go through TMGMT's JobItem/ContentEntitySource
 * pipeline: that pipeline assumes source and target are two languages of
 * the SAME entity via Drupal's own content_translation, but Layout
 * Builder's inline-block edit form isn't translation-context aware (it
 * always loads/edits whatever block_revision_id a component points at,
 * with no per-request language resolution) - making block_content a
 * genuinely multi-translation entity would silently break manual editing
 * of a shared block. Blocks stay single-langcode entities throughout; the
 * core module's Translation/* services are entity-agnostic (a
 * TranslationFieldContext just names a source and a target entity/field
 * slot, source and target commonly being the SAME entity id here), so
 * they work unmodified for this case.
 *
 * Split into submit/complete (Part 4, async support): submitInlineBlocks()
 * gathers every block's/label's source text into one batch and hands it
 * to the provider; completeInlineBlocks() re-derives the same block pairs
 * fresh once the batch is ready and applies the unchanged conflict-check/
 * write/proposal logic against the now-available translations. Block
 * pairs are deliberately NOT frozen at submit time - LayoutBlockMapper's
 * id-then-positional pairing is cheap and idempotent, and re-deriving it
 * against the host entity's CURRENT state at completion time is more
 * correct than a stale snapshot (mirrors how the previous synchronous
 * code always read current entity state right before translating).
 *
 * The repointing below covers the direct "safe to write" path. The
 * proposal Accept/Edit path (core module, entity-agnostic) is covered
 * separately by RepointBlockRevisionSubscriber, which reacts to
 * EntityFieldWrittenEvent instead of needing to know about Layout
 * Builder itself.
 */
class LayoutBlockTranslationService {

  const TABLE = 'translation_pending_batch';

  const REFERENCE_FIELD_TYPES = ['entity_reference'];

  public function __construct(
    protected LayoutEntityCollector $collector,
    protected LayoutBlockMapper $mapper,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TranslationApiClientInterface $client,
    protected TranslationConflictDetectorInterface $conflictDetector,
    protected TranslationProvenanceManagerInterface $provenanceManager,
    protected TranslationProposalManagerInterface $proposalManager,
    protected TranslationFieldValueReaderInterface $fieldValueReader,
    protected Connection $database,
    protected TimeInterface $time,
    protected TranslationHashManagerInterface $hashManager,
    protected ContentTranslationManagerInterface $contentTranslationManager,
    protected TranslationEntityWriterInterface $entityWriter,
    protected StateInterface $state,
  ) {}

  /**
   * How many hops of entity_reference fields to follow past the block
   * itself before stopping - e.g. depth 1 only translates fields on an
   * entity the block directly references (a Media item); depth 2 would
   * also follow that Media item's own entity_reference fields, and so
   * on. Configurable via State API (matching this module's existing
   * tunable-via-state pattern, e.g. the mock client's
   * ready_after_checks) rather than a fixed constant, since the right
   * depth depends entirely on how deep a given site's own reference
   * chains actually go. Cycle protection (see the $visited set in the
   * recursive collect/complete methods) makes a generous default safe
   * even if a chain loops back on itself.
   */
  protected function getReferenceMaxDepth(): int {
    return (int) $this->state->get('tmgmt_external_translation_layout_builder.reference_max_depth', 5);
  }

  /**
   * How many levels of "a block that itself has a Layout Builder layout
   * containing further inline blocks" to follow (spec §28, "Nested
   * Inline Blocks": Inline Block -> Inline Block -> Inline Block).
   * Separate from getReferenceMaxDepth() - this is block-within-block
   * nesting via a block's own layout_builder__layout field, not
   * entity_reference fields. Configurable via State API for the same
   * reason.
   */
  protected function getBlockNestingMaxDepth(): int {
    return (int) $this->state->get('tmgmt_external_translation_layout_builder.block_nesting_max_depth', 5);
  }

  /**
   * Submits every inline block's (and component label's) source text for
   * this host entity translation as one async batch. Writes nothing yet -
   * see completeInlineBlocks().
   *
   * @return string[]
   *   Human-readable log messages for the caller to log/record.
   */
  public function submitInlineBlocks(string $hostEntityType, string $hostEntityId, string $sourceLangcode, string $targetLangcode, string $providerName, string $providerVersion): array {
    [$hostEntity, $sourceTranslation, $targetTranslation, $pairs] = $this->deriveBlockPairs($hostEntityType, $hostEntityId, $sourceLangcode, $targetLangcode);
    if ($pairs === NULL) {
      return [];
    }

    $items = $this->collectTranslatableItems($sourceTranslation, $pairs);
    if (!$items) {
      return [];
    }

    $requestId = $this->client->submit($sourceLangcode, $targetLangcode, $items);
    $now = $this->time->getRequestTime();
    $this->database->insert(self::TABLE)
      ->fields([
        'request_id' => $requestId,
        'kind' => 'layout_inline_blocks',
        'host_entity_type' => $hostEntityType,
        'host_entity_id' => $hostEntityId,
        'source_langcode' => $sourceLangcode,
        'target_langcode' => $targetLangcode,
        'provider_name' => $providerName,
        'provider_version' => $providerVersion,
        'status' => 'pending',
        'created' => $now,
        'changed' => $now,
      ])
      ->execute();

    return [sprintf('Submitted %d inline block field(s)/label(s) to external translation provider (request %s).', count($items), $requestId)];
  }

  /**
   * Called whenever an inline block's own content is saved directly (see
   * hook_block_content_update() in this module's .module file). Finds
   * every host translation that maps some OTHER-language block to this
   * one (i.e. every translation for which this block was the source),
   * and for any trackable field/label whose live value no longer matches
   * the source_hash recorded at the last translation, marks that slot
   * stale and automatically resubmits a fresh batch for that host/target
   * pair - so an editor correcting the English source doesn't have to
   * remember to manually re-request translation.
   *
   * Under the shared-by-default model (plan Part 5), "no separate is-this-
   * the-source guard is needed" is no longer safely true on its own - and
   * a langcode-only guard isn't enough either. The same block id stays
   * shared, potentially indefinitely, and this hook fires for EVERY save
   * of it, including OUR OWN writes to the target side (which share the
   * same id while unforked). Comparing only "is the located translation
   * the default one" isn't sufficient: findComponentForBlock() can locate
   * EN as the default match while the save that just happened was
   * actually a TARGET-side write that hasn't moved EN's own component
   * pointer at all. Confirmed as a real, severe bug during development:
   * without a revision check too, a target-side write (e.g. translating
   * a second field on an already-partially-conflicted block) could
   * re-trigger this method, which would then treat its OWN prior write as
   * a new "source change" and resubmit - cascading indefinitely (traced
   * empirically: 40+ chained batches in one run, hitting the polling
   * service's MAX_PASSES cap).
   *
   * The fix: only proceed if the just-saved revision ($revisionId, from
   * the block_content entity hook_block_content_update() was actually
   * called with) matches what EN's OWN component is CURRENTLY pointing
   * at. EN's component pointer only ever advances when EN's own
   * translation is genuinely re-saved (core's native behavior for a real
   * hand-edit) - never as a side effect of a write to another
   * translation's side - so this is an exact, reliable signal that this
   * specific save really was a change to the source's own content.
   *
   * @return string[]
   *   Human-readable log messages.
   */
  public function checkSourceBlockForStaleness(string $blockId, string $revisionId): array {
    $located = $this->collector->findComponentForBlock($blockId);
    if ($located === NULL) {
      return [];
    }

    $sourceTranslation = $located['hostTranslation'];
    $sourceLangcode = $sourceTranslation->language()->getId();
    if ($sourceLangcode !== $sourceTranslation->getUntranslated()->language()->getId()) {
      return [];
    }
    if ($located['blockRevisionId'] !== $revisionId) {
      // This save didn't move EN's own component pointer - it's a write
      // to some other translation's side of a still-shared block, not a
      // genuine source change. See this method's docblock.
      return [];
    }
    $hostEntityType = $sourceTranslation->getEntityTypeId();
    $hostEntityId = (string) $sourceTranslation->id();
    $trackableProperties = tmgmt_external_translation_trackable_field_properties();

    $messages = [];
    foreach ($sourceTranslation->getTranslationLanguages() as $langcode => $language) {
      if ($langcode === $sourceLangcode) {
        continue;
      }
      $targetTranslation = $sourceTranslation->getTranslation($langcode);
      if (!$this->collector->hasLayout($targetTranslation)) {
        continue;
      }

      $matchedPair = NULL;
      foreach ($this->mapper->mapBlocks($sourceTranslation, $targetTranslation) as $pair) {
        if ((string) $pair[0]->block->id() === $blockId) {
          $matchedPair = $pair;
          break;
        }
      }
      if ($matchedPair === NULL) {
        continue;
      }
      [$sourceRef, $targetRef] = $matchedPair;

      // Deliberately reads via $sourceRef->block (the source translation's
      // OWN component pointer), NOT a fresh ->load() of the latest
      // revision. Source and target can be the SAME entity id while still
      // shared - loading "whatever's currently latest" would read back
      // OUR OWN most recent write to the target side as if it were a new
      // EN edit (since writing the target also becomes the entity's
      // latest revision), causing an infinite auto-retranslation loop
      // (confirmed empirically - block content ended up "[fr] [fr] [fr]
      // ..." after 20 cascading passes). The component pointer only ever
      // advances when EN's OWN translation is genuinely re-saved (core's
      // native behavior for a real hand-edit, or a future explicit EN
      // update), which is exactly the signal this method needs.
      //
      // Known, accepted limitation: this hook fires as a NESTED save from
      // WITHIN the host entity's own presave phase, so on the very same
      // save that a genuine EN edit happens, the host's own component
      // config may not have been persisted yet, and this check can miss
      // triggering an auto-retranslation on that exact save. This is a
      // narrow, cosmetic delay (the edit itself is never lost - only the
      // auto-trigger can lag by one save), not a data-correctness issue,
      // and is far preferable to the alternative above.
      $staleFound = FALSE;
      foreach ($sourceRef->block->getFieldDefinitions() as $fieldName => $definition) {
        if (!isset($trackableProperties[$definition->getType()]) || !$targetRef->block->hasField($fieldName)) {
          continue;
        }
        foreach ($sourceRef->block->get($fieldName) as $delta => $item) {
          foreach ($trackableProperties[$definition->getType()] as $property) {
            if (!$item->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinition($property)) {
              continue;
            }
            $sourceValue = $item->get($property)->getValue();
            if (!is_scalar($sourceValue) || $sourceValue === '') {
              continue;
            }
            $context = new TranslationFieldContext(
              'block_content', $blockId, $sourceLangcode,
              $langcode, 'block_content', (string) $targetRef->block->id(),
              $fieldName, $delta, $property,
            );
            $record = $this->provenanceManager->getRecord($context);
            if ($record === NULL) {
              // Never translated yet - nothing to go stale.
              continue;
            }
            if ($this->hashManager->hash((string) $sourceValue) !== $record['source_hash']) {
              $this->provenanceManager->markStale($context);
              $messages[] = sprintf('Inline block %s field %s (%s): source changed since last translation; marked stale.', $targetRef->block->id(), $fieldName, $property);
              $staleFound = TRUE;
            }
          }
        }
      }

      $sourceLabel = $this->collector->getComponentLabel($sourceTranslation, $sourceRef->sectionDelta, $sourceRef->componentUuid);
      if ($sourceLabel !== NULL && $sourceLabel !== '') {
        $labelContext = new TranslationFieldContext(
          'block_content', $blockId, $sourceLangcode,
          $langcode, 'block_content', (string) $targetRef->block->id(),
          'layout_component_label', 0, 'value',
        );
        $labelRecord = $this->provenanceManager->getRecord($labelContext);
        if ($labelRecord !== NULL && $this->hashManager->hash($sourceLabel) !== $labelRecord['source_hash']) {
          $this->provenanceManager->markStale($labelContext);
          $messages[] = sprintf('Inline block %s label: source changed since last translation; marked stale.', $targetRef->block->id());
          $staleFound = TRUE;
        }
      }

      if ($staleFound && !$this->hasPendingLayoutBatch($hostEntityType, $hostEntityId, $langcode)) {
        $messages = array_merge($messages, $this->submitInlineBlocks(
          $hostEntityType, $hostEntityId, $sourceLangcode, $langcode,
          $this->client->getProviderName(), $this->client->getProviderVersion(),
        ));
      }
    }

    return $messages;
  }

  /**
   * Avoids piling up duplicate in-flight batches if the source block is
   * saved multiple times before a previous auto-re-translation completes.
   */
  protected function hasPendingLayoutBatch(string $hostEntityType, string $hostEntityId, string $targetLangcode): bool {
    return (bool) $this->database->select(self::TABLE, 'b')
      ->condition('kind', 'layout_inline_blocks')
      ->condition('host_entity_type', $hostEntityType)
      ->condition('host_entity_id', $hostEntityId)
      ->condition('target_langcode', $targetLangcode)
      ->condition('status', 'pending')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Applies a ready batch: re-derives the same block pairs fresh and runs
   * the unchanged per-field conflict-check/write/proposal logic against
   * the now-available translations.
   *
   * @return string[]
   */
  public function completeInlineBlocks(array $pendingBatchRow, TranslationResponse $response): array {
    $sourceLangcode = $pendingBatchRow['source_langcode'];
    $targetLangcode = $pendingBatchRow['target_langcode'];
    $providerName = $pendingBatchRow['provider_name'];
    $providerVersion = $pendingBatchRow['provider_version'];

    [$hostEntity, $sourceTranslation, $targetTranslation, $pairs] = $this->deriveBlockPairs($pendingBatchRow['host_entity_type'], $pendingBatchRow['host_entity_id'], $sourceLangcode, $targetLangcode);
    if ($pairs === NULL) {
      return [];
    }

    $messages = [];
    foreach ($pairs as [$sourceRef, $targetRef]) {
      $messages = array_merge($messages, $this->completeBlockPair($sourceRef, $targetRef, $targetTranslation, $sourceLangcode, $targetLangcode, $providerName, $providerVersion, $response));
      $labelMessage = $this->completeComponentLabel($sourceRef, $targetRef, $sourceTranslation, $targetTranslation, $sourceLangcode, $targetLangcode, $providerName, $providerVersion, $response);
      if ($labelMessage !== NULL) {
        $messages[] = $labelMessage;
      }
      $messages = array_merge($messages, $this->completeReferencedEntityFields($sourceRef->block, (string) $targetRef->block->id(), $sourceLangcode, $targetLangcode, $providerName, $providerVersion, $response));
      $nestedMessages = $this->completeNestedBlockPairs($sourceRef->block, $targetRef->block, [(string) $sourceRef->block->id()], $sourceLangcode, $targetLangcode, $providerName, $providerVersion, $response, 1, $this->getBlockNestingMaxDepth());
      if ($nestedMessages) {
        $messages = array_merge($messages, $nestedMessages);
        // completeNestedBlockPairs() can advance $targetRef->block's own
        // revision further (repointing ITS OWN children) after
        // completeBlockPair() above already repointed the node to an
        // earlier revision of it - re-repoint the node to whatever
        // $targetRef->block's revision ID is now, or the node would keep
        // rendering a version of block A that's missing its own later
        // child updates. Cheap/idempotent if nothing actually changed.
        $this->collector->repointComponent($targetTranslation, $targetRef->sectionDelta, $targetRef->componentUuid, $targetRef->block);
      }
    }
    return $messages;
  }

  /**
   * Writes translated values onto a block's OWN nested inline blocks
   * (spec §28) - a block that itself has a Layout Builder layout field
   * containing further inline blocks, which can themselves have further
   * children, recursively.
   *
   * Deliberately reuses completeBlockPair()/completeComponentLabel() AS-IS
   * for each child, passing $targetParentBlock as their
   * $targetHostTranslation argument - both methods (and the
   * repointComponent()/setComponentLabel() they call) already operate on
   * a generic ContentEntityInterface, not something hardcoded to "the
   * top-level node", so no new write logic is needed here at all: a
   * block-as-host behaves identically to a node-as-host from their point
   * of view.
   *
   * The one thing THIS method must handle that the top-level call site
   * doesn't: $targetParentBlock itself needs setNewRevision(TRUE) before
   * any child gets repointed into it, or - since block_content's own
   * isNewRevision() defaults to FALSE (confirmed repeatedly elsewhere in
   * this class) - repointComponent()'s save of $targetParentBlock would
   * silently mutate the SAME revision EN's own parent component may still
   * point at, corrupting what EN renders. Only needs to fire once per
   * parent (Drupal clears the flag after the first successful save); a
   * parent already forked by an earlier step in this same request is a
   * harmless no-op extra revision, not a correctness issue.
   *
   * $visitedBlockIds mirrors collectNestedBlockItems()'s cycle
   * guard exactly, so a chain that loops back on itself stops here too
   * rather than recursing forever.
   *
   * @return string[]
   */
  protected function completeNestedBlockPairs(ContentEntityInterface $sourceParentBlock, ContentEntityInterface $targetParentBlock, array $visitedBlockIds, string $sourceLangcode, string $targetLangcode, string $providerName, string $providerVersion, TranslationResponse $response, int $depth, int $maxDepth): array {
    $messages = [];
    if ($depth > $maxDepth || !$this->collector->hasLayout($sourceParentBlock)) {
      return $messages;
    }

    $childPairs = $this->mapper->mapBlocks($sourceParentBlock, $targetParentBlock);
    if ($childPairs && $targetParentBlock instanceof \Drupal\Core\Entity\RevisionableInterface) {
      $targetParentBlock->setNewRevision(TRUE);
    }

    foreach ($childPairs as [$childSourceRef, $childTargetRef]) {
      if (in_array((string) $childSourceRef->block->id(), $visitedBlockIds, TRUE)) {
        continue;
      }
      $messages = array_merge($messages, $this->completeBlockPair($childSourceRef, $childTargetRef, $targetParentBlock, $sourceLangcode, $targetLangcode, $providerName, $providerVersion, $response));
      $labelMessage = $this->completeComponentLabel($childSourceRef, $childTargetRef, $sourceParentBlock, $targetParentBlock, $sourceLangcode, $targetLangcode, $providerName, $providerVersion, $response);
      if ($labelMessage !== NULL) {
        $messages[] = $labelMessage;
      }
      $messages = array_merge($messages, $this->completeReferencedEntityFields($childSourceRef->block, (string) $childTargetRef->block->id(), $sourceLangcode, $targetLangcode, $providerName, $providerVersion, $response));
      $grandchildMessages = $this->completeNestedBlockPairs($childSourceRef->block, $childTargetRef->block, array_merge($visitedBlockIds, [(string) $childSourceRef->block->id()]), $sourceLangcode, $targetLangcode, $providerName, $providerVersion, $response, $depth + 1, $maxDepth);
      if ($grandchildMessages) {
        $messages = array_merge($messages, $grandchildMessages);
        // Same reasoning as the top-level call site: a grandchild pass
        // can advance $childTargetRef->block's own revision further -
        // re-repoint THIS level's parent to match, or the chain breaks
        // one level up too.
        $this->collector->repointComponent($targetParentBlock, $childTargetRef->sectionDelta, $childTargetRef->componentUuid, $childTargetRef->block);
      }
    }

    return $messages;
  }

  /**
   * Writes translated values onto any referenced entity found by
   * collectReferencedEntityItems() - a genuinely separate write target
   * from the block itself (e.g. the Media entity a block's reference
   * field points at), so this doesn't participate in the block's own
   * setNewRevision()/repointComponent() flow at all. Uses the generic,
   * entity-translation-aware conflict check and TranslationEntityWriter
   * (creates the target-language translation if missing) rather than the
   * block-specific explicit-value machinery, since a referenced entity
   * like Media is a genuinely, natively translatable entity - unlike
   * blocks, which stay single-langcode by design (see this class's own
   * docblock).
   *
   * @return string[]
   */
  protected function completeReferencedEntityFields(ContentEntityInterface $sourceBlock, string $targetBlockId, string $sourceLangcode, string $targetLangcode, string $providerName, string $providerVersion, TranslationResponse $response): array {
    $messages = [];
    $this->completeReferencedEntityFieldsRecursive($sourceBlock, [$targetBlockId], [], $sourceLangcode, $targetLangcode, $providerName, $providerVersion, $response, $messages, 1, $this->getReferenceMaxDepth());
    return $messages;
  }

  /**
   * Recursive counterpart to collectReferencedEntityItemsRecursive() -
   * same traversal (field/delta hops, $visited cycle guard, depth cap),
   * but writes instead of reading: for each trackable property found on
   * a referenced entity, looks up its translation in $response by the
   * exact same key collectReferencedEntityItemsRecursive() would have
   * produced, then runs it through conflict-detection/provenance/write
   * exactly like completeBlockPair() does for a block's own fields - the
   * generic, entity-translation-aware path (hasEditorModification(),
   * TranslationEntityWriter), not the block-specific explicit-value one,
   * since every entity reachable here is independently confirmed
   * content-translation-enabled.
   *
   * @param array<string, mixed> $messages
   *   Accumulator, passed by reference.
   */
  protected function completeReferencedEntityFieldsRecursive(ContentEntityInterface $entity, array $keyPath, array $visited, string $sourceLangcode, string $targetLangcode, string $providerName, string $providerVersion, TranslationResponse $response, array &$messages, int $depth, int $maxDepth): void {
    if ($depth > $maxDepth) {
      return;
    }
    $trackableProperties = tmgmt_external_translation_trackable_field_properties();

    foreach ($entity->getFieldDefinitions() as $fieldName => $definition) {
      if (!in_array($definition->getType(), self::REFERENCE_FIELD_TYPES, TRUE)) {
        continue;
      }
      foreach ($entity->get($fieldName) as $delta => $item) {
        $referencedEntity = $item->entity ?? NULL;
        if (!$referencedEntity instanceof ContentEntityInterface
          || !$this->isGenericallyTranslatableTarget($referencedEntity)) {
          continue;
        }
        $visitKey = $referencedEntity->getEntityTypeId() . ':' . $referencedEntity->id();
        if (in_array($visitKey, $visited, TRUE)) {
          continue;
        }
        $hopPath = array_merge($keyPath, [$fieldName, (string) $delta]);
        $sourceReferenced = $referencedEntity->getUntranslated();

        foreach ($sourceReferenced->getFieldDefinitions() as $refFieldName => $refDefinition) {
          if (!$refDefinition->isTranslatable() || !isset($trackableProperties[$refDefinition->getType()])) {
            continue;
          }
          foreach ($sourceReferenced->get($refFieldName) as $refDelta => $refItem) {
            foreach ($trackableProperties[$refDefinition->getType()] as $property) {
              if (!$refItem->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinition($property)) {
                continue;
              }
              $sourceValue = $refItem->get($property)->getValue();
              if (!is_scalar($sourceValue) || $sourceValue === '') {
                continue;
              }
              $sourceValue = (string) $sourceValue;

              $key = $this->referenceItemKey(array_merge($hopPath, [$refFieldName, (string) $refDelta]), $property);
              $translated = $response->getTranslation($key);
              if ($translated === NULL) {
                continue;
              }

              $context = new TranslationFieldContext(
                $referencedEntity->getEntityTypeId(), (string) $referencedEntity->id(), $sourceLangcode,
                $targetLangcode, $referencedEntity->getEntityTypeId(), (string) $referencedEntity->id(),
                $refFieldName, $refDelta, $property,
              );

              if ($this->conflictDetector->hasEditorModification($context, $sourceValue)) {
                $this->proposalManager->createProposal($context, $sourceValue, $translated, $providerName, $providerVersion);
                $messages[] = sprintf('Referenced %s %s field %s (%s): new machine translation conflicts with an existing edit; proposal created.', $referencedEntity->getEntityTypeId(), $referencedEntity->id(), $refFieldName, $property);
                continue;
              }

              $this->provenanceManager->recordMachineTranslation($context, $sourceValue, $translated, $providerName, $providerVersion);
              $this->entityWriter->write($context, $translated);
              $messages[] = sprintf('Referenced %s %s field %s (%s): translated.', $referencedEntity->getEntityTypeId(), $referencedEntity->id(), $refFieldName, $property);
            }
          }
        }

        $this->completeReferencedEntityFieldsRecursive($sourceReferenced, $hopPath, array_merge($visited, [$visitKey]), $sourceLangcode, $targetLangcode, $providerName, $providerVersion, $response, $messages, $depth + 1, $maxDepth);
      }
    }
  }

  /**
   * Shared setup for both halves: load the host entity, confirm it has a
   * layout override in both languages, and map its inline blocks.
   *
   * @return array{0: ?ContentEntityInterface, 1: ?ContentEntityInterface, 2: ?ContentEntityInterface, 3: ?array}
   */
  protected function deriveBlockPairs(string $hostEntityType, string $hostEntityId, string $sourceLangcode, string $targetLangcode): array {
    $storage = $this->entityTypeManager->getStorage($hostEntityType);
    $hostEntity = $storage->load($hostEntityId);
    if (!$hostEntity instanceof ContentEntityInterface || !$this->collector->hasLayout($hostEntity)) {
      return [NULL, NULL, NULL, NULL];
    }
    if (!$hostEntity->hasTranslation($targetLangcode)) {
      // No target-language layout exists yet (editor hasn't used "Copy
      // blocks into translation" or built one manually) - nothing to do.
      return [NULL, NULL, NULL, NULL];
    }

    $sourceTranslation = $hostEntity->hasTranslation($sourceLangcode) ? $hostEntity->getTranslation($sourceLangcode) : $hostEntity;
    $targetTranslation = $hostEntity->getTranslation($targetLangcode);
    $pairs = $this->mapper->mapBlocks($sourceTranslation, $targetTranslation);

    return [$hostEntity, $sourceTranslation, $targetTranslation, $pairs];
  }

  /**
   * Builds the flattened items[] to submit: every trackable field on
   * every source block, plus every component label, keyed uniquely by
   * target block id so completeBlockPair()/completeComponentLabel() can
   * look their translations back up from the response using the same key.
   */
  protected function collectTranslatableItems(ContentEntityInterface $sourceTranslation, array $pairs): array {
    $items = [];
    $trackableProperties = tmgmt_external_translation_trackable_field_properties();

    foreach ($pairs as [$sourceRef, $targetRef]) {
      // See checkSourceBlockForStaleness()'s docblock for why this reads
      // via $sourceRef->block deliberately, not a fresh reload.
      $sourceBlock = $sourceRef->block;
      $targetBlockId = (string) $targetRef->block->id();
      foreach ($sourceBlock->getFieldDefinitions() as $fieldName => $definition) {
        if (!isset($trackableProperties[$definition->getType()]) || !$targetRef->block->hasField($fieldName)) {
          continue;
        }
        foreach ($sourceBlock->get($fieldName) as $delta => $item) {
          foreach ($trackableProperties[$definition->getType()] as $property) {
            if (!$item->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinition($property)) {
              continue;
            }
            $sourceValue = $item->get($property)->getValue();
            if (!is_scalar($sourceValue) || $sourceValue === '') {
              continue;
            }
            $items[$this->blockFieldKey($targetBlockId, $fieldName, $delta, $property)] = ['source' => (string) $sourceValue];
          }
        }
      }

      $items += $this->collectReferencedEntityItems($sourceBlock, $targetBlockId);

      $sourceLabel = $this->collector->getComponentLabel($sourceTranslation, $sourceRef->sectionDelta, $sourceRef->componentUuid);
      if ($sourceLabel !== NULL && $sourceLabel !== '') {
        $items[$this->labelKey($targetBlockId)] = ['source' => $sourceLabel];
      }

      $items += $this->collectNestedBlockItems($sourceBlock, $targetRef->block, [(string) $sourceBlock->id()], 1, $this->getBlockNestingMaxDepth());
    }

    return $items;
  }

  /**
   * Follows a block's OWN Layout Builder layout field (spec §28, "Nested
   * Inline Blocks") - a block that itself hosts inline blocks, which can
   * themselves host further inline blocks. Reuses blockFieldKey()/
   * labelKey() with the CHILD's own target block id (block ids are
   * globally unique, so no compound path is needed to keep keys unique
   * across nesting levels - unlike the entity-reference recursion, which
   * shares an id space with the block itself and does need one).
   *
   * $visitedBlockIds guards against a cycle (a block whose layout somehow
   * references an ancestor block) the same way collectReferencedEntityItemsRecursive()
   * does for entity references.
   *
   * @return array<string, array{source: string}>
   */
  protected function collectNestedBlockItems(ContentEntityInterface $sourceParentBlock, ContentEntityInterface $targetParentBlock, array $visitedBlockIds, int $depth, int $maxDepth): array {
    $items = [];
    if ($depth > $maxDepth || !$this->collector->hasLayout($sourceParentBlock)) {
      return $items;
    }
    $trackableProperties = tmgmt_external_translation_trackable_field_properties();

    foreach ($this->mapper->mapBlocks($sourceParentBlock, $targetParentBlock) as [$childSourceRef, $childTargetRef]) {
      $childSourceBlock = $childSourceRef->block;
      $childTargetBlock = $childTargetRef->block;
      if (in_array((string) $childSourceBlock->id(), $visitedBlockIds, TRUE)) {
        continue;
      }
      $childTargetBlockId = (string) $childTargetBlock->id();

      foreach ($childSourceBlock->getFieldDefinitions() as $fieldName => $definition) {
        if (!isset($trackableProperties[$definition->getType()]) || !$childTargetBlock->hasField($fieldName)) {
          continue;
        }
        foreach ($childSourceBlock->get($fieldName) as $delta => $item) {
          foreach ($trackableProperties[$definition->getType()] as $property) {
            if (!$item->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinition($property)) {
              continue;
            }
            $sourceValue = $item->get($property)->getValue();
            if (!is_scalar($sourceValue) || $sourceValue === '') {
              continue;
            }
            $items[$this->blockFieldKey($childTargetBlockId, $fieldName, $delta, $property)] = ['source' => (string) $sourceValue];
          }
        }
      }

      $childLabel = $this->collector->getComponentLabel($sourceParentBlock, $childSourceRef->sectionDelta, $childSourceRef->componentUuid);
      if ($childLabel !== NULL && $childLabel !== '') {
        $items[$this->labelKey($childTargetBlockId)] = ['source' => $childLabel];
      }

      $items += $this->collectNestedBlockItems($childSourceBlock, $childTargetBlock, array_merge($visitedBlockIds, [(string) $childSourceBlock->id()]), $depth + 1, $maxDepth);
    }

    return $items;
  }

  /**
   * Follows this block's entity_reference fields (e.g. a media reference)
   * to any referenced entity that's itself content-translation-enabled
   * (a plain block's own referenced target_id doesn't vary by language,
   * but the REFERENCED entity - e.g. a Media item - can have its own
   * genuine per-language translation, independent of the block), and
   * collects its trackable fields/properties (its own 'name', an image
   * field's 'alt'/'title', etc.) as additional batch items.
   *
   * Recurses into the referenced entity's OWN entity_reference fields up
   * to getReferenceMaxDepth() hops (configurable via State API), so a
   * chain like block -> media -> some other translatable entity is
   * followed too, not just the first hop. $visited guards against a
   * reference cycle (A references B references A) looping forever -
   * each hop's entity_type:id is tracked and any repeat is skipped
   * rather than recursed into again.
   *
   * Whether $entity is a safe target for the GENERIC entity-reference
   * translation path (create/use a real Drupal translation via
   * TranslationEntityWriter). Requires content_translation enabled AND
   * excludes block_content specifically: content_translation happens to
   * be enabled for block_content:basic on this site (required by
   * layout_builder_at's own "Copy blocks into translation" feature,
   * unrelated to this decision), so the generic check alone would wrongly
   * treat a plain entity_reference field pointing at ANOTHER block as
   * "genuinely translatable" - creating a real multi-langcode Drupal
   * translation of it. That directly violates this whole class's core
   * rule (see its own docblock): blocks stay single-langcode forever,
   * because Layout Builder's inline-block edit form isn't translation-
   * context aware. Confirmed as a real bug empirically: without this
   * exclusion, a block referenced only via a plain field (never placed
   * via Layout Builder anywhere) silently gained a genuine
   * hasTranslation('fr') === TRUE, which would corrupt manual editing if
   * that same block were ever placed via Layout Builder later.
   *
   * A block referenced via entity_reference is NOT covered by this
   * class at all yet, for a deeper reason than just this exclusion: a
   * plain entity_reference field only stores a target_id, which always
   * resolves to whatever the referenced entity's current DEFAULT
   * revision is - there is no per-translation "which revision" pin the
   * way a Layout Builder component's own block_revision_id config
   * provides. Supporting it correctly would need the referencING field
   * to be entity_reference_revisions (which DOES pin a specific
   * revision, the same way Layout Builder's own component config does),
   * not plain entity_reference - a distinct feature, not built here.
   */
  protected function isGenericallyTranslatableTarget(ContentEntityInterface $entity): bool {
    if ($entity->getEntityTypeId() === 'block_content') {
      return FALSE;
    }
    return $this->contentTranslationManager->isEnabled($entity->getEntityTypeId(), $entity->bundle());
  }

  /**
   * @return array<string, array{source: string}>
   */
  protected function collectReferencedEntityItems(ContentEntityInterface $sourceBlock, string $targetBlockId): array {
    $items = [];
    $this->collectReferencedEntityItemsRecursive($sourceBlock, [$targetBlockId], [], $items, 1, $this->getReferenceMaxDepth());
    return $items;
  }

  /**
   * @param string[] $keyPath
   *   Field-name/delta path hops accumulated so far, used to build a
   *   unique, deterministic item key for each property found at any
   *   depth - starts as [$targetBlockId] and grows by [fieldName, delta]
   *   per hop.
   * @param string[] $visited
   *   "entity_type:id" strings for every referenced entity already
   *   walked into on this path, to detect and stop at a cycle.
   * @param array<string, array{source: string}> $items
   *   Accumulator, passed by reference.
   */
  protected function collectReferencedEntityItemsRecursive(ContentEntityInterface $entity, array $keyPath, array $visited, array &$items, int $depth, int $maxDepth): void {
    if ($depth > $maxDepth) {
      return;
    }
    $trackableProperties = tmgmt_external_translation_trackable_field_properties();

    foreach ($entity->getFieldDefinitions() as $fieldName => $definition) {
      if (!in_array($definition->getType(), self::REFERENCE_FIELD_TYPES, TRUE)) {
        continue;
      }
      foreach ($entity->get($fieldName) as $delta => $item) {
        $referencedEntity = $item->entity ?? NULL;
        if (!$referencedEntity instanceof ContentEntityInterface
          || !$this->isGenericallyTranslatableTarget($referencedEntity)) {
          continue;
        }
        $visitKey = $referencedEntity->getEntityTypeId() . ':' . $referencedEntity->id();
        if (in_array($visitKey, $visited, TRUE)) {
          continue;
        }
        $hopPath = array_merge($keyPath, [$fieldName, (string) $delta]);
        $sourceReferenced = $referencedEntity->getUntranslated();

        foreach ($sourceReferenced->getFieldDefinitions() as $refFieldName => $refDefinition) {
          if (!$refDefinition->isTranslatable() || !isset($trackableProperties[$refDefinition->getType()])) {
            continue;
          }
          foreach ($sourceReferenced->get($refFieldName) as $refDelta => $refItem) {
            foreach ($trackableProperties[$refDefinition->getType()] as $property) {
              if (!$refItem->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinition($property)) {
                continue;
              }
              $value = $refItem->get($property)->getValue();
              if (!is_scalar($value) || $value === '') {
                continue;
              }
              $key = $this->referenceItemKey(array_merge($hopPath, [$refFieldName, (string) $refDelta]), $property);
              $items[$key] = ['source' => (string) $value];
            }
          }
        }

        $this->collectReferencedEntityItemsRecursive($sourceReferenced, $hopPath, array_merge($visited, [$visitKey]), $items, $depth + 1, $maxDepth);
      }
    }
  }

  protected function blockFieldKey(string $targetBlockId, string $fieldName, int $delta, string $property = 'value'): string {
    return 'block:' . $targetBlockId . ':' . $fieldName . ':' . $delta . ':' . $property;
  }

  /**
   * @param string[] $path
   *   Alternating fieldName/delta hops (see collectReferencedEntityItemsRecursive()).
   */
  protected function referenceItemKey(array $path, string $property): string {
    return 'ref:' . implode(':', $path) . ':' . $property;
  }

  protected function labelKey(string $targetBlockId): string {
    return 'label:' . $targetBlockId;
  }

  /**
   * Translates a component's own display label (the heading shown when
   * "Display title" is checked) - config on the host entity's layout
   * field, not a block_content field, so it's handled separately from
   * completeBlockPair(). A genuine edit that conflicts creates a real
   * proposal (resolvable via ProposalActionEvent + LayoutLabelProposalHandler,
   * not the core module's generic entity-field write-back, which doesn't
   * apply here).
   *
   * Labels never fork or divide identity - label storage lives on the
   * HOST translation's own layout config, always independent per
   * translation already. The block referenced by the component never
   * gets a new id either (see plan Part 5 redesign): machine translation
   * and human edits both write in place via a new REVISION of the same
   * block_content id, repointing only the editing translation's own
   * component - so $targetRef->block->id() is stable across this whole
   * completion pass and needs no resolution.
   */
  protected function completeComponentLabel(InlineBlockReference $sourceRef, InlineBlockReference $targetRef, ContentEntityInterface $sourceHostTranslation, ContentEntityInterface $targetHostTranslation, string $sourceLangcode, string $targetLangcode, string $providerName, string $providerVersion, TranslationResponse $response): ?string {
    $sourceLabel = $this->collector->getComponentLabel($sourceHostTranslation, $sourceRef->sectionDelta, $sourceRef->componentUuid);
    if ($sourceLabel === NULL || $sourceLabel === '') {
      return NULL;
    }
    $currentTargetLabel = $this->collector->getComponentLabel($targetHostTranslation, $targetRef->sectionDelta, $targetRef->componentUuid) ?? '';

    $targetBlockId = (string) $targetRef->block->id();
    $translated = $response->getTranslation($this->labelKey($targetBlockId));
    if ($translated === NULL) {
      return NULL;
    }

    $context = new TranslationFieldContext(
      'block_content', (string) $sourceRef->block->id(), $sourceLangcode,
      $targetLangcode, 'block_content', $targetBlockId,
      'layout_component_label', 0, 'value',
    );

    if ($this->conflictDetector->hasEditorModificationForValue($context, $sourceLabel, $currentTargetLabel)) {
      $this->proposalManager->createProposal($context, $sourceLabel, $translated, $providerName, $providerVersion);
      return sprintf('Inline block %s label: new machine translation conflicts with an existing edit; proposal created.', $targetBlockId);
    }

    $this->provenanceManager->recordMachineTranslation($context, $sourceLabel, $translated, $providerName, $providerVersion);
    $this->collector->setComponentLabel($targetHostTranslation, $targetRef->sectionDelta, $targetRef->componentUuid, $translated);
    return sprintf('Inline block %s label: translated.', $targetBlockId);
  }

  /**
   * Writes translated field values directly onto the target block - the
   * SAME entity/id as the source when still shared, or a genuinely
   * independent entity if a prior human edit or structural addition put
   * a different block at this position (LayoutBlockMapper's id-then-
   * positional pairing already resolved that upstream). No forking of
   * any kind here: the write below creates a new REVISION of whatever
   * $targetRef->block currently is and repoints only this translation's
   * own component to it - mirroring exactly what core's own
   * InlineBlock::saveBlockContent() does for a human hand-edit (see plan
   * Part 5 redesign / Spike B), so both paths are indistinguishable to
   * every downstream consumer (mapper, staleness, proposals).
   *
   * The conflict check reads the target's LIVE value directly from
   * $targetBlock's own loaded field (hasEditorModificationForValue()),
   * not via the generic entity-translation-based hasEditorModification():
   * block_content stays a single-langcode entity throughout (never a
   * genuine Drupal translation of itself - see plan Part 5 redesign,
   * Layout Builder's own inline-block edit form isn't translation-context
   * aware, so making blocks properly multilingual would silently break
   * manual editing), so the generic hasTranslation()-based reader would
   * always find nothing to compare and never protect a hand-edited value.
   */
  protected function completeBlockPair(InlineBlockReference $sourceRef, InlineBlockReference $targetRef, ContentEntityInterface $targetHostTranslation, string $sourceLangcode, string $targetLangcode, string $providerName, string $providerVersion, TranslationResponse $response): array {
    // Reads via $sourceRef->block (the source translation's OWN component
    // pointer) deliberately, not a fresh reload - see
    // checkSourceBlockForStaleness()'s docblock for why: source and
    // target can be the same shared entity, and reading "whatever's
    // currently latest" would read back our own prior write as if it
    // were new source content.
    $sourceBlock = $sourceRef->block;
    $targetBlock = $targetRef->block;
    $targetBlockId = (string) $targetBlock->id();
    $messages = [];
    $trackableProperties = tmgmt_external_translation_trackable_field_properties();
    $changed = FALSE;

    foreach ($sourceBlock->getFieldDefinitions() as $fieldName => $definition) {
      if (!isset($trackableProperties[$definition->getType()]) || !$targetBlock->hasField($fieldName)) {
        continue;
      }
      foreach ($sourceBlock->get($fieldName) as $delta => $item) {
        foreach ($trackableProperties[$definition->getType()] as $property) {
          if (!$item->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinition($property)) {
            continue;
          }
          $sourceValue = $item->get($property)->getValue();
          if (!is_scalar($sourceValue) || $sourceValue === '') {
            continue;
          }
          $sourceValue = (string) $sourceValue;

          $translated = $response->getTranslation($this->blockFieldKey($targetBlockId, $fieldName, $delta, $property));
          if ($translated === NULL) {
            continue;
          }

          $context = new TranslationFieldContext(
            'block_content', (string) $sourceBlock->id(), $sourceLangcode,
            $targetLangcode, 'block_content', $targetBlockId,
            $fieldName, $delta, $property,
          );

          $liveValue = $targetBlock->get($fieldName)->get($delta)?->getValue()[$property] ?? NULL;
          $liveValue = is_scalar($liveValue) ? (string) $liveValue : NULL;

          if ($this->conflictDetector->hasEditorModificationForValue($context, $sourceValue, $liveValue)) {
            $this->proposalManager->createProposal($context, $sourceValue, $translated, $providerName, $providerVersion);
            $messages[] = sprintf('Inline block %s field %s (%s): new machine translation conflicts with an existing edit; proposal created.', $targetBlockId, $fieldName, $property);
            continue;
          }

          $this->provenanceManager->recordMachineTranslation($context, $sourceValue, $translated, $providerName, $providerVersion);
          $existing = $targetBlock->get($fieldName)->get($delta)?->getValue() ?? [];
          $targetBlock->get($fieldName)->set($delta, [$property => $translated] + $existing);
          $changed = TRUE;
          $messages[] = sprintf('Inline block %s field %s (%s): translated.', $targetBlockId, $fieldName, $property);
        }
      }
    }

    if ($changed) {
      // Explicit setNewRevision(TRUE) is required here, not optional: when
      // the block is still shared, $targetBlock is the SAME entity/revision
      // EN's own component also points at - without forcing a genuinely
      // NEW revision, save() would mutate that shared revision in place,
      // corrupting what EN renders too (confirmed by a real bug during
      // development: EN's block showed the translated text after a plain
      // save() with no explicit new-revision flag). The layout's stored
      // block_revision_id pointer is now stale either way - repoint it and
      // save the host translation, or Layout Builder would keep rendering
      // the pre-translation revision (spec section 32). EN's own component
      // is deliberately left untouched, still pointing at the prior
      // revision - exactly mirroring core's own human-edit behavior.
      $targetBlock->setNewRevision(TRUE);
      $targetBlock->save();
      $this->collector->repointComponent($targetHostTranslation, $targetRef->sectionDelta, $targetRef->componentUuid, $targetBlock);
    }

    return $messages;
  }

}
