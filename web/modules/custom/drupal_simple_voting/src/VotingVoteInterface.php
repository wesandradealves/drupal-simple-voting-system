<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the contract of a single ballot.
 */
interface VotingVoteInterface extends ContentEntityInterface {

  /**
   * The question this ballot answers.
   */
  public function getQuestion(): ?VotingQuestionInterface;

  /**
   * The identifier of the question this ballot answers.
   */
  public function getQuestionId(): ?int;

  /**
   * The chosen option.
   */
  public function getOption(): ?VotingOptionInterface;

  /**
   * The identifier of the chosen option.
   */
  public function getOptionId(): ?int;

  /**
   * The account that cast this ballot.
   */
  public function getVoter(): ?AccountInterface;

  /**
   * Casting timestamp.
   */
  public function getCreatedTime(): int;

}
