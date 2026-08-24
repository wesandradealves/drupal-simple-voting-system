<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\drupal_simple_voting\OptionRowSet;
use Drupal\drupal_simple_voting\VotingOptionInterface;
use Drupal\drupal_simple_voting\VotingOptionSetSynchronizer;
use Drupal\drupal_simple_voting\VotingQuestionInterface;

/**
 * Creates and edits a poll and its answer options on one screen.
 *
 * The rows of the option table are owned by an OptionRowSet, which keeps them
 * keyed by a stable string across every Ajax rebuild. That stability is what
 * lets an image upload survive a rebuild: the upload button freezes its own
 * #array_parents when the row is built and resolves them against the rebuilt
 * form afterwards.
 *
 * @see \Drupal\file\Element\ManagedFile::processManagedFile()
 * @see \Drupal\drupal_simple_voting\OptionRowSet
 */
final class VotingQuestionForm extends ContentEntityForm {

  use AutowireTrait;

  private const WRAPPER_ID = 'voting-option-set';
  private const TABLE_ID = 'voting-option-order';
  private const WEIGHT_CLASS = 'voting-option-weight';
  private const MINIMUM_OPTIONS = 2;

  /**
   * The option set service.
   *
   * Not readonly: this form is serialized into the form cache between Ajax
   * requests, and DependencySerializationTrait reassigns on wake-up.
   *
   * @var \Drupal\drupal_simple_voting\VotingOptionSetSynchronizer
   */
  protected VotingOptionSetSynchronizer $optionSet;

  /**
   * The answer-option rows being edited, built once per request.
   */
  private ?OptionRowSet $optionRows = NULL;

  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    VotingOptionSetSynchronizer $option_set,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->optionSet = $option_set;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $form['#attached']['library'][] = 'drupal_simple_voting/admin';
    $form['options'] = $this->buildOptionSet($form_state);

    // The question has no revisions and no authoring information, so the meta
    // sidebar the admin theme builds for content forms would render as an
    // empty bordered box in the middle of the form.
    $form['advanced']['#access'] = FALSE;

