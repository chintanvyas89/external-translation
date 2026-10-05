<?php

namespace Drupal\tmgmt_external_translation\Client;

use Drupal\Core\State\StateInterface;

/**
 * POC stand-in for the real external translation API.
 *
 * Returns the source string prefixed with the target language code, e.g.
 * "Hello world" translated to French becomes "[fr] Hello world". This is
 * deterministic and requires no network access, which keeps the POC
 * independent of any real vendor while the rest of the pipeline (TMGMT
 * job orchestration, provenance, conflict detection, proposals) is built
 * and tested.
 *
 * submit()/checkStatus() simulate a genuinely asynchronous provider: a
 * request isn't ready until it's been checked
 * `tmgmt_external_translation.mock_client.ready_after_checks` times
 * (default 1, i.e. ready on the first check - keeps existing single-poll
 * test flows working unless a test deliberately wants to exercise real
 * delay). State persists across separate PHP processes via the State
 * API, since submit() and checkStatus() are typically called from
 * different requests/drush invocations.
 */
class MockTranslationApiClient implements TranslationApiClientInterface {

  const STATE_KEY_PREFIX = 'tmgmt_external_translation.mock_client.request.';
  const STATE_KEY_READY_AFTER_CHECKS = 'tmgmt_external_translation.mock_client.ready_after_checks';

  public function __construct(
    protected StateInterface $state,
  ) {}

  public function translate(string $sourceLanguage, string $targetLanguage, array $items): TranslationResponse {
    return $this->buildResponse($targetLanguage, $items);
  }

  public function submit(string $sourceLanguage, string $targetLanguage, array $items): string {
    $requestId = 'mock-' . \Drupal::service('uuid')->generate();
    $this->state->set(self::STATE_KEY_PREFIX . $requestId, [
      'source_langcode' => $sourceLanguage,
      'target_langcode' => $targetLanguage,
      'items' => $items,
      'submitted_check_count' => 0,
      'ready_after_checks' => (int) $this->state->get(self::STATE_KEY_READY_AFTER_CHECKS, 1),
      'created' => \Drupal::time()->getRequestTime(),
    ]);
    return $requestId;
  }

  public function checkStatus(string $requestId): ?TranslationResponse {
    $entry = $this->state->get(self::STATE_KEY_PREFIX . $requestId);
    if ($entry === NULL) {
      return NULL;
    }
    $entry['submitted_check_count']++;
    $this->state->set(self::STATE_KEY_PREFIX . $requestId, $entry);

    if ($entry['submitted_check_count'] < $entry['ready_after_checks']) {
      return NULL;
    }
    return $this->buildResponse($entry['target_langcode'], $entry['items']);
  }

  protected function buildResponse(string $targetLanguage, array $items): TranslationResponse {
    $response = new TranslationResponse();
    foreach ($items as $itemId => $item) {
      $source = $item['source'] ?? '';
      $response->setTranslation((string) $itemId, '[' . $targetLanguage . '] ' . $source);
    }
    return $response;
  }

  public function getProviderName(): string {
    return 'mock';
  }

  public function getProviderVersion(): string {
    return '1.0';
  }

}
