<?php

namespace Drupal\tmgmt_external_translation_interface\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tmgmt_external_translation_interface\InterfaceTranslation\InterfaceTranslationManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Accept / Keep / Edit review UI for interface string proposals - the
 * same pattern as the content module's TranslationProposalReviewForm.
 */
class InterfaceProposalReviewForm extends FormBase {

  public function __construct(
    protected InterfaceTranslationManagerInterface $manager,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('tmgmt_external_translation_interface.manager'));
  }

  public function getFormId() {
    return 'tmgmt_external_translation_interface_proposal_review_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $langcode = NULL) {
    $form['#tree'] = TRUE;
    $form_state->set('langcode', $langcode);
    $proposals = $this->manager->getPendingProposals($langcode);

    if (!$proposals) {
      $form['empty'] = ['#markup' => $this->t('No pending interface translation proposals for this language.')];
      return $form;
    }

    foreach ($proposals as $id => $proposal) {
      $form['proposals'][$id] = [
        '#type' => 'fieldset',
        '#title' => $proposal['source_string'] ?? ('String #' . $proposal['target_entity_id']),
      ];
      $form['proposals'][$id]['proposed'] = [
        '#type' => 'item',
        '#title' => $this->t('Machine translation proposal'),
        '#markup' => htmlspecialchars((string) $proposal['provider_translation']),
      ];
      $form['proposals'][$id]['edited_text'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Edit before saving'),
        '#default_value' => $proposal['provider_translation'],
      ];
      $form['proposals'][$id]['actions'] = ['#type' => 'actions'];
      $form['proposals'][$id]['actions']['keep'] = [
        '#type' => 'submit', '#value' => $this->t('Keep current'),
        '#name' => 'keep_' . $id, '#proposal_id' => $id, '#proposal_action' => 'keep',
        '#submit' => ['::submitForm'],
      ];
      $form['proposals'][$id]['actions']['accept'] = [
        '#type' => 'submit', '#value' => $this->t('Accept translation'),
        '#name' => 'accept_' . $id, '#proposal_id' => $id, '#proposal_action' => 'accept',
        '#submit' => ['::submitForm'],
      ];
      $form['proposals'][$id]['actions']['edit'] = [
        '#type' => 'submit', '#value' => $this->t('Save edited translation'),
        '#name' => 'edit_' . $id, '#proposal_id' => $id, '#proposal_action' => 'edit',
        '#submit' => ['::submitForm'],
      ];
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $triggering = $form_state->getTriggeringElement();
    $action = $triggering['#proposal_action'] ?? NULL;
    $proposalId = (int) ($triggering['#proposal_id'] ?? 0);
    if (!$action || !$proposalId) {
      return;
    }

    switch ($action) {
      case 'accept':
        $this->manager->acceptStringProposal($proposalId);
        $this->messenger()->addStatus($this->t('Machine translation accepted.'));
        break;

      case 'keep':
        $this->manager->rejectStringProposal($proposalId);
        $this->messenger()->addStatus($this->t('Existing translation kept.'));
        break;

      case 'edit':
        $parents = $triggering['#array_parents'];
        array_splice($parents, -2);
        $editedText = $form_state->getValue(array_merge($parents, ['edited_text']));
        $this->manager->editStringProposal($proposalId, (string) $editedText);
        $this->messenger()->addStatus($this->t('Edited translation saved.'));
        break;
    }

    $form_state->setRebuild();
  }

}
