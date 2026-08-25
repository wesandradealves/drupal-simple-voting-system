<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Shared error replies for the JSON endpoints.
 *
 * The API answers '{"error": ...}'; on a _format: json route core would answer
 * '{"message": ...}'. Keeping that shape in one place is why a missing poll is
 * turned away here instead of by a route parameter converter or a thrown
 * NotFoundHttpException, either of which would hand the caller core's wording.
 */
abstract class ApiResource extends ControllerBase {

  /**
   * Builds the shared error body '{"error": message}' with the given status.
   */
  protected function errorResponse(string $message, int $status): JsonResponse {
    return new JsonResponse(['error' => $message], $status);
  }

  /**
   * The canonical 404 for an unknown poll.
   */
  protected function pollNotFound(): JsonResponse {
    return $this->errorResponse('Poll not found.', 404);
  }

}
