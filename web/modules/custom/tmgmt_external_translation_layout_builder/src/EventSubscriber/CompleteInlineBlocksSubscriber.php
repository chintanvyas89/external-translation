<?php

namespace Drupal\tmgmt_external_translation_layout_builder\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\tmgmt_external_translation\Event\TranslationBatchCompletedEvent;
use Drupal\tmgmt_external_translation_layout_builder\Layout\LayoutBlockTranslationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies a ready async batch of inline blocks/labels (kind=layout_inline_blocks) -
 * the async counterpart to TranslateInlineBlocksSubscriber, which only
 * submits.
 */
class CompleteInlineBlocksSubscriber implements EventSubscriberInterface {

  const KIND = 'layout_inline_blocks';

  protected LoggerInterface $logger;

  public function __construct(
    protected LayoutBlockTranslationService $translationService,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('tmgmt_external_translation_layout_builder');
  }

  public static function getSubscribedEvents(): array {
    return [
      TranslationBatchCompletedEvent::EVENT_NAME => 'onBatchCompleted',
    ];
  }

  public function onBatchCompleted(TranslationBatchCompletedEvent $event): void {
    if (($event->pendingBatchRow['kind'] ?? NULL) !== self::KIND) {
      return;
    }

    $messages = $this->translationService->completeInlineBlocks($event->pendingBatchRow, $event->response);
    foreach ($messages as $message) {
      $this->logger->info('@host_type @host_id (@source -> @target): @message', [
        '@host_type' => $event->pendingBatchRow['host_entity_type'],
        '@host_id' => $event->pendingBatchRow['host_entity_id'],
        '@source' => $event->pendingBatchRow['source_langcode'],
        '@target' => $event->pendingBatchRow['target_langcode'],
        '@message' => $message,
      ]);
    }
  }

}
