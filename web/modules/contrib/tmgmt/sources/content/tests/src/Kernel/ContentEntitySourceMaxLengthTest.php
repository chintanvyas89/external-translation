<?php

declare(strict_types=1);

namespace Drupal\Tests\tmgmt_content\Kernel;

use Drupal\Component\Utility\Unicode;
use Drupal\entity_test\Entity\EntityTestMul;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\MessageInterface;

/**
 * Tests the configurable policy for translations longer than the field.
 *
 * @group tmgmt
 */
class ContentEntitySourceMaxLengthTest extends ContentEntityTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['file', 'image', 'link'];

  /**
   * The max length of the test string field.
   */
  private const MAX_LENGTH = 30;

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);

    // A plain, fixed-length string field shorter than the returned translation.
    FieldStorageConfig::create([
      'entity_type' => $this->entityTypeId,
      'field_name' => 'field_test_string',
      'type' => 'string',
      'cardinality' => 1,
      'translatable' => TRUE,
      'settings' => ['max_length' => self::MAX_LENGTH],
    ])->save();
    FieldConfig::create([
      'entity_type' => $this->entityTypeId,
      'field_name' => 'field_test_string',
      'bundle' => $this->entityTypeId,
      'label' => 'Test string-field',
      'translatable' => TRUE,
    ])->save();

    FieldStorageConfig::create([
      'entity_type' => $this->entityTypeId,
      'field_name' => 'field_test_image',
      'type' => 'image',
      'cardinality' => 1,
      'translatable' => TRUE,
    ])->save();
    FieldConfig::create([
      'entity_type' => $this->entityTypeId,
      'field_name' => 'field_test_image',
      'bundle' => $this->entityTypeId,
      'label' => 'Test image-field',
      'translatable' => TRUE,
      'settings' => ['alt_field' => 1, 'title_field' => 1],
    ])->save();
    \Drupal::service('file_system')->copy(
      DRUPAL_ROOT . '/core/misc/druplicon.png',
      'public://example.png',
    );

    FieldStorageConfig::create([
      'entity_type' => $this->entityTypeId,
      'field_name' => 'field_test_link',
      'type' => 'link',
      'cardinality' => 1,
      'translatable' => TRUE,
    ])->save();
    FieldConfig::create([
      'entity_type' => $this->entityTypeId,
      'field_name' => 'field_test_link',
      'bundle' => $this->entityTypeId,
      'label' => 'Test link-field',
      'translatable' => TRUE,
    ])->save();
  }

  /**
   * Creates an English source entity and requests its translation.
   *
   * @return \Drupal\tmgmt\JobItemInterface
   *   The job item, with the translation requested.
   */
  protected function requestTranslation(): JobItemInterface {
    $entity = EntityTestMul::create([
      'langcode' => 'en',
      'user_id' => 1,
      // A 64-character string whose prefixed translation overflows the field.
      'name' => $this->randomString(64),
      // A multi-word source whose prefixed translation overflows the field.
      'field_test_string' => 'lorem ipsum dolor sit amet ww',
      'field_test_image' => [
        'entity' => File::create(['uri' => 'public://example.png']),
        // Total 512 characters.
        'alt' => str_repeat('alt txt ', 64),
        // Total 1024 characters.
        'title' => str_repeat('title text text ', 64),
      ],
      'field_test_link' => [
        'uri' => 'https://example.com',
        // Total 255 characters.
        'title' => str_repeat('link ', 51)
      ]
    ]);
    $entity->save();

    $job = tmgmt_job_create('en', 'de');
    $job->translator = 'test_translator';
    $job->save();
    $job->addItem('content', $this->entityTypeId, $entity->id());
    // The test translator prefixes every string, so the returned translation is
    // longer than the source and overflows the field limit.
    $job->requestTranslation();

    $items = $job->getItems();
    return reset($items);
  }

  /**
   * The 'truncate' policy shortens the translation to fit and accepts it.
   */
  public function testTruncatePolicy(): void {
    $this->config('tmgmt.settings')->set('field_length_overflow_policy', 'truncate')->save();

    $item = $this->requestTranslation();
    $this->assertTrue($item->acceptTranslation());

    $translated_entity = EntityTestMul::load($item->getItemId())->getTranslation('de');

    $this->assertTranslationTruncated($translated_entity, $item, 'Name', 'name', 'value', 64);
    $this->assertTranslationTruncated($translated_entity, $item, 'Test string-field', 'field_test_string', 'value', self::MAX_LENGTH);
    // @see \Drupal\image\Plugin\Field\FieldType\ImageItem::schema()
    $this->assertTranslationTruncated($translated_entity, $item, 'Test image-field > Alternative text', 'field_test_image', 'alt', 512);
    $this->assertTranslationTruncated($translated_entity, $item, 'Test image-field > Title', 'field_test_image', 'title', 1024);
    // @see \Drupal\link\Plugin\Field\FieldType\LinkItem::schema()
    $this->assertTranslationTruncated($translated_entity, $item, 'Test link-field > Link text', 'field_test_link', 'title', 255);
  }

  /**
   * The 'needs_review' policy keeps the item for review.
   */
  public function testNeedsReviewPolicy(): void {
    $this->config('tmgmt.settings')->set('field_length_overflow_policy', 'needs_review')->save();
    $item = $this->requestTranslation();

    $this->assertFalse($item->acceptTranslation());
    $this->assertTrue($item->isNeedsReview());
    $this->assertFalse(EntityTestMul::load($item->getItemId())->hasTranslation('de'));

    $expected_messages = [
      'The translation for Name is longer than the maximum of 64 characters allowed and cannot be accepted. Shorten it in the review form.',
      'The translation for Test image-field > Alternative text is longer than the maximum of 512 characters allowed and cannot be accepted. Shorten it in the review form.',
      'The translation for Test image-field > Title is longer than the maximum of 1024 characters allowed and cannot be accepted. Shorten it in the review form.',
      'The translation for Test link-field > Link text is longer than the maximum of 255 characters allowed and cannot be accepted. Shorten it in the review form.',
      'The translation for Test string-field is longer than the maximum of 30 characters allowed and cannot be accepted. Shorten it in the review form.',
    ];

    $actual_messages = array_map(
      fn(MessageInterface $message): string => htmlspecialchars_decode(strip_tags((string) $message->getMessage())),
      array_values($item->getMessages(['type' => 'error'])),
    );
    // Make the order predictable.
    sort($actual_messages);

    $this->assertSame($expected_messages, $actual_messages);
  }

  /**
   * Asserts that a translation has been truncated and a warning was logged.
   *
   * @param \Drupal\entity_test\Entity\EntityTestMul $translated
   *   The translated entity.
   * @param \Drupal\tmgmt\JobItemInterface $item
   *   The job item entity.
   * @param string $label
   *   The label.
   * @param string $field_name
   *   The field name.
   * @param string $property
   *   The field property name.
   * @param int $max_length
   *   The max length.
   */
  protected function assertTranslationTruncated(EntityTestMul $translated, JobItemInterface $item, string $label, string $field_name, string $property, int $max_length): void {
    // Check that the translated string has been properly truncated.
    $translation = $item->getData([$field_name, 0, $property])['#translation']['#text'];
    $this->assertSame(Unicode::truncate($translation, $max_length, TRUE, TRUE), $translated->get($field_name)->first()->getValue()[$property]);

    // Check that the correct warning message was logged.
    foreach ($item->getMessages(['type' => 'warning']) as $message) {
      if ($message->get('variables')->first()->getValue()['%field'] === $label) {
        $this->assertEquals(t('The translation for %field was longer than the maximum of @max characters allowed and has been truncated.', [
          '%field' => $label,
          '@max' => $max_length,
        ]), $message->getMessage());
        return;
      }
    }
    $this->fail('No warning message was logged.');
  }

}
