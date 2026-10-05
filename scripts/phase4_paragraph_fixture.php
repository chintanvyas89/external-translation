<?php

/**
 * Creates a node with a paragraph (with a nested child paragraph) and
 * prints the raw flattened TMGMT job item data keys, to see the actual
 * key format tmgmt_content produces for entity_reference_revisions
 * fields. Run via `ddev drush php:script`.
 */

use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

$child = Paragraph::create([
  'type' => 'test_paragraph',
  'field_text' => ['value' => '<p>Child paragraph text.</p>', 'format' => 'basic_html'],
]);
$child->save();

$parent = Paragraph::create([
  'type' => 'test_paragraph',
  'field_text' => ['value' => '<p>Parent paragraph text.</p>', 'format' => 'basic_html'],
  'field_child' => [$child],
]);
$parent->save();

$node = Node::create([
  'type' => 'page',
  'title' => 'Paragraphs test page',
  'langcode' => 'en',
  'body' => ['value' => '<p>Body text.</p>', 'format' => 'basic_html'],
  'field_paragraphs' => [$parent],
  'status' => 1,
]);
$node->save();
echo "PARAGRAPH_NODE_ID=" . $node->id() . "\n";

$job = tmgmt_job_create('en', 'fr', 0, ['label' => 'Paragraph key inspection']);
$job->translator = 'external_api';
$job->save();
$job_item = $job->addItem('content', 'node', $node->id());

$data_service = \Drupal::service('tmgmt.data');
$flattened = $data_service->filterTranslatable($job_item->getData());
foreach ($flattened as $key => $value) {
  echo "KEY: $key => " . ($value['#text'] ?? '(no text)') . "\n";
}
