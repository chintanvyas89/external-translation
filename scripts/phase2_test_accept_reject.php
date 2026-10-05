<?php

/**
 * Tests the Accept / Keep / Edit proposal actions against the pending
 * proposal created by phase2_test.php (node 2, field body).
 * Run via `ddev drush php:script`.
 */

function ptr_log(string $label, $value): void {
  echo str_pad($label, 28) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

/** @var \Drupal\tmgmt_external_translation\Translation\TranslationProposalManagerInterface $proposalManager */
$proposalManager = \Drupal::service('tmgmt_external_translation.proposal_manager');
/** @var \Drupal\tmgmt_external_translation\Translation\TranslationProvenanceManagerInterface $provenanceManager */
$provenanceManager = \Drupal::service('tmgmt_external_translation.provenance_manager');

$nodeStorage = \Drupal::entityTypeManager()->getStorage('node');

// --- Scenario A: Accept -------------------------------------------------
$pending = $proposalManager->getPendingProposalsForEntity('node', '2', 'fr');
$proposal = reset($pending);
ptr_log('A: accepting proposal', $proposal['id']);
$ok = $proposalManager->acceptProposal((int) $proposal['id']);
ptr_log('A: accept result', $ok ? 'success' : 'FAILED');

$node = $nodeStorage->loadUnchanged(2);
$fr = $node->getTranslation('fr');
ptr_log('A: FR body after accept', $fr->get('body')->value);

$context = new \Drupal\tmgmt_external_translation\Translation\TranslationFieldContext('node', '2', 'en', 'fr', 'node', '2', 'body', 0, 'value');
$record = $provenanceManager->getRecord($context);
ptr_log('A: provenance status', $record['status']);
ptr_log('A: provenance origin', $record['origin']);

// --- Scenario B: create another conflict, then Keep ---------------------
$node = $nodeStorage->loadUnchanged(2);
$fr = $node->getTranslation('fr');
$fr->set('body', ['value' => '<p>Texte corrige par editeur.</p>', 'format' => 'basic_html']);
$fr->save();

$node = $nodeStorage->loadUnchanged(2);
$node->set('body', ['value' => '<p>We are launching in Q3.</p>', 'format' => 'basic_html']);
$node->save();

$job3 = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase2 retranslate B']);
$job3->translator = 'external_api';
$job3->save();
$job3->addItem('content', 'node', 2);
$job3->requestTranslation();
foreach ($job3->getItems() as $item) {
  $item->acceptTranslation();
}

$pending = $proposalManager->getPendingProposalsForEntity('node', '2', 'fr');
$proposal = reset($pending);
ptr_log('B: pending proposal id', $proposal['id'] ?? 'NONE');

$ok = $proposalManager->rejectProposal((int) $proposal['id']);
ptr_log('B: reject result', $ok ? 'success' : 'FAILED');

$node = $nodeStorage->loadUnchanged(2);
$fr = $node->getTranslation('fr');
ptr_log('B: FR body after keep (must be editor text)', $fr->get('body')->value);

// --- Scenario C: create another conflict, then Edit ---------------------
$node = $nodeStorage->loadUnchanged(2);
$node->set('body', ['value' => '<p>We are launching in Q4 instead.</p>', 'format' => 'basic_html']);
$node->save();

$job4 = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase2 retranslate C']);
$job4->translator = 'external_api';
$job4->save();
$job4->addItem('content', 'node', 2);
$job4->requestTranslation();
foreach ($job4->getItems() as $item) {
  $item->acceptTranslation();
}

$pending = $proposalManager->getPendingProposalsForEntity('node', '2', 'fr');
$proposal = reset($pending);
ptr_log('C: pending proposal id', $proposal['id'] ?? 'NONE');

$ok = $proposalManager->applyEditedProposal((int) $proposal['id'], '<p>Nous lancons au T4 a la place (edite).</p>');
ptr_log('C: edit-apply result', $ok ? 'success' : 'FAILED');

$node = $nodeStorage->loadUnchanged(2);
$fr = $node->getTranslation('fr');
ptr_log('C: FR body after edit', $fr->get('body')->value);
$record = $provenanceManager->getRecord($context);
ptr_log('C: provenance status', $record['status']);
ptr_log('C: provenance origin', $record['origin']);
