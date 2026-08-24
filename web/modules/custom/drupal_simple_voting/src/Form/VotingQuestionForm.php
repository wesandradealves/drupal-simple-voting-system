<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\drupal_simple_voting\VotingOptionInterface;
use Drupal\drupal_simple_voting\VotingOptionSetSynchronizer;
use Drupal\drupal_simple_voting\VotingQuestionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates and edits a poll and its answer options on one screen.
 *
 * The option rows are keyed by a stable string — 'id:12' for a stored option,
 * 'new:3' for one the editor just added — and those keys are never reindexed.
 * That is what lets an image upload survive an Ajax rebuild: the upload button
 * freezes its own #array_parents into the callback query string when the row is
 * built, and resolves them against the rebuilt form afterwards.
 *
 * @see \Drupal\file\Element\ManagedFile::processManagedFile()
 */
final class VotingQuestionForm extends ContentEntityForm {

  private const OPTION_SET_STATE = 'voting_option_set';
  private const STORED_PREFIX = 'id:';
  private const ADDED_PREFIX = 'new:';
  private const WRAPPER_ID = 'voting-option-set';
  private const TABLE_ID = 'voting-option-order';
  private const WEIGHT_CLASS = 'voting-option-weight';
  private const SEEDED_ROWS = 2;
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
   * Option entities backing the rows, keyed by row key.
   *
   * @var \Drupal\drupal_simple_voting\VotingOptionInterface[]
   */
  private array $rowOptions = [];

  /**
   * Options already stored for the edited question, keyed by entity ID.
   *
   * @var \Drupal\drupal_simple_voting\VotingOptionInterface[]|null
   */
  private ?array $storedOptions = NULL;

