<?php

declare(strict_types=1);

namespace Drupal\crosspost;

use Drupal\crosspost\Entity\ConnectionInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * Turns a connection into the credentials its adapter asked for.
 */
class Credentials {

  public function __construct(protected KeyRepositoryInterface $keys) {}

  /**
   * Resolves a connection's credentials.
   *
   * @param \Drupal\crosspost\Entity\ConnectionInterface $connection
   *   The connection.
   *
   * @return array<string, string>
   *   The values, secrets included, keyed by credential field name.
   */
  public function resolve(ConnectionInterface $connection): array {
    return $connection->getSettings() + $this->secrets($connection->getKeys());
  }

  /**
   * Reads the values of keys.
   *
   * @param array<string, string> $key_ids
   *   Key IDs, keyed by credential field name.
   *
   * @return array<string, string>
   *   The key values, keyed the same. A missing key gives an empty string.
   */
  public function secrets(array $key_ids): array {
    $values = [];
    foreach ($key_ids as $name => $key_id) {
      $key = $key_id ? $this->keys->getKey($key_id) : NULL;
      $values[$name] = $key ? trim((string) $key->getKeyValue()) : '';
    }
    return $values;
  }

}
