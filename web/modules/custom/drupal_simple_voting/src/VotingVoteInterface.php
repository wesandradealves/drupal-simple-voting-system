<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Defines the contract of a single ballot.
 */
interface VotingVoteInterface extends ContentEntityInterface {

  /**
   * The identifier of the chosen option.
   */
  public function getOptionId(): ?int;

}
