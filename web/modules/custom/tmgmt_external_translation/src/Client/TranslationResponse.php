<?php

namespace Drupal\tmgmt_external_translation\Client;

/**
 * Value object holding the result of a translation API call.
 */
class TranslationResponse {

  /**
   * @var array
   *   Keyed by the item id passed in the request, value is the translated
   *   string.
   */
  protected array $translations = [];

  public function setTranslation(string $itemId, string $translatedText): void {
    $this->translations[$itemId] = $translatedText;
  }

  public function getTranslation(string $itemId): ?string {
    return $this->translations[$itemId] ?? NULL;
  }

  /**
   * @return array
   *   All translations keyed by item id.
   */
  public function getTranslations(): array {
    return $this->translations;
  }

}
