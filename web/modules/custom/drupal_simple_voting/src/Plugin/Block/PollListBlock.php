<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\drupal_simple_voting\PollIndex;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Places the poll list in any region of any theme.
 */
#[Block(
  id: 'drupal_simple_voting_poll_list',
  admin_label: new TranslatableMarkup('Poll list'),
  category: new TranslatableMarkup('Voting'),
)]
final class PollListBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Which pager on the page this block owns.
   *
   * The poll page lists every question without a pager, so element zero is
   * free and the block can keep its page argument to itself.
   */
  private const PAGER_ELEMENT = 0;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly PollIndex $pollIndex,
    private readonly AccountInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('drupal_simple_voting.poll_index'),
      $container->get('current_user'),
    );
  }

  public function defaultConfiguration(): array {
    return ['items_per_page' => 5];
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $form['items_per_page'] = [
      '#type' => 'number',
      '#title' => $this->t('Polls per page'),
      '#description' => $this->t('How many polls to show before the pager. Zero lists every poll and hides the pager.'),
      '#default_value' => $this->configuration['items_per_page'],
      '#min' => 0,
      '#max' => 100,
      '#required' => TRUE,
    ];

    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['items_per_page'] = (int) $form_state->getValue('items_per_page');
  }

  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'access content');
  }

  public function build(): array {
    return $this->pollIndex->build(
      $this->currentUser,
      (int) $this->configuration['items_per_page'],
      self::PAGER_ELEMENT,
    );
  }

}
