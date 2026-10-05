<?php

namespace Drupal\tmgmt_external_translation\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Dispatched as soon as a content job item's fields have been *submitted*
 * to the provider (not yet translated - see EntityTranslatedEvent for
 * that). Lets optional modules - e.g. tmgmt_external_translation_layout_builder -
 * submit related async work (inline blocks) at the same time as the host
 * entity's own fields, instead of waiting for the host batch to complete
 * first: block/label translation only depends on the target-language
 * layout already existing, not on the host's own fields being done, so
 * there's no reason to serialize the two async round-trips.
 */
class EntityTranslationRequestedEvent extends Event {

  const EVENT_NAME = 'tmgmt_external_translation.entity_translation_requested';

  public function __construct(
    public readonly string $entityType,
    public readonly string $entityId,
    public readonly string $sourceLangcode,
    public readonly string $targetLangcode,
    public readonly string $providerName,
    public readonly string $providerVersion,
  ) {}

}
