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

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The cache tag carrying one question's tally.
   *
   * Every write that changes a question's count invalidates this tag alone, so
   * a ballot in one poll never clears the cache of another. Built here because
   * the policy owns what the tag means; BallotBox and the result endpoint ask
   * for it instead of spelling the string out again.
   */
  public static function resultCacheTag(int|string $question_id): string {
    return 'voting_result:' . $question_id;
  }

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
    $cacheability->addCacheTags([self::resultCacheTag($question->id())]);

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
