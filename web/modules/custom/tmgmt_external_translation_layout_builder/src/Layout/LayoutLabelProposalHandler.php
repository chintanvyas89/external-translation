<?php

namespace Drupal\tmgmt_external_translation_layout_builder\Layout;

use Drupal\tmgmt_external_translation\Translation\TranslationProposalManagerInterface;
use Drupal\tmgmt_external_translation\Translation\TranslationProvenanceManagerInterface;

/**
 * Accept/Keep/Edit for a Layout Builder component-label proposal - the
 * non-entity-field write path a label needs instead of
 * TranslationProposalManager's generic writeToEntity(), mirroring how
 * InterfaceTranslationManager resolves interface-string proposals with
 * its own write logic + markResolved().
 *
 * findComponentForBlock() calls below always pass $context->targetLangcode
 * as the preferred langcode: normally a label proposal's target block id
 * is already forked (distinct from source) by the time it exists, but a
 * block with no trackable content fields could still have a proposal
 * against a not-yet-forked (shared) id - see plan Part 5.3. Without the
 * explicit preference, findComponentForBlock()'s own default (prefer the
 * host's DEFAULT/source translation when a shared id is ambiguous) would
 * resolve to the wrong (source-side) component here, since this handler
 * always wants the TARGET side specifically.
 */
class LayoutLabelProposalHandler {

  public function __construct(
    protected TranslationProposalManagerInterface $proposalManager,
    protected TranslationProvenanceManagerInterface $provenanceManager,
    protected LayoutEntityCollector $collector,
  ) {}

  public function acceptLabelProposal(int $proposalId): bool {
    $proposal = $this->proposalManager->getProposal($proposalId);
    $context = $this->proposalManager->getContextForProposal($proposalId);
    $location = $context ? $this->collector->findComponentForBlock($context->targetEntityId, $context->targetLangcode) : NULL;
    if (!$proposal || !$context || !$location) {
      return FALSE;
    }

    $this->collector->setComponentLabel($location['hostTranslation'], $location['sectionDelta'], $location['componentUuid'], $proposal['provider_translation']);
    $this->provenanceManager->setBaseline($context, $proposal['provider_translation'], TranslationProvenanceManagerInterface::ORIGIN_MACHINE_ACCEPTED, TranslationProvenanceManagerInterface::STATUS_APPROVED);
    $this->proposalManager->markResolved($proposalId, 'accepted');
    return TRUE;
  }

  public function rejectLabelProposal(int $proposalId): bool {
    $context = $this->proposalManager->getContextForProposal($proposalId);
    $location = $context ? $this->collector->findComponentForBlock($context->targetEntityId, $context->targetLangcode) : NULL;
    if (!$context || !$location) {
      return FALSE;
    }

    // Label is untouched; restore provenance to reflect the editor's
    // existing text remains authoritative.
    $currentLabel = $this->collector->getComponentLabel($location['hostTranslation'], $location['sectionDelta'], $location['componentUuid']) ?? '';
    $this->provenanceManager->recordEditorModification($context, $currentLabel);
    $this->proposalManager->markResolved($proposalId, 'rejected');
    return TRUE;
  }

  public function editLabelProposal(int $proposalId, string $editedText): bool {
    $context = $this->proposalManager->getContextForProposal($proposalId);
    $location = $context ? $this->collector->findComponentForBlock($context->targetEntityId, $context->targetLangcode) : NULL;
    if (!$context || !$location) {
      return FALSE;
    }

    $this->collector->setComponentLabel($location['hostTranslation'], $location['sectionDelta'], $location['componentUuid'], $editedText);
    $this->provenanceManager->recordEditorEdit($context, $editedText);
    $this->proposalManager->markResolved($proposalId, 'edited');
    return TRUE;
  }

}
