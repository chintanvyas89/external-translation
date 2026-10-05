<?php

/**
 * Enables Layout Builder overrides on node:page, makes the layout field
 * translatable, and enables content translation for block_content.
 * Run via `ddev drush php:script`.
 */

use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;

$display = \Drupal::entityTypeManager()
  ->getStorage('entity_view_display')
  ->load('node.page.default');

if (!$display instanceof LayoutBuilderEntityViewDisplay) {
  // Convert to a layout-builder-enabled display.
  $values = $display->toArray();
  $values['third_party_settings']['layout_builder'] = [
    'enabled' => TRUE,
    'allow_custom' => TRUE,
  ];
  \Drupal::entityTypeManager()->getStorage('entity_view_display')->delete([$display]);
  $display = LayoutBuilderEntityViewDisplay::create($values);
  $display->save();
  echo "Enabled Layout Builder overrides on node.page.default\n";
}
else {
  $display->enableLayoutBuilder();
  $display->setOverridable(TRUE);
  $display->save();
  echo "Layout Builder already present, ensured overridable\n";
}

// Enable content translation on block_content (all bundles present).
$manager = \Drupal::service('content_translation.manager');
foreach (\Drupal::entityTypeManager()->getStorage('block_content_type')->loadMultiple() as $bundle) {
  $manager->setEnabled('block_content', $bundle->id(), TRUE);
  echo "Enabled content translation for block_content:" . $bundle->id() . "\n";
}

// Make the layout_builder__layout field on node:page translatable. It's a
// base/computed field (not a configurable FieldConfig), so translatability
// is controlled via a BaseFieldOverride, same mechanism content_translation's
// own settings form uses (FieldDefinitionInterface::getConfig($bundle)).
$fieldDefinitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'page');
if (isset($fieldDefinitions['layout_builder__layout'])) {
  $fieldConfig = $fieldDefinitions['layout_builder__layout']->getConfig('page');
  if (!$fieldConfig->isTranslatable()) {
    $fieldConfig->setTranslatable(TRUE)->save();
    echo "Made layout_builder__layout translatable on node:page\n";
  }
}
else {
  echo "WARNING: layout_builder__layout field not found on node:page\n";
}

\Drupal::entityTypeManager()->clearCachedDefinitions();
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
\Drupal::service('plugin.manager.field.field_type')->clearCachedDefinitions();

echo "Done.\n";
