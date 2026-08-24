<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The sentences the ballot and result screens say to the elector.
 *
 * One owner for the wording the two screens share, so a change to a phrase
 * reaches both at once. The JSON API deliberately does not read from here: its
 * messages are untranslated protocol strings, not interface text.
 */
final class BallotNotice {

  public static function closed(): TranslatableMarkup {
    return new TranslatableMarkup('This poll is closed.');
  }

  public static function alreadyVoted(): TranslatableMarkup {
    return new TranslatableMarkup('You have already voted in this poll.');
  }

  public static function recorded(): TranslatableMarkup {
    return new TranslatableMarkup('Your vote was recorded.');
  }

  public static function notAllowed(): TranslatableMarkup {
    return new TranslatableMarkup('You are not allowed to vote in this poll.');
  }

}
