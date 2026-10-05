<?php

namespace Drupal\tmgmt_external_translation\Translation;

/**
 * Reads the live value of a translated field slot directly off the entity.
 */
interface TranslationFieldValueReaderInterface {

  public function getLiveValue(TranslationFieldContext $context): ?string;

}
