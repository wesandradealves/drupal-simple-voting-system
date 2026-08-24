<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\drupal_simple_voting\VotingAccessControlHandler;
use Drupal\drupal_simple_voting\VotingOptionInterface;
use Drupal\drupal_simple_voting\VotingQuestionInterface;
use Drupal\drupal_simple_voting\VotingVoteInterface;
use Drupal\drupal_simple_voting\VotingVoteStorageSchema;

/**
 * Defines the ballot entity.
 *
 * The question is denormalised onto the ballot row on purpose: without that
 * column the unique key (uid, question) that enforces one vote per user per
 * poll cannot exist.
 */
#[ContentEntityType(
  id: 'voting_vote',
  label: new TranslatableMarkup('Vote'),
  label_collection: new TranslatableMarkup('Votes'),
  label_singular: new TranslatableMarkup('vote'),
  label_plural: new TranslatableMarkup('votes'),
  label_count: [
    'singular' => '@count vote',
    'plural' => '@count votes',
  ],
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'storage_schema' => VotingVoteStorageSchema::class,
    'access' => VotingAccessControlHandler::class,
  ],
  admin_permission: 'administer polls',
  base_table: 'voting_vote',
)]
class VotingVote extends ContentEntityBase implements VotingVoteInterface {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['question'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Poll'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'voting_question');

    $fields['option'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Option'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'voting_option');

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Voter'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Cast on'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): ?VotingQuestionInterface {
    $question = $this->get('question')->entity;

    return $question instanceof VotingQuestionInterface ? $question : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestionId(): ?int {
    $id = $this->get('question')->target_id;

    return $id === NULL ? NULL : (int) $id;
  }

  /**
   * {@inheritdoc}
   */
  public function getOption(): ?VotingOptionInterface {
    $option = $this->get('option')->entity;

    return $option instanceof VotingOptionInterface ? $option : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getOptionId(): ?int {
    $id = $this->get('option')->target_id;

    return $id === NULL ? NULL : (int) $id;
  }

  /**
   * {@inheritdoc}
   */
  public function getVoter(): ?AccountInterface {
    $voter = $this->get('uid')->entity;

    return $voter instanceof AccountInterface ? $voter : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

}
