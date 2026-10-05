<?php

namespace Drupal\tmgmt_external_translation_layout_builder\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\tmgmt_external_translation\Event\EntityTranslationRequestedEvent;
use Drupal\tmgmt_external_translation_layout_builder\Layout\LayoutBlockTranslationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When the core module submits a host entity's normal fields for
 * translation, also submit any inline blocks referenced by its Layout
 * Builder override at the same time - so a single "Translate" action
 * covers both, without the core module needing to know Layout Builder
 * exists.
 *
 * Reacts to the submit-time event (EntityTranslationRequestedEvent), not
 * the completion-time one (EntityTranslatedEvent): block/label translation
 * only depends on the target-language layout already existing, not on the
 * host's own fields being translated, so both async batches can run
 * concurrently instead of the block batch waiting for the host batch to
 * finish first.
 */
class TranslateInlineBlocksSubscriber implements EventSubscriberInterface {

  protected LoggerInterface $logger;

  public function __construct(
    protected LayoutBlockTranslationService $translationService,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('tmgmt_external_translation_layout_builder');
  }

  public static function getSubscribedEvents(): array {
    return [
      EntityTranslationRequestedEvent::EVENT_NAME => 'onEntityTranslationRequested',
    ];
  }

  public function onEntityTranslationRequested(EntityTranslationRequestedEvent $event): void {
    $messages = $this->translationService->submitInlineBlocks(
      $event->entityType,
      $event->entityId,
      $event->sourceLangcode,
      $event->targetLangcode,
      $event->providerName,
      $event->providerVersion,
    );
    foreach ($messages as $message) {
      $this->logger->info('@entity_type @entity_id (@source -> @target): @message', [
        '@entity_type' => $event->entityType,
        '@entity_id' => $event->entityId,
        '@source' => $event->sourceLangcode,
        '@target' => $event->targetLangcode,
        '@message' => $message,
      ]);
    }
  }

}
