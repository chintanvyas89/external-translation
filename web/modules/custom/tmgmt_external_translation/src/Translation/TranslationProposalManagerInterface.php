<?php

namespace Drupal\tmgmt_external_translation\Translation;

/**
 * Manages machine-translation proposals: the Accept / Keep / Edit workflow
 * shown when a new provider translation conflicts with an editor's
 * existing, already-approved translation.
 */
interface TranslationProposalManagerInterface {

  /**
   * Creates (or replaces the pending one for) a proposal for a slot.
   * Does not touch the live entity value.
   */
  public function createProposal(TranslationFieldContext $context, string $sourceText, string $providerText, string $providerName, string $providerVersion): array;

  /**
   * @return array[]
   *   All pending proposals for a target entity translation, keyed by
   *   proposal id. Used to build the proposal review UI for a node/langcode.
   */
  public function getPendingProposalsForEntity(string $entityType, string $entityId, string $langcode): array;

  /**
   * @return array[]
   *   Every pending proposal system-wide (no entity/langcode scoping),
   *   keyed by proposal id, each row including target_entity_type/id/
   *   langcode and field_name so a caller can categorize and dispatch
   *   accept/reject/edit appropriately. Powers the central dashboard.
   */
  public function getAllPendingProposals(): array;

  /**
   * Single entry point for resolving a proposal (Accept/Keep/Edit),
   * regardless of what kind of target it's for. Dispatches
   * ProposalActionEvent first so non-entity targets (interface strings,
   * Layout Builder component labels) can claim and handle it with their
   * own write logic; falls back to acceptProposal()/rejectProposal()/
   * applyEditedProposal() (unchanged) if nothing claimed it.
   */
  public function resolveProposal(int $proposalId, string $action, ?string $editedText = NULL): bool;

  /**
   * "Accept": writes the provider's proposed text into the entity as-is.
   */
  public function acceptProposal(int $proposalId): bool;

  /**
   * "Keep": leaves the entity untouched, closes the proposal.
   */
  public function rejectProposal(int $proposalId): bool;

  /**
   * "Edit": writes editor-supplied text (not the raw provider text) into
   * the entity, and records it as an editor-owned value going forward.
   */
  public function applyEditedProposal(int $proposalId, string $editedText): bool;

  /**
   * Loads a single proposal record by id.
   */
  public function getProposal(int $proposalId): ?array;

  /**
   * Marks a proposal resolved without touching any entity - for
   * non-entity targets (e.g. interface strings) that do their own write.
   */
  public function markResolved(int $proposalId, string $status): void;

  /**
   * Reconstructs the TranslationFieldContext for a proposal, for
   * non-entity callers that need it without duplicating the lookup.
   */
  public function getContextForProposal(int $proposalId): ?TranslationFieldContext;

}
