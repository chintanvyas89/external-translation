<?php

namespace Drupal\tmgmt_external_translation\Translation;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\tmgmt_external_translation\Client\TranslationApiClientInterface;
use Drupal\tmgmt_external_translation\Event\TranslationBatchCompletedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class TranslationPollingService implements TranslationPollingServiceInterface {

  const TABLE = 'translation_pending_batch';

  public function __construct(
    protected Connection $database,
    protected TranslationApiClientInterface $client,
    protected EventDispatcherInterface $eventDispatcher,
    protected TimeInterface $time,
  ) {}

  /**
   * Max passes per call, purely as a runaway safety net - in practice the
   * loop below stops itself (see processAllPending()'s docblock) long
   * before this is ever reached.
   */
  const MAX_PASSES = 20;

  public function processAllPending(): array {
    $totals = ['checked' => 0, 'completed' => 0, 'still_pending' => 0, 'failed' => 0];

    // Completing a batch can dispatch TranslationBatchCompletedEvent
    // subscribers that themselves submit a NEW async batch (e.g. a job
    // item's fields completing triggers Layout Builder's
    // TranslateInlineBlocksSubscriber to submit its own inline-blocks
    // batch) - that new batch is inserted mid-call, too late for a single
    // row scan to see. Loop passes as long as the previous pass completed
    // at least one item, so a cascade of instantly-ready completions all
    // resolve within one call; stop as soon as a pass completes nothing,
    // which correctly leaves genuinely still-pending (real provider
    // delay) batches for a later call instead of spinning on them.
    $pass = 0;
    do {
      $summary = $this->processOnePass();
      $totals['checked'] += $summary['checked'];
      $totals['completed'] += $summary['completed'];
      $totals['still_pending'] = $summary['still_pending'];
      $totals['failed'] += $summary['failed'];
      $pass++;
    } while ($summary['completed'] > 0 && $pass < self::MAX_PASSES);

    return $totals;
  }

  protected function processOnePass(): array {
    $summary = ['checked' => 0, 'completed' => 0, 'still_pending' => 0, 'failed' => 0];

    $rows = $this->database->select(self::TABLE, 'b')
      ->fields('b')
      ->condition('status', 'pending')
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);

    foreach ($rows as $id => $row) {
      $summary['checked']++;
      $response = $this->client->checkStatus($row['request_id']);
      if ($response === NULL) {
        $summary['still_pending']++;
        continue;
      }

      $this->database->update(self::TABLE)
        ->fields(['status' => 'completed', 'changed' => $this->time->getRequestTime()])
        ->condition('id', $id)
        ->execute();

      $this->eventDispatcher->dispatch(
        new TranslationBatchCompletedEvent($row, $response),
        TranslationBatchCompletedEvent::EVENT_NAME,
      );
      $summary['completed']++;
    }

    return $summary;
  }

}
