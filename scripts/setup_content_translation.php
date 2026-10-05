<?php

/**
 * One-time setup script: enable content translation on node:page (title +
 * body), run via `ddev drush php:script`.
 */

use Drupal\language\Entity\ContentLanguageSettings;

$manager = \Drupal::service('content_translation.manager');
$manager->setEnabled('node', 'page', TRUE);

// Title is a base field; mark it translatable explicitly via the entity
// field manager override, same as the UI does at
// /admin/config/regional/content-language.
$content_translation_settings = ContentLanguageSettings::loadByEntityTypeBundle('node', 'page');
$content_translation_settings->setThirdPartySetting('content_translation', 'enabled', TRUE);
$content_translation_settings->save();

/** @var \Drupal\field\Entity\FieldConfig $body_field */
$body_field = \Drupal\field\Entity\FieldConfig::loadByName('node', 'page', 'body');
if ($body_field) {
  $body_field->setTranslatable(TRUE);
  $body_field->save();
}

\Drupal::entityTypeManager()->clearCachedDefinitions();
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

echo "Content translation enabled for node:page (title + body translatable).\n";
