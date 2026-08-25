<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\Database\Connection;

/**
 * Counts the ballots held for a question.
 */
final class VoteTally {

  /**
   * Injects the database connection the tally query runs on.
   */
  public function __construct(private readonly Connection $database) {}

  /**
   * Ballots per option, keyed by option id.
   *
   * One aggregate query, served by the voting_vote__tally index on
   * (question, option). Options nobody chose are absent from the result.
   *
   * @return array<int, int>
   *   Ballot count keyed by option id.
   */
  public function countsFor(VotingQuestionInterface $question): array {
    $query = $this->database->select('voting_vote', 'v');
    $query->fields('v', ['option']);
    $query->condition('v.question', $question->id());
    $query->addExpression('COUNT(*)', 'total');
    $query->groupBy('v.option');

    $counts = [];
    foreach ($query->execute() as $row) {
      $counts[(int) $row->option] = (int) $row->total;
    }

    return $counts;
  }

}
