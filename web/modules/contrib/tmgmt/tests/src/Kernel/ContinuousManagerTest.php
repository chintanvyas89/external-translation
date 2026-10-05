<?php

declare(strict_types=1);

namespace Drupal\Tests\tmgmt\Kernel;

use Drupal\entity_test\Entity\EntityTestMul;
use Drupal\tmgmt\ContinuousManager;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\JobItemInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests ContinuousManager.
 */
#[CoversClass(ContinuousManager::class)]
#[Group('tmgmt')]
class ContinuousManagerTest extends TMGMTKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['user', 'system', 'field', 'text', 'entity_test', 'language', 'locale', 'tmgmt', 'tmgmt_test', 'options'];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test_mul');
  }

  /**
   * Creates a continuous job with a still active job item.
   */
  protected function createActiveJobItem(string $item_id): JobItemInterface {
    $job = $this->createJob('en', 'de', 0, ['job_type' => Job::TYPE_CONTINUOUS]);
    $job_item = $job->addItem('test_source', 'entity_test_mul', $item_id);
    $this->assertFalse($job_item->isAborted());
    $this->assertFalse($job_item->isAccepted());
    return $job_item;
  }

  /**
   * Invokes the protected ContinuousManager::logIgnoredChange() method.
   */
  protected function invokeLogIgnoredChange(JobItemInterface $job_item, string $item_type, string $item_id): void {
    $manager = \Drupal::service('tmgmt.continuous');
    $method = new \ReflectionMethod($manager, 'logIgnoredChange');
    $method->invoke($manager, $job_item, $item_type, $item_id);
  }

  /**
   * Tests that a message is logged when the source actually changed.
   */
  public function testLogIgnoredChangeWhenSourceChanged(): void {
    $entity = EntityTestMul::create(['name' => 'Original title', 'langcode' => 'en']);
    $entity->save();
    $job_item = $this->createActiveJobItem((string) $entity->id());

    $storage = \Drupal::entityTypeManager()->getStorage('entity_test_mul');
    $storage->load($entity->id())->set('name', 'Changed title');

    $this->invokeLogIgnoredChange($job_item, 'entity_test_mul', (string) $entity->id());

    $messages = $job_item->getMessages();
    $last_message = end($messages);
    $this->assertNotFalse($last_message);
    $this->assertEquals('Source was updated, changes were ignored as job item is still active.', (string) $last_message->getMessage());
  }

  /**
   * Tests that no message is logged when the source has not changed.
   */
  public function testLogIgnoredChangeWhenSourceUnchanged(): void {
    $entity = EntityTestMul::create(['name' => 'Original title', 'langcode' => 'en']);
    $entity->save();
    $job_item = $this->createActiveJobItem((string) $entity->id());

    $this->invokeLogIgnoredChange($job_item, 'entity_test_mul', (string) $entity->id());

    $this->assertCount(0, $job_item->getMessages());
  }

}
