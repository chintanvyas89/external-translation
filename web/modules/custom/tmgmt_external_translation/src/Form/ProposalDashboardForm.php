<?php

namespace Drupal\tmgmt_external_translation\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Link;
use Drupal\tmgmt_external_translation\Translation\TranslationFieldValueReaderInterface;
use Drupal\tmgmt_external_translation\Translation\TranslationProposalManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Central "pending translation proposals" dashboard: every category
 * (content entity fields, interface strings, Layout Builder component
 * labels) in one filterable table, so proposals no longer require
 * knowing a specific entity's URL or which of the two per-entity review
 * forms applies. Accept/Keep/Edit all go through
 * TranslationProposalManager::resolveProposal(), which dispatches to the
 * right write path per category.
 *
 * Each row is a compact summary (category, content, language, age); the
 * last column is a native <details> element (no JS required) that
 * expands in place to the full current-vs-proposed comparison and
 * actions - "tabular at a glance, expandable for detail."
 */
class ProposalDashboardForm extends FormBase {

  use ProposalReviewElementsTrait;

  const LIST_WRAPPER_ID = 'tmgmt-proposal-dashboard-list';

  public function __construct(
    protected TranslationProposalManagerInterface $proposalManager,
    protected TranslationFieldValueReaderInterface $fieldValueReader,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
    protected LanguageManagerInterface $languageManager,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tmgmt_external_translation.proposal_manager'),
      $container->get('tmgmt_external_translation.field_value_reader'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('language_manager'),
    );
  }

