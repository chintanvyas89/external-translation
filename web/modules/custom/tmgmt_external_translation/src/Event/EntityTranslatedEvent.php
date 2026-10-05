<?php

namespace Drupal\tmgmt_external_translation\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Dispatched after the core translation engine finishes processing a
 * content job item (its normal fields have been translated, or diverted
 * to a proposal). Lets optional modules - e.g.
 * tmgmt_external_translation_layout_builder - extend the same "Translate"
 * action to cover related content (inline blocks) without the core module
 * needing to know anything about Layout Builder.
 */
class EntityTranslatedEvent extends Event {

  const EVENT_NAME = 'tmgmt_external_translation.entity_translated';

  public function __construct(
    public readonly string $entityType,
    public readonly string $entityId,
    public readonly string $sourceLangcode,
    public readonly string $targetLangcode,
    public readonly string $providerName,
    public readonly string $providerVersion,
  ) {}

}
