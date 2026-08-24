<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Exception;

/**
 * Thrown when a ballot is refused because voting is not open to the elector.
 */
final class VotingClosedException extends \RuntimeException {

  public function __construct(string $message = 'Voting is closed for this question.', int $code = 0, ?\Throwable $previous = NULL) {
    parent::__construct($message, $code, $previous);
  }

}
