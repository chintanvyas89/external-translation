<?php

namespace Drupal\tmgmt_external_translation\Translation;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

class ModerationGate implements ModerationGateInterface {

  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected ConfigFactoryInterface $configFactory,
    // Optional: content_moderation may not be installed. Symfony/Drupal's
    // container passes NULL for an unresolvable '@?' service rather than
    // erroring, so this stays constructor-injected (matching the rest of
    // this module's style) instead of a runtime \Drupal::service() call.
    protected ?ModerationInformationInterface $moderationInfo = NULL,
  ) {}

  public function gateIfPublished(ContentEntityInterface $translation): void {
    if (!$this->moduleHandler->moduleExists('content_moderation') || !$this->moderationInfo) {
      return;
    }
    if (!$this->moderationInfo->isModeratedEntity($translation) || !$translation->hasField('moderation_state')) {
      return;
    }

    $workflow = $this->moderationInfo->getWorkflowForEntity($translation);
    if (!$workflow) {
      return;
    }
    $workflowPlugin = $workflow->getTypePlugin();
    $currentStateId = $translation->get('moderation_state')->value;
    if (!$currentStateId || !$workflowPlugin->hasState($currentStateId) || !$workflowPlugin->getState($currentStateId)->isPublishedState()) {
      // Not currently published (already draft/archived/whatever else) -
      // nothing live to protect.
      return;
    }

    // Same config TMGMT's own ContentEntitySource::saveTranslation() reads
    // (currently only consulted there, for ordinary job-item writes) -
    // reusing it here keeps a single admin-configurable setting
    // controlling every write path in this system, not a second,
    // independent default.
    $configuredStateId = $this->configFactory->get('tmgmt_content.settings')->get('default_moderation_states.' . $workflow->id());
    if ($configuredStateId && $workflowPlugin->hasState($configuredStateId)) {
      $targetStateId = $configuredStateId;
    }
    elseif ($workflowPlugin->hasState('draft')) {
      $targetStateId = 'draft';
    }
    else {
      $targetStateId = NULL;
      foreach ($workflowPlugin->getStates() as $stateId => $state) {
        if (!$state->isPublishedState()) {
          $targetStateId = $stateId;
          break;
        }
      }
    }

    if ($targetStateId === NULL || $targetStateId === $currentStateId) {
      return;
    }
    $translation->set('moderation_state', $targetStateId);
  }

}
