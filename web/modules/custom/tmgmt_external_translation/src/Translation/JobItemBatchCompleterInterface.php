<?php

namespace Drupal\tmgmt_external_translation\Translation;

use Drupal\tmgmt_external_translation\Client\TranslationResponse;

/**
 * Applies a ready async translation response to the job item it belongs
 * to: the exact per-field conflict-check/write/proposal logic that used
 * to run inline in ExternalApiTranslator::requestJobItemsTranslation(),
 * now invoked once TranslationPollingService confirms the response is
 * ready, instead of immediately after submit().
 */
interface JobItemBatchCompleterInterface {

  /**
   * @param array $pendingBatchRow
   *   A row from translation_pending_batch (kind=job_item).
   */
  public function complete(array $pendingBatchRow, TranslationResponse $response): void;

}
