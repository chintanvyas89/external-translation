<?php

namespace Drupal\tmgmt_external_translation\Translation;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Writes a translated value onto an arbitrary content entity's
 * translation - creating that translation first if the entity supports
 * Drupal content_translation and doesn't have one yet for the target
 * language.
 *
 * Entity-agnostic (works for Media, or any other genuinely-translatable
 * entity type). Deliberately separate from
 * TranslationProposalManager::writeToEntity() (proposal accept/edit only,
 * never creates a missing translation): this is for the "referenced
 * entity" case (e.g. a Media item referenced from a Layout Builder
 * block's entity_reference field), which needs different semantics from
 * a Layout Builder block itself - blocks stay single-langcode forever
 * (see LayoutBlockTranslationService's docblock), but a genuinely
 * translatable referenced entity should get its own proper Drupal
 * translation, the same as an ordinary node would.
 */
interface TranslationEntityWriterInterface {

  /**
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The saved translation that was written to.
   */
  public function write(TranslationFieldContext $context, string $value): ContentEntityInterface;

}
