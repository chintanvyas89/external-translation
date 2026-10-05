<?php

/**
 * Phase 5 test: request machine translation of a batch of interface
 * strings, verify results, simulate an editor correction, then simulate
 * a retranslation and verify the correction survives (same Rule 10/11
 * protection as content). Run via `ddev drush php:script`.
 */

function p5_log(string $label, $value): void {
  echo str_pad($label, 32) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

$manager = \Drupal::service('tmgmt_external_translation_interface.manager');

$messages = $manager->translateUntranslatedStrings('fr', 5);
foreach ($messages as $m) {
  p5_log('Result', $m);
}

// Find one of the strings we just translated to inspect/edit.
$db = \Drupal::database();
$row = $db->select('locales_target', 't')
  ->fields('t', ['lid', 'translation'])
  ->condition('language', 'fr')
  ->range(0, 1)
  ->orderBy('lid', 'DESC')
  ->execute()
  ->fetchAssoc();
p5_log('Sample translated lid', $row['lid']);
p5_log('Sample translation', $row['translation']);

// Simulate an editor correction directly via locale storage (mirrors what
// the real edit form does before our submit-hook classification runs).
$localeStorage = \Drupal::service('locale.storage');
$translation = $localeStorage->findTranslation(['lid' => $row['lid'], 'language' => 'fr']);
$translation->setString('Correction manuelle')->setCustomized(TRUE)->save();

// Manually invoke the same classification our form submit hook runs,
// since we bypassed the actual form here.
$context = new \Drupal\tmgmt_external_translation\Translation\TranslationFieldContext('interface_string', (string) $row['lid'], 'en', 'fr', 'interface_string', (string) $row['lid'], 'translation', 0, 'value');
$provenanceManager = \Drupal::service('tmgmt_external_translation.provenance_manager');
$provenanceManager->recordEditorModification($context, 'Correction manuelle');
p5_log('After editor correction, origin', $provenanceManager->getRecord($context)['origin']);

// Retranslate the same string - must NOT overwrite, must create a proposal.
$result = $manager->retranslateString((int) $row['lid'], 'fr');
p5_log('Retranslate result', $result);

$translation = $localeStorage->findTranslation(['lid' => $row['lid'], 'language' => 'fr']);
p5_log('Live value after retranslate (must be correction)', $translation->getString());

$pending = $manager->getPendingProposals('fr');
p5_log('Pending proposal count', count($pending));
foreach ($pending as $id => $p) {
  p5_log('Proposal source', $p['source_string']);
  p5_log('Proposal provider text', $p['provider_translation']);
  echo "PROPOSAL_ID=$id\n";
}
