<?php

namespace Drupal\tmgmt_external_translation\Translation;

/**
 * Detects whether a live target value has been edited by a human since the
 * last machine translation, i.e. whether it's now safe to auto-overwrite.
 */
interface TranslationConflictDetectorInterface {

  /**
   * @param string $currentSourceText
   *   The CURRENT source-language value for this slot, as the caller is
   *   about to translate it. Needed to distinguish an untouched
   *   copy-of-source placeholder (safe to overwrite) from genuine
   *   hand-authored/edited content (protected) - see implementation.
   *
   * @return bool
   *   TRUE if the current live value differs from the last provider
   *   translation recorded for this slot (editor modification, or there
   *   was never a machine translation to begin with but a value exists
   *   that isn't just an untouched copy of the source).
   */
  public function hasEditorModification(TranslationFieldContext $context, string $currentSourceText): bool;

  /**
   * Same rule as hasEditorModification(), but for a live value the caller
   * already has in hand rather than one readable via
   * TranslationFieldValueReaderInterface (e.g. Layout Builder component
   * labels, which are config on the host entity's layout field, not a
   * real field on any entity - see LayoutBlockTranslationService).
   */
  public function hasEditorModificationForValue(TranslationFieldContext $context, string $currentSourceText, ?string $liveValue): bool;

}
