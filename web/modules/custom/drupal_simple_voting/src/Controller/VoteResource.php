<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\drupal_simple_voting\BallotBox;
use Drupal\drupal_simple_voting\Exception\DuplicateVoteException;
use Drupal\drupal_simple_voting\Exception\VotingClosedException;
use Drupal\drupal_simple_voting\PollSerializer;
use Drupal\drupal_simple_voting\VotingPolicy;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Ballot registration.
 *
 * The rule is not reimplemented here: drupal_simple_voting.ballot_box writes
 * the ballot, the same service the CMS form uses. That is why the API cannot
 * drift from the site.
 */
final class VoteResource extends ControllerBase {

  public function __construct(
    private readonly PollSerializer $serializer,
    private readonly BallotBox $ballotBox,
    private readonly VotingPolicy $policy,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('drupal_simple_voting.serializer'),
      $container->get('drupal_simple_voting.ballot_box'),
      $container->get('drupal_simple_voting.policy'),
    );
  }

  public function cast(Request $request, string $uuid): JsonResponse {
    $question = $this->serializer->loadByUuid($uuid);
    if ($question === NULL) {
      return new JsonResponse(['error' => 'Poll not found.'], 404);
    }

    // Authorisation before input: a closed poll refuses every body, and
    // answering 422 about the option first would describe the wrong problem.
    // The reading comes from the same policy service the site consults.
    if (!$this->policy->canVote($question, $this->currentUser())) {
      return new JsonResponse(['error' => 'This poll is closed.'], 403);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload) || !isset($payload['option_id']) || !is_string($payload['option_id'])) {
      return new JsonResponse(['error' => 'Send a JSON body with a string option_id.'], 400);
    }

    $option = $this->serializer->loadOptionByUuid($payload['option_id']);
    if ($option === NULL || (int) $option->get('question')->target_id !== (int) $question->id()) {
      return new JsonResponse(['error' => 'That option does not belong to this poll.'], 422);
    }

    try {
      $vote = $this->ballotBox->cast($question, $option, $this->currentUser());
    }
    catch (DuplicateVoteException) {
      return new JsonResponse(['error' => 'You have already voted in this poll.'], 409);
    }
    catch (VotingClosedException) {
      return new JsonResponse(['error' => 'This poll is closed.'], 403);
    }

    return new JsonResponse([
      'data' => [
        'id' => $vote->uuid(),
        'poll' => $question->uuid(),
        'option' => $option->uuid(),
        'results' => $this->serializer->results($question, $this->currentUser()),
      ],
    ], 201);
  }

}
