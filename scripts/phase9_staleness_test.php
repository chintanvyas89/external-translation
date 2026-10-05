<?php

/**
 * Verifies automatic staleness detection + re-translation for Layout
 * Builder inline blocks: when the English source block's own content is
 * edited directly (not via a new translation-add flow), the French
 * counterpart's provenance record should flip to 'stale' and a fresh
 * translation batch should be auto-submitted and, once polled, applied -
 * without anyone manually re-requesting translation.
 *
 * Run via `ddev drush php:script`.
 */

use Drupal\node\Entity\Node;
use Drupal\block_content\Entity\BlockContent;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\tmgmt_external_translation\Translation\TranslationFieldContext;

function p9_log(string $label, $value): void {
  echo str_pad($label, 55) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

\Drupal::state()->set('tmgmt_external_translation.mock_client.ready_after_checks', 1);

// --- Fixture: node with one inline block, EN + FR --------------------
$blockEn = BlockContent::create([
  'type' => 'basic',
  'info' => 'Staleness test block',
  'langcode' => 'en',
  'body' => ['value' => '<p>Original text.</p>', 'format' => 'basic_html'],
]);
$blockEn->save();

$uuidService = \Drupal::service('uuid');
$section = new Section('layout_onecol');
$section->appendComponent(new SectionComponent($uuidService->generate(), 'content', [
  'id' => 'inline_block:basic',
  'label' => 'Staleness test block',
  'block_revision_id' => $blockEn->getRevisionId(),
  'view_mode' => 'full',
]));

$node = Node::create([
  'type' => 'page',
  'title' => 'Staleness test page',
  'langcode' => 'en',
  'body' => ['value' => '<p>English body.</p>', 'format' => 'basic_html'],
  'status' => 1,
]);
$node->set(OverridesSectionStorage::FIELD_NAME, [$section]);
$node->save();
\Drupal::service('inline_block.usage')->addUsage($blockEn->id(), $node);

$blockFr = $blockEn->createDuplicate();
$blockFr->set('langcode', 'fr');
$blockFr->save();

$sectionFr = new Section('layout_onecol');
$sectionFr->appendComponent(new SectionComponent($uuidService->generate(), 'content', [
  'id' => 'inline_block:basic',
  'label' => 'Staleness test block',
  'block_revision_id' => $blockFr->getRevisionId(),
  'view_mode' => 'full',
]));

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
if (!$node->hasTranslation('fr')) {
  $node->addTranslation('fr', $node->toArray());
}
$fr = $node->getTranslation('fr');
$fr->set(OverridesSectionStorage::FIELD_NAME, [$sectionFr]);
$node->save();
\Drupal::service('inline_block.usage')->addUsage($blockFr->id(), $node);

p9_log('Created node', $node->id());
p9_log('EN block id', $blockEn->id());
p9_log('FR block id', $blockFr->id());

// --- Step 1: first translation, as normal ------------------------------
$job = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase9 initial translation']);
$job->translator = 'external_api';
$job->save();
$job->addItem('content', 'node', $node->id());
$job->requestTranslation();
\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();

$blockFr = \Drupal::entityTypeManager()->getStorage('block_content')->loadUnchanged($blockFr->id());
p9_log('Step1 FR block body (should be [fr]-prefixed)', $blockFr->get('body')->value);

$provenanceManager = \Drupal::service('tmgmt_external_translation.provenance_manager');
$context = new TranslationFieldContext('block_content', (string) $blockEn->id(), 'en', 'fr', 'block_content', (string) $blockFr->id(), 'body', 0, 'value');
$record = $provenanceManager->getRecord($context);
p9_log('Step1 provenance status (want machine_translated)', $record['status'] ?? 'NONE');

// --- Step 2: edit the EN source block directly -------------------------
$blockEn = \Drupal::entityTypeManager()->getStorage('block_content')->loadUnchanged($blockEn->id());
$blockEn->set('body', ['value' => '<p>Updated text.</p>', 'format' => 'basic_html']);
$blockEn->save();
// hook_block_content_update() fires synchronously inside save() above.

$record = $provenanceManager->getRecord($context);
p9_log('Step2 provenance status right after EN edit (want stale)', $record['status'] ?? 'NONE');

$pendingCount = (int) \Drupal::database()->select('translation_pending_batch', 'b')
  ->condition('kind', 'layout_inline_blocks')
  ->condition('host_entity_type', 'node')
  ->condition('host_entity_id', (string) $node->id())
  ->condition('status', 'pending')
  ->countQuery()
  ->execute()
  ->fetchField();
p9_log('Step2 new pending layout batch auto-created (want 1)', $pendingCount);

// Saving again with the SAME (already-current) value must not create a
// second duplicate batch while the first is still pending.
$blockEn->save();
$pendingCountAfterResave = (int) \Drupal::database()->select('translation_pending_batch', 'b')
  ->condition('kind', 'layout_inline_blocks')
  ->condition('host_entity_type', 'node')
  ->condition('host_entity_id', (string) $node->id())
  ->condition('status', 'pending')
  ->countQuery()
  ->execute()
  ->fetchField();
p9_log('Step2b pending batches after redundant re-save (want still 1)', $pendingCountAfterResave);

// --- Step 3: poll -> auto re-translation applies ------------------------
\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();

$blockFr = \Drupal::entityTypeManager()->getStorage('block_content')->loadUnchanged($blockFr->id());
p9_log('Step3 FR block body after poll (want [fr] Updated text.)', $blockFr->get('body')->value);

$record = $provenanceManager->getRecord($context);
p9_log('Step3 provenance status after re-translation (want machine_translated)', $record['status'] ?? 'NONE');

\Drupal::state()->set('tmgmt_external_translation.mock_client.ready_after_checks', 1);
