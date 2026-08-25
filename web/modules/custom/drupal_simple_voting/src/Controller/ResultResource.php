<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\drupal_simple_voting\VotingPolicy;
use Drupal\drupal_simple_voting\PollSerializer;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Read-only endpoint for a single question result.
 */
final class ResultResource extends ApiResource {

  use AutowireTrait;

  /**
   * Injects the serializer that shapes the payload and the voting policy.
   *
   * @param \Drupal\drupal_simple_voting\PollSerializer $serializer
   *   Loads the question by UUID and builds its results array.
   * @param \Drupal\drupal_simple_voting\VotingPolicy $policy
   *   Supplies the per-question cacheability metadata.
   */
  public function __construct(
    private readonly PollSerializer $serializer,
    private readonly VotingPolicy $policy,
  ) {}

  /**
   * GET /api/v1/polls/{uuid}/results: reads one poll's tally.
   *
   * Returns 404 when no poll carries that UUID, otherwise 200 with
   * '{"data": ...}'. The body varies by user (a voter also sees is_your_vote)
   * and is tagged with the poll's result cache tag.
   */
  public function read(string $uuid): JsonResponse {
    $question = $this->serializer->loadByUuid($uuid);
    if ($question === NULL) {
      return $this->pollNotFound();
    }

    $response = new CacheableJsonResponse([
      'data' => $this->serializer->results($question, $this->currentUser()),
    ]);
    $cacheability = $this->policy->cacheabilityFor($question);
    // The body changes with who is asking: an elector who already voted sees
    // the tally and is_your_vote. Without this context one answer would leak.
    $cacheability->addCacheContexts(['user']);
    $cacheability->addCacheTags([VotingPolicy::resultCacheTag($question->id())]);
    $response->addCacheableDependency($cacheability);

    return $response;
  }

}
