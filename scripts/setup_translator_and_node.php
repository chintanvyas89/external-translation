<?php

/**
 * One-time setup: create the tmgmt_translator config entity for our plugin,
 * and a test "page" node with title + body, run via `ddev drush php:script`.
 */

use Drupal\tmgmt\Entity\Translator;
use Drupal\node\Entity\Node;

$translator = Translator::load('external_api');
if (!$translator) {
  $translator = Translator::create([
    'name' => 'external_api',
    'label' => 'External API (machine translation)',
    'plugin' => 'external_api',
    'auto_accept' => FALSE,
  ]);
  $translator->save();
  echo "Created translator: external_api\n";
}
else {
  echo "Translator external_api already exists.\n";
}

$existing = \Drupal::entityTypeManager()->getStorage('node')
  ->loadByProperties(['title' => 'Welcome to our website']);
if ($existing) {
  $node = reset($existing);
  echo "Test node already exists: nid=" . $node->id() . "\n";
}
else {
  $node = Node::create([
    'type' => 'page',
    'title' => 'Welcome to our website',
    'langcode' => 'en',
    'body' => [
      'value' => '<p>Welcome to our website.</p>',
      'format' => 'basic_html',
    ],
    'status' => 1,
  ]);
  $node->save();
  echo "Created test node: nid=" . $node->id() . "\n";
}
