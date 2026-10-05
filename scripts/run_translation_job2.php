<?php

/**
 * Creates a TMGMT job for the test node (en -> fr), requests translation
 * via our external_api translator, and prints the resulting job item data
 * so we can verify the mock translation flowed through correctly.
 * Run via `ddev drush php:script`.
 */

$existing = \Drupal::entityTypeManager()->getStorage('node')
  ->loadByProperties(['title' => 'Welcome to our website']);
$node = reset($existing);
if (!$node) {
  echo "Test node not found.\n";
  return;
}

$job = tmgmt_job_create('en', 'fr', 0, [
  'label' => 'POC translation ' . $node->id(),
]);
$job->translator = 'external_api';
$job->save();

$job_item = $job->addItem('content', 'node', $node->id());

$result = $job->requestTranslation();

\Drupal::entityTypeManager()->getStorage('tmgmt_job')->resetCache();
$job = \Drupal::entityTypeManager()->getStorage('tmgmt_job')->load($job->id());

echo "Job id: " . $job->id() . "\n";
echo "Job state: " . $job->getState() . "\n";

foreach ($job->getItems() as $item) {
  echo "Job item id: " . $item->id() . " state: " . $item->getState() . " needsReview: " . ($item->isNeedsReview() ? 'yes' : 'no') . "\n";
  $data = \Drupal::service('tmgmt.data')->filterTranslatable($item->getData());
  foreach ($data as $key => $value) {
    $source = $value['#text'] ?? '(none)';
    $translated = $value['#translation']['#text'] ?? '(none)';
    echo "  [$key] source: $source\n";
    echo "  [$key] translated: $translated\n";
  }
}
