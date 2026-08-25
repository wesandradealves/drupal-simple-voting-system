<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Marks every /api request as JSON before routing runs.
 *
 * Route level _format only reaches requests that matched a route, so a wrong
 * method or an unknown path still fell through to the HTML error page. Setting
 * the format this early hands those cases to the core JSON exception
 * subscriber instead.
 */
final class ApiFormatSubscriber implements EventSubscriberInterface {

  private const PREFIX = '/api/';

  /**
   * Subscribes onRequest to the kernel request event at high priority (100),
   * so the format is set before routing runs.
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => [['onRequest', 100]]];
  }

  /**
   * Forces the 'json' request format on any path under /api/.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if (str_starts_with($request->getPathInfo(), self::PREFIX)) {
      $request->setRequestFormat('json');
    }
  }

}
