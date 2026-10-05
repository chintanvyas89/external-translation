<?php

declare(strict_types=1);

namespace Drupal\Tests\tmgmt\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\Entity\JobItem;

/**
 * Tests purging of job items and jobs when their source entity is deleted.
 *
 * @group tmgmt
 */
class PurgeStaleJobItemsAndJobsTest extends TMGMTKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('entity_test');
    // Deleting a job item cascades to clean up its remote mappings.
    $this->installEntitySchema('tmgmt_remote');
  }

  /**
   * Tests that nothing is purged while the setting is disabled.
   */
  public function testPurgeStaleDisabled(): void {
    $this->config('tmgmt.settings')->set('purge_stale', FALSE)->save();

    $entity1 = EntityTest::create();
    $entity1->save();
    $entity2 = EntityTest::create();
    $entity2->save();

    $job = $this->createJob();
    $item1 = $job->addItem('test_source', 'entity_test', $entity1->id());
    $item2 = $job->addItem('test_source', 'entity_test', $entity2->id());

    $entity1->delete();
    $entity2->delete();

    $this->assertNotNull(Job::load($job->id()));
    $this->assertNotNull(JobItem::load($item1->id()));
    $this->assertNotNull(JobItem::load($item2->id()));
  }

  /**
   * Tests that a deleted source purges its item, and the job once it is empty.
   */
  public function testPurgeStaleEnabled(): void {
    $this->config('tmgmt.settings')->set('purge_stale', TRUE)->save();

    $entity1 = EntityTest::create();
    $entity1->save();
    $entity2 = EntityTest::create();
    $entity2->save();

    $job = $this->createJob();
    $item1 = $job->addItem('test_source', 'entity_test', $entity1->id());
    $item2 = $job->addItem('test_source', 'entity_test', $entity2->id());

    // The job survives while it still has another item.
    $entity1->delete();
    $this->assertNull(JobItem::load($item1->id()));
    $this->assertNotNull(JobItem::load($item2->id()));
    $this->assertNotNull(Job::load($job->id()));

    // The now-empty job is purged with its last item.
    $entity2->delete();
    $this->assertNull(JobItem::load($item2->id()));
    $this->assertNull(Job::load($job->id()));
  }

  /**
   * Tests that an emptied continuous job is kept, only its items are purged.
   */
  public function testPurgeStaleKeepsContinuousJob(): void {
    $this->config('tmgmt.settings')->set('purge_stale', TRUE)->save();

    $entity = EntityTest::create();
    $entity->save();

    $job = $this->createJob('en', 'de', 0, ['job_type' => Job::TYPE_CONTINUOUS]);
    $item = $job->addItem('test_source', 'entity_test', $entity->id());

    $entity->delete();

    $this->assertNull(JobItem::load($item->id()));
    $this->assertNotNull(Job::load($job->id()));
  }

}
