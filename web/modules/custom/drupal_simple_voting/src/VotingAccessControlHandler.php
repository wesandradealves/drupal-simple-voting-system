<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Grants access to questions, options and ballots.
 *
 * Shared by the three entity types: they answer to the same permissions and a
 * question's verdict governs its options.
 */
final class VotingAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    $administer = AccessResult::allowedIfHasPermission($account, VotingPolicy::ADMINISTER_PERMISSION);
    if ($administer->isAllowed()) {
      return $administer;
    }

    if ($operation !== 'view' && $operation !== 'view label') {
      return $administer;
    }

    return $administer->orIf($this->viewAccess($entity, $account));
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    if ($this->entityTypeId === 'voting_vote') {
      return AccessResult::allowedIfHasPermission($account, VotingPolicy::VOTE_PERMISSION);
    }

    return AccessResult::allowedIfHasPermission($account, VotingPolicy::ADMINISTER_PERMISSION);
  }

  /**
   * Routes a view check to the rule of the entity type being read.
   */
  private function viewAccess(EntityInterface $entity, AccountInterface $account): AccessResultInterface {
    return match ($entity->getEntityTypeId()) {
      'voting_question' => $this->questionViewAccess($entity),
      'voting_option' => $this->optionViewAccess($entity),
      'voting_vote' => $this->voteViewAccess($entity, $account),
      default => AccessResult::neutral(),
    };
  }

  /**
   * A question is readable while it is open, or once it publishes its tally.
   *
   * Closing a poll must not bury the result it exists to announce, so a closed
   * question that shows results stays readable; a closed question that hides
   * them is readable by its administrators only.
   */
  private function questionViewAccess(EntityInterface $entity): AccessResultInterface {
    if (!$entity instanceof VotingQuestionInterface) {
      return AccessResult::neutral();
    }

    return AccessResult::allowedIf($entity->isOpen() || $entity->showsResults())
      ->addCacheableDependency($entity);
  }

  /**
   * An option is only as visible as the question that owns it.
   */
  private function optionViewAccess(EntityInterface $entity): AccessResultInterface {
    if (!$entity instanceof VotingOptionInterface) {
      return AccessResult::neutral();
    }

    $question = $entity->getQuestion();
    if (!$question instanceof VotingQuestionInterface) {
      return AccessResult::neutral()->addCacheableDependency($entity);
    }

    return $this->questionViewAccess($question)->addCacheableDependency($entity);
  }

  /**
   * A ballot is secret: its own elector reads it, nobody else.
   */
  private function voteViewAccess(EntityInterface $entity, AccountInterface $account): AccessResultInterface {
    if (!$entity instanceof FieldableEntityInterface) {
      return AccessResult::neutral();
    }

    $elector = (int) $entity->get('uid')->target_id;

    return AccessResult::allowedIf($elector === (int) $account->id())
      ->cachePerUser()
      ->addCacheableDependency($entity);
  }

}
