<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\Submit;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Url;
use Drupal\drupal_simple_voting\BallotBox;
use Drupal\drupal_simple_voting\BallotNotice;
use Drupal\drupal_simple_voting\Exception\DuplicateVoteException;
use Drupal\drupal_simple_voting\Exception\VotingClosedException;
use Drupal\drupal_simple_voting\VotingPolicy;
use Drupal\drupal_simple_voting\VotingQuestionInterface;

/**
 * The ballot for one question.
 */
final class BallotForm extends FormBase implements TrustedCallbackInterface {

  use AutowireTrait;

  /**
   * Injects the storage, the ballot box that casts votes and the voting policy.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly BallotBox $ballotBox,
    private readonly VotingPolicy $policy,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'voting_ballot_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $ballot_options
   *   Prepared option rows, in display order, coming from
   *   \Drupal\drupal_simple_voting\Controller\VotingController::optionRows().
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?VotingQuestionInterface $voting_question = NULL,
    array $ballot_options = [],
  ): array {
    if ($voting_question === NULL) {
      throw new \InvalidArgumentException('BallotForm requires a question.');
    }

    $account = $this->currentUser();
    $already_voted = $this->ballotBox->findVote($voting_question, $account) !== NULL;
    $can_vote = $ballot_options !== []
      && !$already_voted
      && $this->policy->canVote($voting_question, $account);

    $form_state->set('question_id', $voting_question->id());
    $form_state->set('option_keys', array_column($ballot_options, 'key'));

    // The read above is interface comfort only. What guarantees one ballot
    // per elector is the unique key (uid, question); submit handles its refusal.
    $this->policy->cacheabilityFor($voting_question)->applyTo($form);

    $form['#attached']['library'][] = 'drupal_simple_voting/drupal_simple_voting';

    $form['ballot'] = [
      '#type' => 'component',
      '#component' => 'drupal_simple_voting:ballot',
      '#props' => [
        'question_description' => (string) $voting_question->getDescription(),
        'show_totals' => FALSE,
      ],
    ];

    if (!$can_vote) {
      $form['ballot']['status'] = [
        '#type' => 'component',
        '#component' => 'drupal_simple_voting:vote-status',
        '#props' => [
          'state' => $this->ballotState($voting_question, $ballot_options, $already_voted),
          'message' => $this->ballotNotice($voting_question, $ballot_options, $already_voted),
          'action_url' => $this->signInUrl($voting_question),
          'action_label' => (string) $this->t('Sign in'),
        ],
      ];
    }

    $form['ballot']['options'] = [
      '#type' => 'fieldset',
      '#title' => $voting_question->getTitle(),
      '#title_display' => 'invisible',
    ];

    // One radio per option, all sharing #parents so they share the name
    // attribute. Radios::processRadios() does the same internally; here it is
    // explicit because each radio has to go into a component slot.
    foreach ($ballot_options as $option) {
      $form['ballot']['options'][$option['key']] = [
        '#type' => 'component',
        '#component' => 'drupal_simple_voting:ballot-option',
        '#props' => [
          'input_id' => $option['input_id'],
          'title' => $option['title'],
          'description' => (string) $option['description'],
          'image' => $option['image'],
          'answered' => FALSE,
          'chosen' => FALSE,
          'show_totals' => FALSE,
        ],
        'input' => [
          '#type' => 'radio',
          '#id' => $option['input_id'],
          '#theme_wrappers' => [],
          '#return_value' => (string) $option['key'],
          '#default_value' => FALSE,
          '#parents' => ['choice'],
          '#name' => 'choice',
          '#disabled' => !$can_vote,
          '#attributes' => ['class' => ['vt-option__input']],
        ],
      ];
    }

    $form['ballot']['actions'] = ['#type' => 'actions'];
    $form['ballot']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirm vote'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['vt-action', 'vt-ballot__submit']],
      // Core stamps every submit with .button (and .button--primary), which the
      // active theme paints over the module's own .vt-action at equal
      // specificity. Dropping those theme hooks keeps the module button without
      // a specificity war or !important.
      '#pre_render' => [
        [Submit::class, 'preRenderButton'],
        [self::class, 'stripThemeButtonClasses'],
      ],
    ];
    $form['ballot']['actions']['#access'] = AccessResult::allowedIf($can_vote)
      ->addCacheableDependency($voting_question)
      ->addCacheableDependency($this->config(VotingPolicy::SETTINGS))
      ->cachePerUser();

    return $form;
  }

  /**
   * Strips the theme button classes core stamps onto the submit.
   *
   * Runs after Submit::preRenderButton(), so it removes the .button and
   * .button--* hooks the active theme styles, leaving the module's .vt-action.
   */
  public static function stripThemeButtonClasses(array $element): array {
    $classes = $element['#attributes']['class'] ?? [];
    $element['#attributes']['class'] = array_values(array_filter(
      $classes,
      static fn ($class): bool => $class !== 'button'
        && (!is_string($class) || !str_starts_with($class, 'button--')),
    ));

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['stripThemeButtonClasses'];
  }

