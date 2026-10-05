<?php

declare(strict_types=1);

namespace Drupal\Tests\tmgmt\Kernel;

use Drupal\tmgmt\JobItemInterface;

/**
 * Tests cleanup functionality on cron run.
 *
 * @group tmgmt
 */
class CronTest extends TMGMTKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'filter',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig('tmgmt');
    $this->installEntitySchema('tmgmt_remote');
  }

  /**
   * Tests cron.
   *
   * @dataProvider cronTestData
   */
  public function testCron(array $tmgmtSettings, int $elapsedTime, array $initialJobItems, array $expectedJobItems): void {
    $continuousJob = $this->createJob('en', 'de', 0, [
      'label' => $this->randomMachineName(),
      'job_type' => 'continuous',
      'continuous_settings' => [
        'content' => [
          'entity_test_mul' => [
            'enabled' => TRUE,
            'bundles' => ['entity_test_mul' => TRUE],
          ],
        ],
      ],
    ]);
    $config = $this->config('tmgmt.settings');
    foreach ($tmgmtSettings as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
    $requestTime = \Drupal::time()->getRequestTime();
    $changed = $requestTime - $elapsedTime;

    $jobItemStorage = \Drupal::entityTypeManager()->getStorage('tmgmt_job_item');
    foreach ($initialJobItems as $id => $state) {
      $item = $jobItemStorage->create([
        'plugin' => 'test_source',
        'tjid' => $continuousJob,
        'item_type' => 'test',
        'state' => $state,
        'changed' => $changed,
        'item_id' => $id,
      ]);
      $item->save();
    }
    $this->assertCount(count($initialJobItems), $jobItemStorage->loadMultiple());

    $this->container->get('cron')->run();

    $jobItemStorage->resetCache();
    $jobItems = $jobItemStorage->loadMultiple();
    $this->assertEquals(
      $expectedJobItems,
      array_map(
        fn (JobItemInterface $job_item) => $job_item->getState(),
        $jobItems,
      )
    );
  }

  /**
   * Data provider for ::testCron
   *
   * @return array
   *   The test cases.
   */
  public static function cronTestData(): array {
    $initialJobItems = [
      1 => JobItemInterface::STATE_INACTIVE,
      2 => JobItemInterface::STATE_ACTIVE,
      3 => JobItemInterface::STATE_REVIEW,
      4 => JobItemInterface::STATE_ACCEPTED,
      5 => JobItemInterface::STATE_ABORTED,
    ];
    $day = 60 * 60 * 24;

    return [
      'Not enough time elapsed' => [
        [
          'purge_continuous' => (string) (7 * $day),
        ],
        $day,
        $initialJobItems,
        $initialJobItems,
      ],
      'Purge active after a day' => [
        [
          'purge_continuous' => (string) $day,
        ],
        $day,
        $initialJobItems,
        [
          1 => JobItemInterface::STATE_INACTIVE,
          2 => JobItemInterface::STATE_ACTIVE,
          3 => JobItemInterface::STATE_REVIEW,
          5 => JobItemInterface::STATE_ABORTED,
        ],
      ],
      'No purge' => [
        [
          'purge_continuous' => '_never',
        ],
        366 * $day,
        $initialJobItems,
        $initialJobItems,
      ],
      'Purge aborted' => [
        [
          'purge_continuous' => (string) $day,
          'purge_continuous_aborted' => TRUE,
        ],
        2 * $day,
        $initialJobItems,
        [
          1 => JobItemInterface::STATE_INACTIVE,
          2 => JobItemInterface::STATE_ACTIVE,
          3 => JobItemInterface::STATE_REVIEW,
        ],
      ],
    ];
  }

}
