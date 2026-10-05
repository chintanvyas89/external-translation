<?php

namespace Drupal\tmgmt_external_translation\Translation;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

class TranslationProvenanceManager implements TranslationProvenanceManagerInterface {

  const TABLE = 'translation_provenance';

  public function __construct(
    protected Connection $database,
    protected TranslationHashManagerInterface $hashManager,
    protected TimeInterface $time,
  ) {}

  public function getRecord(TranslationFieldContext $context): ?array {
    $record = $this->database->select(self::TABLE, 'p')
      ->fields('p')
      ->condition('target_entity_type', $context->targetEntityType)
      ->condition('target_entity_id', $context->targetEntityId)
      ->condition('target_langcode', $context->targetLangcode)
      ->condition('field_name', $context->fieldName)
      ->condition('field_delta', $context->fieldDelta)
      ->condition('field_property', $context->fieldProperty)
      ->execute()
      ->fetchAssoc();
    return $record ?: NULL;
  }

  public function recordMachineTranslation(TranslationFieldContext $context, string $sourceText, string $providerText, string $providerName, string $providerVersion): array {
    $providerHash = $this->hashManager->hash($providerText);
    return $this->upsert($context, [
      'source_hash' => $this->hashManager->hash($sourceText),
      'provider_translation_hash' => $providerHash,
      'target_hash_at_import' => $providerHash,
      'current_target_hash' => $providerHash,
      'origin' => self::ORIGIN_MACHINE_TRANSLATED,
      'status' => self::STATUS_MACHINE_TRANSLATED,
      'provider_name' => $providerName,
      'provider_version' => $providerVersion,
      'last_translated_at' => $this->time->getRequestTime(),
    ]);
  }

  public function recordEditorModification(TranslationFieldContext $context, string $currentText): array {
    return $this->recordEditorEdit($context, $currentText, self::ORIGIN_EDITOR_MODIFIED);
  }

  public function recordEditorEdit(TranslationFieldContext $context, string $currentText, string $origin = self::ORIGIN_EDITOR, string $status = self::STATUS_APPROVED): array {
    // Deliberately does not touch provider_translation_hash - see interface
    // docblock. Only current_target_hash moves, so a future machine
    // retranslation still compares against the real last provider output.
    return $this->upsert($context, [
      'current_target_hash' => $this->hashManager->hash($currentText),
      'origin' => $origin,
      'status' => $status,
      'last_reviewed_at' => $this->time->getRequestTime(),
    ]);
  }

  public function recordEditorApproval(TranslationFieldContext $context, string $currentText): array {
    return $this->upsert($context, [
      'current_target_hash' => $this->hashManager->hash($currentText),
      'origin' => self::ORIGIN_MACHINE_ACCEPTED,
      'status' => self::STATUS_APPROVED,
      'last_reviewed_at' => $this->time->getRequestTime(),
    ]);
  }

  public function isEditorModified(TranslationFieldContext $context): bool {
    $record = $this->getRecord($context);
    if (!$record) {
      return FALSE;
    }
    return $record['current_target_hash'] !== $record['provider_translation_hash'];
  }

  public function markNeedsReview(TranslationFieldContext $context): void {
    $this->upsert($context, [
      'status' => self::STATUS_NEEDS_REVIEW,
    ]);
  }

  public function markStale(TranslationFieldContext $context): void {
    $this->upsert($context, [
      'status' => self::STATUS_STALE,
    ]);
  }

  public function setBaseline(TranslationFieldContext $context, string $currentText, string $origin, string $status): array {
    $hash = $this->hashManager->hash($currentText);
    return $this->upsert($context, [
      'provider_translation_hash' => $hash,
      'target_hash_at_import' => $hash,
      'current_target_hash' => $hash,
      'origin' => $origin,
      'status' => $status,
      'last_reviewed_at' => $this->time->getRequestTime(),
    ]);
  }

  /**
   * Inserts or updates the provenance row for a slot with the given field
   * values, filling in identity fields and timestamps as needed.
   */
  protected function upsert(TranslationFieldContext $context, array $values): array {
    $now = $this->time->getRequestTime();
    $existing = $this->getRecord($context);

    $values += [
      'source_entity_type' => $context->sourceEntityType,
      'source_entity_id' => $context->sourceEntityId,
      'source_langcode' => $context->sourceLangcode,
      'target_langcode' => $context->targetLangcode,
      'target_entity_type' => $context->targetEntityType,
      'target_entity_id' => $context->targetEntityId,
      'field_name' => $context->fieldName,
      'field_delta' => $context->fieldDelta,
      'field_property' => $context->fieldProperty,
      'changed' => $now,
    ];

    if ($existing) {
      $this->database->update(self::TABLE)
        ->fields($values)
        ->condition('id', $existing['id'])
        ->execute();
      return $this->getRecord($context);
    }

    $values += ['created' => $now];
    $this->database->insert(self::TABLE)
      ->fields($values)
      ->execute();
    return $this->getRecord($context);
  }

}
