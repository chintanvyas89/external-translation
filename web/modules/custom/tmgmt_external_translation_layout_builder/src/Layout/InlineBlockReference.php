<?php

namespace Drupal\tmgmt_external_translation_layout_builder\Layout;

use Drupal\block_content\BlockContentInterface;

/**
 * An inline block plus enough information to find and update the exact
 * layout component that references it (needed after translating it,
 * since saving a block_content entity creates a new revision - the
 * component's block_revision_id pointer must be updated to match, or
 * Layout Builder will keep rendering the pre-translation revision).
 */
final class InlineBlockReference {

  public function __construct(
    public readonly BlockContentInterface $block,
    public readonly int $sectionDelta,
    public readonly string $componentUuid,
  ) {}

}
