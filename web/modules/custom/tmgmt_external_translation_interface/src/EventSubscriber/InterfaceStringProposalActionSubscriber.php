<?php

namespace Drupal\tmgmt_external_translation_interface\EventSubscriber;

use Drupal\tmgmt_external_translation\Event\ProposalActionEvent;
use Drupal\tmgmt_external_translation_interface\InterfaceTranslation\InterfaceTranslationManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Claims proposal actions for interface strings (target_entity_type ===
 * 'interface_string', a pseudo-type - not a real Drupal entity type),
 * resolving them via the module's existing accept/reject/edit methods
 * instead of the core module's generic entity-field write-back.
 */
class InterfaceStringProposalActionSubscriber implements EventSubscriberInterface {

  const TARGET_ENTITY_TYPE = 'interface_string';

  public function __construct(
    protected InterfaceTranslationManagerInterface $manager,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      ProposalActionEvent::EVENT_NAME => 'onProposalAction',
    ];
  }

  public function onProposalAction(ProposalActionEvent $event): void {
    if (($event->proposal['target_entity_type'] ?? NULL) !== self::TARGET_ENTITY_TYPE) {
      return;
    }

    $success = match ($event->action) {
      'accept' => $this->manager->acceptStringProposal($event->proposalId),
      'reject' => $this->manager->rejectStringProposal($event->proposalId),
      'edit' => $event->editedText !== NULL && $this->manager->editStringProposal($event->proposalId, $event->editedText),
      default => FALSE,
    };
    $event->setHandled($success);
  }

}
