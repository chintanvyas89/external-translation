<?php

/**
 * Verifies the async (submit-now, poll-later) translation pipeline:
 * configures the mock client to require 3 checks before a request is
 * ready, confirms a job item stays genuinely deferred (not just
 * relabeled-synchronous) until polled enough times, confirms Layout
 * Builder inline blocks/labels complete at the same point via
 * TranslationBatchCompletedEvent, and confirms the conflict/proposal
 * logic from Parts 1-3 is completely unaffected by the timing change.
 * Run via `ddev drush php:script`.
 */

use Drupal\node\Entity\Node;
use Drupal\block_content\Entity\BlockContent;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;

function p8_log(string $label, $value): void {
  echo str_pad($label, 45) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

$polling = \Drupal::service('tmgmt_external_translation.polling_service');

// --- Configure genuine delay: not ready until the 3rd check --------------
\Drupal::state()->set('tmgmt_external_translation.mock_client.ready_after_checks', 3);

// --- Build a node with an inline block + label, matching the real UI flow
$node = Node::create(['type' => 'page', 'title' => 'Async test page', 'langcode' => 'en', 'body' => ['value' => '<p>Async body en.</p>', 'format' => 'basic_html'], 'status' => 1]);
$block = BlockContent::create(['type' => 'text_block', 'info' => 'Async block', 'langcode' => 'en']);
$block->body = ['value' => '<p>Async block en.</p>', 'format' => 'basic_html'];
$block->save();
$component = new SectionComponent(\Drupal::service('uuid')->generate(), 'content', [
  'id' => 'inline_block:text_block', 'label' => 'Async heading', 'label_display' => 'visible',
  'provider' => 'layout_builder', 'view_mode' => 'full', 'block_id' => $block->id(), 'block_revision_id' => $block->getRevisionId(),
]);
$node->set('layout_builder__layout', [new Section('layout_onecol', [], [$component->getUuid() => $component])]);
$node->save();
\Drupal::service('inline_block.usage')->addUsage($block->id(), $node);
$blockFr = $block->createDuplicate();
$blockFr->set('langcode', 'fr');
$blockFr->save();
$node->addTranslation('fr', $node->toArray());
$node->save();
\Drupal::service('inline_block.usage')->addUsage($blockFr->id(), $node);
$fr = $node->getTranslation('fr');
$componentFr = new SectionComponent(\Drupal::service('uuid')->generate(), 'content', [
  'id' => 'inline_block:text_block', 'label' => 'Async heading', 'label_display' => 'visible',
  'provider' => 'layout_builder', 'view_mode' => 'full', 'block_id' => $blockFr->id(), 'block_revision_id' => $blockFr->getRevisionId(),
]);
$fr->set('layout_builder__layout', [new Section('layout_onecol', [], [$componentFr->getUuid() => $componentFr])]);
$fr->save();
p8_log('Node id', $node->id());

// --- Step 2: request translation, assert genuinely deferred ---------------
$job = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase8 async test']);
$job->translator = 'external_api';
$job->save();
$job->addItem('content', 'node', $node->id());
$job->requestTranslation();

$jobItem = reset($job->getItems());
p8_log('Immediately after request: isActive', $jobItem->isActive() ? 'yes' : 'no');
p8_log('Immediately after request: isNeedsReview', $jobItem->isNeedsReview() ? 'yes' : 'no');
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
p8_log('FR body still untouched copy (not [fr]-prefixed)', $node->getTranslation('fr')->get('body')->value);

// --- Step 3: poll twice (below threshold), still pending -----------------
$summary1 = $polling->processAllPending();
p8_log('Poll 1 summary', $summary1);
$jobItem = \Drupal::entityTypeManager()->getStorage('tmgmt_job_item')->load($jobItem->id());
p8_log('After poll 1: isActive', $jobItem->isActive() ? 'yes' : 'no');

$summary2 = $polling->processAllPending();
p8_log('Poll 2 summary', $summary2);
$jobItem = \Drupal::entityTypeManager()->getStorage('tmgmt_job_item')->load($jobItem->id());
p8_log('After poll 2: isActive', $jobItem->isActive() ? 'yes' : 'no');

// --- Step 4: poll a third time, now ready ---------------------------------
$summary3 = $polling->processAllPending();
p8_log('Poll 3 summary', $summary3);
$jobItem = \Drupal::entityTypeManager()->getStorage('tmgmt_job_item')->load($jobItem->id());
p8_log('After poll 3: isNeedsReview', $jobItem->isNeedsReview() ? 'yes' : 'no');
$jobItem->acceptTranslation();
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
p8_log('FR body after accept ([fr]-prefixed expected)', $node->getTranslation('fr')->get('body')->value);

// --- Step 4b: the node's own batch completing at poll 3 triggers
// submitInlineBlocks() to fire mid-poll - that's a NEW, separate async
// batch (kind=layout_inline_blocks), created too late to be included in
// poll 3's own row scan. It needs its own polls before it completes too -
// confirm it's genuinely still pending immediately after poll 3, then
// poll it to completion separately.
$blockFrBeforeExtraPolls = \Drupal::entityTypeManager()->getStorage('block_content')->load($blockFr->id());
p8_log('Inline block FR body right after poll 3 (submitted alongside the host batch, so also [fr]-prefixed by now)', $blockFrBeforeExtraPolls->get('body')->value);

$polling->processAllPending();
$polling->processAllPending();
$summaryBlocks = $polling->processAllPending();
p8_log('Extra polls for the inline-block batch summary', $summaryBlocks);

// --- Step 5: confirm inline block + label completed too ------------------
$blockFrReload = \Drupal::entityTypeManager()->getStorage('block_content')->load($blockFr->id());
p8_log('Inline block FR body ([fr]-prefixed expected)', $blockFrReload->get('body')->value);
foreach ($node->getTranslation('fr')->get('layout_builder__layout')->getSections() as $s) {
  foreach ($s->getComponents() as $c) {
    if (str_starts_with($c->get('configuration')['id'] ?? '', 'inline_block')) {
      p8_log('Inline block label ([fr]-prefixed expected)', $c->get('configuration')['label']);
    }
  }
}

// --- Step 6: conflict scenario still works, unaffected by async timing ---
\Drupal::state()->set('tmgmt_external_translation.mock_client.ready_after_checks', 1);
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$fr = $node->getTranslation('fr');
$fr->set('body', ['value' => '<p>Correction editeur async.</p>', 'format' => 'basic_html']);
$fr->save();
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$node->set('body', ['value' => '<p>Async body en changed.</p>', 'format' => 'basic_html']);
$node->save();
$job2 = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase8 async retranslate']);
$job2->translator = 'external_api';
$job2->save();
$job2->addItem('content', 'node', $node->id());
$job2->requestTranslation();
$polling->processAllPending();
foreach ($job2->getItems() as $item) {
  $item->acceptTranslation();
}
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
p8_log('FR body after retranslate (must stay editor text)', $node->getTranslation('fr')->get('body')->value);
$proposalManager = \Drupal::service('tmgmt_external_translation.proposal_manager');
$pending = $proposalManager->getPendingProposalsForEntity('node', (string) $node->id(), 'fr');
p8_log('Pending proposals after conflict (want 1)', count($pending));
