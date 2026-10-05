<?php

namespace Drupal\tmgmt_external_translation\Translation;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\tmgmt_external_translation\Client\TranslationResponse;
use Drupal\tmgmt_external_translation\Event\EntityTranslatedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class JobItemBatchCompleter implements JobItemBatchCompleterInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TranslationConflictDetectorInterface $conflictDetector,
    protected TranslationProvenanceManagerInterface $provenanceManager,
    protected TranslationProposalManagerInterface $proposalManager,
    protected TranslationFieldValueReaderInterface $fieldValueReader,
    protected EventDispatcherInterface $eventDispatcher,
  ) {}

  public function complete(array $pendingBatchRow, TranslationResponse $response): void {
    $job_item = $this->entityTypeManager->getStorage('tmgmt_job_item')->load($pendingBatchRow['job_item_id']);
    if (!$job_item) {
      // Job item deleted since submission; nothing to apply to.
      return;
    }

    /** @var \Drupal\tmgmt\Data $data_service */
    $data_service = \Drupal::service('tmgmt.data');
    $decoded = json_decode($pendingBatchRow['context_data'], TRUE);
    $items = $decoded['items'];
    $contexts = array_map(fn(array $c) => TranslationFieldContext::fromArray($c), $decoded['contexts']);
    $providerName = $pendingBatchRow['provider_name'];
    $providerVersion = $pendingBatchRow['provider_version'];

    $tdata = [];
    foreach ($items as $key => $item) {
      $translated = $response->getTranslation($key);
      if ($translated === NULL) {
        $job_item->addMessage('External provider returned no translation for @key.', ['@key' => $key], 'error');
        continue;
      }

      $context = $contexts[$key];

      if ($this->conflictDetector->hasEditorModification($context, $item['source'])) {
        // The live value has diverged from the last provider baseline
        // (or was never machine-translated at all): do not touch it.
        // Park the new machine text as a proposal for editor review
        // instead, and write back the *current* value unchanged so this
        // job item can still complete without altering the entity.
        $this->proposalManager->createProposal($context, $item['source'], $translated, $providerName, $providerVersion);
        $job_item->addMessage('New machine translation for @key conflicts with the existing translation; a proposal was created for review instead of overwriting it.', ['@key' => $key], 'warning');

        $currentValue = $this->fieldValueReader->getLiveValue($context) ?? $item['source'];
        $tdata[$key]['#text'] = $currentValue;
        $tdata[$key]['#origin'] = 'remote';
        continue;
      }

      // Safe to write: either this is the first translation for this
      // slot, or the live value still matches the last provider output.
      $this->provenanceManager->recordMachineTranslation($context, $item['source'], $translated, $providerName, $providerVersion);
      $tdata[$key]['#text'] = $translated;
      $tdata[$key]['#origin'] = 'remote';
    }

    $job_item->addTranslatedData($data_service->unflatten($tdata));

    // This pipeline's human review checkpoint is the proposal system
    // (conflicts are diverted there above), not TMGMT's own job-item
    // review state - so once a batch's data is in, accept it immediately
    // rather than leaving it in "needs review" with no automatic path to
    // ever apply it to the live entity. Without this, an item that
    // completes asynchronously (rather than within the single poll
    // TranslationRequestHelper does right after requestTranslation())
    // would sit accepted-looking-but-unapplied forever.
    if ($job_item->isNeedsReview()) {
      $job_item->acceptTranslation();
    }

    $this->eventDispatcher->dispatch(
      new EntityTranslatedEvent($job_item->getItemType(), (string) $job_item->getItemId(), $pendingBatchRow['source_langcode'], $pendingBatchRow['target_langcode'], $providerName, $providerVersion),
      EntityTranslatedEvent::EVENT_NAME,
    );
  }

}
