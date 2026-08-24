<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormStateInterface;

/**
 * The answer-option rows a question form is editing across its Ajax rebuilds.
 *
 * Rows are keyed by a stable string — 'id:12' for a stored option, 'new:3' for
 * one the editor just added — and those keys are never reindexed. The durable
 * bookkeeping (which keys exist, behind which counter) lives under a single
 * form-state key so it survives every rebuild; this object caches the entities
 * and the shared form display for the length of one request.
 */
final class OptionRowSet {

  use DependencySerializationTrait;

  private const STATE_KEY = 'voting_option_set';
  private const STORED_PREFIX = 'id:';
  private const ADDED_PREFIX = 'new:';
  private const SEEDED_ROWS = 2;

  /**
   * The option set service.
   *
   * Not readonly: the set is serialized into the form cache between Ajax
   * requests, and DependencySerializationTrait reassigns on wake-up.
   *
   * @var \Drupal\drupal_simple_voting\VotingOptionSetSynchronizer
   */
  private VotingOptionSetSynchronizer $optionSet;

  /**
   * The poll whose stored options seed the rows.
   */
  private VotingQuestionInterface $question;

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

  public function __construct(VotingOptionSetSynchronizer $option_set, VotingQuestionInterface $question) {
    $this->optionSet = $option_set;
    $this->question = $question;
  }

  /**
   * Row keys in the order the editor last left them.
   *
   * @return string[]
   *   Stable row keys.
   */
  public function keysInOrder(FormStateInterface $form_state): array {
    $state = $this->state($form_state);
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
   * The option entity behind a row, or NULL if it vanished from storage.
   */
  public function optionForRow(string $key, FormStateInterface $form_state): ?VotingOptionInterface {
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
   * The form display every row shares.
   *
   * voting_option has no bundles, so one display serves the whole table and
   * the config lookup happens once instead of once per row.
   */
  public function displayFor(VotingOptionInterface $option): EntityFormDisplayInterface {
    return $this->optionDisplay ??= EntityFormDisplay::collectRenderDisplay($option, 'default');
  }

  /**
   * Reads the submitted rows back into option entities.
   *
   * @return \Drupal\drupal_simple_voting\VotingOptionInterface[]
   *   The options worth keeping, keyed by row key, in display order.
   */
  public function collectSubmitted(array &$form, FormStateInterface $form_state): array {
    $collected = [];

    foreach ($this->keysInOrder($form_state) as $key) {
      if (!isset($form['options']['table'][$key]['fields'])) {
        continue;
      }
      $option = $this->optionForRow($key, $form_state);
      if ($option === NULL) {
        continue;
      }

      $this->restoreUploadedFileIds($form_state, ['options', 'table', $key, 'fields', 'image']);

      $this->displayFor($option)
        ->extractFormValues($option, $form['options']['table'][$key]['fields'], $form_state);

      if ($option->isNew() && $this->isUntouched($option)) {
        continue;
      }

      $collected[$key] = $option;
    }

    return $collected;
  }

  /**
   * Adds one empty row without disturbing the keys already on screen.
   */
  public static function addOptionSubmit(array $form, FormStateInterface $form_state): void {
    // The values of a limited-validation submit are gone by now; only what the
    // form itself stored is trustworthy here.
    // @see \Drupal\Core\Form\FormValidator::handleErrorsWithLimitedValidation()
    $state = $form_state->get(self::STATE_KEY) ?? ['keys' => [], 'next' => 0];
    $state['next']++;
    $state['keys'][self::ADDED_PREFIX . $state['next']] = TRUE;

    $form_state->set(self::STATE_KEY, $state);
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
    $state = $form_state->get(self::STATE_KEY) ?? ['keys' => [], 'next' => 0];

    if (is_string($key)) {
      unset($state['keys'][$key]);
    }

    $form_state->set(self::STATE_KEY, $state);
    $form_state->setRebuild();
  }

  /**
   * Returns the whole option set to the browser after add or remove.
   */
  public static function ajaxRefresh(array $form, FormStateInterface $form_state): array {
    $button = $form_state->getTriggeringElement();
    $parents = array_slice($button['#array_parents'], 0, $button['#voting_wrapper_depth']);

    return NestedArray::getValue($form, $parents);
  }

  /**
   * Whether the submission came from the add or remove buttons of the table.
   */
  public static function isTriggeredBy(FormStateInterface $form_state): bool {
    return isset($form_state->getTriggeringElement()['#voting_wrapper_depth']);
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
  private function state(FormStateInterface $form_state): array {
    $state = $form_state->get(self::STATE_KEY);
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

    $form_state->set(self::STATE_KEY, $state);

    return $state;
  }

  /**
   * The options already stored for the edited question, keyed by entity ID.
   *
   * @return \Drupal\drupal_simple_voting\VotingOptionInterface[]
   *   The stored options.
   */
  private function storedOptions(): array {
    if ($this->storedOptions === NULL) {
      $this->storedOptions = $this->optionSet->loadForQuestion($this->question);
    }

    return $this->storedOptions;
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
   * Keeps an image an option row just uploaded from vanishing on the rebuild.
   *
   * The rows carry hand-assigned #parents (options/table/{key}/fields) so each
   * widget owns its own table cell, which forces #parents and #array_parents to
   * diverge for the managed_file: its #array_parents end in image/widget/0
   * while its #parents end in image/0. On an upload request
   * ManagedFile::valueCallback() saves the file and fills the delta's fids, but
   * the form builder then reaches the field-level element whose #parents is
   * .../fields/image with no delta; the user input holds nothing under that
   * path, so FormBuilder::handleInputElement() overwrites it with NULL and the
   * freshly saved delta is thrown away. ManagedFile::submit() copies the ids
   * back into the user input only for the remove button, leaving the upload
   * button to valueCallback() on the rebuild — which here finds only the NULL.
   * So we hang our own submit on the upload button to carry the ids across, the
   * way the core already does for Remove.
   *
   * @see \Drupal\Core\Form\FormBuilder::handleInputElement()
   * @see \Drupal\file\Element\ManagedFile::submit()
   */
  public function guardUploadedImage(array &$fields): void {
    if (isset($fields['image']['widget'][0])) {
      $fields['image']['widget'][0]['#process'][] = [self::class, 'armUploadButton'];
    }
  }

  /**
   * Hangs the id-preserving submit on the widget's upload button.
   *
   * The button is built by the core's own #process, so this is appended after
   * it and runs once the button exists.
   */
  public static function armUploadButton(array $element, FormStateInterface $form_state, array $form): array {
    if (isset($element['upload_button'])) {
      $element['upload_button']['#submit'][] = [self::class, 'retainUploadedFileIds'];
    }

    return $element;
  }

  /**
   * Writes the just-uploaded file ids back under the widget's real #parents.
   *
   * @see \Drupal\drupal_simple_voting\OptionRowSet::guardUploadedImage()
   */
  public static function retainUploadedFileIds(array $form, FormStateInterface $form_state): void {
    $button = $form_state->getTriggeringElement();
    $widget = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -1));
    $fids = $widget['#value']['fids'] ?? [];
    if (!$fids || empty($widget['#parents'])) {
      return;
    }

    $input = $form_state->getUserInput();
    NestedArray::setValue($input, array_merge($widget['#parents'], ['fids']), implode(' ', $fids));
    $form_state->setUserInput($input);
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

}
