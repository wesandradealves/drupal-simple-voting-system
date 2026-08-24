<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\drupal_simple_voting\VotingAccessControlHandler;
use Drupal\drupal_simple_voting\VotingPolicy;
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
  public function getOptionId(): ?int {
    $id = $this->get('option')->target_id;

    return $id === NULL ? NULL : (int) $id;
  }

  /**
   * {@inheritdoc}
   *
   * A ballot written or removed changes its question's tally, so the result
   * cache tag for that question is cleared as the row changes. This is the one
   * place every write passes through — the CMS form, the API and any seeder all
   * save the entity — so the tag can be per question instead of a global list
   * tag that a vote in one poll would use to wipe the cache of every other.
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);

    $question_id = $this->get('question')->target_id;
    if ($question_id !== NULL) {
      \Drupal::service('cache_tags.invalidator')
        ->invalidateTags([VotingPolicy::resultCacheTag($question_id)]);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Covers every deletion the same way postSave() covers every write: a poll or
   * option cascade loads its ballots and deletes them through here, and a single
   * ballot revoked by hand lands here too.
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities) {
    parent::postDelete($storage, $entities);

    $tags = [];
    foreach ($entities as $vote) {
      $question_id = $vote->get('question')->target_id;
      if ($question_id !== NULL) {
        $tags[VotingPolicy::resultCacheTag($question_id)] = TRUE;
      }
    }

    if ($tags) {
      \Drupal::service('cache_tags.invalidator')->invalidateTags(array_keys($tags));
    }
  }

}
