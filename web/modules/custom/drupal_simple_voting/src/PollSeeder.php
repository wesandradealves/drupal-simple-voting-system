<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\FileInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Fills a fresh install with sample polls, voters and ballots.
 *
 * The content ships with the module so that bringing the environment up is
 * enough to have something to vote on, and so uninstalling takes it away
 * again.
 */
final class PollSeeder implements ContainerInjectionInterface {

  public const VOTER_ROLE = 'voter';
  public const DEMO_USER = 'eleitor';

  private const SEEDED_USERS_KEY = 'drupal_simple_voting.seeded_users';
  private const IMAGE_DIRECTORY = 'public://polls';

  /**
   * Which option each seeded voter picks, as an index into the option list.
   *
   * A flat spread would give every option the same percentage and the result
   * screen would look like placeholder data. This leans on the first options
   * so the tally reads like a real poll, and it is a constant so the same
   * percentages come back on every install.
   */
  private const PREFERENCE = [0, 1, 0, 2, 0, 1, 3, 0, 1, 2, 0, 4];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly ModuleExtensionList $moduleList,
    private readonly StateInterface $state,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('extension.list.module'),
      $container->get('state'),
    );
  }

  /**
   * Creates the sample voters, polls and ballots, once.
   */
  public function seed(): void {
    $data = $this->readSeedData();
    if ($data === NULL) {
      return;
    }

    $voters = $this->seedUsers($data['users'] ?? []);
    $questions = $this->seedPolls($data['polls'] ?? []);
    $this->seedBallots($questions, $voters);
  }

  /**
   * Deletes the demonstration account and every account this class created.
   */
  public function removeSeededAccounts(): void {
    $storage = $this->entityTypeManager->getStorage('user');

    $names = $this->state->get(self::SEEDED_USERS_KEY, []);
    $names[] = self::DEMO_USER;

    foreach (array_unique($names) as $name) {
      $found = $storage->loadByProperties(['name' => $name]);
      if ($found !== []) {
        $storage->delete($found);
      }
    }

    $this->state->delete(self::SEEDED_USERS_KEY);
  }

  private function readSeedData(): ?array {
    $path = $this->moduleList->getPath('drupal_simple_voting') . '/data/seed.json';
    if (!is_readable($path)) {
      return NULL;
    }

    $decoded = json_decode((string) file_get_contents($path), TRUE);

    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * @return \Drupal\user\UserInterface[]
   */
  private function seedUsers(array $rows): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $created = [];
    $names = $this->state->get(self::SEEDED_USERS_KEY, []);

    foreach ($rows as $row) {
      $name = (string) ($row['name'] ?? '');
      if ($name === '') {
        continue;
      }

      $existing = $storage->loadByProperties(['name' => $name]);
      if ($existing !== []) {
        $created[] = reset($existing);
        continue;
      }

      $user = User::create([
        'name' => $name,
        'pass' => $name,
        'mail' => (string) ($row['mail'] ?? $name . '@example.com'),
        'status' => 1,
      ]);
      $user->addRole(self::VOTER_ROLE);
      $user->save();

      $created[] = $user;
      $names[] = $name;
    }

    $this->state->set(self::SEEDED_USERS_KEY, array_values(array_unique($names)));

    return $created;
  }

  /**
   * @return \Drupal\drupal_simple_voting\VotingQuestionInterface[]
   */
  private function seedPolls(array $rows): array {
    $questions = $this->entityTypeManager->getStorage('voting_question');
    $options = $this->entityTypeManager->getStorage('voting_option');
    $created = [];

    foreach ($rows as $row) {
      $title = (string) ($row['title'] ?? '');
      if ($title === '' || $questions->loadByProperties(['title' => $title]) !== []) {
        continue;
      }

      $question = $questions->create([
        'title' => $title,
        'description' => (string) ($row['description'] ?? ''),
        'show_results' => (bool) ($row['show_results'] ?? TRUE),
        'status' => (bool) ($row['status'] ?? TRUE),
      ]);
      $question->save();

      $weight = 0;
      foreach ($row['options'] ?? [] as $index => $option) {
        $image = $this->drawOption(
          sprintf('poll-%s-%d.png', $question->id(), $index),
          $option['color'] ?? [70, 110, 160],
        );

        $options->create([
          'question' => $question->id(),
          'title' => (string) ($option['title'] ?? ''),
          'description' => (string) ($option['description'] ?? ''),
          'image' => $image instanceof FileInterface ? ['target_id' => $image->id()] : NULL,
          'weight' => $weight++,
        ])->save();
      }

      $created[] = $question;
    }

    return $created;
  }

  /**
   * Spreads ballots across the sample polls so results have something to show.
   *
   * The tally is deterministic on purpose: a reviewer reopening the site sees
   * the same percentages the screenshots show.
   */
  private function seedBallots(array $questions, array $voters): void {
    if ($questions === [] || $voters === []) {
      return;
    }

    $ballotBox = \Drupal::service('drupal_simple_voting.ballot_box');
    $optionStorage = $this->entityTypeManager->getStorage('voting_option');

    foreach ($questions as $qi => $question) {
      $options = array_values($optionStorage->loadByProperties(['question' => $question->id()]));
      if ($options === []) {
        continue;
      }

      foreach ($voters as $vi => $voter) {
        // Leave the last two voters without a ballot on each poll so the
        // "not voted yet" state is also reachable in the seeded site.
        if ($vi >= count($voters) - 2) {
          continue;
        }

        $choice = $options[self::PREFERENCE[($vi + $qi) % count(self::PREFERENCE)] % count($options)];
        try {
          $ballotBox->cast($question, $choice, $voter);
        }
        catch (\Throwable) {
          // A closed poll or an already-cast ballot is an expected outcome
          // here; the seed only fills what the domain rules allow.
        }
      }
    }
  }

  /**
   * Draws a flat thumbnail for one option and saves it as a managed file.
   */
  private function drawOption(string $filename, array $rgb): ?FileInterface {
    if (!function_exists('imagecreatetruecolor')) {
      return NULL;
    }

    [$width, $height] = [480, 360];
    $canvas = imagecreatetruecolor($width, $height);

    $background = imagecolorallocate($canvas, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2]);
    imagefilledrectangle($canvas, 0, 0, $width, $height, $background);

    $veil = imagecolorallocatealpha($canvas, 255, 255, 255, 100);
    imagefilledellipse($canvas, (int) ($width * 0.80), (int) ($height * 0.20), 280, 280, $veil);
    imagefilledellipse($canvas, (int) ($width * 0.14), (int) ($height * 0.88), 220, 220, $veil);

    $directory = self::IMAGE_DIRECTORY;
    $this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );

    ob_start();
    imagepng($canvas);
    $bytes = (string) ob_get_clean();
    imagedestroy($canvas);

    $uri = $this->fileSystem->saveData($bytes, $directory . '/' . $filename, FileExists::Replace);
    if ($uri === FALSE) {
      return NULL;
    }

    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $uri,
      'status' => 1,
    ]);
    $file->save();

    return $file;
  }

}
