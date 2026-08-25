<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RequestStack;

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

  /**
   * Query argument that narrows the listing by state.
   */
  private const STATUS_ARGUMENT = 'status';

  /**
   * Query argument that flips the ordering.
   */
  private const SORT_ARGUMENT = 'sort';

  private const STATUSES = ['all', 'open', 'closed'];

  private const SORTS = ['newest' => 'DESC', 'oldest' => 'ASC'];

  /**
   * Injects the storage, policy and ballot box the listing reads from, plus the
   * request stack that carries the reader's filter and sort choices.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly VotingPolicy $policy,
    private readonly BallotBox $ballotBox,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Builds the render array for the poll listing: the filter control, one card
   * per question, an empty state, and an optional pager.
   *
   * @param int $per_page
   *   How many polls to show before a pager. Zero lists every poll.
   * @param int $pager_element
   *   Which pager on the page this listing owns, so a block and a page can
   *   paginate side by side without stealing each other's query argument.
   */
  public function build(AccountInterface $account, int $per_page = 0, int $pager_element = 0): array {
    $storage = $this->entityTypeManager->getStorage('voting_question');

    $status = $this->selectedStatus();
    $sort = $this->selectedSort();

    // Ordering is the reader's choice alone. Putting open polls first would
    // silently outrank the direction they picked, and the status control is
    // now the explicit way to ask for them.
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('created', self::SORTS[$sort])
      // Two polls created in the same second would otherwise come back in
      // whatever order the database felt like, and the direction would look
      // broken to the reader who just flipped it.
      ->sort('id', self::SORTS[$sort]);

    if ($status !== 'all') {
      $query->condition('status', $status === 'open' ? 1 : 0);
    }

    if ($per_page > 0) {
      $query->pager($per_page, $pager_element);
    }

    $questions = $storage->loadMultiple($query->execute());
    $voted = $this->ballotBox->votedQuestionIds(array_keys($questions), $account);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['vt-polls']],
      '#attached' => ['library' => ['drupal_simple_voting/drupal_simple_voting']],
      '#cache' => [
        'contexts' => [
          'user',
          'url.query_args:' . self::STATUS_ARGUMENT,
          'url.query_args:' . self::SORT_ARGUMENT,
          'url.query_args.pagers:' . $pager_element,
        ],
        'tags' => array_merge(
          $this->entityTypeManager->getDefinition('voting_question')->getListCacheTags(),
          $this->entityTypeManager->getDefinition('voting_option')->getListCacheTags(),
          $this->configFactory->get(VotingPolicy::SETTINGS)->getCacheTags(),
        ),
      ],
    ];

    $build['filter'] = [
      '#type' => 'component',
      '#component' => 'drupal_simple_voting:poll-filter',
      '#props' => [
        'action' => $this->listingPath(),
        'status' => $status,
        'sort' => $sort,
      ],
      '#weight' => -100,
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
          'voted' => isset($voted[(int) $question->id()]),
        ],
      ];
    }

    if ($questions === []) {
      $build['empty'] = [
        '#type' => 'component',
        '#component' => 'drupal_simple_voting:vote-status',
        '#props' => [
          'state' => 'empty',
          'message' => $status === 'all'
            ? (string) $this->t('There are no polls yet.')
            : (string) $this->t('No poll matches this filter.'),
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
   * The status the reader asked for, or every poll when they asked for nothing.
   */
  private function selectedStatus(): string {
    $value = (string) $this->currentQuery()->get(self::STATUS_ARGUMENT, 'all');

    return in_array($value, self::STATUSES, TRUE) ? $value : 'all';
  }

  /**
   * The ordering the reader asked for, newest first by default.
   */
  private function selectedSort(): string {
    $value = (string) $this->currentQuery()->get(self::SORT_ARGUMENT, 'newest');

    return isset(self::SORTS[$value]) ? $value : 'newest';
  }

  /**
   * The query bag of the current request, empty when there is no request.
   */
  private function currentQuery(): InputBag {
    $request = $this->requestStack->getCurrentRequest();

    return $request?->query ?? new InputBag();
  }

  /**
   * Where the filter form submits.
   *
   * It posts back to the page being read, so the block keeps filtering in
   * place wherever it was put instead of dragging the reader to /polls.
   */
  private function listingPath(): string {
    $request = $this->requestStack->getCurrentRequest();

    return $request === NULL ? '' : $request->getPathInfo();
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
