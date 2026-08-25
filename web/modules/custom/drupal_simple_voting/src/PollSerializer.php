<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\drupal_simple_voting\BallotBox;
use Drupal\drupal_simple_voting\VoteTally;
use Drupal\drupal_simple_voting\VotingOptionInterface;
use Drupal\drupal_simple_voting\VotingOptionSetSynchronizer;
use Drupal\drupal_simple_voting\VotingPolicy;
use Drupal\drupal_simple_voting\VotingQuestionInterface;

/**
 * Translates the domain into the shape that goes out as JSON.
 *
 * The business rule does not live here: drupal_simple_voting.policy still
 * decides whether the tally may be revealed, the same service the CMS asks.
 */
final class PollSerializer {

  /**
   * Injects the storage, tally, policy and ballot box the serialiser reads,
   * and the file URL generator that turns option images into absolute URLs.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly VoteTally $tally,
    private readonly VotingPolicy $policy,
    private readonly BallotBox $ballotBox,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly VotingOptionSetSynchronizer $optionSet,
  ) {}

  /**
   * The public shape of one question, without its options.
   *
   * @param bool|null $has_voted
   *   Whether this elector already voted, when a caller serialising a whole
   *   listing has already read it in one query. Left null, a single-question
   *   caller has it read here.
   *
   * @return array<string, mixed>
   */
  public function summary(VotingQuestionInterface $question, AccountInterface $account, ?bool $has_voted = NULL): array {
    return [
      'id' => $question->uuid(),
      'title' => $question->getTitle(),
      'description' => (string) $question->getDescription(),
      'open' => $this->policy->isOpen($question),
      'reveals_totals' => $question->showsResults(),
      'has_voted' => $has_voted ?? ($this->ballotBox->findVote($question, $account) !== NULL),
      'created' => $question->getCreatedTime(),
    ];
  }

  /**
   * Summaries for a set of questions, reading their ballots in one query.
   *
   * @param array<int, \Drupal\drupal_simple_voting\VotingQuestionInterface> $questions
   *   The questions to serialise, in the order they should appear.
   *
   * @return array<int, array<string, mixed>>
   */
  public function collection(array $questions, AccountInterface $account): array {
    $ids = array_map(static fn (VotingQuestionInterface $question): int => (int) $question->id(), $questions);
    $voted = $this->ballotBox->votedQuestionIds($ids, $account);

    $summaries = [];
    foreach ($questions as $question) {
      $summaries[] = $this->summary($question, $account, isset($voted[(int) $question->id()]));
    }

    return $summaries;
  }

  /**
   * The question with its options, for the ballot screen.
   *
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
   * The result payload: every option with the elector's own pick marked, and
   * the tally folded in only when the policy allows revealing it.
   *
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
   * The question's options in display order.
   *
   * @return array<int, \Drupal\drupal_simple_voting\VotingOptionInterface>
   */
  public function options(VotingQuestionInterface $question): array {
    return $this->optionSet->orderedForQuestion($question);
  }

  /**
   * The question carrying this UUID, or null when none does.
   *
   * The API addresses questions by their UUID, never by the sequential id.
   */
  public function loadByUuid(string $uuid): ?VotingQuestionInterface {
    $storage = $this->entityTypeManager->getStorage('voting_question');
    $found = $storage->loadByProperties(['uuid' => $uuid]);
    $question = reset($found);

    return $question instanceof VotingQuestionInterface ? $question : NULL;
  }

  /**
   * The option carrying this UUID, or null when none does.
   */
  public function loadOptionByUuid(string $uuid): ?VotingOptionInterface {
    $storage = $this->entityTypeManager->getStorage('voting_option');
    $found = $storage->loadByProperties(['uuid' => $uuid]);
    $option = reset($found);

    return $option instanceof VotingOptionInterface ? $option : NULL;
  }

  /**
   * The public shape of one option.
   *
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

  /**
   * The absolute URL of an option's image, or null when it has none.
   */
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
