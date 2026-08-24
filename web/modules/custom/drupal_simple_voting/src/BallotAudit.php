<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Records what happened to every ballot, on the module logger channel.
 */
final class BallotAudit {

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  public function ballotCast(VotingVoteInterface $vote, VotingQuestionInterface $question, AccountInterface $account): void {
    $this->logger->info('Ballot @ballot cast on question @question (@title) by user @uid for option @option.', [
      '@ballot' => $vote->uuid(),
      '@question' => $question->id(),
      '@title' => $question->label(),
      '@uid' => $account->id(),
      '@option' => $vote->getOptionId(),
    ]);
  }

  public function duplicateRefused(VotingQuestionInterface $question, AccountInterface $account): void {
    $this->logger->warning('Duplicate ballot refused on question @question by user @uid. The unique key held.', [
      '@question' => $question->id(),
      '@uid' => $account->id(),
    ]);
  }

  public function closedRefused(VotingQuestionInterface $question, AccountInterface $account): void {
    $this->logger->warning('Ballot refused on closed question @question for user @uid.', [
      '@question' => $question->id(),
      '@uid' => $account->id(),
    ]);
  }

  public function storageFailed(VotingQuestionInterface $question, AccountInterface $account, \Throwable $failure): void {
    $this->logger->error('Ballot storage failed on question @question for user @uid: @class @message', [
      '@question' => $question->id(),
      '@uid' => $account->id(),
      '@class' => $failure::class,
      '@message' => $failure->getMessage(),
    ]);
  }

}
