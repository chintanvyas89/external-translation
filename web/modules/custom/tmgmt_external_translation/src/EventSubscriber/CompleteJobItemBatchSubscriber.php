<?php

namespace Drupal\tmgmt_external_translation\EventSubscriber;

use Drupal\tmgmt_external_translation\Event\TranslationBatchCompletedEvent;
use Drupal\tmgmt_external_translation\Translation\JobItemBatchCompleterInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies a ready async batch to its TMGMT job item (kind=job_item) -
 * the core module's own category, alongside the layout_builder
 * submodule's equivalent subscriber for kind=layout_inline_blocks.
 */
class CompleteJobItemBatchSubscriber implements EventSubscriberInterface {

  const KIND = 'job_item';

  public function __construct(
    protected JobItemBatchCompleterInterface $completer,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      TranslationBatchCompletedEvent::EVENT_NAME => 'onBatchCompleted',
    ];
  }

  public function onBatchCompleted(TranslationBatchCompletedEvent $event): void {
    if (($event->pendingBatchRow['kind'] ?? NULL) !== self::KIND) {
      return;
    }
    $this->completer->complete($event->pendingBatchRow, $event->response);
  }

}
