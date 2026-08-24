<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\drupal_simple_voting\Form\VotingQuestionForm;
use Drupal\drupal_simple_voting\VotingAccessControlHandler;
use Drupal\drupal_simple_voting\VotingPolicy;
use Drupal\drupal_simple_voting\VotingQuestionListBuilder;
use Drupal\drupal_simple_voting\VotingQuestionInterface;

/**
 * Defines the poll question entity.
 *
 * The question holds no reference to its options: the pointer lives on the
 * option, so editing an option never writes to the question row.
 */
#[ContentEntityType(
  id: 'voting_question',
  label: new TranslatableMarkup('Poll'),
  label_collection: new TranslatableMarkup('Polls'),
  label_singular: new TranslatableMarkup('poll'),
  label_plural: new TranslatableMarkup('polls'),
  label_count: [
    'singular' => '@count poll',
    'plural' => '@count polls',
  ],
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'title',
  ],
  handlers: [
    'access' => VotingAccessControlHandler::class,
    'view_builder' => EntityViewBuilder::class,
    'list_builder' => VotingQuestionListBuilder::class,
    'form' => [
      'default' => VotingQuestionForm::class,
      'add' => VotingQuestionForm::class,
      'edit' => VotingQuestionForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => DefaultHtmlRouteProvider::class,
    ],
  ],
  links: [
    'canonical' => '/poll/{voting_question}',
    'collection' => '/admin/content/polls',
    'add-form' => '/admin/content/polls/add',
    'edit-form' => '/admin/content/polls/{voting_question}/edit',
    'delete-form' => '/admin/content/polls/{voting_question}/delete',
  ],
  admin_permission: 'administer polls',
  base_table: 'voting_question',
)]
class VotingQuestion extends ContentEntityBase implements VotingQuestionInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Question'))
      ->setDescription(new TranslatableMarkup('The wording shown to voters.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -20,
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'label' => 'hidden',
        'weight' => -20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDescription(new TranslatableMarkup('Optional supporting text shown under the question.'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => -15,
        'settings' => ['rows' => 3],
      ])
      ->setDisplayOptions('view', [
        'type' => 'basic_string',
        'label' => 'hidden',
        'weight' => -15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['show_results'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Show results after voting'))
      ->setDescription(new TranslatableMarkup('Discloses the tally to whoever already voted on this poll.'))
      ->setDefaultValue(FALSE)
      ->setSetting('on_label', new TranslatableMarkup('Shown'))
      ->setSetting('off_label', new TranslatableMarkup('Hidden'))
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => -10,
        'settings' => ['display_label' => TRUE],
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Open for voting'))
      ->setDescription(new TranslatableMarkup('Closed polls reject new votes.'))
      ->setDefaultValue(TRUE)
      ->setSetting('on_label', new TranslatableMarkup('Open'))
      ->setSetting('off_label', new TranslatableMarkup('Closed'))
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => -5,
        'settings' => ['display_label' => TRUE],
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function isOpen(): bool {
    return (bool) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function showsResults(): bool {
    return (bool) $this->get('show_results')->value;
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
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   *
   * Options and ballots are only reachable through their question, so deleting
   * the question has to sweep them or the hand-declared foreign keys start
   * lying about the data.
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities) {
    parent::postDelete($storage, $entities);

    $question_ids = array_keys($entities);
    if (!$question_ids) {
      return;
    }

    // The question's own entity tag already clears its cached result, but the
    // result tag is invalidated explicitly too so the "a tally write clears
    // voting_result" rule holds at every entity that can end that tally.
    \Drupal::service('cache_tags.invalidator')->invalidateTags(array_map(
      static fn ($question_id): string => VotingPolicy::resultCacheTag($question_id),
      $question_ids,
    ));

    foreach (['voting_option', 'voting_vote'] as $entity_type_id) {
      $child_storage = \Drupal::entityTypeManager()->getStorage($entity_type_id);
      $child_ids = $child_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('question', $question_ids, 'IN')
        ->execute();

      if ($child_ids) {
        $child_storage->delete($child_storage->loadMultiple($child_ids));
      }
    }
  }

}
