<?php

namespace Drupal\tmgmt_external_translation\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Dispatched by TranslationProposalManager::resolveProposal() before
 * falling back to its own generic entity-field write. Lets a submodule
 * claim a proposal whose target isn't a real entity field (e.g. interface
 * strings, Layout Builder component labels) and resolve it with its own
 * write logic, without the core module needing to know those categories
 * exist - mirrors EntityTranslatedEvent/EntityFieldWrittenEvent.
 */
class ProposalActionEvent extends Event {

  const EVENT_NAME = 'tmgmt_external_translation.proposal_action';

  public function __construct(
    public readonly int $proposalId,
    public readonly string $action,
    public readonly ?string $editedText,
    public readonly array $proposal,
  ) {}

  protected bool $handled = FALSE;
  protected bool $success = FALSE;

  /**
   * Called by whichever subscriber recognizes this proposal's category,
   * after it has performed (or attempted) the write itself.
   */
  public function setHandled(bool $success): void {
    $this->handled = TRUE;
    $this->success = $success;
  }

  public function isHandled(): bool {
    return $this->handled;
  }

  public function wasSuccessful(): bool {
    return $this->success;
  }

}
