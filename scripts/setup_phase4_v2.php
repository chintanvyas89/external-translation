<?php

/**
 * Phase 4 setup: paragraph type + field on node:page, a media type + field,
 * content moderation workflow on node:page. Run via `ddev drush php:script`.
 */

use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\media\Entity\MediaType;
use Drupal\workflows\Entity\Workflow;

function p4_log(string $msg): void {
  echo $msg . "\n";
}

// --- Paragraph type -------------------------------------------------------
if (!ParagraphsType::load('test_paragraph')) {
  ParagraphsType::create(['id' => 'test_paragraph', 'label' => 'Test Paragraph'])->save();
  p4_log('Created paragraph type test_paragraph');
}
\Drupal::service('content_translation.manager')->setEnabled('paragraph', 'test_paragraph', TRUE);

if (!FieldStorageConfig::loadByName('paragraph', 'field_text')) {
  FieldStorageConfig::create([
    'field_name' => 'field_text',
    'entity_type' => 'paragraph',
    'type' => 'text_long',
  ])->save();
}
if (!FieldConfig::loadByName('paragraph', 'test_paragraph', 'field_text')) {
  FieldConfig::create([
    'field_name' => 'field_text',
    'entity_type' => 'paragraph',
    'bundle' => 'test_paragraph',
    'label' => 'Text',
    'translatable' => TRUE,
  ])->save();
  p4_log('Created paragraph.test_paragraph.field_text (translatable)');
}

// Nested paragraphs: allow a paragraph to reference another paragraph.
if (!FieldStorageConfig::loadByName('paragraph', 'field_child')) {
  FieldStorageConfig::create([
    'field_name' => 'field_child',
    'entity_type' => 'paragraph',
    'type' => 'entity_reference_revisions',
    'settings' => ['target_type' => 'paragraph'],
    'cardinality' => -1,
  ])->save();
}
if (!FieldConfig::loadByName('paragraph', 'test_paragraph', 'field_child')) {
  FieldConfig::create([
    'field_name' => 'field_child',
    'entity_type' => 'paragraph',
    'bundle' => 'test_paragraph',
    'label' => 'Child paragraph',
    'settings' => ['handler_settings' => ['target_bundles' => ['test_paragraph' => 'test_paragraph']]],
  ])->save();
  p4_log('Created paragraph.test_paragraph.field_child (nesting)');
}

// Node field referencing paragraphs.
if (!FieldStorageConfig::loadByName('node', 'field_paragraphs')) {
  FieldStorageConfig::create([
    'field_name' => 'field_paragraphs',
    'entity_type' => 'node',
    'type' => 'entity_reference_revisions',
    'settings' => ['target_type' => 'paragraph'],
    'cardinality' => -1,
  ])->save();
}
if (!FieldConfig::loadByName('node', 'page', 'field_paragraphs')) {
  FieldConfig::create([
    'field_name' => 'field_paragraphs',
    'entity_type' => 'node',
    'bundle' => 'page',
    'label' => 'Paragraphs',
    'settings' => ['handler_settings' => ['target_bundles' => ['test_paragraph' => 'test_paragraph']]],
  ])->save();
  p4_log('Created node.page.field_paragraphs');
}

$formDisplay = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load('node.page.default');
$formDisplay->setComponent('field_paragraphs', ['type' => 'paragraphs'])->save();

// --- Media type -------------------------------------------------------
if (!MediaType::load('document_test')) {
  /** @var \Drupal\media\MediaTypeInterface $mediaType */
  $mediaType = MediaType::create([
    'id' => 'document_test',
    'label' => 'Document Test',
    'source' => 'file',
  ]);
  $mediaType->save();

  $source = $mediaType->getSource();
  $sourceField = $source->createSourceField($mediaType);
  $storage = $sourceField->getFieldStorageDefinition();
  if ($storage->isNew()) {
    $storage->save();
  }
  $sourceField->save();
  $mediaType->set('source_configuration', ['source_field' => $sourceField->getName()])->save();
  p4_log('Created media type document_test');
}
\Drupal::service('content_translation.manager')->setEnabled('media', 'document_test', TRUE);
$nameField = FieldConfig::loadByName('media', 'document_test', 'name');
if ($nameField) {
  $nameField->setTranslatable(TRUE)->save();
}

// --- Content moderation on node:page ------------------------------------
$workflow = Workflow::load('editorial');
if ($workflow && $workflow->getTypePlugin()->getEntityTypes() && !in_array('page', $workflow->getTypePlugin()->getBundlesForEntityType('node'), TRUE)) {
  $workflow->getTypePlugin()->addEntityTypeBundle('node', 'page');
  $workflow->save();
  p4_log('Added node:page to the editorial workflow');
}
elseif (!$workflow) {
  p4_log('WARNING: editorial workflow not found - is content_moderation/workflows enabled?');
}

\Drupal::entityTypeManager()->clearCachedDefinitions();
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

p4_log('Done.');
