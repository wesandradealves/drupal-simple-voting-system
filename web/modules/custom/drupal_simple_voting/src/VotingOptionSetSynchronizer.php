<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Owns the set of options that belongs to a poll question.
 *
 * The question form collects the options the editor left on screen; deciding
 * which of them are created, updated or dropped, and writing them, happens
 * only here.
 */
final class VotingOptionSetSynchronizer implements ContainerInjectionInterface {

  use AutowireTrait;
  use DependencySerializationTrait;

  /**
   * The entity type manager.
   *
   * Not readonly: the question form is serialized into the form cache, and
   * DependencySerializationTrait reassigns injected services on wake-up.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * An unsaved option, ready to back an empty row of the question form.
   */
  public function blankOption(): VotingOptionInterface {
    /** @var \Drupal\drupal_simple_voting\VotingOptionInterface $option */
    $option = $this->optionStorage()->create();

    return $option;
  }

  /**
   * The options of a question in the order voters see them.
   *
   * Access-checked: this feeds the public ballot and result screens, where an
   * option is only as visible as the question that owns it.
   *
   * @return \Drupal\drupal_simple_voting\VotingOptionInterface[]
   *   Options in display order, as a list.
   */
  public function orderedForQuestion(VotingQuestionInterface $question): array {
    $storage = $this->optionStorage();
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('question', $question->id())
      ->sort('weight')
      ->sort('id')
      ->execute();

    return array_values(array_filter(
      $storage->loadMultiple($ids),
      static fn ($option): bool => $option instanceof VotingOptionInterface,
    ));
  }

  /**
   * The stored options of a question, keyed by entity ID, in display order.
   *
   * The question form addresses its rows by option ID, so this keeps the keys
   * that the ordered reader drops.
   *
   * @return \Drupal\drupal_simple_voting\VotingOptionInterface[]
   *   Options keyed by entity ID.
   */
  public function loadForQuestion(VotingQuestionInterface $question): array {
    if ($question->isNew()) {
      return [];
    }

    $keyed = [];
    foreach ($this->orderedForQuestion($question) as $option) {
      $keyed[(int) $option->id()] = $option;
    }

    return $keyed;
  }

  /**
   * Makes the stored option set match what the editor submitted.
   *
   * @param \Drupal\drupal_simple_voting\VotingQuestionInterface $question
   *   The saved question the options belong to.
   * @param \Drupal\drupal_simple_voting\VotingOptionInterface[] $submitted
   *   The options the editor left on screen, in display order. New and already
   *   stored options are mixed together; whatever is missing from this list is
   *   deleted.
   */
  public function synchronize(VotingQuestionInterface $question, array $submitted): void {
    $obsolete = $this->loadForQuestion($question);

    $weight = 0;
    foreach ($submitted as $option) {
      if (!$option->isNew()) {
        unset($obsolete[$option->id()]);
      }
      $option->set('question', $question->id());
      $option->set('weight', $weight);
      $option->save();
      $weight++;
    }

    if ($obsolete !== []) {
      // Deleting an option sweeps its ballots through VotingOption::postDelete.
      $this->optionStorage()->delete($obsolete);
    }
  }

  private function optionStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('voting_option');
  }

}
