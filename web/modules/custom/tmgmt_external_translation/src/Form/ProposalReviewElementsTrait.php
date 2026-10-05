<?php

namespace Drupal\tmgmt_external_translation\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\tmgmt_external_translation\Translation\TranslationFieldContext;

/**
 * The "View / Resolve" expandable details element (current vs. proposed
 * value, Accept/Keep/Edit actions) and its submit-dispatch logic, shared
 * between ProposalDashboardForm (site-wide) and EntityTranslationStatusForm
 * (per-entity) so the two don't duplicate this - the only real difference
 * between the two contexts is which proposals get listed, not how a
 * single proposal is reviewed/resolved.
 *
 * Classes using this trait must inject TranslationProposalManagerInterface
 * as $this->proposalManager and TranslationFieldValueReaderInterface as
 * $this->fieldValueReader.
 */
trait ProposalReviewElementsTrait {

  const LABEL_FIELD_NAME = 'layout_component_label';
  const INTERFACE_STRING_TYPE = 'interface_string';

  protected function isLabelCategory(array $proposal): bool {
    return ($proposal['field_name'] ?? '') === self::LABEL_FIELD_NAME;
  }

  protected function isInterfaceStringCategory(array $proposal): bool {
    return ($proposal['target_entity_type'] ?? '') === self::INTERFACE_STRING_TYPE;
  }

  /**
   * Only real, currently-loadable content entity fields get a "current
   * value" comparison - interface strings and layout labels aren't
   * generic entity fields, and this deliberately doesn't duplicate their
   * category-specific live-value lookups.
   */
  protected function buildProposalCurrentValue(array $proposal): ?string {
    if ($this->isLabelCategory($proposal) || $this->isInterfaceStringCategory($proposal)) {
      return NULL;
    }
    $context = new TranslationFieldContext(
      $proposal['source_entity_type'],
      $proposal['source_entity_id'],
      $proposal['source_langcode'],
      $proposal['target_langcode'],
      $proposal['target_entity_type'],
      $proposal['target_entity_id'],
      $proposal['field_name'],
      (int) $proposal['field_delta'],
      $proposal['field_property'],
    );
    return $this->fieldValueReader->getLiveValue($context) ?? '';
  }

  /**
   * The expandable "View / Resolve" details element for one proposal:
   * current vs. proposed value, an edit textarea, and Accept/Keep/Edit
   * submit buttons wired to submitProposalReviewAction() below.
   */
  protected function buildProposalReviewElement(int $id, array $proposal): array {
    $review = [
      '#type' => 'details',
      '#title' => $this->t('View / Resolve'),
    ];

    $currentValue = $this->buildProposalCurrentValue($proposal);
    if ($currentValue !== NULL) {
      $review['current'] = [
        '#type' => 'item',
        '#title' => $this->t('Current translation'),
        '#markup' => '<div class="tmgmt-et-current">' . nl2br(htmlspecialchars($currentValue)) . '</div>',
      ];
    }
    $review['proposed'] = [
      '#type' => 'item',
      '#title' => $this->t('Machine translation proposal'),
      '#markup' => '<div class="tmgmt-et-proposed">' . nl2br(htmlspecialchars((string) $proposal['provider_translation'])) . '</div>',
    ];
    $review['edited_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Edit before saving'),
      '#default_value' => $proposal['provider_translation'],
      '#rows' => 3,
    ];
    $review['actions'] = ['#type' => 'actions'];
    $review['actions']['keep'] = [
      '#type' => 'submit',
      '#value' => $this->t('Keep current'),
      '#name' => 'keep_' . $id,
      '#proposal_id' => $id,
      '#proposal_action' => 'reject',
      '#submit' => ['::submitForm'],
      '#description' => $this->t('Discards this suggestion; your current translation is left untouched.'),
    ];
    $review['actions']['accept'] = [
      '#type' => 'submit',
      '#value' => $this->t('Accept translation'),
      '#name' => 'accept_' . $id,
      '#proposal_id' => $id,
      '#proposal_action' => 'accept',
      '#submit' => ['::submitForm'],
      '#description' => $this->t('Overwrites the current translation with the machine translation shown above.'),
    ];
    $review['actions']['edit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save edited translation'),
      '#name' => 'edit_' . $id,
      '#proposal_id' => $id,
      '#proposal_action' => 'edit',
      '#submit' => ['::submitForm'],
      '#description' => $this->t('Writes your own text above instead of either version.'),
    ];

    return $review;
  }

  /**
   * @return bool
   *   TRUE if the triggering element was a proposal action (whether or
   *   not the caller should also process a "Refresh now"-style submit) -
   *   callers check this to decide whether to skip their own submit logic.
   */
  protected function submitProposalReviewAction(array &$form, FormStateInterface $form_state): bool {
    $triggering = $form_state->getTriggeringElement();
    $action = $triggering['#proposal_action'] ?? NULL;
    $proposalId = (int) ($triggering['#proposal_id'] ?? 0);
    if (!$action || !$proposalId) {
      return FALSE;
    }

    $editedText = NULL;
    if ($action === 'edit') {
      $parents = $triggering['#array_parents'];
      array_splice($parents, -2);
      $editedText = (string) $form_state->getValue(array_merge($parents, ['edited_text']));
    }

    $success = $this->proposalManager->resolveProposal($proposalId, $action, $editedText);
    if ($success) {
      $this->messenger()->addStatus($this->t('Proposal resolved.'));
    }
    else {
      $this->messenger()->addWarning($this->t('Could not resolve this proposal.'));
    }

    return TRUE;
  }

}
