<?php

/**
 * Verifies the source_copy provenance fix, per the 12-step acceptance
 * scenario:
 *
 * 1. Create EN node
 * 2. Add FR translation (core copies EN values into FR verbatim - the
 *    untouched placeholder)
 * 3. (Layout Builder blocks are covered separately by phase3_test.php -
 *    the fix here is entity-agnostic via hook_entity_insert, exercised
 *    there, not repeated in this script)
 * 4. Verify FR initially contains EN values
 * 5. Create TMGMT translation job
 * 6. Verify copied values are classified as origin=source_copy /
 *    status=copied_from_source (seeds, NOT editor changes)
 * 7. Request translation - must NOT be diverted to a proposal
 * 8. Accept - verify FR now holds real machine-translated text
 * 9. Editor modifies FR value
 * 10. Change EN source
 * 11. Run translation again
 * 12. Verify editor modification is protected (FR unchanged, proposal
 *     created instead)
 *
 * Run via `ddev drush php:script`.
 */

use Drupal\node\Entity\Node;
use Drupal\tmgmt_external_translation\Translation\TranslationFieldContext;
use Drupal\tmgmt_external_translation\Translation\TranslationProvenanceManagerInterface;

function p6_log(string $label, $value): void {
  echo str_pad($label, 34) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

// --- Step 1: Create EN node ---------------------------------------------
$node = Node::create([
  'type' => 'page',
  'title' => 'Source copy test page',
  'langcode' => 'en',
  'body' => ['value' => '<p>We ship globally.</p>', 'format' => 'basic_html'],
  'status' => 1,
]);
$node->save();
p6_log('Step1 created node', $node->id());

// --- Step 2: Add FR translation (core's own copy-of-source behavior) ---
$node->addTranslation('fr', $node->toArray());
$node->save();

// --- Step 4: Verify FR initially contains EN values ---------------------
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$fr = $node->getTranslation('fr');
p6_log('Step4 FR title (should equal EN)', $fr->getTitle());
p6_log('Step4 FR body (should equal EN)', $fr->get('body')->value);

$provenanceManager = \Drupal::service('tmgmt_external_translation.provenance_manager');
$context = fn($field, $delta = 0) => new TranslationFieldContext('node', (string) $node->id(), 'en', 'fr', 'node', (string) $node->id(), $field, $delta, 'value');

// --- Step 6: Verify classification as source_copy, not editor ----------
$record = $provenanceManager->getRecord($context('body'));
p6_log('Step6 body origin (want source_copy)', $record['origin'] ?? 'NONE');
p6_log('Step6 body status (want copied_from_source)', $record['status'] ?? 'NONE');

// --- Step 5+7: Create job, request translation --------------------------
$job = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase6 source-copy test ' . $node->id()]);
$job->translator = 'external_api';
$job->save();
$job->addItem('content', 'node', $node->id());
$job->requestTranslation();
\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();

$proposalManager = \Drupal::service('tmgmt_external_translation.proposal_manager');
$pendingAfterFirstRequest = $proposalManager->getPendingProposalsForEntity('node', (string) $node->id(), 'fr');
p6_log('Step7 pending proposals (want 0)', count($pendingAfterFirstRequest));

// --- Step 8: Accept, verify real translation landed ----------------------
foreach ($job->getItems() as $item) {
  $item->acceptTranslation();
}
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$fr = $node->getTranslation('fr');
p6_log('Step8 FR body (should be [fr]-prefixed, real text)', $fr->get('body')->value);
$record = $provenanceManager->getRecord($context('body'));
p6_log('Step8 body origin (want machine_accepted after accept)', $record['origin'] ?? 'NONE');

// --- Step 9: Editor modifies FR value ------------------------------------
$fr->set('body', ['value' => '<p>Nous livrons dans le monde entier (corrige).</p>', 'format' => 'basic_html']);
$fr->save();
$record = $provenanceManager->getRecord($context('body'));
p6_log('Step9 body origin (want editor_modified)', $record['origin'] ?? 'NONE');

// --- Step 10: Change EN source -------------------------------------------
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$node->set('body', ['value' => '<p>We ship globally, now faster.</p>', 'format' => 'basic_html']);
$node->save();

// --- Step 11: Run translation again --------------------------------------
$job2 = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase6 retranslate ' . $node->id()]);
$job2->translator = 'external_api';
$job2->save();
$job2->addItem('content', 'node', $node->id());
$job2->requestTranslation();
\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();
foreach ($job2->getItems() as $item) {
  $item->acceptTranslation();
}

// --- Step 12: Verify editor modification is protected --------------------
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$fr = $node->getTranslation('fr');
p6_log('Step12 FR body (must still be editor text)', $fr->get('body')->value);
$pendingAfterSecondRequest = $proposalManager->getPendingProposalsForEntity('node', (string) $node->id(), 'fr');
p6_log('Step12 pending proposals (want 1)', count($pendingAfterSecondRequest));
foreach ($pendingAfterSecondRequest as $id => $p) {
  p6_log('Step12 proposal provider text', $p['provider_translation']);
}