  /**
   * {@inheritdoc}
   *
   * The radios are built one by one to fit the component slot, so validating
   * against the option list happens here instead of coming free from the
   * #options of a radios element.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $question = $this->loadQuestion($form_state);
    if ($question === NULL) {
      $form_state->setErrorByName('choice', $this->t('This poll is no longer available.'));
      return;
    }
    if (!$this->policy->isOpen($question)) {
      $form_state->setErrorByName('choice', BallotNotice::closed());
      return;
    }

    $choice = $form_state->getValue('choice');
    if ($choice === NULL || $choice === '') {
      $form_state->setErrorByName('choice', $this->t('Select one option to vote.'));
      return;
    }

    $allowed = array_map('strval', (array) $form_state->get('option_keys'));
    if (!in_array((string) $choice, $allowed, TRUE)) {
      $form_state->setErrorByName('choice', $this->t('The chosen option is not allowed.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $question = $this->loadQuestion($form_state);
    $option = $this->entityTypeManager
      ->getStorage('voting_option')
      ->load($form_state->getValue('choice'));

    if ($question === NULL || $option === NULL) {
      $this->messenger()->addError($this->t('The chosen option is no longer available.'));
      $form_state->setRebuild();
      return;
    }

    try {
      $this->ballotBox->cast($question, $option, $this->currentUser());
      $this->messenger()->addStatus(BallotNotice::recorded());
      $form_state->setRedirectUrl($question->toUrl());
    }
    catch (DuplicateVoteException) {
      $this->messenger()->addError(BallotNotice::alreadyVoted());
      $form_state->setRebuild();
    }
    catch (VotingClosedException) {
      $this->messenger()->addError($this->t('This poll is closed and no longer accepts votes.'));
      $form_state->setRebuild();
    }
  }

  /**
   * Reloads the question from its stored id, or NULL if it no longer exists.
   */
  private function loadQuestion(FormStateInterface $form_state): ?VotingQuestionInterface {
    $question = $this->entityTypeManager
      ->getStorage('voting_question')
      ->load($form_state->get('question_id'));

    return $question instanceof VotingQuestionInterface ? $question : NULL;
  }

  /**
   * The state the vote-status component shows when the reader cannot vote.
   *
   * @param array<int, array<string, mixed>> $ballot_options
   */
  private function ballotState(VotingQuestionInterface $question, array $ballot_options, bool $already_voted): string {
    if ($ballot_options === []) {
      return 'empty';
    }
    if ($already_voted) {
      return 'voted';
    }
    if (!$this->policy->isOpen($question)) {
      return 'closed';
    }

    return 'anonymous';
  }

  /**
   * The message explaining why the reader cannot vote right now.
   *
   * @param array<int, array<string, mixed>> $ballot_options
   */
  private function ballotNotice(VotingQuestionInterface $question, array $ballot_options, bool $already_voted): string {
    if ($ballot_options === []) {
      return (string) $this->t('This poll has no options yet.');
    }
    if ($already_voted) {
      return (string) BallotNotice::alreadyVoted();
    }
    if (!$this->policy->isOpen($question)) {
      return (string) BallotNotice::closed();
    }

    return $this->currentUser()->isAnonymous()
      ? (string) $this->t('Sign in to vote in this poll.')
      : (string) BallotNotice::notAllowed();
  }

  /**
   * Where an anonymous reader goes to get a ballot.
   *
   * The destination carries the poll along, so signing in or registering
   * lands the reader back on the question they clicked rather than on the
   * site front page.
   */
  private function signInUrl(VotingQuestionInterface $question): string {
    if (!$this->currentUser()->isAnonymous()) {
      return '';
    }

    return Url::fromRoute('user.login', [], [
      'query' => ['destination' => $question->toUrl()->toString()],
    ])->toString();
  }

}
