<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\drupal_simple_voting\BallotBox;
use Drupal\drupal_simple_voting\VoteTally;
use Drupal\drupal_simple_voting\VotingOptionInterface;
use Drupal\drupal_simple_voting\VotingPolicy;
use Drupal\drupal_simple_voting\VotingQuestionInterface;

/**
 * Translates the domain into the shape that goes out as JSON.
 *
 * The business rule does not live here: drupal_simple_voting.policy still
 * decides whether the tally may be revealed, the same service the CMS asks.
 */
final class PollSerializer {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly VoteTally $tally,
    private readonly VotingPolicy $policy,
    private readonly BallotBox $ballotBox,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function summary(VotingQuestionInterface $question, AccountInterface $account): array {
    return [
      'id' => $question->uuid(),
      'title' => $question->getTitle(),
      'description' => (string) $question->getDescription(),
      'open' => $this->policy->isOpen($question),
      'reveals_totals' => $question->showsResults(),
      'has_voted' => $this->ballotBox->findVote($question, $account) !== NULL,
      'created' => (int) $question->get('created')->value,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function detail(VotingQuestionInterface $question, AccountInterface $account): array {
    $options = [];
    foreach ($this->options($question) as $option) {
      $options[] = $this->option($option);
    }

    return $this->summary($question, $account) + ['options' => $options];
  }

  /**
   * @return array<string, mixed>
   */
  public function results(VotingQuestionInterface $question, AccountInterface $account): array {
    $counts = $this->tally->countsFor($question);
    $total = array_sum($counts);
    $vote = $this->ballotBox->findVote($question, $account);
    $reveal = $this->policy->showsResults($question, $account);

    $rows = [];
    foreach ($this->options($question) as $option) {
      $row = $this->option($option) + [
        'is_your_vote' => $vote !== NULL && $vote->getOptionId() === (int) $option->id(),
      ];
      // The tally enters the response only when the policy allows it. Hiding
      // it on the client would not do: the API is as public a door as the page.
      if ($reveal) {
        $count = $counts[(int) $option->id()] ?? 0;
        $row['votes'] = $count;
        $row['share'] = $total > 0 ? round(($count / $total) * 100, 2) : 0.0;
      }
      $rows[] = $row;
    }

    $payload = [
      'poll' => $this->summary($question, $account),
      'reveals_totals' => $reveal,
      'options' => $rows,
    ];
    if ($reveal) {
      $payload['total_votes'] = $total;
    }

    return $payload;
  }

  /**
   * @return array<int, \Drupal\drupal_simple_voting\VotingOptionInterface>
   */
  public function options(VotingQuestionInterface $question): array {
    $storage = $this->entityTypeManager->getStorage('voting_option');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('question', $question->id())
      ->sort('weight')
      ->sort('id')
      ->execute();

    return array_values(array_filter(
      $storage->loadMultiple($ids),
      static fn ($o): bool => $o instanceof VotingOptionInterface,
    ));
  }

  public function loadByUuid(string $uuid): ?VotingQuestionInterface {
    $storage = $this->entityTypeManager->getStorage('voting_question');
    $found = $storage->loadByProperties(['uuid' => $uuid]);
    $question = reset($found);

    return $question instanceof VotingQuestionInterface ? $question : NULL;
  }

  public function loadOptionByUuid(string $uuid): ?VotingOptionInterface {
    $storage = $this->entityTypeManager->getStorage('voting_option');
    $found = $storage->loadByProperties(['uuid' => $uuid]);
    $option = reset($found);

    return $option instanceof VotingOptionInterface ? $option : NULL;
  }

  /**
   * @return array<string, mixed>
   */
  private function option(VotingOptionInterface $option): array {
    return [
      'id' => $option->uuid(),
      'title' => $option->getTitle(),
      'description' => (string) $option->getDescription(),
      'image' => $this->imageUrl($option),
    ];
  }

  private function imageUrl(VotingOptionInterface $option): ?string {
    if (!$option->hasImage()) {
      return NULL;
    }
    $file = $option->get('image')->first()?->entity;

    return $file === NULL
      ? NULL
      : $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
  }

}