    return $form;
  }

  /**
   * The whole option table, its rows and the add button, as one Ajax target.
   */
  private function buildOptionSet(FormStateInterface $form_state): array {
    $element = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#weight' => 10,
      '#attributes' => ['class' => ['voting-option-set']],
      '#prefix' => '<div id="' . self::WRAPPER_ID . '">',
      '#suffix' => '</div>',
      'table' => $this->buildOptionTable(),
      'add' => $this->buildAddButton(),
    ];

    $rows = $this->optionRows();
    $keys = $rows->keysInOrder($form_state);
    $delta = max(count($keys), 10);
    $position = 0;
    foreach ($keys as $key) {
      $option = $rows->optionForRow($key, $form_state);
      if ($option === NULL) {
        continue;
      }
      $position++;
      $element['table'][$key] = $this->buildOptionRow($key, $option, $position, $delta, $form_state);
    }

    return $element;
  }

  /**
   * The empty draggable table the option rows are hung on.
   */
  private function buildOptionTable(): array {
    return [
      '#type' => 'table',
      '#caption' => $this->t('Answer options. Drag the rows to change the order voters see; rows left completely empty are discarded.'),
      '#header' => [
        $this->t('Option'),
        $this->t('Order'),
        $this->t('Operations'),
      ],
      '#empty' => $this->t('This poll has no options yet. Use "Add option" to create the first one.'),
      // Without an id on the table itself, #tabledrag attaches neither the
      // library nor its settings, and fails silently.
      // @see \Drupal\Core\Render\Element\Table::preRenderTable()
      '#attributes' => ['id' => self::TABLE_ID],
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => self::WEIGHT_CLASS,
        ],
      ],
    ];
  }

  /**
   * The button that appends one empty row.
   */
  private function buildAddButton(): array {
    return [
      '#type' => 'submit',
      '#name' => 'voting_option_add',
      '#value' => $this->t('Add option'),
      // A button that limits validation errors is only honoured when it also
      // carries its own #submit handler.
      // @see \Drupal\Core\Form\FormValidator::determineLimitValidationErrors()
      '#submit' => [[OptionRowSet::class, 'addOptionSubmit']],
      '#limit_validation_errors' => [],
      '#voting_wrapper_depth' => -1,
      '#ajax' => [
        'callback' => [OptionRowSet::class, 'ajaxRefresh'],
        'wrapper' => self::WRAPPER_ID,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entity = parent::validateForm($form, $form_state);

    if (OptionRowSet::isTriggeredBy($form_state)) {
      return $entity;
    }

    $options = $this->optionRows()->collectSubmitted($form, $form_state);
    if (count($options) < self::MINIMUM_OPTIONS) {
      $form_state->setError($form['options']['table'], $this->t('A poll needs at least @count answer options.', [
        '@count' => self::MINIMUM_OPTIONS,
      ]));
    }

    foreach ($options as $key => $option) {
      $this->validateOption($option, $form['options']['table'][$key]['fields'], $form_state);
    }

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $question = $this->entity;
    assert($question instanceof VotingQuestionInterface);
    $this->optionSet->synchronize($question, $this->optionRows()->collectSubmitted($form, $form_state));

    $this->messenger()->addStatus($this->t('The poll %label has been saved.', [
      '%label' => $question->label(),
    ]));

    if ($question->hasLinkTemplate('collection')) {
      $form_state->setRedirectUrl($question->toUrl('collection'));
    }

    return $result;
  }

  /**
   * Builds one draggable row around a real entity form display.
   */
  private function buildOptionRow(string $key, VotingOptionInterface $option, int $position, int $delta, FormStateInterface $form_state): array {
    $row = [
      '#attributes' => ['class' => ['draggable']],
    ];

    $row['fields'] = [
      '#type' => 'container',
      // The widgets are built before Form API assigns parents, so the entity
      // form display needs them spelled out here.
      // @see \Drupal\media_library\Form\AddFormBase::buildEntityFormElement()
      '#parents' => ['options', 'table', $key, 'fields'],
    ];
    $this->optionRows()->displayFor($option)->buildForm($option, $row['fields'], $form_state);
    $this->optionRows()->guardUploadedImage($row['fields']);

    $row['weight'] = [
      '#type' => 'weight',
      '#title' => $this->t('Order for option @position', ['@position' => $position]),
      '#title_display' => 'invisible',
      '#delta' => $delta,
      '#default_value' => $position,
      '#attributes' => ['class' => [self::WEIGHT_CLASS]],
    ];

    $row['remove'] = [
      '#type' => 'submit',
      '#name' => 'voting_option_remove__' . $key,
      '#value' => $this->t('Remove'),
      '#attributes' => [
        'aria-label' => $this->t('Remove option @position', ['@position' => $position]),
      ],
      '#submit' => [[OptionRowSet::class, 'removeOptionSubmit']],
      '#limit_validation_errors' => [],
      '#voting_row_key' => $key,
      '#voting_wrapper_depth' => -3,
      '#ajax' => [
        'callback' => [OptionRowSet::class, 'ajaxRefresh'],
        'wrapper' => self::WRAPPER_ID,
      ],
    ];

    return $row;
  }

  /**
   * Reports the violations of one option next to the widget that caused them.
   */
  private function validateOption(VotingOptionInterface $option, array &$row, FormStateInterface $form_state): void {
    $violations = $option->validate();
    $violations->filterByFieldAccess();
    // The reference to the poll and the ordering weight are assigned by the
    // option set once the poll itself is saved, so they are legitimately empty
    // while the form is being validated.
    $violations->filterByFields(['question', 'weight']);

    foreach ($violations->getEntityViolations() as $violation) {
      $form_state->setError($row, $violation->getMessage());
    }

    $this->optionRows()->displayFor($option)->flagWidgetsErrorsFromViolations($violations, $row, $form_state);
  }

  /**
   * The answer-option rows this form is editing.
   */
  private function optionRows(): OptionRowSet {
    if ($this->optionRows === NULL) {
      $question = $this->entity;
      assert($question instanceof VotingQuestionInterface);
      $this->optionRows = new OptionRowSet($this->optionSet, $question);
    }

    return $this->optionRows;
  }

}
