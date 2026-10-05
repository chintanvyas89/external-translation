<?php

namespace Drupal\tmgmt_external_translation\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tmgmt_external_translation\Translation\TranslationFieldValueReaderInterface;
use Drupal\tmgmt_external_translation\Translation\TranslationProposalManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * "Translation Proposal" review UI (spec section 13): shows the source,
 * the current (editor-approved) translation, and the new machine
 * translation side by side, with Accept / Keep / Edit actions - reachable
 * from the normal Drupal editing workflow, not a separate translation
 * editor.
 */
class TranslationProposalReviewForm extends FormBase {

  public function __construct(
    protected TranslationProposalManagerInterface $proposalManager,
    protected TranslationFieldValueReaderInterface $fieldValueReader,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tmgmt_external_translation.proposal_manager'),
      $container->get('tmgmt_external_translation.field_value_reader'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId() {
    return 'tmgmt_external_translation_proposal_review_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $entity_type = NULL, $entity_id = NULL, $langcode = NULL) {
    $form['#tree'] = TRUE;
    $form_state->set('entity_type', $entity_type);
    $form_state->set('entity_id', $entity_id);
    $form_state->set('langcode', $langcode);

    $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
    $proposals = $this->proposalManager->getPendingProposalsForEntity($entity_type, $entity_id, $langcode);

    if (!$entity || !$proposals) {
      $form['empty'] = [
        '#markup' => $this->t('There are no pending translation proposals for this content.'),
      ];
      return $form;
    }

    $fieldLabels = [];
    if ($entity->getEntityType()->entityClassImplements(\Drupal\Core\Entity\FieldableEntityInterface::class)) {
      foreach ($entity->getFieldDefinitions() as $name => $definition) {
        $fieldLabels[$name] = $definition->getLabel();
      }
    }

    foreach ($proposals as $id => $proposal) {
      $context = new \Drupal\tmgmt_external_translation\Translation\TranslationFieldContext(
        $proposal['source_entity_type'],
        $proposal['source_entity_id'],
        $proposal['source_langcode'],
        $langcode,
        $entity_type,
        $entity_id,
        $proposal['field_name'],
        (int) $proposal['field_delta'],
        $proposal['field_property'],
      );
      $currentValue = $this->fieldValueReader->getLiveValue($context) ?? '';
      $label = $fieldLabels[$proposal['field_name']] ?? $proposal['field_name'];

      $form['proposals'][$id] = [
        '#type' => 'fieldset',
        '#title' => $label,
      ];
      $form['proposals'][$id]['current'] = [
        '#type' => 'item',
        '#title' => $this->t('Current translation'),
        '#markup' => '<div class="tmgmt-et-current">' . nl2br(htmlspecialchars($currentValue)) . '</div>',
      ];
      $form['proposals'][$id]['proposed'] = [
        '#type' => 'item',
        '#title' => $this->t('Machine translation proposal'),
        '#markup' => '<div class="tmgmt-et-proposed">' . nl2br(htmlspecialchars((string) $proposal['provider_translation'])) . '</div>',
      ];
      $form['proposals'][$id]['edited_text'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Edit before saving'),
        '#default_value' => $proposal['provider_translation'],
        '#rows' => 3,
      ];
      $form['proposals'][$id]['actions'] = ['#type' => 'actions'];
      $form['proposals'][$id]['actions']['keep'] = [
        '#type' => 'submit',
        '#value' => $this->t('Keep current'),
        '#name' => 'keep_' . $id,
        '#proposal_id' => $id,
        '#proposal_action' => 'keep',
        '#submit' => ['::submitForm'],
      ];
      $form['proposals'][$id]['actions']['accept'] = [
        '#type' => 'submit',
        '#value' => $this->t('Accept translation'),
        '#name' => 'accept_' . $id,
        '#proposal_id' => $id,
        '#proposal_action' => 'accept',
        '#submit' => ['::submitForm'],
      ];
      $form['proposals'][$id]['actions']['edit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save edited translation'),
        '#name' => 'edit_' . $id,
        '#proposal_id' => $id,
        '#proposal_action' => 'edit',
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
        $this->proposalManager->acceptProposal($proposalId);
        $this->messenger()->addStatus($this->t('Machine translation accepted.'));
        break;

      case 'keep':
        $this->proposalManager->rejectProposal($proposalId);
        $this->messenger()->addStatus($this->t('Existing translation kept.'));
        break;

      case 'edit':
        $parents = $triggering['#array_parents'];
        array_splice($parents, -2);
        $editedText = $form_state->getValue(array_merge($parents, ['edited_text']));
        $this->proposalManager->applyEditedProposal($proposalId, (string) $editedText);
        $this->messenger()->addStatus($this->t('Edited translation saved.'));
        break;
    }

    $form_state->setRebuild();
  }

}
