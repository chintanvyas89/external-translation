<?php

namespace Drupal\tmgmt_external_translation\Translation;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;

class TranslationEntityWriter implements TranslationEntityWriterInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModerationGateInterface $moderationGate,
  ) {}

  public function write(TranslationFieldContext $context, string $value): ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage($context->targetEntityType);
    $entity = $storage->load($context->targetEntityId);
    $translation = $this->getOrCreateTranslation($entity, $context->targetLangcode);

    $translation->get($context->fieldName)->set($context->fieldDelta, [
      $context->fieldProperty => $value,
    ] + ($translation->get($context->fieldName)->get($context->fieldDelta)?->getValue() ?? []));

    if ($translation instanceof RevisionableInterface) {
      $translation->setNewRevision(TRUE);
    }
    $this->moderationGate->gateIfPublished($translation);
    $translation->save();

    return $translation;
  }

  /**
   * Non-translatable entity types (e.g. block_content, per this module's
   * own deliberate design) fall back to the entity itself. A translatable
   * entity that doesn't yet have the target language gets one created
   * from its current source values - mirroring core's own
   * ContentTranslationController::prepareTranslation() seeding behavior,
   * so a freshly-created referenced-entity translation starts the same
   * way any other new translation would.
   */
  protected function getOrCreateTranslation(ContentEntityInterface $entity, string $langcode): ContentEntityInterface {
    if (!$entity->getEntityType()->isTranslatable()) {
      return $entity;
    }
    if ($entity->hasTranslation($langcode)) {
      return $entity->getTranslation($langcode);
    }
    return $entity->addTranslation($langcode, $entity->toArray());
  }

}
