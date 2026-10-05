<?php

/**
 * Phase 0 spike (plan Part 5.8, script 1): confirms that when the
 * "Copy blocks into translation" checkbox is left unchecked, FR ends up
 * referencing the SAME block ids as EN (shared, not empty) - i.e. that
 * hook_entity_presave()'s restore-on-empty fix (tmgmt_external_translation_layout_builder.module)
 * actually undoes LayoutBuilderCopyWidget::extractFormValues()'s
 * confirmed-destructive unchecked path ($items->setValue(NULL)).
 *
 * Since driving a real HTTP form POST from a drush script is impractical,
 * this reproduces the exact end-state the widget's unchecked branch
 * produces (an emptied layout field on the new translation) and verifies
 * our own presave hook restores it correctly - the widget's own behavior
 * was independently confirmed via direct source read (line 335,
 * $items->setValue(NULL)), so this is a faithful test of the fix's logic
 * against that confirmed input, not a guess about what the widget does.
 *
 * Run via `ddev drush php:script`.
 */

use Drupal\node\Entity\Node;
use Drupal\block_content\Entity\BlockContent;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;

function p10_log(string $label, $value): void {
  echo str_pad($label, 55) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

/**
 * Inline block components reference their block via block_revision_id
 * (block_id in the configuration array is typically empty) - resolve it
 * the same way LayoutEntityCollector::collectInlineBlocks() does.
 */
function p10_resolve_block_id($section): ?string {
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

$blockEn = BlockContent::create([
  'type' => 'basic',
  'info' => 'Shared layout spike block',
  'langcode' => 'en',
  'body' => ['value' => '<p>Shared block text.</p>', 'format' => 'basic_html'],
]);
$blockEn->save();

$uuidService = \Drupal::service('uuid');
$section = new Section('layout_onecol');
$section->appendComponent(new SectionComponent($uuidService->generate(), 'content', [
  'id' => 'inline_block:basic',
  'label' => 'Shared layout spike block',
  'block_revision_id' => $blockEn->getRevisionId(),
  'view_mode' => 'full',
]));

$node = Node::create([
  'type' => 'page',
  'title' => 'Shared layout spike page',
  'langcode' => 'en',
  'body' => ['value' => '<p>English body.</p>', 'format' => 'basic_html'],
  'status' => 1,
]);
$node->set(OverridesSectionStorage::FIELD_NAME, [$section]);
$node->save();
\Drupal::service('inline_block.usage')->addUsage($blockEn->id(), $node);

p10_log('Created node', $node->id());
p10_log('EN block id', $blockEn->id());

// --- Mirror core's addTranslation() pre-fill (what the "Add translation"
// form starts with, before the widget touches anything) -----------------
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$fr = $node->addTranslation('fr', $node->toArray());

$fieldName = OverridesSectionStorage::FIELD_NAME;
$preWidgetBlockId = NULL;
foreach ($fr->get($fieldName)->getSections() as $s) {
  $preWidgetBlockId = p10_resolve_block_id($s) ?? $preWidgetBlockId;
}
p10_log('Pre-fill FR block id (want same as EN)', $preWidgetBlockId);

// --- Reproduce the widget's confirmed-destructive unchecked branch ------
$fr->set($fieldName, NULL);
p10_log('After simulated unchecked-checkbox clear, FR field empty', $fr->get($fieldName)->isEmpty() ? 'yes' : 'no');

$node->save();
// hook_entity_presave() fires synchronously inside save() above.

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$fr = $node->getTranslation('fr');
$postSaveSections = $fr->get($fieldName)->getSections();
$postSaveBlockId = NULL;
$componentCount = 0;
foreach ($postSaveSections as $s) {
  $componentCount += count($s->getComponents());
  $postSaveBlockId = p10_resolve_block_id($s) ?? $postSaveBlockId;
}
p10_log('After presave restore, FR component count (want 1)', $componentCount);
p10_log('After presave restore, FR block id (want same as EN)', $postSaveBlockId);
p10_log('Shared (FR block id === EN block id)', ((string) $postSaveBlockId === (string) $blockEn->id()) ? 'yes' : 'no');

// --- Confirm the CHECKED (opt-in duplicate) path is untouched by our fix:
// simulate what the widget's checked branch leaves behind (a DIFFERENT
// block id already populated) and confirm presave does NOT touch it.
$blockEn2 = BlockContent::create([
  'type' => 'basic',
  'info' => 'Second spike block',
  'langcode' => 'en',
  'body' => ['value' => '<p>Second block.</p>', 'format' => 'basic_html'],
]);
$blockEn2->save();
$blockFrDuplicate = $blockEn2->createDuplicate();
$blockFrDuplicate->set('langcode', 'fr');
$blockFrDuplicate->save();

$section2 = new Section('layout_onecol');
$section2->appendComponent(new SectionComponent($uuidService->generate(), 'content', [
  'id' => 'inline_block:basic',
  'label' => 'Second spike block',
  'block_revision_id' => $blockFrDuplicate->getRevisionId(),
  'view_mode' => 'full',
]));

$node2 = Node::create([
  'type' => 'page',
  'title' => 'Opt-in duplicate spike page',
  'langcode' => 'en',
  'body' => ['value' => '<p>English body.</p>', 'format' => 'basic_html'],
  'status' => 1,
]);
$node2->set(OverridesSectionStorage::FIELD_NAME, []);
$node2->save();
$node2 = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node2->id());
$fr2 = $node2->hasTranslation('fr') ? $node2->getTranslation('fr') : $node2->addTranslation('fr', $node2->toArray());
$fr2->set($fieldName, [$section2]);
$node2->save();

$node2 = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node2->id());
$fr2 = $node2->getTranslation('fr');
$postSaveBlockId2 = NULL;
foreach ($fr2->get($fieldName)->getSections() as $s) {
  $postSaveBlockId2 = p10_resolve_block_id($s) ?? $postSaveBlockId2;
}
p10_log('Checked-path (already-populated) FR block id unchanged (want duplicate id, not EN id)', $postSaveBlockId2);
p10_log('Checked-path left untouched by our fix', ((string) $postSaveBlockId2 === (string) $blockFrDuplicate->id()) ? 'yes' : 'no');
