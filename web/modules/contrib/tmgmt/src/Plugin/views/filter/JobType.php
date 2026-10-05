<?php

namespace Drupal\tmgmt\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\tmgmt\JobInterface;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\InOperator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter based on job type.
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter('tmgmt_job_type_filter')]
class JobType extends InOperator {

  /**
   * The continuous manager.
   *
   * @var \Drupal\tmgmt\ContinuousManager
   */
  protected $continuousManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->continuousManager = $container->get('tmgmt.continuous');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultExposeOptions() {
    parent::defaultExposeOptions();
    $this->options['expose']['hide_no_continuous'] = TRUE;
  }
  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['expose']['contains']['hide_no_continuous'] = ['default' => TRUE];

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    $form['expose']['hide_no_continuous'] = [
      '#type' => 'checkbox',
      '#title' => t('Hide this filter if there are no continuous jobs.'),
      '#default_value' => $this->options['expose']['hide_no_continuous'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getValueOptions(): void {
    $this->valueOptions = [
      JobInterface::TYPE_NORMAL => $this->t('Normal'),
      JobInterface::TYPE_CONTINUOUS => $this->t('Continuous'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account) {
    if ($this->options['expose']['hide_no_continuous'] && !$this->continuousManager->hasContinuousJobs()) {
      return FALSE;
    }
    return parent::access($account);
  }

}
