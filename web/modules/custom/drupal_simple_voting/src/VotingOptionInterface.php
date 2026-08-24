<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Defines the contract of a poll option.
 */
interface VotingOptionInterface extends ContentEntityInterface {

  /**
   * The question this option belongs to.
   */
  public function getQuestion(): ?VotingQuestionInterface;

  /**
   * The identifier of the question this option belongs to.
   */
  public function getQuestionId(): ?int;

  /**
   * The option wording.
   */
  public function getTitle(): string;

  /**
   * The short description shown under the option wording.
   */
  public function getDescription(): string;

  /**
   * The ordering weight inside the question.
   */
  public function getWeight(): int;

  /**
   * Whether the option carries a thumbnail.
   */
  public function hasImage(): bool;

}
