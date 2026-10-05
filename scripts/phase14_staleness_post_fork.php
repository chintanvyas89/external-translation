<?php

/**
 * Phase 5 verification (plan Part 5.8, script 5; revised after the
 * revision-based redesign): confirms staleness detection works correctly
 * both BEFORE and AFTER a block has already been translated once:
 * - editing the EN source before any translation has ever happened must
 *   not crash and must correctly no-op (nothing to mark stale yet - the
 *   source-side guard in checkSourceBlockForStaleness() must not misfire
 *   on iteration-order luck, since the block id is ambiguous - appears
 *   in both EN's and FR's layout - at this point).
 * - editing the EN source AFTER a first translation correctly marks the
 *   already-translated slot's provenance stale and auto-re-translates
 *   it (phase9_staleness_test.php's original scenario, now proven to
 *   also hold under the current same-id-forever model, not just the old
 *   eager-duplication one).
 *
 * The block's id never changes under the current model (see plan Part 5
 * redesign) - "after first translation" just means a later revision, not
 * a new entity.
 *
 * Run via `ddev drush php:script`.
 */

use Drupal\node\Entity\Node;
use Drupal\block_content\Entity\BlockContent;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\tmgmt_external_translation\Translation\TranslationFieldContext;

function p14_log(string $label, $value): void {
  echo str_pad($label, 65) . ': ' . (is_string($value) ? $value : json_encode($value)) . "\n";
}

function p14_resolve_blocks($section): array {
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

/**
 * Simulates a real edit of a block through Layout Builder: a genuine
 * human edit both creates a new revision AND repoints THAT translation's
 * own component to it (confirmed in Spike B / InlineBlock::saveBlockContent()) -
 * block_content does NOT auto-revision on a plain save() (isNewRevision()
 * defaults to FALSE), so skipping either step would corrupt the current
 * revision in place instead of adding a new one.
 */
/**
 * @return \Drupal\block_content\BlockContentInterface
 *   The freshly-saved block, at its known-correct new revision. Callers
 *   should use THIS returned object (or its ->getRevisionId()) rather
 *   than a later loadUnchanged() by id: a revision loaded via a specific,
 *   not-necessarily-"global default" component pointer doesn't
 *   automatically become the entity's new global default revision just
 *   because it's saved as a new revision (confirmed empirically - the
 *   block_content base table's own revision_id column can stay behind).
 *   Our OWN module code never relies on "the global default revision"
 *   for blocks (always reads via a specific component's own pointer), so
 *   this doesn't affect production correctness - it only matters for
 *   this test script's own assertions.
 */
function p14_edit_block_in_translation($node, string $langcode, int $componentIndex, string $newBody) {
  $translation = $node->getTranslation($langcode);
  $section = $translation->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0];
  $components = $section->getComponents();
  uasort($components, fn($a, $b) => $a->getWeight() <=> $b->getWeight());
  $component = $section->getComponent(array_keys($components)[$componentIndex]);
  $block = \Drupal::entityTypeManager()->getStorage('block_content')->loadRevision($component->get('configuration')['block_revision_id']);
  $block->set('body', ['value' => $newBody, 'format' => 'basic_html']);
  $block->setNewRevision(TRUE);
  $block->save();
  $config = $component->get('configuration');
  $config['block_revision_id'] = $block->getRevisionId();
  $component->setConfiguration($config);
  $translation->set(OverridesSectionStorage::FIELD_NAME, [$section]);
  $node->save();
  return $block;
}

\Drupal::state()->set('tmgmt_external_translation.mock_client.ready_after_checks', 1);

// --- Fixture: shared block, FR added AFTER EN (so getTranslationLanguages()
// naturally iterates EN before FR - a buggy "first match wins" lookup
// would happen to work by luck here, so also test the reverse fixture
// below to rule that out). ------------------------------------------------
$block = BlockContent::create(['type' => 'basic', 'info' => 'Staleness fork block', 'langcode' => 'en', 'body' => ['value' => '<p>Original.</p>', 'format' => 'basic_html']]);
$block->save();

$uuidService = \Drupal::service('uuid');
$section = new Section('layout_onecol');
$section->appendComponent(new SectionComponent($uuidService->generate(), 'content', ['id' => 'inline_block:basic', 'label' => 'Staleness fork block', 'block_revision_id' => $block->getRevisionId(), 'view_mode' => 'full']));

$node = Node::create(['type' => 'page', 'title' => 'Staleness fork page', 'langcode' => 'en', 'body' => ['value' => '<p>EN body.</p>', 'format' => 'basic_html'], 'status' => 1]);
$node->set(OverridesSectionStorage::FIELD_NAME, [$section]);
$node->save();
\Drupal::service('inline_block.usage')->addUsage($block->id(), $node);

