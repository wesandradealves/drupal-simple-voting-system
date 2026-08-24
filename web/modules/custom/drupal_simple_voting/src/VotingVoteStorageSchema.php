<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Puts the one-vote-per-user rule inside the database.
 *
 * The unique key is the only arbiter of duplicate ballots: the write path
 * inserts and lets the constraint refuse, instead of reading before writing.
 */
class VotingVoteStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base_table = $this->storage->getBaseTable();

    $schema[$base_table]['unique keys'] += [
      'voting_vote__user_question' => ['uid', 'question'],
    ];
    $schema[$base_table]['indexes'] += [
      'voting_vote__tally' => ['question', 'option'],
    ];

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  protected function getSharedTableFieldSchema(FieldStorageDefinitionInterface $storage_definition, $table_name, array $column_mapping) {
    $schema = parent::getSharedTableFieldSchema($storage_definition, $table_name, $column_mapping);

    if ($table_name !== $this->storage->getBaseTable()) {
      return $schema;
    }

    switch ($storage_definition->getName()) {
      case 'uid':
        // Columns of a unique key must be NOT NULL: several databases do not
        // reliably enforce uniqueness over nullable columns.
        $schema['fields']['uid']['not null'] = TRUE;
        $this->addSharedTableFieldForeignKey($storage_definition, $schema, 'users', 'uid');
        break;

      case 'question':
        $schema['fields']['question']['not null'] = TRUE;
        $this->addSharedTableFieldForeignKey($storage_definition, $schema, 'voting_question', 'id');
        break;

      case 'option':
        $schema['fields']['option']['not null'] = TRUE;
        // Entity reference fields declare no foreign key of their own.
        $this->addSharedTableFieldForeignKey($storage_definition, $schema, 'voting_option', 'id');
        break;
    }

    return $schema;
  }

}