  /**
   * The form display shared by every option row.
   */
  private ?EntityFormDisplayInterface $optionDisplay = NULL;

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
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      VotingOptionSetSynchronizer::create($container),
    );
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

    $keys = $this->orderedRowKeys($form_state);
    $delta = max(count($keys), 10);
    $position = 0;
    foreach ($keys as $key) {
      $option = $this->optionForRow($key, $form_state);
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
      '#submit' => [[static::class, 'addOptionSubmit']],
      '#limit_validation_errors' => [],
      '#voting_wrapper_depth' => -1,
      '#ajax' => [
        'callback' => [static::class, 'optionSetAjax'],
        'wrapper' => self::WRAPPER_ID,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entity = parent::validateForm($form, $form_state);

    if ($this->isOptionSetTrigger($form_state)) {
      return $entity;
    }

    $options = $this->collectSubmittedOptions($form, $form_state);
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
    $this->optionSet->synchronize($question, $this->collectSubmittedOptions($form, $form_state));

    $this->messenger()->addStatus($this->t('The poll %label has been saved.', [
      '%label' => $question->label(),
    ]));

    if ($question->hasLinkTemplate('collection')) {
      $form_state->setRedirectUrl($question->toUrl('collection'));
    }

    return $result;
  }

  /**
   * Adds one empty row without disturbing the keys already on screen.
   */
  public static function addOptionSubmit(array $form, FormStateInterface $form_state): void {
    // The values of a limited-validation submit are gone by now; only what the
    // form itself stored is trustworthy here.
    // @see \Drupal\Core\Form\FormValidator::handleErrorsWithLimitedValidation()
    $state = $form_state->get(self::OPTION_SET_STATE) ?? ['keys' => [], 'next' => 0];
    $state['next']++;
    $state['keys'][self::ADDED_PREFIX . $state['next']] = TRUE;

    $form_state->set(self::OPTION_SET_STATE, $state);
    $form_state->setRebuild();
  }

  /**
   * Drops one row.
   *
   * The surviving keys keep their exact position in the form array, because
   * they are strings: unsetting one never renumbers the others.
   */
  public static function removeOptionSubmit(array $form, FormStateInterface $form_state): void {
    $key = $form_state->getTriggeringElement()['#voting_row_key'] ?? NULL;
    $state = $form_state->get(self::OPTION_SET_STATE) ?? ['keys' => [], 'next' => 0];

    if (is_string($key)) {
      unset($state['keys'][$key]);
    }

    $form_state->set(self::OPTION_SET_STATE, $state);
    $form_state->setRebuild();
  }

  /**
   * Returns the whole option set to the browser after add or remove.
   */
  public static function optionSetAjax(array $form, FormStateInterface $form_state): array {
    $button = $form_state->getTriggeringElement();
    $parents = array_slice($button['#array_parents'], 0, $button['#voting_wrapper_depth']);

    return NestedArray::getValue($form, $parents);
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
    $this->optionDisplay($option)->buildForm($option, $row['fields'], $form_state);

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
      '#submit' => [[static::class, 'removeOptionSubmit']],
      '#limit_validation_errors' => [],
      '#voting_row_key' => $key,
      '#voting_wrapper_depth' => -3,
      '#ajax' => [
        'callback' => [static::class, 'optionSetAjax'],
        'wrapper' => self::WRAPPER_ID,
      ],
    ];

    return $row;
  }

  /**
   * Reads the submitted rows back into option entities.
   *
   * @return \Drupal\drupal_simple_voting\VotingOptionInterface[]
   *   The options worth keeping, keyed by row key, in display order.
   */
  private function collectSubmittedOptions(array &$form, FormStateInterface $form_state): array {
    $collected = [];

    foreach ($this->orderedRowKeys($form_state) as $key) {
      if (!isset($form['options']['table'][$key]['fields'])) {
        continue;
      }
      $option = $this->optionForRow($key, $form_state);
      if ($option === NULL) {
        continue;
      }

      $this->restoreUploadedFileIds($form_state, ['options', 'table', $key, 'fields', 'image']);

      $this->optionDisplay($option)
        ->extractFormValues($option, $form['options']['table'][$key]['fields'], $form_state);

      if ($option->isNew() && $this->isUntouched($option)) {
        continue;
      }

      $collected[$key] = $option;
    }

    return $collected;
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

    $this->optionDisplay($option)->flagWidgetsErrorsFromViolations($violations, $row, $form_state);
  }

  /**
   * Row keys in the order the editor last left them.
   *
   * @return string[]
   *   Stable row keys.
   */
  private function orderedRowKeys(FormStateInterface $form_state): array {
    $state = $this->optionSetState($form_state);
    $keys = array_keys($state['keys']);
    $positions = array_flip($keys);

    // Tabledrag does not reorder anything server side; it only rewrites the
    // weight selects, so the submitted weights are the order.
    $submitted = $this->submittedWeights($form_state);
    usort($keys, static function (string $first, string $second) use ($submitted, $positions): int {
      $order = ($submitted[$first] ?? $positions[$first]) <=> ($submitted[$second] ?? $positions[$second]);

      return $order !== 0 ? $order : $positions[$first] <=> $positions[$second];
    });

    return $keys;
  }

  /**
   * The weight the browser sent for each row, keyed by row key.
   *
   * @return array<string, int>
   *   Submitted weights; empty on the first build.
   */
  private function submittedWeights(FormStateInterface $form_state): array {
    $rows = NestedArray::getValue($form_state->getUserInput(), ['options', 'table']);
    if (!is_array($rows)) {
      return [];
    }

    $weights = [];
    foreach ($rows as $key => $row) {
      if (is_array($row) && isset($row['weight']) && is_numeric($row['weight'])) {
        $weights[(string) $key] = (int) $row['weight'];
      }
    }

    return $weights;
  }

  /**
   * The row bookkeeping, seeded once per form build chain.
   *
   * @return array{keys: array<string, true>, next: int}
   *   The stable keys on screen and the counter behind 'new:N'.
   */
  private function optionSetState(FormStateInterface $form_state): array {
    $state = $form_state->get(self::OPTION_SET_STATE);
    if (is_array($state)) {
      return $state;
    }

    $state = ['keys' => [], 'next' => 0];
    foreach (array_keys($this->storedOptions()) as $id) {
      $state['keys'][self::STORED_PREFIX . $id] = TRUE;
    }

    if ($state['keys'] === []) {
      for ($seeded = 0; $seeded < self::SEEDED_ROWS; $seeded++) {
        $state['next']++;
        $state['keys'][self::ADDED_PREFIX . $state['next']] = TRUE;
      }
    }

    $form_state->set(self::OPTION_SET_STATE, $state);

    return $state;
  }

  /**
   * The option entity behind a row, or NULL if it vanished from storage.
   */
  private function optionForRow(string $key, FormStateInterface $form_state): ?VotingOptionInterface {
    if (isset($this->rowOptions[$key])) {
      return $this->rowOptions[$key];
    }

    if (str_starts_with($key, self::STORED_PREFIX)) {
      $stored = $this->storedOptions();
      $id = (int) substr($key, strlen(self::STORED_PREFIX));
      if (!isset($stored[$id])) {
        return NULL;
      }
      $this->rowOptions[$key] = $stored[$id];
    }
    else {
      $this->rowOptions[$key] = $this->optionSet->blankOption();
    }

    return $this->rowOptions[$key];
  }

  /**
   * The options already stored for the edited question, keyed by entity ID.
   *
   * @return \Drupal\drupal_simple_voting\VotingOptionInterface[]
   *   The stored options.
   */
  private function storedOptions(): array {
    if ($this->storedOptions === NULL) {
      $question = $this->entity;
      assert($question instanceof VotingQuestionInterface);
      $this->storedOptions = $this->optionSet->loadForQuestion($question);
    }

    return $this->storedOptions;
  }

  /**
   * The form display every row shares.
   *
   * voting_option has no bundles, so one display serves the whole table and
   * the config lookup happens once instead of once per row.
   */
  private function optionDisplay(VotingOptionInterface $option): EntityFormDisplayInterface {
    return $this->optionDisplay ??= EntityFormDisplay::collectRenderDisplay($option, 'default');
  }

  /**
   * Puts the submitted file ids back into the array shape the widget expects.
   *
   * The rows carry hand-assigned #parents so each widget lands on its own cell
   * of the table. In that arrangement the hidden 'fids' element reaches
   * $form_state as the raw submitted string ("4"), not as the array of ids
   * ManagedFile normally leaves behind, and FileWidget::massageFormValues()
   * iterates that key: a string yields no items, so every option silently lost
   * its thumbnail on save.
   */
  private function restoreUploadedFileIds(FormStateInterface $form_state, array $path): void {
    $items = NestedArray::getValue($form_state->getValues(), $path);
    if (!is_array($items)) {
      return;
    }

    $restored = FALSE;
    foreach ($items as $delta => $item) {
      if (!is_array($item) || !isset($item['fids']) || is_array($item['fids'])) {
        continue;
      }
      $ids = array_values(array_filter(
        preg_split('/\s+/', trim((string) $item['fids'])) ?: [],
        static fn(string $id): bool => is_numeric($id),
      ));
      $items[$delta]['fids'] = array_map('intval', $ids);
      $restored = TRUE;
    }

    if ($restored) {
      $form_state->setValue($path, $items);
    }
  }

  /**
   * Whether a row the editor never filled in should simply be forgotten.
   */
  private function isUntouched(VotingOptionInterface $option): bool {
    foreach (['title', 'description', 'image'] as $field_name) {
      if (!$option->get($field_name)->isEmpty()) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Whether the submission came from the add or remove buttons of this table.
   */
  private function isOptionSetTrigger(FormStateInterface $form_state): bool {
    return isset($form_state->getTriggeringElement()['#voting_wrapper_depth']);
  }

}
