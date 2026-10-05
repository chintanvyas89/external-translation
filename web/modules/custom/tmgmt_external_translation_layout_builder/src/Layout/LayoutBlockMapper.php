<?php

namespace Drupal\tmgmt_external_translation_layout_builder\Layout;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Pairs source-language inline blocks with their target-language
 * counterparts.
 *
 * Under the shared-by-default model (plan Part 5), a target component
 * commonly references the EXACT SAME block_content id as its source
 * counterpart (unforked - editors haven't touched it, and neither has our
 * MT pipeline yet) - this is now the *common* case, not an error, so
 * pairing matches by block id first. Only once a block has actually
 * forked (via a hand-edit or LayoutEntityCollector::forkIfShared()) do
 * source/target ids permanently differ - layout_builder_at's eager
 * "copy blocks into translation" duplication (still available as an
 * opt-in escape hatch) also always produces distinct ids from day one.
 * For any references that can't be matched by id - already-forked pairs,
 * or genuinely independent per-language additions an editor made - fall
 * back to positional pairing (section delta, then component weight)
 * among the remainder only.
 *
 * Known limitation (documented, not solved, for this POC): among already-
 * forked/independent components, if an editor reorders them independently
 * in one language but not the other, positional pairing among the
 * remainder can still mismatch. Fixing this would require
 * layout_builder_at to expose an explicit source-to-target block mapping,
 * which it does not currently do.
 */
class LayoutBlockMapper {

  public function __construct(
    protected LayoutEntityCollector $collector,
  ) {}

  /**
   * @return array{0: \Drupal\tmgmt_external_translation_layout_builder\Layout\InlineBlockReference, 1: \Drupal\tmgmt_external_translation_layout_builder\Layout\InlineBlockReference}[]
   *   Ordered [sourceReference, targetReference] pairs.
   */
  public function mapBlocks(ContentEntityInterface $sourceTranslation, ContentEntityInterface $targetTranslation): array {
    $sourceBlocks = $this->collector->collectInlineBlocks($sourceTranslation);
    $targetBlocks = $this->collector->collectInlineBlocks($targetTranslation);

    return $this->pairByIdThenPosition($sourceBlocks, $targetBlocks);
  }

  /**
   * @param \Drupal\tmgmt_external_translation_layout_builder\Layout\InlineBlockReference[] $sourceBlocks
   * @param \Drupal\tmgmt_external_translation_layout_builder\Layout\InlineBlockReference[] $targetBlocks
   *
   * @return array{0: \Drupal\tmgmt_external_translation_layout_builder\Layout\InlineBlockReference, 1: \Drupal\tmgmt_external_translation_layout_builder\Layout\InlineBlockReference}[]
   */
  protected function pairByIdThenPosition(array $sourceBlocks, array $targetBlocks): array {
    $unmatchedTargetsByIndex = [];
    foreach ($targetBlocks as $index => $targetRef) {
      $unmatchedTargetsByIndex[$index] = $targetRef;
    }

    $pairs = [];
    $unmatchedSources = [];
    foreach ($sourceBlocks as $sourceRef) {
      $matchedIndex = NULL;
      foreach ($unmatchedTargetsByIndex as $index => $targetRef) {
        if ((string) $targetRef->block->id() === (string) $sourceRef->block->id()) {
          $matchedIndex = $index;
          break;
        }
      }
      if ($matchedIndex !== NULL) {
        $pairs[] = [$sourceRef, $unmatchedTargetsByIndex[$matchedIndex]];
        unset($unmatchedTargetsByIndex[$matchedIndex]);
      }
      else {
        $unmatchedSources[] = $sourceRef;
      }
    }

    $remainingTargets = array_values($unmatchedTargetsByIndex);
    $count = min(count($unmatchedSources), count($remainingTargets));
    for ($i = 0; $i < $count; $i++) {
      $pairs[] = [$unmatchedSources[$i], $remainingTargets[$i]];
    }

    return $pairs;
  }

}
