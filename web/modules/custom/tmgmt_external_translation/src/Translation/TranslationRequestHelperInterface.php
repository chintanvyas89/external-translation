<?php

namespace Drupal\tmgmt_external_translation\Translation;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Programmatically requests (and accepts) machine translation of an entity
 * translation, for the "Also request machine translation" checkbox on the
 * standard content-translation "Add translation" form.
 */
interface TranslationRequestHelperInterface {

  /**
   * Creates a TMGMT job for $entity (en -> $targetLangcode, translator
   * external_api), requests translation, and accepts every resulting job
   * item so the translated values land immediately.
   *
   * Never throws: any failure (translator missing, entity type unsupported,
   * job not applicable) is reported back in the result rather than
   * propagated, since the caller (a form submit handler) runs after the
   * entity's own save has already succeeded and must not fail because of
   * this best-effort automation.
   *
   * @return array{success: bool, message: \Drupal\Core\StringTranslation\TranslatableMarkup}
   */
  public function requestMachineTranslationForNewTranslation(ContentEntityInterface $entity, string $targetLangcode): array;

}
