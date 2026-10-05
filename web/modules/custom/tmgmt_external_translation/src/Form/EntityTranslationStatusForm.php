<?php

namespace Drupal\tmgmt_external_translation\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tmgmt_external_translation\Translation\TranslationFieldValueReaderInterface;
use Drupal\tmgmt_external_translation\Translation\TranslationPollingServiceInterface;
use Drupal\tmgmt_external_translation\Translation\TranslationProposalManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Per-entity "translation status" tab next to the standard Translate tab:
 * shows outstanding/recent async translation activity for this specific
 * node (both its own fields and, via Layout Builder, its inline blocks),
 * any pending translation proposals for it (so an editor doesn't have to
 * know about the separate site-wide dashboard to find them), and a
 * manual "Refresh now" button - there's no clean per-row alter hook into
 * core's own ContentTranslationController::overview() table, so this is
 * a dedicated adjacent page rather than an injected column.
 */
class EntityTranslationStatusForm extends FormBase {

  use ProposalReviewElementsTrait;

  const STATUS_LIST_WRAPPER_ID = 'tmgmt-et-status-list';

  public function __construct(
    protected Connection $database,
    protected TranslationPollingServiceInterface $pollingService,
    protected DateFormatterInterface $dateFormatter,
    protected TranslationProposalManagerInterface $proposalManager,
    protected TranslationFieldValueReaderInterface $fieldValueReader,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('tmgmt_external_translation.polling_service'),
      $container->get('date.formatter'),
      $container->get('tmgmt_external_translation.proposal_manager'),
      $container->get('tmgmt_external_translation.field_value_reader'),
    );
  }

  public function getFormId() {
    return 'tmgmt_external_translation_entity_translation_status_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?ContentEntityInterface $node = NULL) {
    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'tmgmt_external_translation/proposal_dashboard';
    $entityType = $node->getEntityTypeId();
    $entityId = (string) $node->id();

    $hasAnyActivity = (bool) $this->loadBatchRows($entityType, $entityId, 'all', 1);

    $form['status_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Machine translation status'),
      '#open' => TRUE,
    ];

    // '#tree' is TRUE at the form's top level, so this element's real
    // submitted key is the nested path, not the flat 'status_filter' -
    // a flat getValue() lookup here always silently falls back to the
    // default regardless of what was actually selected.
    $statusFilter = (string) $form_state->getValue(['status_section', 'status_filter'], 'non_completed');
    $form['status_section']['status_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#access' => $hasAnyActivity,
      '#options' => [
        'non_completed' => $this->t('Pending / failed (hide completed)'),
        'all' => $this->t('All statuses'),
        'completed' => $this->t('Completed only'),
        'pending' => $this->t('Pending only'),
        'failed' => $this->t('Failed only'),
      ],
      '#default_value' => $statusFilter,
      '#ajax' => ['callback' => '::statusFilterAjax', 'wrapper' => self::STATUS_LIST_WRAPPER_ID],
    ];

    $form['status_section']['list'] = [
      '#type' => 'container',
      '#attributes' => ['id' => self::STATUS_LIST_WRAPPER_ID],
    ];

    $rows = $this->loadBatchRows($entityType, $entityId, $statusFilter);

    if (!$hasAnyActivity) {
      $form['status_section']['list']['empty'] = [
        '#markup' => '<p>' . $this->t('No machine translation activity recorded for this content yet.') . '</p>',
      ];
    }
    elseif (!$rows) {
      $form['status_section']['list']['empty'] = [
        '#markup' => '<p>' . $this->t('No activity matches the selected status filter.') . '</p>',
      ];
    }
    else {
      $form['status_section']['list']['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Category'),
          $this->t('Target language'),
          $this->t('Status'),
          $this->t('Submitted'),
        ],
      ];
      foreach ($rows as $id => $row) {
        $form['status_section']['list']['table'][$id]['category'] = ['#markup' => $this->categoryLabel($row['kind'])];
        $form['status_section']['list']['table'][$id]['language'] = ['#markup' => htmlspecialchars($row['target_langcode'])];
        $form['status_section']['list']['table'][$id]['status'] = ['#markup' => $this->statusLabel($row['status'])];
        $form['status_section']['list']['table'][$id]['submitted'] = [
          '#markup' => '<span title="' . $this->dateFormatter->format((int) $row['created'], 'long') . '">'
            . $this->dateFormatter->formatTimeDiffSince((int) $row['created']) . ' ' . $this->t('ago') . '</span>',
        ];
      }
    }

    if ($this->currentUser()->hasPermission('accept translation proposals') || $this->currentUser()->hasPermission('reject translation proposals')) {
      $proposals = $this->loadProposalsForEntity($entityType, $entityId);
      if ($proposals) {
        $form['proposals'] = [
          '#type' => 'details',
          '#title' => $this->formatPlural(count($proposals), 'Proposal (1)', 'Proposals (@count)'),
          '#open' => TRUE,
        ];
        foreach ($proposals as $id => $proposal) {
          $form['proposals'][$id] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['tmgmt-et-proposal-row']],
          ];
          $form['proposals'][$id]['label'] = [
            '#markup' => '<strong>' . htmlspecialchars($this->proposalSummaryLabel($proposal)) . '</strong>',
          ];
          $form['proposals'][$id]['review'] = $this->buildProposalReviewElement((int) $id, $proposal);
        }
      }
    }

    if ($this->currentUser()->hasPermission('manage translation providers')) {
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['refresh'] = [
        '#type' => 'submit',
        '#value' => $this->t('Refresh now'),
        '#description' => $this->t('Checks the translation provider for any outstanding requests and applies results that are ready - the same check that runs automatically on cron.'),
      ];
    }

    return $form;
  }

  /**
   * @return array[]
   *   Pending proposals directly targeting this entity, plus any
   *   targeting a block_content entity used as an inline block somewhere
   *   in this entity's own Layout Builder overrides - so an editor
   *   working a single node's translation doesn't have to separately
   *   know about proposals attached to its blocks, which are technically
   *   a different target_entity_type/id under the hood.
   */
  protected function loadProposalsForEntity(string $entityType, string $entityId): array {
    $allProposals = $this->proposalManager->getAllPendingProposals();
    $blockIds = $this->loadRelevantBlockIds($entityType, $entityId);
    return array_filter($allProposals, function (array $proposal) use ($entityType, $entityId, $blockIds) {
      if (($proposal['target_entity_type'] ?? '') === $entityType && (string) ($proposal['target_entity_id'] ?? '') === $entityId) {
        return TRUE;
      }
      return $blockIds && ($proposal['target_entity_type'] ?? '') === 'block_content'
        && in_array((string) ($proposal['target_entity_id'] ?? ''), $blockIds, TRUE);
    });
  }

  /**
   * @return string[]
   *   block_content ids used as inline blocks somewhere in this host
   *   entity's Layout Builder overrides (any translation), read directly
   *   from Layout Builder's own inline_block_usage table. Reads the
   *   table directly rather than injecting a Layout Builder service,
   *   since this core module must not depend on the _layout_builder
   *   submodule (or, formally, on Layout Builder at all) - the same
   *   "read a known table shape directly" pattern loadBatchRows() above
   *   already uses for tmgmt_job_item. Returns empty (not an error) when
   *   Layout Builder isn't installed.
   */
  protected function loadRelevantBlockIds(string $entityType, string $entityId): array {
    if (!$this->database->schema()->tableExists('inline_block_usage')) {
      return [];
    }
    return array_map('strval', $this->database->select('inline_block_usage', 'u')
      ->fields('u', ['block_content_id'])
      ->condition('u.layout_entity_type', $entityType)
      ->condition('u.layout_entity_id', $entityId)
      ->execute()
      ->fetchCol());
  }

  /**
   * Short plain-text identifier for a proposal row on the entity page -
   * unlike the dashboard's badge+link columns, this page already knows
   * "this is about the current entity" and just needs to say which slot.
   */
  protected function proposalSummaryLabel(array $proposal): string {
    if ($this->isLabelCategory($proposal)) {
      $category = $this->t('Layout block heading (block #@id)', ['@id' => $proposal['target_entity_id'] ?? '']);
    }
    elseif ($this->isInterfaceStringCategory($proposal)) {
      $category = $this->t('Interface string');
    }
    elseif (($proposal['target_entity_type'] ?? '') === 'block_content') {
      $category = $this->t('Layout block field: @field (block #@id)', [
        '@field' => $proposal['field_name'] ?? '',
        '@id' => $proposal['target_entity_id'] ?? '',
      ]);
    }
    else {
      $category = $this->t('Field: @field', ['@field' => $proposal['field_name'] ?? '']);
    }
    return (string) $this->t('@category (@source → @target)', [
      '@category' => $category,
      '@source' => $proposal['source_langcode'] ?? '',
      '@target' => $proposal['target_langcode'] ?? '',
    ]);
  }

  protected function categoryLabel(string $kind): string {
    return match ($kind) {
      'job_item' => (string) $this->t('Content fields'),
      'layout_inline_blocks' => (string) $this->t('Layout Builder blocks'),
      default => $kind,
    };
  }

  protected function statusLabel(string $status): string {
    return match ($status) {
      'pending' => (string) $this->t('Awaiting provider response'),
      'completed' => (string) $this->t('Completed'),
      'failed' => (string) $this->t('Failed'),
      default => $status,
    };
  }

  /**
   * @param string $statusFilter
   *   One of 'non_completed' (pending+failed - the default, to keep
   *   completed noise out of an editor's way), 'all', 'completed',
   *   'pending', or 'failed'.
   * @param int $limit
   *   Row cap, applied in SQL after the status filter - so filtering out
   *   completed rows actually surfaces more of the pending/failed ones
   *   instead of a filtered-down slice of whatever 10 rows happened to be
   *   most recent overall.
   *
   * @return array[]
   *   Up to $limit most recent translation_pending_batch rows for this
   *   entity, across both kinds: kind=job_item is matched via a join to
   *   tmgmt_job_item (which records item_type/item_id, not the host
   *   entity directly); kind=layout_inline_blocks matches host_entity_type/id
   *   directly.
   */
  protected function loadBatchRows(string $entityType, string $entityId, string $statusFilter = 'non_completed', int $limit = 10): array {
    $query = $this->database->select('translation_pending_batch', 'b');
    $query->leftJoin('tmgmt_job_item', 'ji', 'b.job_item_id = ji.tjiid');
    $query->fields('b', ['id', 'kind', 'status', 'target_langcode', 'created']);
    $query->condition($query->orConditionGroup()
      ->condition($query->andConditionGroup()
        ->condition('b.kind', 'job_item')
        ->condition('ji.item_type', $entityType)
        ->condition('ji.item_id', $entityId))
      ->condition($query->andConditionGroup()
        ->condition('b.kind', 'layout_inline_blocks')
        ->condition('b.host_entity_type', $entityType)
        ->condition('b.host_entity_id', $entityId))
    );
    match ($statusFilter) {
      'non_completed' => $query->condition('b.status', 'completed', '!='),
      'completed', 'pending', 'failed' => $query->condition('b.status', $statusFilter),
      default => NULL,
    };
    $query->orderBy('b.created', 'DESC');
    $query->range(0, $limit);
    return $query->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
  }

  /**
   * #ajax callback for the status filter select: swap just the list.
   */
  public function statusFilterAjax(array &$form, FormStateInterface $form_state): array {
    return $form['status_section']['list'];
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($this->submitProposalReviewAction($form, $form_state)) {
      $form_state->setRebuild();
      return;
    }

    $summary = $this->pollingService->processAllPending();
    if ($summary['checked'] === 0) {
      $this->messenger()->addStatus($this->t('No translation requests are currently waiting on the provider, site-wide - either nothing has been submitted since the last check, or everything already completed. If you expected a request here specifically, confirm it was actually submitted (see the table above) rather than left on a checkout page.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Checked @checked outstanding translation request(s) site-wide: @completed completed, @pending still pending.', [
        '@checked' => $summary['checked'],
        '@completed' => $summary['completed'],
        '@pending' => $summary['still_pending'],
      ]));
    }
    $form_state->setRebuild();
  }

}
