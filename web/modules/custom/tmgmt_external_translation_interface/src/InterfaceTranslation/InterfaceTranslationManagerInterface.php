<?php

namespace Drupal\tmgmt_external_translation_interface\InterfaceTranslation;

/**
 * Translates Drupal interface strings (Locale) through the same
 * provenance/conflict-protection engine as content, without requiring
 * the core module to know Locale exists (locale strings aren't content
 * entities, so the entity-coupled parts of the core Translation/*
 * services - ConflictDetector, ProposalManager's accept/reject/edit -
 * aren't reused directly; the field-level bookkeeping services
 * (TranslationHashManager, TranslationProvenanceManager) are, since
 * they only deal in TranslationFieldContext and arbitrary strings).
 */
interface InterfaceTranslationManagerInterface {

  /**
   * Machine-translates up to $limit interface strings that have no
   * translation yet for $targetLangcode.
   *
   * @return string[] Human-readable result messages.
   */
  public function translateUntranslatedStrings(string $targetLangcode, int $limit = 50): array;

  /**
   * Re-translates a specific already-translated string (source changed):
   * safe to overwrite directly if unmodified since the last machine
   * translation, otherwise creates a proposal.
   */
  public function retranslateString(int $lid, string $targetLangcode): string;

  public function acceptStringProposal(int $proposalId): bool;

  public function rejectStringProposal(int $proposalId): bool;

  public function editStringProposal(int $proposalId, string $editedText): bool;

  /**
   * @return array[] Pending proposals for a target language, keyed by id,
   *   each with the source string joined in for display.
   */
  public function getPendingProposals(string $targetLangcode): array;

}
