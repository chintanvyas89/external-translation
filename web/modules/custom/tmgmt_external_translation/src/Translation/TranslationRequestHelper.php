<?php

namespace Drupal\tmgmt_external_translation\Translation;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tmgmt\TMGMTException;

class TranslationRequestHelper implements TranslationRequestHelperInterface {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TranslationPollingServiceInterface $pollingService,
  ) {}

  public function requestMachineTranslationForNewTranslation(ContentEntityInterface $entity, string $targetLangcode): array {
    /** @var \Drupal\tmgmt\Entity\Translator|null $translator */
    $translator = $this->entityTypeManager->getStorage('tmgmt_translator')->load('external_api');
    if (!$translator) {
      return [
        'success' => FALSE,
        'message' => $this->t('Machine translation was not requested: no translation provider is configured.'),
      ];
    }
    $availability = $translator->checkAvailable();
    if (!$availability->getSuccess()) {
      return [
        'success' => FALSE,
        'message' => $this->t('Machine translation was not requested: @reason', ['@reason' => $availability->getReason()]),
      ];
    }

    $sourceLangcode = $entity->getUntranslated()->language()->getId();

    try {
      $job = tmgmt_job_create($sourceLangcode, $targetLangcode, 0, [
        'label' => $this->t('Auto-request from translation-add form: @type @id', [
          '@type' => $entity->getEntityTypeId(),
          '@id' => $entity->id(),
        ]),
      ]);
      $job->translator = 'external_api';
      $job->save();
      $job->addItem('content', $entity->getEntityTypeId(), $entity->id());
    }
    catch (TMGMTException $e) {
      return [
        'success' => FALSE,
        'message' => $this->t('Machine translation was not requested: @message', ['@message' => $e->getMessage()]),
      ];
    }

    $check = $job->canRequestTranslation();
    if (!$check->getSuccess()) {
      return [
        'success' => FALSE,
        'message' => $this->t('Machine translation was not requested: @reason', ['@reason' => $check->getReason()]),
      ];
    }

    // Void return - success/failure of the request itself is reflected in
    // job item state, checked below. requestTranslation() only submits now
    // (Part 4, async support); poll immediately so this stays a single,
    // synchronous-feeling action for the editor by default (the mock
    // client's default ready_after_checks=1 means one poll completes it
    // right away). If the provider genuinely takes longer, completion (and
    // acceptance - see JobItemBatchCompleter) happens later via cron or the
    // "Refresh now" button on the translations page instead.
    $job->requestTranslation();
    $this->pollingService->processAllPending();

    $acceptedCount = 0;
    foreach ($job->getItems() as $jobItem) {
      if ($jobItem->isAccepted()) {
        $acceptedCount++;
      }
    }

    if ($acceptedCount < count($job->getItems())) {
      return [
        'success' => TRUE,
        'message' => $this->t('Machine translation requested. @count of @total item(s) applied so far; the rest are still awaiting the provider and will apply automatically once ready.', [
          '@count' => $acceptedCount,
          '@total' => count($job->getItems()),
        ]),
      ];
    }

    return [
      'success' => TRUE,
      'message' => $this->t('Machine translation requested and applied for @count item(s).', ['@count' => $acceptedCount]),
    ];
  }

}
