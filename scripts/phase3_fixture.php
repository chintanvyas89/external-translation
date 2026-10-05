<?php

/**
 * Builds a Layout Builder test fixture matching what layout_builder_at's
 * "Copy blocks into translation" produces, without needing a browser:
 *
 * 1. A node with a layout override containing two inline blocks (A, B).
 * 2. A French translation whose OWN layout override references two
 *    DUPLICATED block_content entities (new IDs, langcode forced to fr,
 *    body text still in English) - exactly matching
 *    LayoutBuilderCopyWidget::extractFormValues's output.
 *
 * Run via `ddev drush php:script`.
 */

use Drupal\node\Entity\Node;
use Drupal\block_content\Entity\BlockContent;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;

function pf_log(string $label, $value): void {
  echo str_pad($label, 28) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

// --- Source blocks (English) --------------------------------------------
$blockA = BlockContent::create([
  'type' => 'basic',
  'info' => 'Hero block',
  'langcode' => 'en',
  'body' => ['value' => '<p>Discover our products.</p>', 'format' => 'basic_html'],
]);
$blockA->save();

$blockB = BlockContent::create([
  'type' => 'basic',
  'info' => 'CTA block',
  'langcode' => 'en',
  'body' => ['value' => '<p>Sign up today.</p>', 'format' => 'basic_html'],
]);
$blockB->save();

pf_log('Source block A id/revision', [$blockA->id(), $blockA->getRevisionId()]);
pf_log('Source block B id/revision', [$blockB->id(), $blockB->getRevisionId()]);

$uuidService = \Drupal::service('uuid');

$section = new Section('layout_onecol');
$section->appendComponent(new SectionComponent($uuidService->generate(), 'content', [
  'id' => 'inline_block:basic',
  'label' => 'Hero block',
  'block_revision_id' => $blockA->getRevisionId(),
  'view_mode' => 'full',
]));
$section->appendComponent(new SectionComponent($uuidService->generate(), 'content', [
  'id' => 'inline_block:basic',
  'label' => 'CTA block',
  'block_revision_id' => $blockB->getRevisionId(),
  'view_mode' => 'full',
]));

$node = Node::create([
  'type' => 'page',
  'title' => 'Layout builder test page',
  'langcode' => 'en',
  'body' => ['value' => '<p>English body.</p>', 'format' => 'basic_html'],
  'status' => 1,
]);
$node->set(OverridesSectionStorage::FIELD_NAME, [$section]);
$node->save();
pf_log('Created node', $node->id());

foreach ([$blockA->id(), $blockB->id()] as $bid) {
  \Drupal::service('inline_block.usage')->addUsage($bid, $node);
}

// --- Target (French) blocks: duplicate, force langcode=fr, keep EN text -
function pf_duplicate_block($block, string $targetLangcode) {
  $clone = $block->createDuplicate();
  $clone->set('langcode', $targetLangcode);
  $clone->save();
  return $clone;
}

$blockAFr = pf_duplicate_block($blockA, 'fr');
$blockBFr = pf_duplicate_block($blockB, 'fr');
pf_log('Duplicated FR block A id/revision', [$blockAFr->id(), $blockAFr->getRevisionId()]);
pf_log('Duplicated FR block B id/revision', [$blockBFr->id(), $blockBFr->getRevisionId()]);

$sectionFr = new Section('layout_onecol');
$sectionFr->appendComponent(new SectionComponent($uuidService->generate(), 'content', [
  'id' => 'inline_block:basic',
  'label' => 'Hero block',
  'block_revision_id' => $blockAFr->getRevisionId(),
  'view_mode' => 'full',
]));
$sectionFr->appendComponent(new SectionComponent($uuidService->generate(), 'content', [
  'id' => 'inline_block:basic',
  'label' => 'CTA block',
  'block_revision_id' => $blockBFr->getRevisionId(),
  'view_mode' => 'full',
]));

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
if (!$node->hasTranslation('fr')) {
  $node->addTranslation('fr', $node->toArray());
}
$fr = $node->getTranslation('fr');
$fr->set('title', 'Layout builder test page (FR pending)');
$fr->set('body', ['value' => '<p>English body.</p>', 'format' => 'basic_html']);
$fr->set(OverridesSectionStorage::FIELD_NAME, [$sectionFr]);
$node->save();

foreach ([$blockAFr->id(), $blockBFr->id()] as $bid) {
  \Drupal::service('inline_block.usage')->addUsage($bid, $node);
}

echo "FIXTURE_NODE_ID=" . $node->id() . "\n";
