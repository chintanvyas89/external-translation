<?php

namespace Drupal\tmgmt_external_translation_interface\InterfaceTranslation;

use Drupal\Core\Database\Connection;
use Drupal\locale\StringStorageInterface;
use Drupal\tmgmt_external_translation\Client\TranslationApiClientInterface;
use Drupal\tmgmt_external_translation\Translation\TranslationFieldContext;
use Drupal\tmgmt_external_translation\Translation\TranslationHashManagerInterface;
use Drupal\tmgmt_external_translation\Translation\TranslationProposalManagerInterface;
use Drupal\tmgmt_external_translation\Translation\TranslationProvenanceManagerInterface;

class InterfaceTranslationManager implements InterfaceTranslationManagerInterface {

  const SOURCE_ENTITY_TYPE = 'interface_string';

  public function __construct(
    protected StringStorageInterface $localeStorage,
    protected Connection $database,
    protected TranslationApiClientInterface $client,
    protected TranslationHashManagerInterface $hashManager,
    protected TranslationProvenanceManagerInterface $provenanceManager,
    protected TranslationProposalManagerInterface $proposalManager,
  ) {}

  public function translateUntranslatedStrings(string $targetLangcode, int $limit = 50): array {
    $query = $this->database->select('locales_source', 's');
    $query->leftJoin('locales_target', 't', 't.lid = s.lid AND t.language = :lc', [':lc' => $targetLangcode]);
    $query->fields('s', ['lid', 'source'])
      ->isNull('t.lid')
      ->condition('s.source', '', '<>')
      ->range(0, $limit);
    $rows = $query->execute()->fetchAllKeyed(0, 1);

    $messages = [];
    foreach ($rows as $lid => $source) {
      $messages[] = $this->translateOne((int) $lid, $source, $targetLangcode);
    }
    return $messages ?: ['No untranslated strings found for ' . $targetLangcode . '.'];
  }

  public function retranslateString(int $lid, string $targetLangcode): string {
    $source = $this->database->select('locales_source', 's')
      ->fields('s', ['source'])
      ->condition('lid', $lid)
      ->execute()
      ->fetchField();
    if ($source === FALSE) {
      return "Source string $lid not found.";
    }
    return $this->translateOne($lid, $source, $targetLangcode);
  }

  protected function translateOne(int $lid, string $source, string $targetLangcode): string {
    $context = $this->buildContext($lid, $targetLangcode);
    $response = $this->client->translate('en', $targetLangcode, ['s' => ['source' => $source]]);
    $translated = $response->getTranslation('s');
    if ($translated === NULL) {
      return "lid $lid: provider returned no translation.";
    }

    $liveValue = $this->getLiveTranslation($lid, $targetLangcode);
    if ($this->isModified($context, $liveValue)) {
      $this->proposalManager->createProposal($context, $source, $translated, $this->client->getProviderName(), $this->client->getProviderVersion());
      return "lid $lid: conflicts with an existing translation; proposal created.";
    }

    $this->writeTranslation($lid, $targetLangcode, $translated, FALSE);
    $this->provenanceManager->recordMachineTranslation($context, $source, $translated, $this->client->getProviderName(), $this->client->getProviderVersion());
    return "lid $lid: translated.";
  }

  public function acceptStringProposal(int $proposalId): bool {
    $proposal = $this->proposalManager->getProposal($proposalId);
    if (!$proposal || $proposal['status'] !== 'pending') {
      return FALSE;
    }
    $context = $this->proposalManager->getContextForProposal($proposalId);
    if (!$context) {
      return FALSE;
    }
    $lid = (int) $context->targetEntityId;
    $this->writeTranslation($lid, $context->targetLangcode, $proposal['provider_translation'], FALSE);
    $this->provenanceManager->setBaseline($context, $proposal['provider_translation'], TranslationProvenanceManagerInterface::ORIGIN_MACHINE_ACCEPTED, TranslationProvenanceManagerInterface::STATUS_APPROVED);
    $this->proposalManager->markResolved($proposalId, 'accepted');
    return TRUE;
  }

  public function rejectStringProposal(int $proposalId): bool {
    $proposal = $this->proposalManager->getProposal($proposalId);
    if (!$proposal || $proposal['status'] !== 'pending') {
      return FALSE;
    }
    $context = $this->proposalManager->getContextForProposal($proposalId);
    if ($context) {
      $liveValue = $this->getLiveTranslation((int) $context->targetEntityId, $context->targetLangcode) ?? '';
      $this->provenanceManager->recordEditorModification($context, $liveValue);
    }
    $this->proposalManager->markResolved($proposalId, 'rejected');
    return TRUE;
  }

  public function editStringProposal(int $proposalId, string $editedText): bool {
    $proposal = $this->proposalManager->getProposal($proposalId);
    if (!$proposal || $proposal['status'] !== 'pending') {
      return FALSE;
    }
    $context = $this->proposalManager->getContextForProposal($proposalId);
    if (!$context) {
      return FALSE;
    }
    $lid = (int) $context->targetEntityId;
    $this->writeTranslation($lid, $context->targetLangcode, $editedText, TRUE);
    $this->provenanceManager->recordEditorEdit($context, $editedText);
    $this->proposalManager->markResolved($proposalId, 'edited');
    return TRUE;
  }

  public function getPendingProposals(string $targetLangcode): array {
    $query = $this->database->select('translation_proposal', 'prop');
    $query->join('translation_provenance', 'prov', 'prov.id = prop.provenance_id');
    $query->leftJoin('locales_source', 's', 's.lid = prov.target_entity_id');
    $query->fields('prop')
      ->fields('prov', ['target_entity_id'])
      ->addField('s', 'source', 'source_string');
    $query->condition('prov.target_entity_type', self::SOURCE_ENTITY_TYPE)
      ->condition('prov.target_langcode', $targetLangcode)
      ->condition('prop.status', 'pending');
    return $query->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
  }

  /**
   * Same rule as TranslationConflictDetector::hasEditorModification(), for
   * a live value read directly from locale storage instead of an entity.
   */
  protected function isModified(TranslationFieldContext $context, ?string $liveValue): bool {
    $record = $this->provenanceManager->getRecord($context);
    if (!$record) {
      return $liveValue !== NULL && $liveValue !== '';
    }
    return $this->hashManager->hash($liveValue) !== $record['provider_translation_hash'];
  }

  protected function getLiveTranslation(int $lid, string $langcode): ?string {
    $translation = $this->localeStorage->findTranslation(['lid' => $lid, 'language' => $langcode]);
    // findTranslation() left-joins against the source, so it can return a
    // non-null placeholder object (language/translation both NULL) even
    // when no target row exists yet - isTranslation() is the real check.
    return ($translation && $translation->isTranslation()) ? $translation->getString() : NULL;
  }

  protected function writeTranslation(int $lid, string $langcode, string $text, bool $customized): void {
    $translation = $this->localeStorage->findTranslation(['lid' => $lid, 'language' => $langcode]);
    if (!$translation || !$translation->isTranslation()) {
      $translation = $this->localeStorage->createTranslation(['lid' => $lid, 'language' => $langcode]);
    }
    $translation->setString($text)->setCustomized($customized)->save();
  }

  protected function buildContext(int $lid, string $targetLangcode): TranslationFieldContext {
    return new TranslationFieldContext(
      self::SOURCE_ENTITY_TYPE, (string) $lid, 'en',
      $targetLangcode, self::SOURCE_ENTITY_TYPE, (string) $lid,
      'translation', 0, 'value',
    );
  }

}
