<?php

/**
 * Verifies TranslationRequestHelper (the service behind the "Also request
 * machine translation" checkbox on the standard "Add translation" form):
 * create EN node, add FR translation (untouched copy-of-source), call the
 * service directly (mirrors what the submit handler does), verify
 * title/body land as real machine translations with one call and no
 * proposals. Run via `ddev drush php:script`.
 */

use Drupal\node\Entity\Node;

function p6b_log(string $label, $value): void {
  echo str_pad($label, 34) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

$node = Node::create([
  'type' => 'page',
  'title' => 'Request helper test page',
  'langcode' => 'en',
  'body' => ['value' => '<p>Automate everything.</p>', 'format' => 'basic_html'],
  'status' => 1,
]);
$node->save();
p6b_log('Created node', $node->id());

$node->addTranslation('fr', $node->toArray());
$node->save();
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());

$helper = \Drupal::service('tmgmt_external_translation.request_helper');
$result = $helper->requestMachineTranslationForNewTranslation($node->getTranslation('fr'), 'fr');
p6b_log('Result success', $result['success'] ? 'yes' : 'no');
p6b_log('Result message', (string) $result['message']);

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$fr = $node->getTranslation('fr');
p6b_log('FR title (real translation expected)', $fr->getTitle());
p6b_log('FR body (real translation expected)', $fr->get('body')->value);

$proposalManager = \Drupal::service('tmgmt_external_translation.proposal_manager');
$pending = $proposalManager->getPendingProposalsForEntity('node', (string) $node->id(), 'fr');
p6b_log('Pending proposals (want 0)', count($pending));
