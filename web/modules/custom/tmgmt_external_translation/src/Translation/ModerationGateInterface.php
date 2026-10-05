<?php

namespace Drupal\tmgmt_external_translation\Translation;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Prevents a machine-translated write from silently going live on an
 * already-published, moderated page: if the translation currently being
 * written to is in a published state, moves it to a review state before
 * the caller saves it, so the change surfaces for human review instead
 * of replacing what's already visible.
 *
 * Only needed by write paths that don't already go through TMGMT's own
 * ContentEntitySource::saveTranslation() (which has equivalent handling
 * built in, driven by the same tmgmt_content.settings:default_moderation_states
 * config, once that's configured) - namely proposal accept/edit
 * (TranslationProposalManager) and Layout Builder host-entity saves
 * (LayoutEntityCollector, in the _layout_builder submodule).
 */
interface ModerationGateInterface {

  /**
   * No-op for a non-moderated entity, or one not currently in a published
   * state. Otherwise sets 'moderation_state' to the configured review
   * state on $translation - the caller is still responsible for actually
   * saving it.
   */
  public function gateIfPublished(ContentEntityInterface $translation): void;

}
