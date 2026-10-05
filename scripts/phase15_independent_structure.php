<?php

/**
 * Phase 5 verification (plan Part 5.8, script 6): confirms editors keep
 * full structural independence per language under the shared-by-default
 * model - FR can add an entirely new section/block that EN never had,
 * without breaking EN's own layout or the mapper's pairing for the
 * still-shared blocks that DO exist on both sides.
 *
 * Run via `ddev drush php:script`.
 */

use Drupal\node\Entity\Node;
use Drupal\block_content\Entity\BlockContent;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;

function p15_log(string $label, $value): void {
  echo str_pad($label, 65) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

function p15_resolve_blocks($section): array {
  $storage = \Drupal::entityTypeManager()->getStorage('block_content');
  $components = $section->getComponents();
  uasort($components, fn($a, $b) => $a->getWeight() <=> $b->getWeight());
  $blocks = [];
  foreach ($components as $component) {
    $configuration = $component->get('configuration');
    if (empty($configuration['block_revision_id'])) {
      continue;
    }
    $blocks[] = $storage->loadRevision($configuration['block_revision_id']);
  }
  return $blocks;
}

\Drupal::state()->set('tmgmt_external_translation.mock_client.ready_after_checks', 1);

// --- Fixture: node with 1 shared block in section 0 ----------------------
$sharedBlock = BlockContent::create(['type' => 'basic', 'info' => 'Independent structure shared block', 'langcode' => 'en', 'body' => ['value' => '<p>Shared block.</p>', 'format' => 'basic_html']]);
$sharedBlock->save();

$uuidService = \Drupal::service('uuid');
$section0 = new Section('layout_onecol');
$section0->appendComponent(new SectionComponent($uuidService->generate(), 'content', ['id' => 'inline_block:basic', 'label' => 'Independent structure shared block', 'block_revision_id' => $sharedBlock->getRevisionId(), 'view_mode' => 'full']));

$node = Node::create(['type' => 'page', 'title' => 'Independent structure page', 'langcode' => 'en', 'body' => ['value' => '<p>EN body.</p>', 'format' => 'basic_html'], 'status' => 1]);
$node->set(OverridesSectionStorage::FIELD_NAME, [$section0]);
$node->save();
\Drupal::service('inline_block.usage')->addUsage($sharedBlock->id(), $node);

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$node->addTranslation('fr', $node->toArray());
$node->save();

p15_log('Created node', $node->id());
p15_log('Shared block id', $sharedBlock->id());

// --- FR adds a brand-new SECOND section with a new block, EN never has --
$frOnlyBlock = BlockContent::create(['type' => 'basic', 'info' => 'FR-only block', 'langcode' => 'fr', 'body' => ['value' => '<p>Contenu francais uniquement.</p>', 'format' => 'basic_html']]);
$frOnlyBlock->save();

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$fr = $node->getTranslation('fr');
$frSections = $fr->get(OverridesSectionStorage::FIELD_NAME)->getSections();

$frNewSection = new Section('layout_onecol');
$frNewSection->appendComponent(new SectionComponent($uuidService->generate(), 'content', ['id' => 'inline_block:basic', 'label' => 'FR-only block', 'block_revision_id' => $frOnlyBlock->getRevisionId(), 'view_mode' => 'full']));
$frSections[] = $frNewSection;
$fr->set(OverridesSectionStorage::FIELD_NAME, $frSections);
$node->save();
\Drupal::service('inline_block.usage')->addUsage($frOnlyBlock->id(), $node);

// --- Assertions: EN unaffected --------------------------------------------
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$en = $node->getTranslation('en');
$enSections = $en->get(OverridesSectionStorage::FIELD_NAME)->getSections();
p15_log('EN section count unaffected (want 1)', count($enSections));

$fr = $node->getTranslation('fr');
$frSectionsAfter = $fr->get(OverridesSectionStorage::FIELD_NAME)->getSections();
p15_log('FR section count (want 2)', count($frSectionsAfter));

// --- Run the real pipeline: the shared block should still translate
// correctly despite the now-uneven section/component counts between EN
// and FR (exercises LayoutBlockMapper's id-match-first, positional-
// fallback-for-remainder design). -----------------------------------------
$job = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase15 independent structure']);
$job->translator = 'external_api';
$job->save();
$job->addItem('content', 'node', $node->id());
$job->requestTranslation();
\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();
foreach ($job->getItems() as $item) {
  if ($item->isNeedsReview()) {
    $item->acceptTranslation();
  }
}

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$fr = $node->getTranslation('fr');
$frSectionsFinal = $fr->get(OverridesSectionStorage::FIELD_NAME)->getSections();
[$frSharedBlockAfter] = p15_resolve_blocks($frSectionsFinal[0]);
p15_log('Shared block translated correctly (want [fr]-prefixed)', $frSharedBlockAfter->get('body')->value);

[$frOnlyBlockAfter] = p15_resolve_blocks($frSectionsFinal[1]);
p15_log('FR-only block untouched by translation (no source counterpart)', $frOnlyBlockAfter->get('body')->value);

$en = $node->getTranslation('en');
$enSectionsFinal = $en->get(OverridesSectionStorage::FIELD_NAME)->getSections();
p15_log('EN still has only 1 section after the whole flow', count($enSectionsFinal));
[$enSharedBlockFinal] = p15_resolve_blocks($enSectionsFinal[0]);
p15_log('EN shared block body unaffected', $enSharedBlockFinal->get('body')->value);

\Drupal::state()->set('tmgmt_external_translation.mock_client.ready_after_checks', 1);
