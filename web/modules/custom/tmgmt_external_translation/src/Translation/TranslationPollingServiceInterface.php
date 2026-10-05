<?php

namespace Drupal\tmgmt_external_translation\Translation;

/**
 * Scans outstanding async translation requests and announces the ones
 * that are ready. Callable directly (from drush scripts, or the request
 * helper) or from hook_cron() for real deployments.
 */
interface TranslationPollingServiceInterface {

  /**
   * @return array{checked: int, completed: int, still_pending: int, failed: int}
   */
  public function processAllPending(): array;

}
