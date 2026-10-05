<?php

namespace Drupal\tmgmt_external_translation\Controller;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\tmgmt_content\Controller\ContentTranslationControllerOverride;

/**
 * Decorates the content-translation overview page with our own
 * status+refresh section (see EntityTranslationStatusForm), embedded
 * directly on /node/{node}/translations rather than as a separate tab.
 *
 * A separate local task was tried first and rejected: Drupal core's
 * LocalTasksBlock deliberately hides a secondary-tab group when it would
 * only contain one tab ("don't show a lone tab" - see
 * \Drupal\Core\Menu\Plugin\Block\LocalTasksBlock::build()), so a
 * standalone "Translation status" tab never actually renders. Overriding
 * this route's controller to append our form to the same page is the
 * only injection point available - core's overview() builds a plain
 * render array without a dedicated theme hook or alter hook of its own
 * for third-party content.
 *
 * Extends tmgmt_content's OWN override (not core's base
 * ContentTranslationController) because tmgmt_content already decorates
 * this exact route (wrapping the page in its ContentTranslateForm to add
 * "Request translation" checkboxes) - extending theirs instead of
 * replacing it preserves that existing behavior. See RouteSubscriber for
 * how the two are ordered.
 */
class TranslationStatusOverviewController extends ContentTranslationControllerOverride {

  public function overview(RouteMatchInterface $route_match, $entity_type_id = NULL) {
    // parent::overview() already returns a complete '#type' => 'form'
    // render array (tmgmt_content's own ContentTranslateForm) - adding our
    // own form as a child key under it would nest one <form> element
    // inside another, which is invalid HTML. Browsers ignore a nested
    // <form>'s own start tag (the form element pointer doesn't change),
    // so its hidden form_id/form_build_id/form_token fields end up
    // submitted as part of the OUTER form instead - confirmed as the
    // actual cause of "Request translation" silently misrouting to
    // EntityTranslationStatusForm's own submit handler. Wrap both forms
    // as siblings under a plain container (no '#type', so it adds no
    // wrapping element of its own) instead.
    $translate_form = parent::overview($route_match, $entity_type_id);
    $build = [
      'tmgmt_content_translate_form' => $translate_form,
    ];
    $entity = $route_match->getParameter($entity_type_id);
    if ($entity instanceof \Drupal\Core\Entity\ContentEntityInterface) {
      $build['tmgmt_external_translation_status'] = \Drupal::formBuilder()->getForm(
        \Drupal\tmgmt_external_translation\Form\EntityTranslationStatusForm::class,
        $entity,
      );
      $build['tmgmt_external_translation_status']['#weight'] = 100;
    }
    return $build;
  }

}
