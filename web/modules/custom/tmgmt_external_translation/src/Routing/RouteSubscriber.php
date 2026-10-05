<?php

namespace Drupal\tmgmt_external_translation\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Points node's content-translation overview route at our decorator
 * controller (see TranslationStatusOverviewController) so the "machine
 * translation status" section can be appended to that page.
 *
 * Must run AFTER tmgmt_content's own RouteSubscriber (which sets this
 * route's controller to ContentTranslationControllerOverride at priority
 * -211), since our controller extends theirs - if we ran first, their
 * subscriber would just overwrite our change back to their own class.
 */
class RouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection) {
    $route = $collection->get('entity.node.content_translation_overview');
    if ($route && $route->getDefault('_controller') === '\Drupal\tmgmt_content\Controller\ContentTranslationControllerOverride::overview') {
      $route->setDefault('_controller', '\Drupal\tmgmt_external_translation\Controller\TranslationStatusOverviewController::overview');
    }
  }

  public static function getSubscribedEvents(): array {
    $events = parent::getSubscribedEvents();
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -300];
    return $events;
  }

}
