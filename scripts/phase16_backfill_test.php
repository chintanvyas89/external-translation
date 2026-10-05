<?php

/**
 * Phase 16: verifies tmgmt_external_translation_layout_builder_update_10001()
 * (tmgmt_external_translation_layout_builder.install) - the one-time
 * migration that backfills an EMPTY layout_builder__layout field on
 * translations that already existed before this module's own
 * entity_presave() restore-on-empty hook was ever live.
 *
 * Simulates legacy pre-module content: a node with an EN Layout Builder
 * block and an FR translation whose layout field is empty NOT because it's
 * new (the live presave hook would already fix that case), but because it
 * was saved back when nothing populated it - reproduced here by emptying
 * the field on an ALREADY-EXISTING (not new) translation, which the live
 * hook's own isNewTranslation() guard deliberately leaves alone, exactly
 * matching what a real legacy translation looks like today.
 *
 * Run via `ddev drush php:script`.
 */

use Drupal\node\Entity\Node;
use Drupal\block_content\Entity\BlockContent;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;

function p16_log(string $label, $value): void {
  echo str_pad($label, 60) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

function p16_resolve_block_id($section): ?string {
  $storage = \Drupal::entityTypeManager()->getStorage('block_content');
  foreach ($section->getComponents() as $component) {
    $configuration = $component->get('configuration');
    if (empty($configuration['block_revision_id'])) {
      continue;
    }
    $block = $storage->loadRevision($configuration['block_revision_id']);
    if ($block) {
      return (string) $block->id();
    }
  }
  return NULL;
}

$fieldName = OverridesSectionStorage::FIELD_NAME;

// --- Build EN node with an inline block, add a properly-shared FR
// translation first (the live hook does this correctly for anything NEW) --
$blockEn = BlockContent::create([
  'type' => 'basic',
  'info' => 'Backfill spike block',
  'langcode' => 'en',
  'body' => ['value' => '<p>English block body.</p>', 'format' => 'basic_html'],
]);
$blockEn->save();

$uuidService = \Drupal::service('uuid');
$section = new Section('layout_onecol');
$section->appendComponent(new SectionComponent($uuidService->generate(), 'content', [
  'id' => 'inline_block:basic',
  'label' => 'Backfill spike block',
  'block_revision_id' => $blockEn->getRevisionId(),
  'view_mode' => 'full',
]));

$node = Node::create([
  'type' => 'page',
  'title' => 'Backfill spike page',
  'langcode' => 'en',
  'body' => ['value' => '<p>English body.</p>', 'format' => 'basic_html'],
  'status' => 1,
]);
$node->set($fieldName, [$section]);
$node->save();
\Drupal::service('inline_block.usage')->addUsage($blockEn->id(), $node);
$nodeId = $node->id();
p16_log('Created node', $nodeId);
p16_log('EN block id', $blockEn->id());

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($nodeId);
$node->addTranslation('fr', $node->toArray());
$node->save();
// Live presave hook already populated FR's layout field correctly here,
// since FR was new during this save.

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($nodeId);
$fr = $node->getTranslation('fr');
p16_log('FR layout populated immediately after add (expected: not empty)', $fr->get($fieldName)->isEmpty() ? 'EMPTY' : 'populated');

// --- Now simulate LEGACY content: empty FR's field on a save where FR is
// no longer new - the live hook's isNewTranslation() guard leaves this
// alone, exactly reproducing what a translation saved before this module
// existed looks like today. -------------------------------------------
$fr = $node->getTranslation('fr');
$fr->set($fieldName, NULL);
p16_log('FR isNewTranslation() at this save (expected: no, not blocked by live hook)', $fr->isNewTranslation() ? 'yes' : 'no');
$node->save();

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($nodeId);
$fr = $node->getTranslation('fr');
p16_log('FR layout empty after simulating legacy state (expected: EMPTY)', $fr->get($fieldName)->isEmpty() ? 'EMPTY' : 'populated');

// --- Run the migration ---------------------------------------------------
\Drupal::moduleHandler()->loadInclude('tmgmt_external_translation_layout_builder', 'install');
$sandbox = [];
$iterations = 0;
do {
  $message = tmgmt_external_translation_layout_builder_update_10001($sandbox);
  $iterations++;
} while ($sandbox['#finished'] < 1 && $iterations < 1000);
p16_log('Update hook iterations', $iterations);
p16_log('Update hook final message', $message);

// --- Verify the backfill -------------------------------------------------
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($nodeId);
$fr = $node->getTranslation('fr');
p16_log('FR layout populated after migration (expected: populated)', $fr->get($fieldName)->isEmpty() ? 'EMPTY' : 'populated');

$frBlockId = NULL;
$componentCount = 0;
foreach ($fr->get($fieldName)->getSections() as $s) {
  $componentCount += count($s->getComponents());
  $frBlockId = p16_resolve_block_id($s) ?? $frBlockId;
}
p16_log('FR component count after migration (want 1)', $componentCount);
p16_log('FR block id after migration (want same as EN)', $frBlockId);
p16_log('Backfilled to shared (FR block id === EN block id)', ((string) $frBlockId === (string) $blockEn->id()) ? 'yes' : 'no');

// --- Confirm the backfilled block is fully functional: a normal MT
// translation pass against it works exactly like any shared block -------
$translationService = \Drupal::service('tmgmt_external_translation_layout_builder.translation_service');
$messages = $translationService->submitInlineBlocks('node', (string) $nodeId, 'en', 'fr', 'mock', '1.0');
p16_log('submitInlineBlocks() messages', $messages);
\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($nodeId);
$fr = $node->getTranslation('fr');
$frBlockIdAfterMt = NULL;
foreach ($fr->get($fieldName)->getSections() as $s) {
  $frBlockIdAfterMt = p16_resolve_block_id($s) ?? $frBlockIdAfterMt;
}
$storage = \Drupal::entityTypeManager()->getStorage('block_content');
$translatedBlock = $storage->loadRevision((int) $storage->load($frBlockIdAfterMt)->getRevisionId());
p16_log('FR block body after MT (want [fr]-prefixed)', $translatedBlock->get('body')->value ?? NULL);

// Deliberately reads EN's OWN component's pinned block_revision_id, not
// loadUnchanged() (which returns the block's latest DEFAULT revision - that
// naturally advances after the MT write above regardless of what EN's own
// component still points at, so it would misreport EN as changed even
// though its own layout config was never touched).
$en = $node->getTranslation('en');
$enPinnedRevisionId = NULL;
foreach ($en->get($fieldName)->getSections() as $s) {
  foreach ($s->getComponents() as $c) {
    $conf = $c->get('configuration');
    if (!empty($conf['block_revision_id'])) {
      $enPinnedRevisionId = $conf['block_revision_id'];
    }
  }
}
$blockEnCheck = $storage->loadRevision($enPinnedRevisionId);
p16_log('EN component still pinned to its original revision (unaffected)', $blockEnCheck->get('body')->value ?? NULL);

echo "\nDone.\n";
