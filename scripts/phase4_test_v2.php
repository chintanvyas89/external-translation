<?php

/**
 * Phase 4 test: translates node 7 (from phase4_paragraph_fixture.php,
 * which has nested paragraphs), verifies paragraph text (incl. nested)
 * gets translated, verifies moderation state stays 'draft', and separately
 * tests a reusable (non-layout) block and a media entity translate
 * through the same generic pipeline. Run via `ddev drush php:script`.
 */

use Drupal\block_content\Entity\BlockContent;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;

function p4t_log(string $label, $value): void {
  echo str_pad($label, 32) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

// --- Paragraphs (incl. nested) + moderation ------------------------------
$nid = 8;
$job = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase4 paragraphs']);
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
p4t_log('Node FR title', $fr->getTitle());
$paragraph = $fr->get('field_paragraphs')->entity;
p4t_log('Parent paragraph FR text', $paragraph->get('field_text')->value);
$child = $paragraph->get('field_child')->entity;
p4t_log('Child paragraph FR text', $child->get('field_text')->value);
p4t_log('Moderation state', $fr->hasField('moderation_state') ? $fr->get('moderation_state')->value : 'NO MODERATION FIELD');

// --- Reusable (non-layout) block ------------------------------------------
$reusable = BlockContent::create([
  'type' => 'basic',
  'info' => 'Reusable footer block',
  'reusable' => TRUE,
  'langcode' => 'en',
  'body' => ['value' => '<p>Contact us for more information.</p>', 'format' => 'basic_html'],
]);
$reusable->save();

$job2 = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase4 reusable block']);
$job2->translator = 'external_api';
$job2->save();
$job2->addItem('content', 'block_content', $reusable->id());
$job2->requestTranslation();
\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();
foreach ($job2->getItems() as $item) {
  $item->acceptTranslation();
}
$reusable = \Drupal::entityTypeManager()->getStorage('block_content')->loadUnchanged($reusable->id());
p4t_log('Reusable block FR body', $reusable->getTranslation('fr')->get('body')->value);

// --- Media -----------------------------------------------------------------
$file = File::create(['uri' => 'public://phase4-test.txt', 'status' => 1]);
file_put_contents('public://phase4-test.txt', 'test file');
$file->save();

$media = Media::create([
  'bundle' => 'document_test',
  'name' => 'Test document',
  'langcode' => 'en',
  'field_media_file' => ['target_id' => $file->id()],
]);
$media->save();

$job3 = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase4 media']);
$job3->translator = 'external_api';
$job3->save();
$job3->addItem('content', 'media', $media->id());
$job3->requestTranslation();
\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();
foreach ($job3->getItems() as $item) {
  foreach ($item->getMessagesSince() as $m) {
    p4t_log('Media job message', $m->getMessage());
  }
  $item->acceptTranslation();
}
$media = \Drupal::entityTypeManager()->getStorage('media')->loadUnchanged($media->id());
p4t_log('Media FR name', $media->getTranslation('fr')->getName());

// --- Confirm the paragraph conflict-protection gap, honestly ---------------
$fr2 = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($nid)->getTranslation('fr');
$fr2->get('field_paragraphs')->entity->set('field_text', ['value' => '<p>Editor corrected text.</p>', 'format' => 'basic_html'])->save();
$node2 = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($nid);
$node2->set('body', ['value' => '<p>Body text changed.</p>', 'format' => 'basic_html']);
$node2->save();
$job4 = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase4 paragraph retranslate']);
$job4->translator = 'external_api';
$job4->save();
$job4->addItem('content', 'node', $nid);
$job4->requestTranslation();
\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();
foreach ($job4->getItems() as $item) {
  $item->acceptTranslation();
}
$fr3 = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($nid)->getTranslation('fr');
p4t_log('Paragraph text after retranslate (protection check)', $fr3->get('field_paragraphs')->entity->get('field_text')->value);
