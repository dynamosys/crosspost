<?php

declare(strict_types=1);

namespace Drupal\crosspost;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Adds the index the due-announcements query uses.
 */
class AnnouncementStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    if ($table = $this->storage->getBaseTable()) {
      $schema[$table]['indexes']['crosspost_due'] = ['state', 'next_try'];
    }
    return $schema;
  }

}
