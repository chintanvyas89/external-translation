<?php

namespace Drupal\tmgmt_external_translation\Translation;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\tmgmt_external_translation\Event\EntityFieldWrittenEvent;
use Drupal\tmgmt_external_translation\Event\ProposalActionEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class TranslationProposalManager implements TranslationProposalManagerInterface {

  const TABLE = 'translation_proposal';
  const PROVENANCE_TABLE = 'translation_provenance';

  public function __construct(
    protected Connection $database,
    protected TranslationHashManagerInterface $hashManager,
    protected TranslationProvenanceManagerInterface $provenanceManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected AccountProxyInterface $currentUser,
    protected TranslationFieldValueReaderInterface $fieldValueReader,
    protected EventDispatcherInterface $eventDispatcher,
    protected ModerationGateInterface $moderationGate,
  ) {}

  public function createProposal(TranslationFieldContext $context, string $sourceText, string $providerText, string $providerName, string $providerVersion): array {
    $record = $this->provenanceManager->getRecord($context);
    if (!$record) {
      // A live value exists with no tracked baseline (e.g. authored before
      // this module was installed). Treat it as editor-owned so it's
      // protected the same way, then proceed to propose against it. Uses
      // recordEditorEdit (not setBaseline) deliberately: it must NOT set
      // provider_translation_hash to this live value, since we have no
      // idea whether it ever came from a machine at all - leaving
      // provider_translation_hash empty keeps this slot permanently
      // protected from auto-overwrite until an explicit Accept happens.
      $liveValue = $this->fieldValueReader->getLiveValue($context) ?? '';
      $record = $this->provenanceManager->recordEditorEdit($context, $liveValue, TranslationProvenanceManagerInterface::ORIGIN_EDITOR);
    }

    $this->provenanceManager->markNeedsReview($context);

    $now = $this->time->getRequestTime();
    $values = [
      'provenance_id' => $record['id'],
      'source_hash' => $this->hashManager->hash($sourceText),
      'current_target_hash' => $record['current_target_hash'],
      'provider_translation' => $providerText,
      'provider_translation_hash' => $this->hashManager->hash($providerText),
      'status' => 'pending',
      'changed' => $now,
    ];

    $existingPending = $this->getPendingProposalByProvenanceId((int) $record['id']);
    if ($existingPending) {
      $this->database->update(self::TABLE)
        ->fields($values)
        ->condition('id', $existingPending['id'])
        ->execute();
      return $this->getProposal((int) $existingPending['id']);
    }

    $values += ['created' => $now];
    $id = $this->database->insert(self::TABLE)->fields($values)->execute();
    return $this->getProposal((int) $id);
  }

  public function getPendingProposalsForEntity(string $entityType, string $entityId, string $langcode): array {
    $query = $this->database->select(self::TABLE, 'prop');
    $query->join(self::PROVENANCE_TABLE, 'prov', 'prov.id = prop.provenance_id');
    $query->fields('prop')
      ->fields('prov', ['field_name', 'field_delta', 'field_property', 'source_entity_type', 'source_entity_id', 'source_langcode'])
      ->condition('prov.target_entity_type', $entityType)
      ->condition('prov.target_entity_id', $entityId)
      ->condition('prov.target_langcode', $langcode)
      ->condition('prop.status', 'pending');
    return $query->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
  }

  public function getAllPendingProposals(): array {
    $query = $this->database->select(self::TABLE, 'prop');
    $query->join(self::PROVENANCE_TABLE, 'prov', 'prov.id = prop.provenance_id');
    $query->fields('prop')
      ->fields('prov', [
        'target_entity_type', 'target_entity_id', 'target_langcode',
        'field_name', 'field_delta', 'field_property',
        'source_entity_type', 'source_entity_id', 'source_langcode',
      ])
      ->condition('prop.status', 'pending');
    return $query->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
  }

  public function resolveProposal(int $proposalId, string $action, ?string $editedText = NULL): bool {
    $proposal = $this->getProposal($proposalId);
    if (!$proposal) {
      return FALSE;
    }
    // getProposal() only reads translation_proposal; enrich with the
    // provenance columns event subscribers need to categorize the target
    // (target_entity_type/field_name), matching getAllPendingProposals()'s
    // row shape.
    $context = $this->contextFromProvenanceId((int) $proposal['provenance_id']);
    if ($context) {
      $proposal += [
        'target_entity_type' => $context->targetEntityType,
        'target_entity_id' => $context->targetEntityId,
        'target_langcode' => $context->targetLangcode,
        'field_name' => $context->fieldName,
      ];
    }

    $event = new ProposalActionEvent($proposalId, $action, $editedText, $proposal);
    $this->eventDispatcher->dispatch($event, ProposalActionEvent::EVENT_NAME);
    if ($event->isHandled()) {
      return $event->wasSuccessful();
    }

    return match ($action) {
      'accept' => $this->acceptProposal($proposalId),
      'reject' => $this->rejectProposal($proposalId),
      'edit' => $editedText !== NULL && $this->applyEditedProposal($proposalId, $editedText),
      default => FALSE,
    };
  }

  public function acceptProposal(int $proposalId): bool {
    $proposal = $this->getProposal($proposalId);
    if (!$proposal || $proposal['status'] !== 'pending') {
      return FALSE;
    }
    $context = $this->contextFromProvenanceId((int) $proposal['provenance_id']);
    if (!$context) {
      return FALSE;
    }

    $this->writeToEntity($context, $proposal['provider_translation']);
    $this->provenanceManager->setBaseline($context, $proposal['provider_translation'], TranslationProvenanceManagerInterface::ORIGIN_MACHINE_ACCEPTED, TranslationProvenanceManagerInterface::STATUS_APPROVED);
    $this->closeProposal($proposalId, 'accepted');
    return TRUE;
  }

  public function rejectProposal(int $proposalId): bool {
    $proposal = $this->getProposal($proposalId);
    if (!$proposal || $proposal['status'] !== 'pending') {
      return FALSE;
    }
    $context = $this->contextFromProvenanceId((int) $proposal['provenance_id']);
    if ($context) {
      // Entity is untouched; restore provenance status to approved since
      // the editor's existing value remains authoritative.
      $liveValue = $this->fieldValueReader->getLiveValue($context) ?? '';
      $this->provenanceManager->recordEditorModification($context, $liveValue);
    }
    $this->closeProposal($proposalId, 'rejected');
    return TRUE;
  }

  public function applyEditedProposal(int $proposalId, string $editedText): bool {
    $proposal = $this->getProposal($proposalId);
    if (!$proposal || $proposal['status'] !== 'pending') {
      return FALSE;
    }
    $context = $this->contextFromProvenanceId((int) $proposal['provenance_id']);
    if (!$context) {
      return FALSE;
    }

    $this->writeToEntity($context, $editedText);
    $this->provenanceManager->recordEditorEdit($context, $editedText);
    $this->closeProposal($proposalId, 'edited');
    return TRUE;
  }

  public function getProposal(int $proposalId): ?array {
    $record = $this->database->select(self::TABLE, 'p')
      ->fields('p')
      ->condition('id', $proposalId)
      ->execute()
      ->fetchAssoc();
    return $record ?: NULL;
  }

  protected function getPendingProposalByProvenanceId(int $provenanceId): ?array {
    $record = $this->database->select(self::TABLE, 'p')
      ->fields('p')
      ->condition('provenance_id', $provenanceId)
      ->condition('status', 'pending')
      ->execute()
      ->fetchAssoc();
    return $record ?: NULL;
  }

  /**
   * Marks a proposal resolved without touching any entity - for targets
   * that aren't entities at all (e.g. interface strings), callers do
   * their own write and provenance update, then call this to close the
   * proposal out.
   */
  public function markResolved(int $proposalId, string $status): void {
    $this->closeProposal($proposalId, $status);
  }

  /**
   * Reconstructs the TranslationFieldContext for a proposal's provenance
   * row - exposed so non-entity callers (interface strings) can resolve
   * proposals without duplicating this lookup.
   */
  public function getContextForProposal(int $proposalId): ?TranslationFieldContext {
    $proposal = $this->getProposal($proposalId);
    return $proposal ? $this->contextFromProvenanceId((int) $proposal['provenance_id']) : NULL;
  }

  protected function closeProposal(int $proposalId, string $status): void {
    $this->database->update(self::TABLE)
      ->fields([
        'status' => $status,
        'changed' => $this->time->getRequestTime(),
        'reviewed_by' => (int) $this->currentUser->id(),
        'reviewed_at' => $this->time->getRequestTime(),
      ])
      ->condition('id', $proposalId)
      ->execute();
  }

  protected function contextFromProvenanceId(int $provenanceId): ?TranslationFieldContext {
    $record = $this->database->select(self::PROVENANCE_TABLE, 'p')
      ->fields('p')
      ->condition('id', $provenanceId)
      ->execute()
      ->fetchAssoc();
    if (!$record) {
      return NULL;
    }
    return new TranslationFieldContext(
      $record['source_entity_type'],
      $record['source_entity_id'],
      $record['source_langcode'],
      $record['target_langcode'],
      $record['target_entity_type'],
      $record['target_entity_id'],
      $record['field_name'],
      (int) $record['field_delta'],
      $record['field_property'],
    );
  }

  protected function writeToEntity(TranslationFieldContext $context, string $value): void {
    $storage = $this->entityTypeManager->getStorage($context->targetEntityType);
    $entity = $storage->load($context->targetEntityId);
    // Most entity types genuinely have the target translation by this
    // point (a proposal only exists after a real conflict was detected,
    // which itself requires the translation to already exist - see
    // TranslationFieldValueReader::getLiveValue()'s own hasTranslation()
    // guard). Layout Builder's inline blocks are the one exception: they
    // stay single-langcode entities throughout (never a genuine Drupal
    // translation of themselves - Layout Builder's own inline-block edit
    // form isn't translation-context aware, so making them multilingual
    // would silently break manual editing), so their conflict detection
    // goes through the explicit-value path instead and can legitimately
    // reach here with no real translation to fetch. Fall back to the
    // entity itself in that case, rather than throwing.
    $translation = $entity->hasTranslation($context->targetLangcode) ? $entity->getTranslation($context->targetLangcode) : $entity;
    $translation->get($context->fieldName)->set($context->fieldDelta, [
      $context->fieldProperty => $value,
    ] + ($translation->get($context->fieldName)->get($context->fieldDelta)?->getValue() ?? []));
    if ($translation instanceof \Drupal\Core\Entity\RevisionableInterface) {
      $translation->setNewRevision(TRUE);
    }
    // Applying a proposal is a deliberate editorial decision, but the
    // editor only reviewed this one text slot in the proposal UI, not the
    // full page in context - if it's currently published, still gate it
    // back to a review state rather than silently republishing.
    $this->moderationGate->gateIfPublished($translation);
    $translation->save();

    $this->eventDispatcher->dispatch(
      new EntityFieldWrittenEvent($context->targetEntityType, $context->targetEntityId, $context->targetLangcode),
      EntityFieldWrittenEvent::EVENT_NAME,
    );
  }

}