  public function getFormId() {
    return 'tmgmt_external_translation_proposal_dashboard_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'tmgmt_external_translation/proposal_dashboard';

    $allProposals = $this->proposalManager->getAllPendingProposals();
    uasort($allProposals, fn(array $a, array $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));

    [$langcodeOptions, $entityOptions] = $this->buildFilterOptions($allProposals);

    $langcodeFilter = (string) $form_state->getValue('langcode_filter', '');
    $entityFilter = (string) $form_state->getValue('entity_filter', '');

    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['tmgmt-proposal-filters']],
      '#access' => (bool) $allProposals,
    ];
    $form['filters']['langcode_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#options' => $langcodeOptions,
      '#default_value' => $langcodeFilter,
      '#ajax' => ['callback' => '::filterAjax', 'wrapper' => self::LIST_WRAPPER_ID],
    ];
    $form['filters']['entity_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Content'),
      '#options' => $entityOptions,
      '#default_value' => $entityFilter,
      '#ajax' => ['callback' => '::filterAjax', 'wrapper' => self::LIST_WRAPPER_ID],
    ];

    $proposals = array_filter($allProposals, function (array $p) use ($langcodeFilter, $entityFilter) {
      if ($langcodeFilter !== '' && ($p['target_langcode'] ?? '') !== $langcodeFilter) {
        return FALSE;
      }
      if ($entityFilter !== '' && $this->entityKey($p) !== $entityFilter) {
        return FALSE;
      }
      return TRUE;
    });

    $form['list'] = [
      '#type' => 'container',
      '#attributes' => ['id' => self::LIST_WRAPPER_ID],
    ];

    if (!$allProposals) {
      $form['list']['empty'] = ['#markup' => '<p>' . $this->t('There are no pending translation proposals.') . '</p>'];
      return $form;
    }

    $form['list']['summary'] = [
      '#markup' => '<p class="tmgmt-proposal-summary">' . $this->formatPlural(count($proposals), 'Showing 1 of @total pending proposal.', 'Showing @count of @total pending proposals.', ['@total' => count($allProposals)]) . '</p>',
    ];

    if (!$proposals) {
      $form['list']['empty'] = ['#markup' => '<p>' . $this->t('No pending proposals match the selected filters.') . '</p>'];
      return $form;
    }

    $form['list']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Category'),
        $this->t('Content'),
        $this->t('Language'),
        $this->t('Submitted'),
        $this->t('Review'),
      ],
      '#attributes' => ['class' => ['tmgmt-proposal-table']],
    ];

    foreach ($proposals as $id => $proposal) {
      $form['list']['table'][$id] = $this->buildRow((int) $id, $proposal);
    }

    return $form;
  }

  /**
   * @return array{0: array, 1: array}
   *   Langcode and entity <select> option lists, derived from the
   *   unfiltered proposal set so filters always offer every value that
   *   currently has at least one pending proposal.
   */
  protected function buildFilterOptions(array $allProposals): array {
    $langcodeOptions = ['' => $this->t('- All languages -')];
    $entityOptions = ['' => $this->t('- All content -')];
    foreach ($allProposals as $proposal) {
      $langcode = $proposal['target_langcode'] ?? '';
      if ($langcode !== '' && !isset($langcodeOptions[$langcode])) {
        $language = $this->languageManager->getLanguage($langcode);
        $langcodeOptions[$langcode] = $language ? $language->getName() : $langcode;
      }
      $key = $this->entityKey($proposal);
      if ($key !== '' && !isset($entityOptions[$key])) {
        $entityOptions[$key] = $this->contentLabel($proposal);
      }
    }
    return [$langcodeOptions, $entityOptions];
  }

  protected function entityKey(array $proposal): string {
    return ($proposal['target_entity_type'] ?? '') . ':' . ($proposal['target_entity_id'] ?? '');
  }

  protected function categoryLabel(array $proposal): array {
    if ($this->isLabelCategory($proposal)) {
      $class = 'layout-label';
      $label = $this->t('Layout label');
    }
    elseif ($this->isInterfaceStringCategory($proposal)) {
      $class = 'interface-string';
      $label = $this->t('Interface string');
    }
    else {
      $class = 'content-field';
      $label = $this->t('Content field');
    }
    return [
      '#markup' => '<span class="tmgmt-proposal-badge tmgmt-proposal-badge--' . $class . '">' . $label . '</span>',
    ];
  }

  /**
   * Plain-text label used for the entity filter dropdown (no markup).
   */
  protected function contentLabel(array $proposal): string {
    if ($this->isLabelCategory($proposal)) {
      return (string) $this->t('Block heading (#@id)', ['@id' => $proposal['target_entity_id'] ?? '']);
    }
    if ($this->isInterfaceStringCategory($proposal)) {
      return (string) $this->t('Interface string (lid @id)', ['@id' => $proposal['target_entity_id'] ?? '']);
    }
    $entity = $this->loadEntity($proposal['target_entity_type'] ?? '', $proposal['target_entity_id'] ?? '');
    return $entity ? $entity->label() : (string) $this->t('@type #@id', ['@type' => $proposal['target_entity_type'] ?? '', '@id' => $proposal['target_entity_id'] ?? '']);
  }

  /**
   * Render array for the "Content" column: a link to the entity where one
   * resolves, plain text otherwise (interface strings/labels aren't
   * directly linkable content).
   */
  protected function contentColumn(array $proposal): array {
    if ($this->isLabelCategory($proposal)) {
      return ['#markup' => $this->t('Block heading (block #@id)', ['@id' => $proposal['target_entity_id'] ?? ''])];
    }
    if ($this->isInterfaceStringCategory($proposal)) {
      return ['#markup' => $this->t('Interface string (lid @id)', ['@id' => $proposal['target_entity_id'] ?? ''])];
    }
    $entity = $this->loadEntity($proposal['target_entity_type'] ?? '', $proposal['target_entity_id'] ?? '');
    if ($entity && $entity->hasLinkTemplate('canonical')) {
      return Link::fromTextAndUrl($entity->label(), $entity->toUrl('canonical'))->toRenderable();
    }
    return ['#markup' => $entity ? $entity->label() : (string) $this->t('@type #@id', ['@type' => $proposal['target_entity_type'] ?? '', '@id' => $proposal['target_entity_id'] ?? ''])];
  }

  protected function loadEntity(string $entityType, string $entityId): ?\Drupal\Core\Entity\EntityInterface {
    if ($entityType === '' || $entityType === self::INTERFACE_STRING_TYPE) {
      return NULL;
    }
    try {
      return $this->entityTypeManager->getStorage($entityType)->load($entityId);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  protected function languageColumn(array $proposal): array {
    $source = $proposal['source_langcode'] ?? '';
    $target = $proposal['target_langcode'] ?? '';
    return ['#markup' => '<span class="tmgmt-proposal-langpair">' . htmlspecialchars($source) . ' → ' . htmlspecialchars($target) . '</span>'];
  }

  protected function buildRow(int $id, array $proposal): array {
    $row = [];
    $row['category'] = $this->categoryLabel($proposal);
    $row['content'] = $this->contentColumn($proposal);
    $row['language'] = $this->languageColumn($proposal);
    $row['submitted'] = [
      '#markup' => '<span title="' . $this->dateFormatter->format((int) ($proposal['created'] ?? 0), 'long') . '">'
        . $this->dateFormatter->formatTimeDiffSince((int) ($proposal['created'] ?? 0)) . ' ' . $this->t('ago') . '</span>',
    ];
    $row['review'] = $this->buildProposalReviewElement($id, $proposal);

    return $row;
  }

  /**
   * #ajax callback for both filter selects: just swap the list wrapper.
   */
  public function filterAjax(array &$form, FormStateInterface $form_state): array {
    return $form['list'];
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->submitProposalReviewAction($form, $form_state);
    $form_state->setRebuild();
  }

}
