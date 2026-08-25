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

  /**
   * Injects the shared poll index and the user the listing is rendered for.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly PollIndex $pollIndex,
    private readonly AccountInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * Resolves the poll index and current user services from the container.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('drupal_simple_voting.poll_index'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Defaults to five polls per page.
   */
  public function defaultConfiguration(): array {
    return ['items_per_page' => 5];
  }

  /**
   * {@inheritdoc}
   *
   * Exposes the polls-per-page setting; zero lists every poll and hides the
   * pager.
   */
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

  /**
   * {@inheritdoc}
   *
   * Persists the polls-per-page setting cast to an integer.
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['items_per_page'] = (int) $form_state->getValue('items_per_page');
  }

  /**
   * {@inheritdoc}
   *
   * Grants the block to any account allowed to access content.
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'access content');
  }

  /**
   * {@inheritdoc}
   *
   * Delegates to the shared PollIndex, passing the configured page size and
   * this block's own pager element.
   */
  public function build(): array {
    return $this->pollIndex->build(
      $this->currentUser,
      (int) $this->configuration['items_per_page'],
      self::PAGER_ELEMENT,
    );
  }

}
