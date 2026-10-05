<?php

namespace Drupal\tmgmt_external_translation\Translation;

class TranslationConflictDetector implements TranslationConflictDetectorInterface {

  public function __construct(
    protected TranslationFieldValueReaderInterface $fieldValueReader,
    protected TranslationHashManagerInterface $hashManager,
    protected TranslationProvenanceManagerInterface $provenanceManager,
  ) {}

  public function hasEditorModification(TranslationFieldContext $context, string $currentSourceText): bool {
    return $this->hasEditorModificationForValue($context, $currentSourceText, $this->fieldValueReader->getLiveValue($context));
  }

  public function hasEditorModificationForValue(TranslationFieldContext $context, string $currentSourceText, ?string $liveValue): bool {
    if ($liveValue === NULL || $liveValue === '') {
      return FALSE;
    }

    if ($this->hashManager->hash($liveValue) === $this->hashManager->hash($currentSourceText)) {
      // Byte-identical to the current source value: an untouched
      // copy-of-source placeholder, never a real translation or edit -
      // safe for the first machine translation to write straight through
      // instead of diverting to a proposal. Checked unconditionally
      // (whether or not a provenance record exists yet) because this
      // holds for BOTH: (a) a translation added via the standard "Add
      // translation" form, which core always pre-fills with the source
      // value, classified via _tmgmt_external_translation_classify_save()
      // as ORIGIN_SOURCE_COPY once a record exists; and (b) Layout
      // Builder inline blocks copied by layout_builder_at's widget, which
      // are brand-new, standalone block_content entities (not
      // translations of one entity - see LayoutBlockTranslationService),
      // so they never get a provenance record at all before their first
      // translation request.
      return FALSE;
    }

    $record = $this->provenanceManager->getRecord($context);
    if (!$record) {
      // No prior machine translation baseline, and the live value differs
      // from the current source: can't tell genuine hand-authored content
      // apart from anything else, so treat it as protected.
      return TRUE;
    }

    if ($record['origin'] === TranslationProvenanceManagerInterface::ORIGIN_SOURCE_COPY) {
      // Was an untouched copy when classified, even though the source has
      // since moved on and no longer matches - still not human-edited.
      return FALSE;
    }

    $liveHash = $this->hashManager->hash($liveValue);
    return $liveHash !== $record['provider_translation_hash'];
  }

}
