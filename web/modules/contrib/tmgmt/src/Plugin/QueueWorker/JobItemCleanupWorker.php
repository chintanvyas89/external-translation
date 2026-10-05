<?php

declare(strict_types=1);

namespace Drupal\tmgmt\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Cleanup worker for continuous job's job items.
 *
 * @QueueWorker(
 *   id = "tmgmt_job_item_cleanup",
 *   title = @Translation("Performs job item cleanup tasks for TMGMT"),
 *   cron = {"time" = 60}
 * )
 */
class JobItemCleanupWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $entity_ids = $data;
    if (!is_array($entity_ids)) {
      return;
    }
    // Do not allow deleting all entities.
    if (!$entity_ids) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('tmgmt_job_item');
    $storage->delete($storage->loadMultiple($entity_ids));
  }

}
