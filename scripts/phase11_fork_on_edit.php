<?php

/**
 * Phase 0 spike (plan Part 5.8, script 2): confirms that hand-editing ONE
 * shared block via FR's own translation context forks only that block
 * (new revision, same block id; EN's component untouched; a second
 * untouched block stays shared on both sides).
 *
 * Run via `ddev drush php:script`.
 */

use Drupal\node\Entity\Node;
use Drupal\block_content\Entity\BlockContent;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;

function p11_log(string $label, $value): void {
  echo str_pad($label, 60) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

/**
 * @return \Drupal\block_content\BlockContentInterface[]
 *   All inline blocks in this section, in component-weight order.
 */
function p11_resolve_blocks($section): array {
  $storage = \Drupal::entityTypeManager()->getStorage('block_content');
  $components = $section->getComponents();
  uasort($components, fn($a, $b) => $a->getWeight() <=> $b->getWeight());
  $blocks = [];
  foreach ($components as $component) {
    $configuration = $component->get('configuration');
    if (empty($configuration['block_revision_id'])) {
      continue;
    }
    $blocks[] = $storage->loadRevision($configuration['block_revision_id']);
  }
  return $blocks;
}

// --- Fixture: node with 2 blocks, FR shared via the presave restore -----
$blockA = BlockContent::create(['type' => 'basic', 'info' => 'Fork spike A', 'langcode' => 'en', 'body' => ['value' => '<p>Block A original.</p>', 'format' => 'basic_html']]);
$blockA->save();
$blockB = BlockContent::create(['type' => 'basic', 'info' => 'Fork spike B', 'langcode' => 'en', 'body' => ['value' => '<p>Block B original.</p>', 'format' => 'basic_html']]);
$blockB->save();

$uuidService = \Drupal::service('uuid');
$section = new Section('layout_onecol');
$section->appendComponent(new SectionComponent($uuidService->generate(), 'content', ['id' => 'inline_block:basic', 'label' => 'Fork spike A', 'block_revision_id' => $blockA->getRevisionId(), 'view_mode' => 'full']));
$section->appendComponent(new SectionComponent($uuidService->generate(), 'content', ['id' => 'inline_block:basic', 'label' => 'Fork spike B', 'block_revision_id' => $blockB->getRevisionId(), 'view_mode' => 'full']));

$node = Node::create(['type' => 'page', 'title' => 'Fork spike page', 'langcode' => 'en', 'body' => ['value' => '<p>EN body.</p>', 'format' => 'basic_html'], 'status' => 1]);
$node->set(OverridesSectionStorage::FIELD_NAME, [$section]);
$node->save();
\Drupal::service('inline_block.usage')->addUsage($blockA->id(), $node);
\Drupal::service('inline_block.usage')->addUsage($blockB->id(), $node);

p11_log('Created node', $node->id());
p11_log('Block A id / revision', [$blockA->id(), $blockA->getRevisionId()]);
p11_log('Block B id / revision', [$blockB->id(), $blockB->getRevisionId()]);

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$node->addTranslation('fr', $node->toArray());
$node->save();
// hook_entity_presave() restores FR's layout to share EN's block ids.

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$fr = $node->getTranslation('fr');
$frSectionBefore = $fr->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0];
[$frBlockABefore] = p11_resolve_blocks($frSectionBefore);
p11_log('FR block A id before edit (want same as EN)', $frBlockABefore->id());

// --- Simulate a hand-edit of block A through FR's own context: load the
// SPECIFIC revision FR's component currently points at, edit it, save,
// then repoint FR's component to the new revision - mirrors what
// InlineBlock::saveBlockContent() does when the block's own edit form is
// actually submitted through Layout Builder's UI. ------------------------
$components = $frSectionBefore->getComponents();
uasort($components, fn($a, $b) => $a->getWeight() <=> $b->getWeight());
$componentUuids = array_keys($components);
$frComponentA = $frSectionBefore->getComponent($componentUuids[0]);

$frBlockA = \Drupal::entityTypeManager()->getStorage('block_content')->loadRevision($frComponentA->get('configuration')['block_revision_id']);
$frBlockA->set('body', ['value' => '<p>Block A - FR edited.</p>', 'format' => 'basic_html']);
$frBlockA->setNewRevision(TRUE);
$frBlockA->save();

$frConfig = $frComponentA->get('configuration');
$frConfig['block_revision_id'] = $frBlockA->getRevisionId();
$frComponentA->setConfiguration($frConfig);
$fr->set(OverridesSectionStorage::FIELD_NAME, [$frSectionBefore]);
$node->save();

// --- Assertions -----------------------------------------------------------
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$en = $node->getTranslation('en');
$fr = $node->getTranslation('fr');

$enSection = $en->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0];
$frSectionAfter = $fr->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0];

[$enBlockA, $enBlockB] = p11_resolve_blocks($enSection);
[$frBlockAAfter, $frBlockBAfter] = p11_resolve_blocks($frSectionAfter);

p11_log('EN block A id unchanged (want ' . $blockA->id() . ')', $enBlockA->id());
p11_log('EN block A revision unchanged (want ' . $blockA->getRevisionId() . ')', $enBlockA->getRevisionId());
p11_log('EN block A body unaffected by FR edit', $enBlockA->get('body')->value);
p11_log('FR block A id (want SAME id as EN - revision fork, not new entity)', $frBlockAAfter->id());
p11_log('FR block A revision (want DIFFERENT from EN)', $frBlockAAfter->getRevisionId());
p11_log('FR block A body (want FR edited text)', $frBlockAAfter->get('body')->value);
p11_log('Block B still shared (EN id === FR id)', ((string) $enBlockB->id() === (string) $frBlockBAfter->id()) ? 'yes' : 'no');
p11_log('Block B still shared (EN revision === FR revision)', ($enBlockB->getRevisionId() === $frBlockBAfter->getRevisionId()) ? 'yes' : 'no');
