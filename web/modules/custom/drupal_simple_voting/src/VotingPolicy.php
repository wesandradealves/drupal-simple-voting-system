<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Decides who may vote and who may see the outcome.
 *
 * Holds every rule about openness and visibility, so that BallotBox only
 * writes and VoteTally only counts.
 */
final class VotingPolicy {

  public const SETTINGS = 'drupal_simple_voting.settings';

  public const VOTE_PERMISSION = 'vote in polls';

  public const ADMINISTER_PERMISSION = 'administer polls';

  public const VIEW_RESULTS_PERMISSION = 'view poll results';

  /**
   * Invalidated whenever any ballot is cast, saved or deleted.
   */
  private const VOTE_LIST_CACHE_TAG = 'voting_vote_list';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Whether the question accepts ballots at all.
   */
  public function isOpen(VotingQuestionInterface $question): bool {
    return $this->isVotingEnabled() && $question->isOpen();
  }

  /**
   * Whether this elector is allowed to cast a ballot on this question.
   *
   * Deliberately silent about ballots already cast: the unique key on
   * (uid, question) settles that, and a read here would only lose the race.
   */
  public function canVote(VotingQuestionInterface $question, AccountInterface $account): bool {
    // Every anonymous visitor carries uid 0, so the unique key would collapse
    // them into a single ballot per question.
    if ($account->isAnonymous()) {
      return FALSE;
    }

    return $this->isOpen($question) && $account->hasPermission(self::VOTE_PERMISSION);
  }

  /**
   * Whether this elector may see the tally of this question.
   */
  public function showsResults(VotingQuestionInterface $question, AccountInterface $account): bool {
    if (!$question->showsResults()) {
      return FALSE;
    }

    return $account->hasPermission(self::VIEW_RESULTS_PERMISSION)
      || $this->hasVoted($question, $account);
  }

  /**
   * Cacheability of every decision this policy makes about a question.
   *
   * Callers that render a decision must fold this into their render array,
   * otherwise a closed kill switch or a freshly cast ballot keeps serving the
   * previous markup.
   */
  public function cacheabilityFor(VotingQuestionInterface $question): CacheableMetadata {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($question);
    $cacheability->addCacheableDependency($this->configFactory->get(self::SETTINGS));
    $cacheability->addCacheContexts(['user', 'user.permissions']);
    $cacheability->addCacheTags([self::VOTE_LIST_CACHE_TAG]);

    return $cacheability;
  }

  /**
   * The global kill switch, honouring configuration overrides.
   */
  private function isVotingEnabled(): bool {
    return (bool) $this->configFactory->get(self::SETTINGS)->get('enabled');
  }

  /**
   * Existence read, safe here because it only reveals, never guarantees.
   */
  private function hasVoted(VotingQuestionInterface $question, AccountInterface $account): bool {
    if ($account->isAnonymous()) {
      return FALSE;
    }

    $found = $this->entityTypeManager->getStorage('voting_vote')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('question', $question->id())
      ->condition('uid', $account->id())
      ->count()
      ->execute();

    return (int) $found > 0;
  }

}
