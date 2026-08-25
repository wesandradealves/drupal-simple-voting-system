<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the polls at /admin/content/polls.
 */
final class VotingQuestionListBuilder extends EntityListBuilder {

  /**
   * Option count keyed by question ID, filled once per page by ::load().
   *
   * @var array<int, int>
   */
  private array $optionCounts = [];

  /**
   * Ballot count keyed by question ID, filled once per page by ::load().
   *
   * @var array<int, int>
   */
  private array $voteCounts = [];

  /**
   * Keeps the entity type manager for the aggregate count queries and their
   * cache tags.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   *
   * Resolves the question storage and the entity type manager from the
   * container.
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Adds the Open, Options and Votes columns before the default operations.
   */
  public function buildHeader(): array {
    $header['title'] = $this->t('Poll');
    $header['open'] = $this->t('Open');
    $header['options'] = $this->t('Options');
    $header['votes'] = $this->t('Votes');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   *
   * Renders one poll row, reading the option and vote counts that ::load()
   * prepared instead of querying per row.
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof VotingQuestionInterface);

    $row['title'] = $entity->hasLinkTemplate('canonical')
      ? ['data' => $entity->toLink()->toRenderable()]
      : $entity->label();
    $row['open'] = $entity->isOpen() ? $this->t('Yes') : $this->t('No');
    $row['options'] = $this->optionCounts[(int) $entity->id()] ?? 0;
    $row['votes'] = $this->voteCounts[(int) $entity->id()] ?? 0;

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   *
   * Loads the current page of questions, then fills the option and vote counts
   * with one aggregate query each so ::buildRow() needs no per-row lookups.
   */
  public function load(): array {
    $questions = parent::load();
    $question_ids = array_keys($questions);

    $this->optionCounts = $this->countByQuestion('voting_option', $question_ids);
    $this->voteCounts = $this->countByQuestion('voting_vote', $question_ids);

    return $questions;
  }

  /**
   * {@inheritdoc}
   *
   * Merges the option and vote list cache tags so the table rebuilds when a
   * child of either type changes.
   */
  public function render(): array {
    $build = parent::render();

    // The counts come from two other entity types, so the listing has to fall
    // out of cache when an option or a ballot changes.
    foreach (['voting_option', 'voting_vote'] as $entity_type_id) {
      $build['table']['#cache']['tags'] = Cache::mergeTags(
        $build['table']['#cache']['tags'],
        $this->entityTypeManager->getDefinition($entity_type_id)->getListCacheTags(),
      );
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   *
   * Orders the newest polls first and applies the pager when a limit is set.
   */
  protected function getEntityListQuery(): QueryInterface {
    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('created', 'DESC')
      ->sort('id', 'DESC');

    if ($this->limit) {
      $query->pager($this->limit);
    }

    return $query;
  }

  /**
   * Counts child rows per question with a single aggregate query.
   *
   * voting.tally answers for one question at a time, which on a listing of
   * fifty polls would be fifty queries; grouping over the whole page keeps it
   * at one, still served by the voting_vote__tally index.
   *
   * @param string $entity_type_id
   *   The child entity type, which must carry a 'question' reference.
   * @param int[] $question_ids
   *   The questions shown on the current page.
   *
   * @return array<int, int>
   *   Row count keyed by question ID; questions without children are absent.
   */
  private function countByQuestion(string $entity_type_id, array $question_ids): array {
    if ($question_ids === []) {
      return [];
    }

    $total = 'total';
    $query = $this->entityTypeManager->getStorage($entity_type_id)->getAggregateQuery();
    // The route already demands 'administer polls', and an aggregate never
    // discloses an individual row, so per-row access checks would only add
    // joins to a count.
    $query->accessCheck(FALSE);
    $query->condition('question', $question_ids, 'IN');
    $query->groupBy('question');
    $query->aggregate('id', 'COUNT', NULL, $total);
    $rows = $query->execute();

    $counts = [];
    foreach ($rows as $row) {
      $counts[(int) $row['question']] = (int) $row[$total];
    }

    return $counts;
  }

}
