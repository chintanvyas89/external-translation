<?php

/**
 * Accepts the job item for the latest job and verifies the node's French
 * translation was created with the machine-translated values.
 * Run via `ddev drush php:script`.
 */

$jobs = \Drupal::entityTypeManager()->getStorage('tmgmt_job')->loadMultiple();
$job = end($jobs);

foreach ($job->getItems() as $item) {
  $accepted = $item->acceptTranslation();
  echo "Job item " . $item->id() . " accept result: " . ($accepted ? 'success' : 'FAILED') . "\n";
  foreach ($item->getMessagesSince() as $message) {
    echo "  message: " . $message->getMessage() . "\n";
  }
}

$node = \Drupal::entityTypeManager()->getStorage('node')->load(1);
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged(1);

if ($node->hasTranslation('fr')) {
  $fr = $node->getTranslation('fr');
  echo "French title: " . $fr->getTitle() . "\n";
  echo "French body: " . $fr->get('body')->value . "\n";
  echo "French moderation state: " . (($fr->hasField('moderation_state')) ? $fr->get('moderation_state')->value : 'n/a - no moderation configured yet') . "\n";
}
else {
  echo "No French translation found on node 1.\n";
}
