<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\drupal_simple_voting\VotingPolicy;
use Drupal\drupal_simple_voting\PollSerializer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Read-only endpoint for a single question result.
 */
final class ResultResource extends ControllerBase {

  public function __construct(
    private readonly PollSerializer $serializer,
    private readonly VotingPolicy $policy,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('drupal_simple_voting.serializer'),
      $container->get('drupal_simple_voting.policy'),
    );
  }

  public function read(string $uuid): JsonResponse {
    $question = $this->serializer->loadByUuid($uuid);
    if ($question === NULL) {
      return new JsonResponse(['error' => 'Poll not found.'], 404);
    }

    $response = new CacheableJsonResponse([
      'data' => $this->serializer->results($question, $this->currentUser()),
    ]);
    $cacheability = $this->policy->cacheabilityFor($question);
    // The body changes with who is asking: an elector who already voted sees
    // the tally and is_your_vote. Without this context one answer would leak.
    $cacheability->addCacheContexts(['user']);
    $cacheability->addCacheTags(['voting_result:' . $question->id()]);
    $response->addCacheableDependency($cacheability);

    return $response;
  }

}
