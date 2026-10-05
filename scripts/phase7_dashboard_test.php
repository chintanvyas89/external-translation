<?php

/**
 * Verifies the unified proposal dashboard: seeds one conflicting proposal
 * per category (real node field, interface string, layout component
 * label), confirms getAllPendingProposals() surfaces all three, then
 * resolves each via resolveProposal() and confirms it dispatched to the
 * correct write path (not the generic entity-field fallback, which would
 * throw for the interface-string and label categories). Run via
 * `ddev drush php:script`.
 */

use Drupal\node\Entity\Node;
use Drupal\block_content\Entity\BlockContent;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\tmgmt_external_translation\Translation\TranslationFieldContext;

function p7_log(string $label, $value): void {
  echo str_pad($label, 40) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

$proposalManager = \Drupal::service('tmgmt_external_translation.proposal_manager');
$provenanceManager = \Drupal::service('tmgmt_external_translation.provenance_manager');

// --- Category 1: real node field conflict --------------------------------
$node = Node::create([
  'type' => 'page',
  'title' => 'Dashboard test page',
  'langcode' => 'en',
  'body' => ['value' => '<p>Original body.</p>', 'format' => 'basic_html'],
  'status' => 1,
]);
$node->save();
$node->addTranslation('fr', $node->toArray());
$node->save();
$job = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase7 node']);
$job->translator = 'external_api';
$job->save();
$job->addItem('content', 'node', $node->id());
$job->requestTranslation();
\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();
foreach ($job->getItems() as $item) {
  $item->acceptTranslation();
}
// Hand-edit, then change source and retranslate to force a conflict.
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$fr = $node->getTranslation('fr');
$fr->set('body', ['value' => '<p>Editeur.</p>', 'format' => 'basic_html']);
$fr->save();
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$node->set('body', ['value' => '<p>Changed body.</p>', 'format' => 'basic_html']);
$node->save();
$job2 = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase7 node retranslate']);
$job2->translator = 'external_api';
$job2->save();
$job2->addItem('content', 'node', $node->id());
$job2->requestTranslation();
\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();
foreach ($job2->getItems() as $item) {
  $item->acceptTranslation();
}
$nodePending = $proposalManager->getPendingProposalsForEntity('node', (string) $node->id(), 'fr');
$nodeProposalId = (int) array_key_first($nodePending);
p7_log('Node proposal id', $nodeProposalId);

// --- Category 2: interface string conflict -------------------------------
$manager = \Drupal::service('tmgmt_external_translation_interface.manager');
$lid = (int) \Drupal::database()->select('locales_source', 's')->fields('s', ['lid'])->condition('source', '', '<>')->range(0, 1)->orderBy('lid', 'DESC')->execute()->fetchField();
$manager->retranslateString($lid, 'fr');
$stringContext = new TranslationFieldContext('interface_string', (string) $lid, 'en', 'fr', 'interface_string', (string) $lid, 'translation', 0, 'value');
$provenanceManager->recordEditorEdit($stringContext, 'Correction editeur');
\Drupal::database()->update('locales_target')->fields(['translation' => 'Correction editeur', 'customized' => 1])->condition('lid', $lid)->condition('language', 'fr')->execute();
$manager->retranslateString($lid, 'fr');
$stringPending = $proposalManager->getPendingProposalsForEntity('interface_string', (string) $lid, 'fr');
$stringProposalId = (int) array_key_first($stringPending);
p7_log('Interface string proposal id', $stringProposalId);

// --- Category 3: layout component label conflict --------------------------
$labelNode = Node::create(['type' => 'page', 'title' => 'Dashboard label test', 'langcode' => 'en', 'body' => ['value' => '<p>Body.</p>', 'format' => 'basic_html'], 'status' => 1]);
$block = BlockContent::create(['type' => 'text_block', 'info' => 'Dashboard block', 'langcode' => 'en']);
$block->body = ['value' => '<p>Block text.</p>', 'format' => 'basic_html'];
$block->save();
$component = new SectionComponent(\Drupal::service('uuid')->generate(), 'content', [
  'id' => 'inline_block:text_block', 'label' => 'Original heading', 'label_display' => 'visible',
  'provider' => 'layout_builder', 'view_mode' => 'full', 'block_id' => $block->id(), 'block_revision_id' => $block->getRevisionId(),
]);
$labelNode->set('layout_builder__layout', [new Section('layout_onecol', [], [$component->getUuid() => $component])]);
$labelNode->save();
\Drupal::service('inline_block.usage')->addUsage($block->id(), $labelNode);
$blockFr = $block->createDuplicate();
$blockFr->set('langcode', 'fr');
$blockFr->save();
$labelNode->addTranslation('fr', $labelNode->toArray());
$labelNode->save();
$labelFr = $labelNode->getTranslation('fr');
$componentFr = new SectionComponent(\Drupal::service('uuid')->generate(), 'content', [
  'id' => 'inline_block:text_block', 'label' => 'Original heading', 'label_display' => 'visible',
  'provider' => 'layout_builder', 'view_mode' => 'full', 'block_id' => $blockFr->id(), 'block_revision_id' => $blockFr->getRevisionId(),
]);
$labelFr->set('layout_builder__layout', [new Section('layout_onecol', [], [$componentFr->getUuid() => $componentFr])]);
$labelFr->save();
\Drupal::service('inline_block.usage')->addUsage($blockFr->id(), $labelNode);

$helper = \Drupal::service('tmgmt_external_translation.request_helper');
$helper->requestMachineTranslationForNewTranslation($labelFr, 'fr');
// Hand-edit the label, change source, retranslate to force a conflict.
$collector = \Drupal::service('tmgmt_external_translation_layout_builder.entity_collector');
$labelNode = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($labelNode->id());
$labelFr = $labelNode->getTranslation('fr');
foreach ($labelFr->get('layout_builder__layout')->getSections() as $sd => $s) {
  foreach ($s->getComponents() as $c) {
    if (str_starts_with($c->get('configuration')['id'] ?? '', 'inline_block')) {
      $collector->setComponentLabel($labelFr, $sd, $c->getUuid(), 'Titre corrige');
    }
  }
}
$labelNode = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($labelNode->id());
foreach ($labelNode->get('layout_builder__layout')->getSections() as $sd => $s) {
  foreach ($s->getComponents() as $c) {
    if (str_starts_with($c->get('configuration')['id'] ?? '', 'inline_block')) {
      $collector->setComponentLabel($labelNode, $sd, $c->getUuid(), 'New heading');
    }
  }
}
$job3 = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase7 label retranslate']);
$job3->translator = 'external_api';
$job3->save();
$job3->addItem('content', 'node', $labelNode->id());
$job3->requestTranslation();
\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();
foreach ($job3->getItems() as $item) {
  $item->acceptTranslation();
}
$labelPending = $proposalManager->getPendingProposalsForEntity('block_content', (string) $blockFr->id(), 'fr');
$labelProposalId = NULL;
foreach ($labelPending as $pid => $p) {
  if ($p['field_name'] === 'layout_component_label') {
    $labelProposalId = (int) $pid;
  }
}
p7_log('Label proposal id', $labelProposalId ?? 'NONE');

// --- Verify all three appear on the dashboard query ------------------------
$all = $proposalManager->getAllPendingProposals();
p7_log('Total pending proposals (dashboard query)', count($all));
p7_log('Contains node proposal', isset($all[$nodeProposalId]) ? 'yes' : 'no');
p7_log('Contains string proposal', isset($all[$stringProposalId]) ? 'yes' : 'no');
p7_log('Contains label proposal', isset($all[$labelProposalId]) ? 'yes' : 'no');

// --- Resolve each via resolveProposal() and verify correct write-back -----
$okNode = $proposalManager->resolveProposal($nodeProposalId, 'accept');
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
p7_log('Node resolve success', $okNode ? 'yes' : 'no');
p7_log('Node FR body after accept', $node->getTranslation('fr')->get('body')->value);

$okString = $proposalManager->resolveProposal($stringProposalId, 'reject');
p7_log('String resolve success', $okString ? 'yes' : 'no');
$stringValue = \Drupal::database()->select('locales_target', 't')->fields('t', ['translation'])->condition('lid', $lid)->condition('language', 'fr')->execute()->fetchField();
p7_log('String value after reject (must be editor text)', $stringValue);

$okLabel = $proposalManager->resolveProposal($labelProposalId, 'edit', 'Titre final edite');
p7_log('Label resolve success', $okLabel ? 'yes' : 'no');
$labelNode = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($labelNode->id());
$labelFr = $labelNode->getTranslation('fr');
foreach ($labelFr->get('layout_builder__layout')->getSections() as $s) {
  foreach ($s->getComponents() as $c) {
    if (str_starts_with($c->get('configuration')['id'] ?? '', 'inline_block')) {
      p7_log('Label value after edit', $c->get('configuration')['label']);
    }
  }
}

$remaining = $proposalManager->getAllPendingProposals();
p7_log('Remaining pending proposals (want 0)', count($remaining));
