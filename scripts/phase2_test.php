<?php

/**
 * End-to-end Phase 2 test, run via `ddev drush php:script`:
 *
 * 1. Create a fresh node, translate to French via TMGMT (machine).
 * 2. Accept the job item -> provenance should show machine_translated status.
 * 3. Editor edits the French body by hand -> provenance should flip to
 *    editor_modified/approved.
 * 4. Change the English source, re-translate -> since the target was
 *    manually modified, this must NOT overwrite the entity; it must create
 *    a translation_proposal instead.
 * 5. Verify the entity still holds the editor's text, and a pending
 *    proposal exists with the new machine text.
 */

use Drupal\node\Entity\Node;

function pt_log(string $label, $value): void {
  echo str_pad($label, 28) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

// --- Step 1: fresh node + first translation ---------------------------
$node = Node::create([
  'type' => 'page',
  'title' => 'Our product launch',
  'langcode' => 'en',
  'body' => ['value' => '<p>We are launching soon.</p>', 'format' => 'basic_html'],
  'status' => 1,
]);
$node->save();
pt_log('Created node', $node->id());

$job = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase2 test ' . $node->id()]);
$job->translator = 'external_api';
$job->save();
$job->addItem('content', 'node', $node->id());
$job->requestTranslation();
\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();

$job_item = NULL;
foreach ($job->getItems() as $item) {
  $job_item = $item;
}
$job_item->acceptTranslation();

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$fr = $node->getTranslation('fr');
pt_log('Step2 FR body (should be [fr]-prefixed)', $fr->get('body')->value);

/** @var \Drupal\tmgmt_external_translation\Translation\TranslationProvenanceManagerInterface $provenanceManager */
$provenanceManager = \Drupal::service('tmgmt_external_translation.provenance_manager');
$context_factory = fn($field, $delta = 0) => new \Drupal\tmgmt_external_translation\Translation\TranslationFieldContext('node', (string) $node->id(), 'en', 'fr', 'node', (string) $node->id(), $field, $delta, 'value');

$record = $provenanceManager->getRecord($context_factory('body'));
pt_log('Step2 provenance status', $record['status'] ?? 'NONE');
pt_log('Step2 provenance origin', $record['origin'] ?? 'NONE');

// --- Step 3: editor hand-edits the French body -------------------------
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$fr = $node->getTranslation('fr');
$fr->set('body', ['value' => '<p>Notre lancement approche.</p>', 'format' => 'basic_html']);
$fr->save();

$record = $provenanceManager->getRecord($context_factory('body'));
pt_log('Step3 provenance status', $record['status'] ?? 'NONE');
pt_log('Step3 provenance origin', $record['origin'] ?? 'NONE');

// --- Step 4: source changes, retranslate --------------------------------
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$node->set('body', ['value' => '<p>We are launching next month.</p>', 'format' => 'basic_html']);
$node->save();

$job2 = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase2 retranslate ' . $node->id()]);
$job2->translator = 'external_api';
$job2->save();
$job2->addItem('content', 'node', $node->id());
$job2->requestTranslation();
\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();

foreach ($job2->getItems() as $item) {
  foreach ($item->getMessagesSince() as $m) {
    pt_log('Step4 job message', $m->getMessage());
  }
  $item->acceptTranslation();
}

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$fr = $node->getTranslation('fr');
pt_log('Step4 FR body (must still be editor text)', $fr->get('body')->value);

$record = $provenanceManager->getRecord($context_factory('body'));
pt_log('Step4 provenance status (needs_review expected)', $record['status'] ?? 'NONE');

/** @var \Drupal\tmgmt_external_translation\Translation\TranslationProposalManagerInterface $proposalManager */
$proposalManager = \Drupal::service('tmgmt_external_translation.proposal_manager');
$pending = $proposalManager->getPendingProposalsForEntity('node', (string) $node->id(), 'fr');
foreach ($pending as $id => $proposal) {
  pt_log('Step4 pending proposal id', $id);
  pt_log('Step4 proposal field', $proposal['field_name']);
  pt_log('Step4 proposal provider text', $proposal['provider_translation']);
}

echo "NODE_ID=" . $node->id() . "\n";
$first = reset($pending);
if ($first) {
  echo "PROPOSAL_ID=" . $first['id'] . "\n";
}
