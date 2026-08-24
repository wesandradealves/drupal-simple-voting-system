<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\drupal_simple_voting\BallotBox;
use Drupal\drupal_simple_voting\BallotNotice;
use Drupal\drupal_simple_voting\PollIndex;
use Drupal\drupal_simple_voting\Form\BallotForm;
use Drupal\drupal_simple_voting\VoteTally;
use Drupal\drupal_simple_voting\VotingOptionInterface;
use Drupal\drupal_simple_voting\VotingOptionSetSynchronizer;
use Drupal\drupal_simple_voting\VotingPolicy;
use Drupal\drupal_simple_voting\VotingQuestionInterface;

/**
 * Public page for one question: the ballot or the result.
 */
final class VotingController extends ControllerBase {

  use AutowireTrait;

  public function __construct(
    private readonly VotingPolicy $policy,
    private readonly VoteTally $tally,
    private readonly BallotBox $ballotBox,
    private readonly PollIndex $pollIndex,
    private readonly VotingOptionSetSynchronizer $optionSet,
  ) {}

  public function title(VotingQuestionInterface $voting_question): string {
    return $voting_question->getTitle();
  }

  /**
   * Public index of the questions.
   */
  public function index(): array {
    return $this->pollIndex->build($this->currentUser());
  }

  /**
   * Canonical route of the question.
   *
   * drupal_simple_voting.policy decides between ballot and result. Twig never
   * has an opinion on a business rule.
   */
  public function view(VotingQuestionInterface $voting_question): array {
    $account = $this->currentUser();
    $options = $this->optionRows($voting_question);
    $vote = $this->ballotBox->findVote($voting_question, $account);

    // The ballot comes first while this elector may still vote: an account
    // holding 'view poll results' that has not voted yet must not land on the
    // result, or seeing the tally would take away the right to cast a ballot.
    $pending = $vote === NULL && $this->policy->canVote($voting_question, $account);

    $build = [
      'poll' => !$pending && $this->policy->showsResults($voting_question, $account)
        ? $this->buildResults($voting_question, $options, $vote?->getOptionId())
        : $this->formBuilder()->getForm(BallotForm::class, $voting_question, $options),
    ];

    $cacheability = $this->policy->cacheabilityFor($voting_question);
    $cacheability->addCacheTags(
      $this->entityTypeManager()->getDefinition('voting_option')->getListCacheTags()
    );
    $cacheability->applyTo($build);

    return $build;
  }

  /**
   * What the result screen announces.
   *
   * The result is also what a reader who never voted sees once a poll closes,
   * so the announcement has to describe that situation instead of claiming a
   * ballot they never cast.
   *
   * @return array<string, string>
   */
  private function resultNotice(VotingQuestionInterface $question, ?int $chosen): array {
    if ($chosen !== NULL) {
      return [
        'state' => 'voted',
        'message' => (string) BallotNotice::recorded(),
      ];
    }

    if (!$this->policy->isOpen($question)) {
      return [
        'state' => 'closed',
        'message' => (string) BallotNotice::closed(),
      ];
    }

    return [
      'state' => 'open',
      'message' => (string) BallotNotice::notAllowed(),
    ];
  }

  /**
   * @param array<int, array<string, mixed>> $options
   */
  private function buildResults(VotingQuestionInterface $question, array $options, ?int $chosen): array {
    $counts = $this->tally->countsFor($question);
    // Sum the counts already fetched; a second aggregate query for the total
    // would only repeat this work.
    $total = array_sum($counts);

    $shows_totals = $question->showsResults();

    $build = [
      '#type' => 'component',
      '#component' => 'drupal_simple_voting:ballot',
      '#props' => [
        'question_description' => (string) $question->getDescription(),
        'total_votes' => $total,
        'show_totals' => $shows_totals,
      ],
      '#attached' => ['library' => ['drupal_simple_voting/drupal_simple_voting']],
    ];

    $build['status'] = [
      '#type' => 'component',
      '#component' => 'drupal_simple_voting:vote-status',
      '#props' => $this->resultNotice($question, $chosen),
    ];

    $build['options'] = ['#type' => 'container'];
    foreach ($options as $option) {
      $count = $counts[(int) $option['key']] ?? 0;
      $share = $total > 0 ? ($count / $total) * 100 : 0.0;
      $build['options'][$option['key']] = [
        '#type' => 'component',
        '#component' => 'drupal_simple_voting:ballot-option',
        '#props' => [
          'input_id' => $option['input_id'],
          'title' => $option['title'],
          'description' => (string) $option['description'],
          'image' => $option['image'],
          'percent' => round($share, 2),
          'votes' => $count,
          'answered' => TRUE,
          'chosen' => $chosen !== NULL && $chosen === (int) $option['key'],
          'show_totals' => $shows_totals,
        ],
      ];
    }

    return $build;
  }

  /**
   * The question options in display order, ready for Twig.
   *
   * @return array<int, array<string, mixed>>
   */
  private function optionRows(VotingQuestionInterface $question): array {
    $rows = [];
    foreach ($this->optionSet->orderedForQuestion($question) as $option) {
      $key = (string) $option->id();
      $rows[] = [
        'key' => $key,
        'input_id' => Html::getUniqueId('voting-option-' . $key),
        'title' => $option->getTitle(),
        'description' => $option->getDescription(),
        'image' => $this->optionImage($option),
      ];
    }

    return $rows;
  }

  private function optionImage(VotingOptionInterface $option): ?array {
    if (!$option->hasImage()) {
      return NULL;
    }

    $item = $option->get('image')->first();
    $file = $item?->entity;
    if ($file === NULL) {
      return NULL;
    }

    return [
      '#theme' => 'image',
      '#uri' => $file->getFileUri(),
      '#alt' => '',
      '#width' => $item->width,
      '#height' => $item->height,
      '#attributes' => [
        'class' => ['vt-option__media-object'],
        'loading' => 'lazy',
      ],
      '#cache' => ['tags' => $file->getCacheTags()],
    ];
  }


}
