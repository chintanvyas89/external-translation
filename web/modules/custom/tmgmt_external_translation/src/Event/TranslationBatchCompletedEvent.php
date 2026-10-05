<?php

namespace Drupal\tmgmt_external_translation\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\tmgmt_external_translation\Client\TranslationResponse;

/**
 * Dispatched by TranslationPollingService once a previously-submitted
 * async translation request is ready. The polling service itself doesn't
 * know how to apply a batch (that's kind-specific - job_item vs.
 * layout_inline_blocks); it only detects readiness and announces it,
 * mirroring EntityTranslatedEvent/EntityFieldWrittenEvent so the core
 * module doesn't need to depend on the submodules that actually consume
 * this.
 */
class TranslationBatchCompletedEvent extends Event {

  const EVENT_NAME = 'tmgmt_external_translation.translation_batch_completed';

  public function __construct(
    public readonly array $pendingBatchRow,
    public readonly TranslationResponse $response,
  ) {}

}
