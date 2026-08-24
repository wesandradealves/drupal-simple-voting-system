<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Defines the contract of a poll question.
 */
interface VotingQuestionInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * Whether the question still accepts votes.
   */
  public function isOpen(): bool;

  /**
   * Whether the tally may be disclosed to whoever already voted.
   */
  public function showsResults(): bool;

  /**
   * The question wording.
   */
  public function getTitle(): string;

  /**
   * The optional supporting text.
   */
  public function getDescription(): string;

  /**
   * Creation timestamp.
   */
  public function getCreatedTime(): int;

}
