<?php

namespace Drupal\tmgmt_external_translation\Translation;

/**
 * Identifies one translated value slot: a single field delta/property on
 * one target entity's translation, tied back to its source.
 */
final class TranslationFieldContext {

  public function __construct(
    public readonly string $sourceEntityType,
    public readonly string $sourceEntityId,
    public readonly string $sourceLangcode,
    public readonly string $targetLangcode,
    public readonly string $targetEntityType,
    public readonly string $targetEntityId,
    public readonly string $fieldName,
    public readonly int $fieldDelta,
    public readonly string $fieldProperty = 'value',
  ) {}

  /**
   * Builds a context from a flattened TMGMT data key such as
   * "title][0][value]" or "body][0][value]".
   *
   * For simple top-level fields this parses into field_name/delta/property
   * so it lines up exactly with the field_name/delta/property the
   * entity-save hook derives independently by iterating field definitions
   * (both paths must agree on the same slot identity for a given field).
   *
   * tmgmt_content also flattens nested reference fields (Paragraphs via
   * entity_reference_revisions) into much deeper keys, e.g.
   * "field_paragraphs][0][entity][field_child][0][entity][field_text][0][value]".
   * Naively taking the first 3 segments would collide two different
   * paragraphs' text fields onto the same context. For those, the full key
   * is used as field_name instead - guarantees uniqueness per slot, at the
   * cost of a less readable field name in the provenance table. Properly
   * attributing these to their own paragraph entity (the way inline
   * blocks got their own target entity in the layout_builder module)
   * would be the more correct fix but is out of scope for this POC.
   */
  public static function fromTmgmtKey(
    string $key,
    string $sourceEntityType,
    string $sourceEntityId,
    string $sourceLangcode,
    string $targetLangcode,
    string $targetEntityType,
    string $targetEntityId,
  ): self {
    if (str_contains($key, '][entity][')) {
      return new self($sourceEntityType, $sourceEntityId, $sourceLangcode, $targetLangcode, $targetEntityType, $targetEntityId, $key, 0, 'nested');
    }
    $parts = explode('][', $key);
    $fieldName = $parts[0] ?? $key;
    $delta = isset($parts[1]) ? (int) $parts[1] : 0;
    $property = $parts[2] ?? 'value';
    return new self($sourceEntityType, $sourceEntityId, $sourceLangcode, $targetLangcode, $targetEntityType, $targetEntityId, $fieldName, $delta, $property);
  }

  /**
   * For persisting a batch of contexts (e.g. in
   * translation_pending_batch.context_data) across separate PHP
   * processes, since submit-now/poll-later means the context can't just
   * stay in memory between the two.
   */
  public function toArray(): array {
    return [
      'sourceEntityType' => $this->sourceEntityType,
      'sourceEntityId' => $this->sourceEntityId,
      'sourceLangcode' => $this->sourceLangcode,
      'targetLangcode' => $this->targetLangcode,
      'targetEntityType' => $this->targetEntityType,
      'targetEntityId' => $this->targetEntityId,
      'fieldName' => $this->fieldName,
      'fieldDelta' => $this->fieldDelta,
      'fieldProperty' => $this->fieldProperty,
    ];
  }

  public static function fromArray(array $data): self {
    return new self(
      $data['sourceEntityType'],
      $data['sourceEntityId'],
      $data['sourceLangcode'],
      $data['targetLangcode'],
      $data['targetEntityType'],
      $data['targetEntityId'],
      $data['fieldName'],
      (int) $data['fieldDelta'],
      $data['fieldProperty'],
    );
  }

}
