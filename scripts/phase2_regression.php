<?php

/**
 * Full Phase 2 regression, including the bug fix verification: after a
 * "Keep" or "Edit" resolution, a THIRD retranslation attempt (with yet
 * another new source text) must ALSO be detected as a conflict - proving
 * provider_translation_hash was not incorrectly clobbered by the
 * accept/keep/edit bookkeeping itself.
 * Run via `ddev drush php:script`.
 */

use Drupal\node\Entity\Node;

function rt_log(string $label, $value): void {
  echo str_pad($label, 32) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

function rt_retranslate($nid, $newSourceBody) {
  $node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($nid);
  $node->set('body', ['value' => $newSourceBody, 'format' => 'basic_html']);
  $node->save();

  $job = tmgmt_job_create('en', 'fr', 0, ['label' => 'regression retranslate ' . microtime(TRUE)]);
  $job->translator = 'external_api';
  $job->save();
  $job->addItem('content', 'node', $nid);
  $job->requestTranslation();
  foreach ($job->getItems() as $item) {
    $item->acceptTranslation();
  }
}

// --- setup: fresh node, initial machine translation, accept -----------
$node = Node::create([
  'type' => 'page',
  'title' => 'Regression node',
  'langcode' => 'en',
  'body' => ['value' => '<p>Version one.</p>', 'format' => 'basic_html'],
  'status' => 1,
]);
$node->save();
$nid = $node->id();
rt_log('Created node', $nid);

$job = tmgmt_job_create('en', 'fr', 0, ['label' => 'regression initial']);
$job->translator = 'external_api';
$job->save();
$job->addItem('content', 'node', $nid);
$job->requestTranslation();
foreach ($job->getItems() as $item) {
  $item->acceptTranslation();
}

$provenanceManager = \Drupal::service('tmgmt_external_translation.provenance_manager');
$proposalManager = \Drupal::service('tmgmt_external_translation.proposal_manager');
$context = new \Drupal\tmgmt_external_translation\Translation\TranslationFieldContext('node', (string) $nid, 'en', 'fr', 'node', (string) $nid, 'body', 0, 'value');

// --- editor edits directly ----------------------------------------------
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($nid);
$fr = $node->getTranslation('fr');
$fr->set('body', ['value' => '<p>Version editeur.</p>', 'format' => 'basic_html']);
$fr->save();
rt_log('After editor edit, origin', $provenanceManager->getRecord($context)['origin']);

// --- retranslate #1: should conflict, create proposal, KEEP it --------
rt_retranslate($nid, '<p>Version two.</p>');
$pending = $proposalManager->getPendingProposalsForEntity('node', (string) $nid, 'fr');
$p1 = reset($pending);
rt_log('Retranslate #1 created proposal', $p1 ? $p1['id'] : 'NONE - BUG');
$proposalManager->rejectProposal((int) $p1['id']);
rt_log('After KEEP, origin', $provenanceManager->getRecord($context)['origin']);
rt_log('After KEEP, provider_hash empty?', $provenanceManager->getRecord($context)['provider_translation_hash'] === '' ? 'yes (correct - never really accepted)' : 'no');

// --- retranslate #2: must STILL conflict (this is the regression check) --
rt_retranslate($nid, '<p>Version three.</p>');
$pending = $proposalManager->getPendingProposalsForEntity('node', (string) $nid, 'fr');
$p2 = reset($pending);
rt_log('Retranslate #2 after KEEP still conflicts', $p2 ? 'YES - correct' : 'NO - BUG: auto-overwrote editor text');
if ($p2) {
  $node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($nid);
  rt_log('Entity body still editor text?', $node->getTranslation('fr')->get('body')->value);
  // Now EDIT it.
  $proposalManager->applyEditedProposal((int) $p2['id'], '<p>Version editee manuellement.</p>');
  rt_log('After EDIT, origin', $provenanceManager->getRecord($context)['origin']);
}

// --- retranslate #3: must STILL conflict after an Edit too -------------
rt_retranslate($nid, '<p>Version four.</p>');
$pending = $proposalManager->getPendingProposalsForEntity('node', (string) $nid, 'fr');
$p3 = reset($pending);
rt_log('Retranslate #3 after EDIT still conflicts', $p3 ? 'YES - correct' : 'NO - BUG: auto-overwrote edited text');
if ($p3) {
  $node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($nid);
  rt_log('Entity body still edited text?', $node->getTranslation('fr')->get('body')->value);
}

echo "REGRESSION_NODE_ID=$nid\n";
