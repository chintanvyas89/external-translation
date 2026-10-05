<?php

namespace Drupal\tmgmt_external_translation\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Dispatched whenever TranslationProposalManager writes a value directly
 * to an entity (Accept or Edit). Lets optional modules react to writes
 * regardless of which code path performed them - e.g.
 * tmgmt_external_translation_layout_builder uses this to detect when a
 * block_content entity referenced by a layout was just given a new
 * revision, so it can repoint the layout's component to it.
 */
class EntityFieldWrittenEvent extends Event {

  const EVENT_NAME = 'tmgmt_external_translation.entity_field_written';

  public function __construct(
    public readonly string $entityType,
    public readonly string $entityId,
    public readonly string $langcode,
  ) {}

}
