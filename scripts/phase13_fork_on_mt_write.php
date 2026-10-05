<?php

/**
 * Phase 5 verification (plan Part 5.8, script 4; revised after the
 * revision-based redesign): runs the REAL async translation pipeline
 * (submitInlineBlocks/completeInlineBlocks via a normal TMGMT job +
 * polling, exactly as an editor's checkbox flow would) against a
 * STILL-SHARED block, and confirms:
 * - before completion, source and target ids are equal (genuinely shared)
 * - after completion, the block's id is UNCHANGED (no new entity - see
 *   plan Part 5 redesign: MT writes a new REVISION of the same id, same
 *   as a human hand-edit would), with translated text on the new revision
 * - EN's block (loaded via EN's own, untouched component pointer) is
 *   byte-for-byte unchanged, since EN's component still points at the
 *   OLD revision
 * - a translation_provenance row exists keyed to that same, unchanged id
 * - a second, untouched block in the same fixture remains fully shared
 *   (same id AND same revision on both sides)
 * - a conflict->proposal->accept round-trip against an already-translated
 *   block also works (via ProposalDashboardForm's resolveProposal()).
 *
 * Run via `ddev drush php:script`.
 */

use Drupal\node\Entity\Node;
use Drupal\block_content\Entity\BlockContent;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\tmgmt_external_translation\Translation\TranslationFieldContext;

function p13_log(string $label, $value): void {
  echo str_pad($label, 65) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

\Drupal::state()->set('tmgmt_external_translation.mock_client.ready_after_checks', 1);

// --- Fixture: node with 2 blocks, FR shared via the real presave path ---
$blockA = BlockContent::create(['type' => 'basic', 'info' => 'MT fork A', 'langcode' => 'en', 'body' => ['value' => '<p>Block A content.</p>', 'format' => 'basic_html']]);
$blockA->save();
$blockB = BlockContent::create(['type' => 'basic', 'info' => 'MT fork B', 'langcode' => 'en', 'body' => ['value' => '<p>Block B content.</p>', 'format' => 'basic_html']]);
$blockB->save();

$uuidService = \Drupal::service('uuid');
$section = new Section('layout_onecol');
$section->appendComponent(new SectionComponent($uuidService->generate(), 'content', ['id' => 'inline_block:basic', 'label' => 'MT fork A', 'block_revision_id' => $blockA->getRevisionId(), 'view_mode' => 'full']));
$section->appendComponent(new SectionComponent($uuidService->generate(), 'content', ['id' => 'inline_block:basic', 'label' => 'MT fork B', 'block_revision_id' => $blockB->getRevisionId(), 'view_mode' => 'full']));

$node = Node::create(['type' => 'page', 'title' => 'MT fork test page', 'langcode' => 'en', 'body' => ['value' => '<p>EN body.</p>', 'format' => 'basic_html'], 'status' => 1]);
$node->set(OverridesSectionStorage::FIELD_NAME, [$section]);
$node->save();
\Drupal::service('inline_block.usage')->addUsage($blockA->id(), $node);
\Drupal::service('inline_block.usage')->addUsage($blockB->id(), $node);

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$node->addTranslation('fr', $node->toArray());
$node->save();
// hook_entity_presave() restores FR's layout to share EN's block ids.

p13_log('Created node', $node->id());
p13_log('Block A id', $blockA->id());
p13_log('Block B id', $blockB->id());

function p13_resolve_blocks($section): array {
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

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$frSectionBefore = $node->getTranslation('fr')->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0];
[$frBlockABefore, $frBlockBBefore] = p13_resolve_blocks($frSectionBefore);
p13_log('Before: FR block A id === EN block A id', ((string) $frBlockABefore->id() === (string) $blockA->id()) ? 'yes' : 'no');
p13_log('Before: FR block B id === EN block B id', ((string) $frBlockBBefore->id() === (string) $blockB->id()) ? 'yes' : 'no');

// --- Run the real async pipeline: request translation for the HOST node,
// which dispatches EntityTranslationRequestedEvent -> submitInlineBlocks()
// concurrently with the host's own fields. -------------------------------
$job = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase13 fork test']);
$job->translator = 'external_api';
$job->save();
$job->addItem('content', 'node', $node->id());
$job->requestTranslation();
\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();
foreach ($job->getItems() as $item) {
  if ($item->isNeedsReview()) {
    $item->acceptTranslation();
  }
}

// --- Assertions -----------------------------------------------------------
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$en = $node->getTranslation('en');
$fr = $node->getTranslation('fr');

$enSection = $en->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0];
$frSectionAfter = $fr->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0];
[$enBlockA, $enBlockB] = p13_resolve_blocks($enSection);
[$frBlockAAfter, $frBlockBAfter] = p13_resolve_blocks($frSectionAfter);

p13_log('EN block A id/body unchanged', [$enBlockA->id(), $enBlockA->get('body')->value]);
p13_log('FR block A id (want SAME as EN - never a new entity)', $frBlockAAfter->id());
p13_log('FR block A revision (want DIFFERENT from EN - new revision only)', $frBlockAAfter->getRevisionId() !== $enBlockA->getRevisionId() ? 'yes, differs' : 'NO, same revision');
p13_log('FR block A body (want [fr]-prefixed)', $frBlockAAfter->get('body')->value);
p13_log('Block B still fully shared (EN id === FR id, EN revision === FR revision)', ((string) $enBlockB->id() === (string) $frBlockBAfter->id() && $enBlockB->getRevisionId() === $frBlockBAfter->getRevisionId()) ? 'yes' : 'no');

