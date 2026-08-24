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

  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => [['onRequest', 100]]];
  }

  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if (str_starts_with($request->getPathInfo(), self::PREFIX)) {
      $request->setRequestFormat('json');
    }
  }

}
