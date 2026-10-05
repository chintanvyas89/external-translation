<?php

namespace Drupal\tmgmt_external_translation\Translation;

/**
 * Tracks the origin (machine vs. editor) and review status of every
 * translated value slot, so editor corrections are never silently
 * overwritten by a later machine retranslation.
 */
interface TranslationProvenanceManagerInterface {

  const ORIGIN_MACHINE_TRANSLATED = 'machine_translated';
  const ORIGIN_MACHINE_ACCEPTED = 'machine_accepted';
  const ORIGIN_EDITOR_MODIFIED = 'editor_modified';
  const ORIGIN_EDITOR = 'editor';

  /**
   * A value that is byte-identical to the current source value and has
   * never been machine-translated or hand-edited - i.e. the untouched
   * copy-of-source placeholder Drupal core seeds every new translation
   * with (see ContentTranslationController::prepareTranslation()). Treated
   * as a translation seed, not protected editor content: safe for the
   * first machine translation to overwrite directly.
   */
  const ORIGIN_SOURCE_COPY = 'source_copy';

  const STATUS_MACHINE_TRANSLATED = 'machine_translated';
  const STATUS_APPROVED = 'approved';
  const STATUS_NEEDS_REVIEW = 'needs_review';

  /**
   * The source value used for this slot's last translation has since
   * changed (e.g. an editor edited the English Layout Builder block
   * directly). Purely an audit marker set right before an automatic
   * re-translation request is submitted - recordMachineTranslation()
   * overwrites it again once that request completes.
   */
  const STATUS_STALE = 'stale';

  /**
   * Paired with ORIGIN_SOURCE_COPY - see its docblock.
   */
  const STATUS_COPIED_FROM_SOURCE = 'copied_from_source';

  /**
   * Loads the raw provenance record for a slot, if one exists.
   */
  public function getRecord(TranslationFieldContext $context): ?array;

  /**
   * Records that a slot just received a fresh, not-yet-reviewed machine
   * translation (first translation, or a re-accepted still-unreviewed MT).
   */
  public function recordMachineTranslation(TranslationFieldContext $context, string $sourceText, string $providerText, string $providerName, string $providerVersion): array;

  /**
   * Records that the editor saved the slot with a value that differs from
   * the last known provider output - the editor's version is now
   * authoritative and must not be auto-overwritten by future MT.
   */
  public function recordEditorModification(TranslationFieldContext $context, string $currentText): array;

  /**
   * Records that the editor saved the slot with a value matching the last
   * known provider output - an implicit approval of the machine text.
   */
  public function recordEditorApproval(TranslationFieldContext $context, string $currentText): array;

  /**
   * Whether the live target value has diverged from the last provider
   * translation recorded for this slot (Rule: current_target_hash !=
   * provider_translation_hash => editor modification).
   */
  public function isEditorModified(TranslationFieldContext $context): bool;

  /**
   * Marks a slot's status as needing review because a new machine
   * translation proposal is pending (does not change the live value).
   */
  public function markNeedsReview(TranslationFieldContext $context): void;

  /**
   * Marks a slot's status as stale because its source value has changed
   * since the last translation was recorded (does not change the live
   * value or the stored hashes - a fresh translation request is
   * responsible for that once it completes).
   */
  public function markStale(TranslationFieldContext $context): void;

  /**
   * Directly overwrites the stored baseline (including provider_translation_hash)
   * after a proposal's *raw provider text* has been accepted and written to
   * the entity as-is - the new machine output legitimately becomes the
   * baseline for future comparisons.
   */
  public function setBaseline(TranslationFieldContext $context, string $currentText, string $origin, string $status): array;

  /**
   * Records that the editor wrote their own text over a proposal (the Edit
   * action) or over an existing translation. Deliberately does NOT touch
   * provider_translation_hash: the editor's words are not a machine
   * translation, so the last real provider baseline must be preserved for
   * future conflict comparisons (otherwise a later retranslation could
   * silently pass the conflict check and overwrite this edit).
   *
   * Also reused (with $origin = ORIGIN_SOURCE_COPY, $status =
   * STATUS_COPIED_FROM_SOURCE) to record an untouched copy-of-source
   * placeholder, which is deliberately NOT protected the way genuine
   * editor content is - see TranslationConflictDetector.
   */
  public function recordEditorEdit(TranslationFieldContext $context, string $currentText, string $origin = self::ORIGIN_EDITOR, string $status = self::STATUS_APPROVED): array;

}
