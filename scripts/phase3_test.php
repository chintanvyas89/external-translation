<?php

/**
 * Translates node 5 (from phase3_fixture.php) via the normal TMGMT flow
 * and verifies the inline blocks got translated too, via the
 * EntityTranslatedEvent -> LayoutBlockTranslationService path.
 * Run via `ddev drush php:script`.
 */

function p3_log(string $label, $value): void {
  echo str_pad($label, 30) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

$nid = 5;
$job = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase3 test']);
$job->translator = 'external_api';
$job->save();
$job->addItem('content', 'node', $nid);
$job->requestTranslation();
\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();
foreach ($job->getItems() as $item) {
  $item->acceptTranslation();
}

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($nid);
$fr = $node->getTranslation('fr');
p3_log('Node FR title', $fr->getTitle());
p3_log('Node FR body', $fr->get('body')->value);

$blockA = \Drupal::entityTypeManager()->getStorage('block_content')->loadUnchanged(3);
$blockB = \Drupal::entityTypeManager()->getStorage('block_content')->loadUnchanged(4);
p3_log('Inline block A (fr) body', $blockA->get('body')->value);
p3_log('Inline block B (fr) body', $blockB->get('body')->value);

$provenanceManager = \Drupal::service('tmgmt_external_translation.provenance_manager');
$contextA = new \Drupal\tmgmt_external_translation\Translation\TranslationFieldContext('block_content', '1', 'en', 'fr', 'block_content', '3', 'body', 0, 'value');
$recordA = $provenanceManager->getRecord($contextA);
p3_log('Block A provenance', $recordA ? [$recordA['origin'], $recordA['status']] : 'NONE');
