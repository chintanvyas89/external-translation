<?php

namespace Drupal\tmgmt_external_translation\Translation;

use Drupal\Core\Entity\EntityTypeManagerInterface;

class TranslationFieldValueReader implements TranslationFieldValueReaderInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function getLiveValue(TranslationFieldContext $context): ?string {
    $storage = $this->entityTypeManager->getStorage($context->targetEntityType);
    $entity = $storage->load($context->targetEntityId);
    if (!$entity || !$entity->hasTranslation($context->targetLangcode)) {
      return NULL;
    }
    $translation = $entity->getTranslation($context->targetLangcode);
    if (!$translation->hasField($context->fieldName) || $translation->get($context->fieldName)->isEmpty()) {
      return NULL;
    }
    $item = $translation->get($context->fieldName)->get($context->fieldDelta);
    if (!$item) {
      return NULL;
    }
    $value = $item->get($context->fieldProperty)->getValue();
    return is_scalar($value) ? (string) $value : NULL;
  }

}
