<?php

namespace Drupal\tmgmt_external_translation\Plugin\tmgmt\Translator;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tmgmt\Attribute\TranslatorPlugin;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\TranslatorPluginBase;
use Drupal\tmgmt_external_translation\Client\TranslationApiClientInterface;
use Drupal\tmgmt_external_translation\Event\EntityTranslationRequestedEvent;
use Drupal\tmgmt_external_translation\Translation\TranslationFieldContext;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * External machine translation provider, routed through TMGMT.
 *
 * This plugin builds the request, sends it to the external provider, and
 * returns the result to TMGMT. Before writing anything back into the job
 * item's translated data, it checks per-field whether the live target
 * value has already been modified by an editor since the last provider
 * translation (Rule 10/11 of the spec): if so, the new value is diverted
 * into a translation_proposal instead of being written, so it can never
 * silently overwrite an editor's correction - regardless of whether the
 * translator is configured for auto-accept downstream.
 */
#[TranslatorPlugin(
  id: 'external_api',
  label: new TranslatableMarkup('External API (machine translation)'),
  description: new TranslatableMarkup('Sends content to an external machine translation provider via a custom API client.'),
)]
class ExternalApiTranslator extends TranslatorPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected TranslationApiClientInterface $client,
    protected Connection $database,
    protected TimeInterface $time,
    protected EventDispatcherInterface $eventDispatcher,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('tmgmt_external_translation.translation_api_client'),
      $container->get('database'),
      $container->get('datetime.time'),
      $container->get('event_dispatcher'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function requestTranslation(JobInterface $job) {
    $job->submitted('Sent to external translation provider.');
    $this->requestJobItemsTranslation($job->getItems());
  }

  /**
   * {@inheritdoc}
   *
   * TranslatorPluginBase's default returns TRUE unconditionally, which
   * forces every job through TMGMT's manual checkout page (JobCheckoutManager::
   * needsCheckoutForm()) even though this translator has no per-job
   * settings to configure - confirmed as a real, confusing gap: a job
   * created via tmgmt_content's own "Request translation" form looked
   * like it had been submitted, but was actually just sitting unprocessed
   * on a checkout page nobody knew to visit. Returning FALSE lets TMGMT's
   * "quick checkout" setting auto-submit immediately instead, matching
   * how our own checkbox-on-add-translation flow (TranslationRequestHelper)
   * already behaves.
   */
  public function hasCheckoutSettings(JobInterface $job) {
    return FALSE;
  }

  /**
   * Submits a set of job items to the external API client for
   * asynchronous translation. Does not write anything to the job item or
   * the entity yet - that happens later in JobItemBatchCompleter::complete(),
   * once TranslationPollingService confirms the provider is ready, which
   * genuinely supports a provider that takes real time to respond (see
   * Part 4 of the translation POC plan).
   *
   * @param \Drupal\tmgmt\JobItemInterface[] $job_items
   */
  public function requestJobItemsTranslation(array $job_items): void {
    /** @var \Drupal\tmgmt\Data $data_service */
    $data_service = \Drupal::service('tmgmt.data');

    foreach ($job_items as $job_item) {
      $job = $job_item->getJob();
      $source_langcode = $job->getSourceLangcode();
      $target_langcode = $job->getTargetLangcode();
      $entity_type = $job_item->getItemType();
      $entity_id = (string) $job_item->getItemId();

      $flattened = $data_service->filterTranslatable($job_item->getData());

      $items = [];
      $contexts = [];
      foreach ($flattened as $key => $value) {
        if (!empty($value['#text'])) {
          $items[$key] = [
            'source' => $value['#text'],
            'context' => [
              'entity_type' => $entity_type,
              'entity_id' => $entity_id,
              'field' => $key,
            ],
          ];
          $contexts[$key] = TranslationFieldContext::fromTmgmtKey($key, $entity_type, $entity_id, $source_langcode, $target_langcode, $entity_type, $entity_id);
        }
      }

      if (!$items) {
        $job_item->addMessage('No translatable text found.', [], 'warning');
        continue;
      }

      $requestId = $this->client->submit($source_langcode, $target_langcode, $items);
      $now = $this->time->getRequestTime();
      $this->database->insert('translation_pending_batch')
        ->fields([
          'request_id' => $requestId,
          'kind' => 'job_item',
          'job_id' => $job->id(),
          'job_item_id' => $job_item->id(),
          'source_langcode' => $source_langcode,
          'target_langcode' => $target_langcode,
          'provider_name' => $this->client->getProviderName(),
          'provider_version' => $this->client->getProviderVersion(),
          'context_data' => json_encode([
            'items' => $items,
            'contexts' => array_map(fn(TranslationFieldContext $c) => $c->toArray(), $contexts),
          ]),
          'status' => 'pending',
          'created' => $now,
          'changed' => $now,
        ])
        ->execute();

      $job_item->addMessage('Submitted to external translation provider (request @id).', ['@id' => $requestId]);

      $this->eventDispatcher->dispatch(
        new EntityTranslationRequestedEvent($entity_type, $entity_id, $source_langcode, $target_langcode, $this->client->getProviderName(), $this->client->getProviderVersion()),
        EntityTranslationRequestedEvent::EVENT_NAME,
      );
    }
  }

}
