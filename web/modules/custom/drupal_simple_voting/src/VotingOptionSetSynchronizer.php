<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Owns the set of options that belongs to a poll question.
 *
 * The question form collects the options the editor left on screen; deciding
 * which of them are created, updated or dropped, and writing them, happens
 * only here.
 */
final class VotingOptionSetSynchronizer implements ContainerInjectionInterface {

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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
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
   * The stored options of a question, in the order voters see them.
   *
   * One query, served by the index EntityReferenceItem puts on the reference
   * column of the option table.
   *
   * @return \Drupal\drupal_simple_voting\VotingOptionInterface[]
   *   Options keyed by entity ID.
   */
  public function loadForQuestion(VotingQuestionInterface $question): array {
    if ($question->isNew()) {
      return [];
    }

    /** @var \Drupal\drupal_simple_voting\VotingOptionInterface[] $options */
    $options = $this->optionStorage()->loadByProperties(['question' => $question->id()]);
    uasort($options, static function (VotingOptionInterface $first, VotingOptionInterface $second): int {
      return [$first->getWeight(), (int) $first->id()] <=> [$second->getWeight(), (int) $second->id()];
    });

    return $options;
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