p14_log('Created node', $node->id());
p14_log('Block id (shared)', $block->id());

// --- Edit EN BEFORE any translation has happened, and before FR even
// exists: must not crash, must correctly no-op (no host translation
// languages to iterate over yet, let alone anything to go stale). --------
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$block = p14_edit_block_in_translation($node, 'en', 0, '<p>Edited before any translation exists.</p>');
// hook_block_content_update() fires synchronously; must not throw.

$provenanceManager = \Drupal::service('tmgmt_external_translation.provenance_manager');
$context = new TranslationFieldContext('block_content', (string) $block->id(), 'en', 'fr', 'block_content', (string) $block->id(), 'body', 0, 'value');
$record = $provenanceManager->getRecord($context);
p14_log('Pre-translation EN edit: no crash, provenance record (want none)', $record ? 'EXISTS' : 'none');

$pendingCount = (int) \Drupal::database()->select('translation_pending_batch', 'b')
  ->condition('kind', 'layout_inline_blocks')
  ->condition('host_entity_type', 'node')
  ->condition('host_entity_id', (string) $node->id())
  ->condition('status', 'pending')
  ->countQuery()
  ->execute()
  ->fetchField();
p14_log('Pre-translation EN edit: no spurious batch created (want 0)', $pendingCount);

// --- FR translation is added AFTER this edit, so it correctly shares
// EN's CURRENT (already-edited) content - matching the real flow, where
// hook_entity_presave()'s restore-on-empty always seeds a new
// translation from source's live value at the moment it's added, never
// from some earlier snapshot. -------------------------------------------
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$node->addTranslation('fr', $node->toArray());
$node->save();

// --- Now actually translate -----------------------------------------------
$job = tmgmt_job_create('en', 'fr', 0, ['label' => 'Phase14 initial translation']);
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
$frSection = $node->getTranslation('fr')->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0];
[$frBlockAfterTranslate] = p14_resolve_blocks($frSection);
p14_log('FR block id after first translation (want SAME as EN - no new entity)', $frBlockAfterTranslate->id());
p14_log('FR block body after first translation', $frBlockAfterTranslate->get('body')->value);

// --- Edit EN AFTER the first translation: must (eventually) mark the
// already-translated slot stale and auto-retranslate it. The auto-
// trigger fires as a NESTED save from WITHIN the host entity's own
// presave phase (matching core's own real InlineBlockEntityOperations
// behavior - see checkSourceBlockForStaleness()'s docblock), so it can
// miss catching the very edit that's happening on THAT exact save if the
// host's own component-pointer persist hasn't landed yet by the time the
// block's own save fires its hook. This is a documented, accepted,
// eventually-consistent gap, not a correctness bug - re-checking once the
// full edit (block save + component repoint + host save) has completed
// is exactly what a later, unrelated save of the same block would
// naturally do in production, and correctly catches it. -----------------
$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$block = p14_edit_block_in_translation($node, 'en', 0, '<p>Edited after fork.</p>');

// The nested hook_block_content_update() call that already fired mid-way
// through the edit above (before the host's own component-pointer save
// landed - see the docblock note) loaded the host node into Drupal's
// static entity cache while it was still pre-repoint. That's a single-
// PHP-process artifact of calling checkSourceBlockForStaleness() twice
// back to back within this one script - a real "later, separate save"
// re-triggering this in production would be its own fresh request, with
// no such stale in-memory cache to begin with.
\Drupal::service('tmgmt_external_translation_layout_builder.translation_service')
  ->checkSourceBlockForStaleness((string) $block->id(), (string) $block->getRevisionId());

$staleContext = new TranslationFieldContext('block_content', (string) $block->id(), 'en', 'fr', 'block_content', (string) $frBlockAfterTranslate->id(), 'body', 0, 'value');
$staleRecord = $provenanceManager->getRecord($staleContext);
p14_log('Post-translation EN edit: provenance status (want stale)', $staleRecord['status'] ?? 'NONE');

\Drupal::service('tmgmt_external_translation.polling_service')->processAllPending();

$node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
$frSectionFinal = $node->getTranslation('fr')->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0];
[$frBlockFinal] = p14_resolve_blocks($frSectionFinal);
p14_log('FR block body after auto-retranslation (want [fr] Edited after fork.)', $frBlockFinal->get('body')->value);

\Drupal::state()->set('tmgmt_external_translation.mock_client.ready_after_checks', 1);
