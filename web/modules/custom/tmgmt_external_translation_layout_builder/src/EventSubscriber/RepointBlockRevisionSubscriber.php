<?php

namespace Drupal\tmgmt_external_translation_layout_builder\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\tmgmt_external_translation\Event\EntityFieldWrittenEvent;
use Drupal\tmgmt_external_translation_layout_builder\Layout\LayoutEntityCollector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Whenever the core module writes a translated value directly to an
 * entity (proposal Accept/Edit), and that entity is a block_content used
 * as a Layout Builder inline block, the write creates a new block
 * revision - repoint the layout component that references it, or Layout
 * Builder keeps rendering the stale pre-write revision (spec section 32).
 *
 * This covers the gap the direct "safe to write" path in
 * LayoutBlockTranslationService can't: it doesn't know when a write came
 * from the core module's generic proposal flow instead.
 */
class RepointBlockRevisionSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LayoutEntityCollector $collector,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      EntityFieldWrittenEvent::EVENT_NAME => 'onEntityFieldWritten',
    ];
  }

  public function onEntityFieldWritten(EntityFieldWrittenEvent $event): void {
    if ($event->entityType !== 'block_content') {
      return;
    }

    // Under the shared-by-default model (plan Part 5), the same block id
    // commonly appears in MORE THAN ONE translation's layout at once
    // (shared, not yet manually forked) - a naive "first match" scan
    // would repoint whichever translation's layout happens to be
    // iterated first (e.g. always EN, if translation languages are
    // returned in a stable order), not the translation the write was
    // actually FOR. The event carries the write's own target langcode -
    // use it as findComponentForBlock()'s preferred langcode to
    // disambiguate correctly, reusing the exact same logic
    // checkSourceBlockForStaleness() relies on for the equivalent problem.
    $located = $this->collector->findComponentForBlock($event->entityId, $event->langcode);
    if (!$located) {
      return;
    }
    $freshBlock = $this->entityTypeManager->getStorage('block_content')->load($event->entityId);
    $this->collector->repointComponent($located['hostTranslation'], $located['sectionDelta'], $located['componentUuid'], $freshBlock);
  }

}
