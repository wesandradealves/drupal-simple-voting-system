<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\drupal_simple_voting\VotingPolicy;

/**
 * Configures the global voting kill switch.
 */
final class VotingSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'voting_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [VotingPolicy::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Accept votes'),
      '#description' => $this->t('Clear this to close every question at once. Existing votes and results are kept.'),
      '#config_target' => VotingPolicy::SETTINGS . ':enabled',
    ];

    return parent::buildForm($form, $form_state);
  }

}
