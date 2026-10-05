<?php

namespace Drupal\tmgmt\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure tmgmt settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getEditableConfigNames() {
    return array('tmgmt.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'tmgmt_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('tmgmt.settings');
    $form['workflow'] = array(
      '#type' => 'details',
      '#title' => t('Workflow settings'),
      '#open' => TRUE,
    );
    $form['workflow']['tmgmt_quick_checkout'] = array(
      '#type' => 'checkbox',
      '#title' => t('Allow quick checkout'),
      '#description' => t("Enabling this will skip the checkout form and instead directly process the translation request in cases where there is only one translator available which doesn't provide any additional configuration options."),
      '#default_value' => $config->get('quick_checkout'),
    );
    $form['workflow']['field_length_overflow_policy'] = [
      '#type' => 'select',
      '#title' => $this->t('Field length overflow policy'),
      '#description' => $this->t('What to do when an accepted translation is longer than the maximum length of its target field.'),
      '#options' => [
        'truncate' => $this->t('Truncate the translation to fit'),
        'needs_review' => $this->t('Keep the item for review (do not shorten)'),
      ],
      '#default_value' => $config->get('field_length_overflow_policy'),
    ];
    $form['performance'] = array(
      '#type' => 'details',
      '#title' => t('Performance settings'),
      '#open' => TRUE,
    );
    $purge_options = [
      '_never' => $this->t('Never'),
      '0' => $this->t('Immediately'),
      '86400' => $this->t('After 24 hours'),
      '604800' => $this->t('After 7 days'),
      '2592000' => $this->t('After 30 days'),
      '31536000' => $this->t('After 365 days'),
    ];
    $form['performance']['tmgmt_purge_finished'] = array(
      '#type' => 'select',
      '#title' => t('Purge finished jobs'),
      '#description' => t('If configured, translation jobs that have been marked as finished will be purged after a given time. The translations itself will not be deleted.'),
      '#options' => $purge_options,
      '#default_value' => $config->get('purge_finished'),
    );
    $form['performance']['tmgmt_purge_continuous'] = [
      '#type' => 'select',
      '#title' => $this->t('Purge accepted job items of continuous jobs'),
      '#description' => $this->t('If configured, continuous translation job items that have been marked as accepted will be purged after the given time. The translations will not be deleted.'),
      '#options' => $purge_options,
      '#config_target' => 'tmgmt.settings:purge_continuous',
    ];
    $form['performance']['tmgmt_purge_continuous_include_aborted'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Also purge aborted job items of continuous jobs'),
      '#description' => $this->t('When checked, aborted job items of continuous translation jobs item will be cleaned up along with the accepted ones.'),
      '#config_target' => 'tmgmt.settings:purge_continuous_aborted',
      '#states' => [
        'visible' => [
          ':input[name="tmgmt_purge_continuous"]' => ['!value' => '_never'],
        ],
      ],
    ];

    $form['performance']['purge_stale'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Purge stale job items and jobs'),
      '#description' => $this->t('When a translatable entity is deleted, also delete its job items. If a job is left with no items, delete the job too (continuous jobs are kept).'),
      '#default_value' => $config->get('purge_stale'),
    ];
    $form['security'] = array(
      '#type' => 'details',
      '#title' => t('Security settings'),
      '#open' => TRUE,
    );
    $form['security']['tmgmt_anonymous_access'] = array(
      '#type' => 'checkbox',
      '#title' => t('Allow access to source for translators'),
      '#description' => t('Enabling this will give translators and anyone with access to jobs access to view all content, including unpublished and other protected content.'),
      '#default_value' => $config->get('anonymous_access'),
    );
    $form['performance']['tmgmt_submit_job_item_on_cron'] = array(
      '#type' => 'checkbox',
      '#title' => t('Submit continuous job items on cron'),
      '#description' => t('Continuous job items are submitted in groups on cron runs. Otherwise they are submitted immediately when content is created.'),
      '#default_value' => $config->get('submit_job_item_on_cron'),
    );
    $form['performance']['job_items_cron_limit'] = array(
      '#type' => 'number',
      '#title' => t('Number of job items to process on cron'),
      '#description' => t('The number of job items that should be processed in one cron run. Depending on the chosen translation provider, increasing the number of job items could make translation projects bigger and slower to process.'),
      '#default_value' => $config->get('job_items_cron_limit'),
      '#min' => 1,
      '#states' => array(
        'visible' => array(
          ':input[name="tmgmt_submit_job_item_on_cron"]' => array('checked' => TRUE),
        ),
      ),
    );
    $form['text_formats'] = [
      '#type' => 'details',
      '#title' => t('Text format settings'),
      '#open' => TRUE,
    ];
    $form['text_formats']['respect_text_format'] = array(
      '#type' => 'checkbox',
      '#title' => t('Respect text format'),
      '#description' => t("Disabling will force all textareas to plaintext. No editors will be shown."),
      '#default_value' => $config->get('respect_text_format'),
    );

    $options = array();
    foreach (filter_formats() as $format) {
      $options[$format->id()] = $format->label();
    }

    $form['text_formats']['allowed_formats'] = array(
      '#type' => 'checkboxes',
      '#title' => t('Allowed formats'),
      '#description' => t("Allows to prevent content with a certain text format from being translated. If none are selected, all are allowed."),
      '#options' => $options,
      '#default_value' => (array) $config->get('allowed_formats'),
    );

    if (\Drupal::moduleHandler()->moduleExists('file') && \Drupal::service('plugin.manager.tmgmt.translator')->supportsFiles()) {
      $form['file'] = [
        '#type' => 'details',
        '#title' => t('File translation'),
        '#description' => t('Chose which file mime types are enabled to be sent to translation providers which support this feature. Not all translation providers support this and the mime types/file formats they support may vary if they do.'),
        '#open' => TRUE,
      ];

      $results = \Drupal::entityQueryAggregate('file')
        ->accessCheck(FALSE)
        ->aggregate('filemime', 'COUNT')
        ->groupBy('filemime')
        ->sortAggregate('filemime', 'COUNT', 'DESC')
        ->execute();

      $mimetype_options = [];
      foreach ($results as $result) {
        $mimetype_options[$result['filemime']] = [
          'mimetype' => $result['filemime'],
          'count' => $result['filemime_count'],
        ];
      }

      $config_mimetypes = (array) $config->get('file_mimetypes');

      $form['file']['mimetypes'] = [
        '#type' => 'tableselect',
        '#header' => [
          'mimetype' => t('Mimetype'),
          'count' => t('Total count'),
        ],
        '#options' => $mimetype_options,
        '#default_value' => array_combine($config_mimetypes, $config_mimetypes),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('tmgmt.settings')
      ->set('quick_checkout', $form_state->getValue('tmgmt_quick_checkout'))
      ->set('field_length_overflow_policy', $form_state->getValue('field_length_overflow_policy'))
      ->set('purge_finished', $form_state->getValue('tmgmt_purge_finished'))
      ->set('anonymous_access', $form_state->getValue('tmgmt_anonymous_access'))
      ->set('respect_text_format', $form_state->getValue('respect_text_format'))
      ->set('allowed_formats', array_keys(array_filter($form_state->getValue('allowed_formats'))))
      ->set('submit_job_item_on_cron', $form_state->getValue('tmgmt_submit_job_item_on_cron'))
      ->set('job_items_cron_limit', $form_state->getValue('job_items_cron_limit'))
      ->set('file_mimetypes', array_values(array_filter($form_state->getValue('mimetypes', []))))
      ->set('purge_stale', $form_state->getValue('purge_stale'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
