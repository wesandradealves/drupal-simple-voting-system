<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Exception;

/**
 * Thrown when a ballot is refused because the elector already voted.
 *
 * Raised only by the database rejecting the insert against the
 * voting_vote__user_question unique key, never by an application-level read.
 */
final class DuplicateVoteException extends \RuntimeException {

  /**
   * Builds the exception with a default message, overridable by the caller.
   */
  public function __construct(string $message = 'This elector has already voted on this question.', int $code = 0, ?\Throwable $previous = NULL) {
    parent::__construct($message, $code, $previous);
  }

}
