<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\drupal_simple_voting\BallotBox;
use Drupal\drupal_simple_voting\Form\BallotForm;
use Drupal\drupal_simple_voting\VoteTally;
use Drupal\drupal_simple_voting\VotingOptionInterface;
use Drupal\drupal_simple_voting\VotingPolicy;
use Drupal\drupal_simple_voting\VotingQuestionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public page for one question: the ballot or the result.
 */
final class VotingController extends ControllerBase {

  public function __construct(
    private readonly VotingPolicy $policy,
    private readonly VoteTally $tally,
    private readonly BallotBox $ballotBox,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('drupal_simple_voting.policy'),
      $container->get('drupal_simple_voting.tally'),
      $container->get('drupal_simple_voting.ballot_box'),
    );
  }

  public function title(VotingQuestionInterface $voting_question): string {
    return $voting_question->getTitle();
  }

  /**
   * Public index of the questions.
   *
   * No tally here: it would reveal the result of questions configured to
   * hide it.
   */
  public function index(): array {
    $storage = $this->entityTypeManager()->getStorage('voting_question');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('status', 'DESC')
      ->sort('created', 'DESC')
      ->execute();

    $questions = $storage->loadMultiple($ids);
    $account = $this->currentUser();

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['vt-polls']],
      '#attached' => ['library' => ['drupal_simple_voting/drupal_simple_voting']],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => array_merge(
          $this->entityTypeManager()->getDefinition('voting_question')->getListCacheTags(),
          $this->entityTypeManager()->getDefinition('voting_option')->getListCacheTags(),
          $this->config(VotingPolicy::SETTINGS)->getCacheTags(),
        ),
      ],
    ];

    foreach ($questions as $question) {
      if (!$question instanceof VotingQuestionInterface) {
        continue;
      }
      $build[$question->id()] = [
        '#type' => 'component',
        '#component' => 'drupal_simple_voting:poll-card',
        '#props' => [
          'title' => $question->getTitle(),
          'description' => (string) $question->getDescription(),
          'url' => $this->cardUrl($question),
          'open' => $this->policy->isOpen($question),
          'voted' => $this->ballotBox->findVote($question, $account) !== NULL,
        ],
      ];
    }

    if ($questions === []) {
      $build['empty'] = [
        '#type' => 'component',
        '#component' => 'drupal_simple_voting:vote-status',
        '#props' => [
          'state' => 'empty',
          'message' => (string) $this->t('There are no polls yet.'),
        ],
      ];
    }

    return $build;
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
   * @param array<int, array<string, mixed>> $options
   */
  private function buildResults(VotingQuestionInterface $question, array $options, ?int $chosen): array {
    $counts = $this->tally->countsFor($question);
    // totalFor() would repeat the same aggregate: the total is the sum above.
    $total = array_sum($counts);

    $shows_totals = $question->showsResults();

    $build = [
      '#type' => 'component',
      '#component' => 'drupal_simple_voting:ballot',
      '#props' => [
        'question_title' => $question->getTitle(),
        'question_description' => (string) $question->getDescription(),
        'heading_level' => 'h1',
        'total_votes' => $total,
        'show_totals' => $shows_totals,
      ],
      '#attached' => ['library' => ['drupal_simple_voting/drupal_simple_voting']],
    ];

    $build['status'] = [
      '#type' => 'component',
      '#component' => 'drupal_simple_voting:vote-status',
      '#props' => [
        'state' => 'voted',
        'message' => (string) $this->t('Your vote was recorded.'),
      ],
    ];

    $build['options'] = ['#type' => 'container'];
    foreach ($options as $option) {
      $count = $counts[(int) $option['key']] ?? 0;
      $share = $total > 0 ? ($count / $total) * 100 : 0.0;
      $build['options'][$option['key']] = [
        '#type' => 'component',
        '#component' => 'drupal_simple_voting:ballot-option',
        '#props' => [
          'option_id' => (string) $option['key'],
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
    $storage = $this->entityTypeManager()->getStorage('voting_option');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('question', $question->id())
      ->sort('weight')
      ->sort('id')
      ->execute();

    $rows = [];
    foreach ($storage->loadMultiple($ids) as $option) {
      if (!$option instanceof VotingOptionInterface) {
        continue;
      }
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


  /**
   * Where a card sends the reader.
   *
   * An anonymous reader is sent to the login screen carrying the poll as the
   * destination, so signing in or registering lands them on the question they
   * clicked instead of on the front page.
   */
  private function cardUrl(VotingQuestionInterface $question): string {
    $ballot = $question->toUrl()->toString();

    if (!$this->currentUser()->isAnonymous()) {
      return $ballot;
    }

    return Url::fromRoute('user.login', [], ['query' => ['destination' => $ballot]])->toString();
  }

}
