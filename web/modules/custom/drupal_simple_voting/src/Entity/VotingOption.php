<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\drupal_simple_voting\VotingAccessControlHandler;
use Drupal\drupal_simple_voting\VotingOptionInterface;
use Drupal\drupal_simple_voting\VotingQuestionInterface;

/**
 * Defines the poll option entity.
 *
 * The reference to the question lives here, on the child, so that adding or
 * reordering options never touches the question row.
 */
#[ContentEntityType(
  id: 'voting_option',
  label: new TranslatableMarkup('Poll option'),
  label_collection: new TranslatableMarkup('Poll options'),
  label_singular: new TranslatableMarkup('poll option'),
  label_plural: new TranslatableMarkup('poll options'),
  label_count: [
    'singular' => '@count poll option',
    'plural' => '@count poll options',
  ],
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'title',
  ],
  handlers: [
    'access' => VotingAccessControlHandler::class,
    'view_builder' => EntityViewBuilder::class,
    'form' => [
      'default' => ContentEntityForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
  ],
  admin_permission: 'administer polls',
  base_table: 'voting_option',
)]
class VotingOption extends ContentEntityBase implements VotingOptionInterface {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Set by the question form, never picked by hand: no form display, so the
    // option row rendered by EntityFormDisplay never shows a question selector.
    $fields['question'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Poll'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'voting_question')
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Option'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'label' => 'hidden',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Short description'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 5,
        'settings' => ['rows' => 2],
      ])
      ->setDisplayOptions('view', [
        'type' => 'basic_string',
        'label' => 'hidden',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // ImageItem already ships the index and the foreign key to file_managed,
    // and its FileFieldItemList registers file usage on save.
    $fields['image'] = BaseFieldDefinition::create('image')
      ->setLabel(new TranslatableMarkup('Thumbnail'))
      ->setSetting('file_directory', 'voting/[date:custom:Y-m]')
      ->setSetting('alt_field', TRUE)
      // The label sits next to the thumbnail, so an empty alt is the correct
      // reading for a decorative image.
      ->setSetting('alt_field_required', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'image_image',
        'weight' => 10,
      ])
      ->setDisplayOptions('view', [
        'type' => 'image',
        'label' => 'hidden',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Ordering is driven by the drag handle column of the question form, which
    // builds its own weight element; a widget here would duplicate it.
    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Weight'))
      ->setDefaultValue(0)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

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
  public function getTitle(): string {
    return (string) $this->get('title')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->get('description')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int {
    return (int) $this->get('weight')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function hasImage(): bool {
    return !$this->get('image')->isEmpty();
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities) {
    parent::postDelete($storage, $entities);

    $option_ids = array_keys($entities);
    if (!$option_ids) {
      return;
    }

    $vote_storage = \Drupal::entityTypeManager()->getStorage('voting_vote');
    $vote_ids = $vote_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('option', $option_ids, 'IN')
      ->execute();

    if ($vote_ids) {
      $vote_storage->delete($vote_storage->loadMultiple($vote_ids));
    }
  }

}