$provenanceManager = \Drupal::service('tmgmt_external_translation.provenance_manager');
$context = new TranslationFieldContext('block_content', (string) $blockA->id(), 'en', 'fr', 'block_content', (string) $blockA->id(), 'body', 0, 'value');
$record = $provenanceManager->getRecord($context);
p13_log('Provenance row keyed to block A id (want machine_translated)', $record['status'] ?? 'NONE');

// --- Conflict -> proposal -> accept round-trip: block B already
// translated during the round above (both blocks translate together on
// the first pass, since neither had prior provenance) - hand-edit FR's
// block B directly (a genuine divergence, same id, new revision), then
// retranslate - must divert to a proposal, not overwrite; then accept it
// and confirm it applies. --------------------------------------------------
$components = $frSectionAfter->getComponents();
uasort($components, fn($a, $b) => $a->getWeight() <=> $b->getWeight());
$componentUuids = array_keys($components);
$frComponentB = $frSectionAfter->getComponent($componentUuids[1]);
$frBlockBForEdit = \Drupal::entityTypeManager()->getStorage('block_content')->loadRevision($frComponentB->get('configuration')['block_revision_id']);
$frBlockBForEdit->set('body', ['value' => '<p>Editor corrected block B.</p>', 'format' => 'basic_html']);
$frBlockBForEdit->setNewRevision(TRUE);
$frBlockBForEdit->save();
$frConfigB = $frComponentB->get('configuration');
$frConfigB['block_revision_id'] = $frBlockBForEdit->getRevisionId();
$frComponentB->setConfiguration($frConfigB);
$fr->set(OverridesSectionStorage::FIELD_NAME, [$frSectionAfter]);
$node->save();

p13_log('After hand-edit, block B id unchanged', (string) $frBlockBForEdit->id() === (string) $blockB->id() ? 'yes' : 'no');

// Change EN block B's source and retranslate. A real edit through Layout
// Builder's own UI does two things together (confirmed in Spike B /
// InlineBlock::saveBlockContent()): setNewRevision(TRUE), AND repoint
// THAT translation's own component to the fresh revision - block_content
// does NOT auto-revision on a plain save() (confirmed: isNewRevision()
// defaults to FALSE), so skipping either step would corrupt the shared
// entity's current revision in place rather than adding a new one,
// leaving EN's OWN component pointer stale relative to EN's own content.
$blockB = \Drupal::entityTypeManager()->getStorage('block_content')->loadUnchanged($blockB->id());
$blockB->set('body', ['value' => '<p>Block B content, updated.</p>', 'format' => 'basic_html']);
$blockB->setNewRevision(TRUE);
$blockB->save();

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$en = $node->getTranslation('en');
$enSectionForRepoint = $en->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0];
$enComponents = $enSectionForRepoint->getComponents();
uasort($enComponents, fn($a, $b) => $a->getWeight() <=> $b->getWeight());
$enComponentB = $enSectionForRepoint->getComponent(array_keys($enComponents)[1]);
$enConfigB = $enComponentB->get('configuration');
$enConfigB['block_revision_id'] = $blockB->getRevisionId();
$enComponentB->setConfiguration($enConfigB);
$en->set(OverridesSectionStorage::FIELD_NAME, [$enSectionForRepoint]);
$node->save();
// hook_block_content_update() auto-requeues via checkSourceBlockForStaleness()
// since a real provenance record now exists for block B (from the round
// above) - but drive a fresh job explicitly here too, for a deterministic
// single completion pass matching this script's own polling calls.
$job2 = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase13 conflict test']);
$job2->translator = 'external_api';
$job2->save();
$job2->addItem('content', 'node', $node->id());
$job2->requestTranslation();
\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();
foreach ($job2->getItems() as $item) {
  if ($item->isNeedsReview()) {
    $item->acceptTranslation();
  }
}

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$fr = $node->getTranslation('fr');
$frSectionConflict = $fr->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0];
[, $frBlockBAfterConflict] = p13_resolve_blocks($frSectionConflict);
p13_log('FR block B body after conflict (must stay editor text)', $frBlockBAfterConflict->get('body')->value);

$proposalManager = \Drupal::service('tmgmt_external_translation.proposal_manager');
$allPending = $proposalManager->getAllPendingProposals();
$blockBProposal = NULL;
foreach ($allPending as $id => $proposal) {
  if ((string) $proposal['target_entity_id'] === (string) $blockB->id() && $proposal['field_name'] === 'body') {
    $blockBProposal = $proposal;
    $blockBProposal['id'] = $id;
  }
}
p13_log('Block B conflict proposal created', $blockBProposal ? 'yes, id=' . $blockBProposal['id'] : 'NO');

if ($blockBProposal) {
  $resolved = $proposalManager->resolveProposal((int) $blockBProposal['id'], 'accept');
  p13_log('Proposal accept result', $resolved ? 'success' : 'FAILED');

  $node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
  $fr = $node->getTranslation('fr');
  $frSectionFinal = $fr->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0];
  [, $frBlockBFinal] = p13_resolve_blocks($frSectionFinal);
  p13_log('FR block B id after accept (want SAME as before)', (string) $frBlockBFinal->id() === (string) $blockB->id() ? 'yes' : 'no');
  p13_log('FR block B body after accept (want [fr]-prefixed updated text)', $frBlockBFinal->get('body')->value);
}

\Drupal::state()->set('tmgmt_external_translation.mock_client.ready_after_checks', 1);
