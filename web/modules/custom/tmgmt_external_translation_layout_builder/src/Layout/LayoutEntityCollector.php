<?php

namespace Drupal\tmgmt_external_translation_layout_builder\Layout;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\layout_builder\InlineBlockUsageInterface;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\SectionComponent;
use Drupal\tmgmt_external_translation\Translation\ModerationGateInterface;

/**
 * Discovers the inline blocks referenced by one entity translation's
 * Layout Builder override, in a stable, deterministic order (section
 * delta, then component weight) - the same order layout_builder_at's
 * copy widget iterates them in, which is what makes positional pairing
 * between source and target layouts meaningful (see LayoutBlockMapper).
 *
 * Deliberately does not touch the serialized layout configuration
 * directly - only reads/writes it through Layout Builder's own
 * Section/SectionComponent API.
 */
class LayoutEntityCollector {

  const FIELD_NAME = OverridesSectionStorage::FIELD_NAME;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected InlineBlockUsageInterface $inlineBlockUsage,
    protected ModerationGateInterface $moderationGate,
  ) {}

  /**
   * @return bool
   *   Whether this entity/bundle has an overridable Layout Builder field.
   */
  public function hasLayout(ContentEntityInterface $entity): bool {
    return $entity->hasField(self::FIELD_NAME) && !$entity->get(self::FIELD_NAME)->isEmpty();
  }

  /**
   * @return \Drupal\tmgmt_external_translation_layout_builder\Layout\InlineBlockReference[]
   *   Inline block references in section/weight order. Each wraps the
   *   specific block_content revision Layout Builder currently references,
   *   plus enough addressing information to repoint the component after
   *   the block gets a new revision (see repointComponent()).
   */
  public function collectInlineBlocks(ContentEntityInterface $entity): array {
    if (!$this->hasLayout($entity)) {
      return [];
    }

    $references = [];
    $storage = $this->entityTypeManager->getStorage('block_content');

    /** @var \Drupal\layout_builder\Section[] $sections */
    $sections = $entity->get(self::FIELD_NAME)->getSections();
    foreach ($sections as $sectionDelta => $section) {
      $components = $section->getComponents();
      uasort($components, function (SectionComponent $a, SectionComponent $b) {
        return $a->getWeight() <=> $b->getWeight();
      });
      foreach ($components as $component) {
        $configuration = $component->get('configuration');
        if (empty($configuration['id']) || !str_starts_with($configuration['id'], 'inline_block:')) {
          continue;
        }
        if (empty($configuration['block_revision_id'])) {
          continue;
        }
        $block = $storage->loadRevision($configuration['block_revision_id']);
        if ($block) {
          $references[] = new InlineBlockReference($block, (int) $sectionDelta, $component->getUuid());
        }
      }
    }

    return $references;
  }

  /**
   * @return string|null
   *   The component's own display label (shown as the block's heading
   *   when "Display title" is checked) - config on the host entity's
   *   layout field, not a field on the block_content entity itself.
   *   NULL if the component/section can't be found.
   */
  public function getComponentLabel(ContentEntityInterface $hostTranslation, int $sectionDelta, string $componentUuid): ?string {
    $sections = $hostTranslation->get(self::FIELD_NAME)->getSections();
    if (!isset($sections[$sectionDelta])) {
      return NULL;
    }
    $component = $sections[$sectionDelta]->getComponent($componentUuid);
    if (!$component) {
      return NULL;
    }
    $configuration = $component->get('configuration');
    return is_scalar($configuration['label'] ?? NULL) ? (string) $configuration['label'] : NULL;
  }

  /**
   * Writes a new component label and saves the host translation.
   */
  public function setComponentLabel(ContentEntityInterface $hostTranslation, int $sectionDelta, string $componentUuid, string $label): void {
    $fieldItemList = $hostTranslation->get(self::FIELD_NAME);
    $sections = $fieldItemList->getSections();
    if (!isset($sections[$sectionDelta])) {
      return;
    }
    $section = $sections[$sectionDelta];
    $component = $section->getComponent($componentUuid);
    if (!$component) {
      return;
    }
    $configuration = $component->get('configuration');
    $configuration['label'] = $label;
    $component->setConfiguration($configuration);

    $fieldItemList->setValue($sections);
    // If the host page is currently published, a translated label
    // shouldn't silently go live without review - same rule as any other
    // machine-written text (see ModerationGate).
    $this->moderationGate->gateIfPublished($hostTranslation);
    $hostTranslation->save();
  }

  /**
   * Given a block_content id, finds the host entity translation whose
   * layout currently references it as an inline block, plus its section
   * delta and component uuid - the reverse lookup a proposal-resolution
   * path needs (it only has the block id, not the placement), extracted
   * from the same usage-table + translation-scan logic
   * RepointBlockRevisionSubscriber already performs for the equivalent
   * problem.
   *
   * Under the shared-by-default model (plan Part 5), a block id is never
   * duplicated into a new entity - the SAME block_content id can appear
   * in MULTIPLE translations' layouts, potentially indefinitely (not just
   * transiently pre-fork), since the same-id/new-revision mechanism is
   * used for BOTH machine translation writes and human edits alike. So
   * "first match wins" would be arbitrary iteration order, not
   * semantically "the source". When $preferredLangcode is given and
   * matches, it wins immediately; otherwise, if the block appears in
   * multiple translations, the host entity's own default/original
   * translation is preferred as the authoritative source (this project's
   * data is always English-original - verified via node_field_data - but
   * the check itself works for any langcode). If the block only appears
   * in one translation (a translation-specific addition), that
   * unambiguous match is returned regardless of either preference.
   *
   * @return array{hostTranslation: \Drupal\Core\Entity\ContentEntityInterface, sectionDelta: int, componentUuid: string, blockRevisionId: string}|null
   */
  public function findComponentForBlock(string $blockId, ?string $preferredLangcode = NULL): ?array {
    $usage = $this->inlineBlockUsage->getUsage((int) $blockId);
    if (!$usage) {
      return NULL;
    }
    $hostStorage = $this->entityTypeManager->getStorage($usage->layout_entity_type);
    $hostEntity = $hostStorage->load($usage->layout_entity_id);
    if (!$hostEntity) {
      return NULL;
    }

    $matches = [];
    foreach ($hostEntity->getTranslationLanguages() as $langcode => $language) {
      $translation = $hostEntity->getTranslation($langcode);
      if (!$this->hasLayout($translation)) {
        continue;
      }
      foreach ($this->collectInlineBlocks($translation) as $reference) {
        if ((string) $reference->block->id() === $blockId) {
          $matches[$langcode] = [
            'hostTranslation' => $translation,
            'sectionDelta' => $reference->sectionDelta,
            'componentUuid' => $reference->componentUuid,
            'blockRevisionId' => (string) $reference->block->getRevisionId(),
          ];
          break;
        }
      }
    }

    if (!$matches) {
      return NULL;
    }
    if (count($matches) === 1) {
      return reset($matches);
    }
    if ($preferredLangcode !== NULL && isset($matches[$preferredLangcode])) {
      return $matches[$preferredLangcode];
    }
    $defaultLangcode = $hostEntity->getUntranslated()->language()->getId();
    return $matches[$defaultLangcode] ?? reset($matches);
  }

  /**
   * Updates the component's block_revision_id (and block_id, in case the
   * block was itself replaced) to point at the block's current revision,
   * and saves the host translation. Must be called after saving a
   * translated block_content entity, since that save creates a new
   * revision by default - the layout would otherwise keep rendering the
   * stale, pre-translation revision (spec section 32).
   */
  public function repointComponent(ContentEntityInterface $hostTranslation, int $sectionDelta, string $componentUuid, $block): void {
    $fieldItemList = $hostTranslation->get(self::FIELD_NAME);
    $sections = $fieldItemList->getSections();
    if (!isset($sections[$sectionDelta])) {
      return;
    }
    $section = $sections[$sectionDelta];
    $component = $section->getComponent($componentUuid);
    $configuration = $component->get('configuration');
    $configuration['block_id'] = $block->id();
    $configuration['block_revision_id'] = $block->getRevisionId();
    if (!empty($configuration['block_uuid'])) {
      $configuration['block_uuid'] = $block->uuid();
    }
    $component->setConfiguration($configuration);

    $fieldItemList->setValue($sections);
    // If the host page is currently published, a translated block
    // shouldn't silently go live without review - same rule as any other
    // machine-written text (see ModerationGate). Covers both the direct
    // "safe to write" path (completeBlockPair()) and proposal accept/edit
    // (RepointBlockRevisionSubscriber) - both call this same method.
    $this->moderationGate->gateIfPublished($hostTranslation);
    $hostTranslation->save();
  }

}
