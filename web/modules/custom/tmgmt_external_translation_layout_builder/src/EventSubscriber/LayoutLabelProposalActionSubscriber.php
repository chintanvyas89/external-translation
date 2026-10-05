<?php

namespace Drupal\tmgmt_external_translation_layout_builder\EventSubscriber;

use Drupal\tmgmt_external_translation\Event\ProposalActionEvent;
use Drupal\tmgmt_external_translation_layout_builder\Layout\LayoutLabelProposalHandler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Claims proposal actions for Layout Builder component labels (identified
 * by field_name, since target_entity_type is the real 'block_content' -
 * entity type alone can't distinguish a label from the block's own
 * fields), resolving them via LayoutLabelProposalHandler instead of the
 * core module's generic entity-field write-back.
 */
class LayoutLabelProposalActionSubscriber implements EventSubscriberInterface {

  const LABEL_FIELD_NAME = 'layout_component_label';

  public function __construct(
    protected LayoutLabelProposalHandler $handler,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      ProposalActionEvent::EVENT_NAME => 'onProposalAction',
    ];
  }

  public function onProposalAction(ProposalActionEvent $event): void {
    if (($event->proposal['field_name'] ?? NULL) !== self::LABEL_FIELD_NAME) {
      return;
    }

    $success = match ($event->action) {
      'accept' => $this->handler->acceptLabelProposal($event->proposalId),
      'reject' => $this->handler->rejectLabelProposal($event->proposalId),
      'edit' => $event->editedText !== NULL && $this->handler->editLabelProposal($event->proposalId, $event->editedText),
      default => FALSE,
    };
    $event->setHandled($success);
  }

}
