<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\drupal_simple_voting\Exception\DuplicateVoteException;
use Drupal\drupal_simple_voting\Exception\VotingClosedException;

/**
 * Receives ballots and refuses the ones the ballot box may not hold.
 */
final class BallotBox {

  /**
   * Injects the vote storage, the policy that guards each cast and the audit
   * log that records every outcome.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly VotingPolicy $policy,
    private readonly BallotAudit $audit,
  ) {}

  /**
   * Casts one ballot.
   *
   * @throws \Drupal\drupal_simple_voting\Exception\VotingClosedException
   *   When the policy refuses the elector or the question.
   * @throws \Drupal\drupal_simple_voting\Exception\DuplicateVoteException
   *   When this elector already has a ballot on this question.
   */
  public function cast(VotingQuestionInterface $question, VotingOptionInterface $option, AccountInterface $account): VotingVoteInterface {
    if (!$this->policy->canVote($question, $account)) {
      $this->audit->closedRefused($question, $account);
      throw new VotingClosedException();
    }

    if ($option->getQuestionId() !== (int) $question->id()) {
      throw new \InvalidArgumentException(sprintf('Option %s does not belong to question %s.', (string) $option->id(), (string) $question->id()));
    }

    /** @var \Drupal\drupal_simple_voting\VotingVoteInterface $vote */
    $vote = $this->voteStorage()->create([
      'question' => $question->id(),
      'option' => $option->id(),
      'uid' => $account->id(),
    ]);

    // No "look before you leap" here on purpose. Under concurrency two
    // requests can both read "no ballot yet" and both write, so the only
    // arbiter of one-ballot-per-elector is the unique key on
    // (uid, question): write, and let the database refuse the loser.
    try {
      $vote->save();
    }
    catch (IntegrityConstraintViolationException | EntityStorageException $failure) {
      if (!$this->isDuplicateBallot($failure)) {
        $this->audit->storageFailed($question, $account, $failure);
        throw $failure;
      }
      $this->audit->duplicateRefused($question, $account);
      throw new DuplicateVoteException(previous: $failure);
    }

    $this->audit->ballotCast($vote, $question, $account);

    return $vote;
  }

  /**
   * Whether a failed insert was the unique key refusing a second ballot.
   *
   * SqlContentEntityStorage::save() rolls back and rethrows every driver error
   * as EntityStorageException, so the violation is only reachable down the
   * chain of previous exceptions, never as the exception actually caught.
   */
  private function isDuplicateBallot(\Throwable $failure): bool {
    for ($cause = $failure; $cause !== NULL; $cause = $cause->getPrevious()) {
      if ($cause instanceof IntegrityConstraintViolationException) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * The ballot this elector already cast on this question, if any.
   */
  public function findVote(VotingQuestionInterface $question, AccountInterface $account): ?VotingVoteInterface {
    if ($account->isAnonymous()) {
      return NULL;
    }

    $votes = $this->voteStorage()->loadByProperties([
      'question' => $question->id(),
      'uid' => $account->id(),
    ]);

    $vote = reset($votes);

    return $vote instanceof VotingVoteInterface ? $vote : NULL;
  }

  /**
   * Which of these questions this elector has already cast a ballot on.
   *
   * One aggregate query for the whole set, so a listing folds in the ballots
   * it already holds in memory instead of asking the database once per poll.
   *
   * @param int[] $question_ids
   *   The questions to look up.
   *
   * @return array<int, true>
   *   A set keyed by the ids of the questions that carry a ballot from this
   *   elector; questions without one are absent.
   */
  public function votedQuestionIds(array $question_ids, AccountInterface $account): array {
    if ($account->isAnonymous() || $question_ids === []) {
      return [];
    }

    $query = $this->voteStorage()->getAggregateQuery();
    $query->accessCheck(FALSE);
    $query->condition('uid', $account->id());
    $query->condition('question', $question_ids, 'IN');
    $query->groupBy('question');

    $voted = [];
    foreach ($query->execute() as $row) {
      $voted[(int) $row['question']] = TRUE;
    }

    return $voted;
  }

  /**
   * The storage handler for voting_vote entities.
   */
  private function voteStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('voting_vote');
  }

}
