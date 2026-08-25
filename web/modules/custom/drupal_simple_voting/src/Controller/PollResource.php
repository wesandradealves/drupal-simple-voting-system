<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\drupal_simple_voting\VotingPolicy;
use Drupal\drupal_simple_voting\VotingQuestionInterface;
use Drupal\drupal_simple_voting\PollSerializer;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Read-only endpoints for the questions.
 */
final class PollResource extends ApiResource {

  use AutowireTrait;

  /**
   * Injects the serializer that shapes the payload and the voting policy.
   *
   * @param \Drupal\drupal_simple_voting\PollSerializer $serializer
   *   Loads questions by UUID and turns them into JSON-ready arrays.
   * @param \Drupal\drupal_simple_voting\VotingPolicy $policy
   *   Supplies the per-question cacheability metadata.
   */
  public function __construct(
    private readonly PollSerializer $serializer,
    private readonly VotingPolicy $policy,
  ) {}

  /**
   * GET /api/v1/polls: lists every poll the caller may see.
   *
   * Returns 200 with '{"data": [...]}', open polls first then newest first.
   * The response varies by user and depends on the question list cache tags
   * and the voting settings.
   */
  public function collection(): CacheableJsonResponse {
    $storage = $this->entityTypeManager()->getStorage('voting_question');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('status', 'DESC')
      ->sort('created', 'DESC')
      ->execute();

    $questions = array_filter(
      $storage->loadMultiple($ids),
      static fn ($question): bool => $question instanceof VotingQuestionInterface,
    );
    $data = $this->serializer->collection(array_values($questions), $this->currentUser());

    $response = new CacheableJsonResponse(['data' => $data]);
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(['user']);
    $cacheability->addCacheTags($storage->getEntityType()->getListCacheTags());
    $cacheability->addCacheableDependency($this->config(VotingPolicy::SETTINGS));
    $response->addCacheableDependency($cacheability);

    return $response;
  }

  /**
   * GET /api/v1/polls/{uuid}: reads one poll with its options.
   *
   * Returns 404 when no poll carries that UUID, otherwise 200 with
   * '{"data": ...}'. The response varies by user and depends on the poll's
   * cacheability plus the option list cache tags.
   */
  public function item(string $uuid): JsonResponse {
    $question = $this->serializer->loadByUuid($uuid);
    if ($question === NULL) {
      return $this->pollNotFound();
    }

    $response = new CacheableJsonResponse([
      'data' => $this->serializer->detail($question, $this->currentUser()),
    ]);
    $cacheability = $this->policy->cacheabilityFor($question);
    $cacheability->addCacheContexts(['user']);
    $cacheability->addCacheTags(
      $this->entityTypeManager()->getDefinition('voting_option')->getListCacheTags()
    );
    $response->addCacheableDependency($cacheability);

    return $response;
  }

}
