<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Builds the list of polls.
 *
 * The page and the block both render this, so a poll looks and behaves the
 * same wherever it is placed and the listing rule lives in one place.
 *
 * No tally is read here: it would reveal the result of questions configured to
 * hide it.
 */
final class PollIndex {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly VotingPolicy $policy,
    private readonly BallotBox $ballotBox,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @param int $per_page
   *   How many polls to show before a pager. Zero lists every poll.
   * @param int $pager_element
   *   Which pager on the page this listing owns, so a block and a page can
   *   paginate side by side without stealing each other's query argument.
   */
  public function build(AccountInterface $account, int $per_page = 0, int $pager_element = 0): array {
    $storage = $this->entityTypeManager->getStorage('voting_question');

    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('status', 'DESC')
      ->sort('created', 'DESC');

    if ($per_page > 0) {
      $query->pager($per_page, $pager_element);
    }

    $questions = $storage->loadMultiple($query->execute());

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['vt-polls']],
      '#attached' => ['library' => ['drupal_simple_voting/drupal_simple_voting']],
      '#cache' => [
        'contexts' => ['user', 'url.query_args.pagers:' . $pager_element],
        'tags' => array_merge(
          $this->entityTypeManager->getDefinition('voting_question')->getListCacheTags(),
          $this->entityTypeManager->getDefinition('voting_option')->getListCacheTags(),
          $this->configFactory->get(VotingPolicy::SETTINGS)->getCacheTags(),
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
          'url' => $this->cardUrl($question, $account),
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

    if ($per_page > 0) {
      $build['pager'] = [
        '#type' => 'pager',
        '#element' => $pager_element,
        '#weight' => 100,
      ];
    }

    return $build;
  }

  /**
   * Where a card sends the reader.
   *
   * An anonymous reader goes to the login screen carrying the poll as the
   * destination, so signing in or registering lands them on the question they
   * clicked instead of on the front page.
   */
  private function cardUrl(VotingQuestionInterface $question, AccountInterface $account): string {
    $ballot = $question->toUrl()->toString();

    if (!$account->isAnonymous()) {
      return $ballot;
    }

    return Url::fromRoute('user.login', [], ['query' => ['destination' => $ballot]])->toString();
  }

}
