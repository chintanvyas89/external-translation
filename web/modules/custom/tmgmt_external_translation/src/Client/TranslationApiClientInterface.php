<?php

namespace Drupal\tmgmt_external_translation\Client;

/**
 * Interface for the external machine translation provider.
 *
 * Implementations must remain Drupal-independent: they receive plain
 * source/target language codes and an item list keyed by an opaque id
 * owned by the caller, and must never modify Drupal entities directly.
 */
interface TranslationApiClientInterface {

  /**
   * Translates a batch of items synchronously.
   *
   * Kept as a convenience path for callers with no job-item/entity
   * lifecycle to defer against (e.g. interface strings, via
   * InterfaceTranslationManager) - everything else (ExternalApiTranslator,
   * LayoutBlockTranslationService) uses submit()/checkStatus() instead, to
   * genuinely support a provider that takes real time to respond.
   *
   * @param string $sourceLanguage
   *   Source language code.
   * @param string $targetLanguage
   *   Target language code.
   * @param array $items
   *   Array keyed by an opaque item id, each value an array with at least
   *   a 'source' key containing the text to translate. May contain a
   *   'context' key with metadata (entity_type, bundle, field, etc.).
   *
   * @return \Drupal\tmgmt_external_translation\Client\TranslationResponse
   *   The translation response.
   */
  public function translate(string $sourceLanguage, string $targetLanguage, array $items): TranslationResponse;

  /**
   * Submits a batch of items for asynchronous translation.
   *
   * @return string
   *   An opaque request id the caller must persist and pass to
   *   checkStatus() later. Callers must not assume any particular format.
   */
  public function submit(string $sourceLanguage, string $targetLanguage, array $items): string;

  /**
   * Polls a previously submitted request.
   *
   * Implementations must be safe to call repeatedly (idempotent) and
   * must return the same response on every call after readiness.
   *
   * @return \Drupal\tmgmt_external_translation\Client\TranslationResponse|null
   *   NULL if still pending; the response once ready.
   */
  public function checkStatus(string $requestId): ?TranslationResponse;

  /**
   * The machine-readable name of the provider, for provenance logging.
   */
  public function getProviderName(): string;

  /**
   * The provider/client version, for provenance logging.
   */
  public function getProviderVersion(): string;

}
