<?php

declare(strict_types=1);

namespace Drupal\crosspost;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;

/**
 * Keeps the tokens platforms hand over when a person approves an app.
 *
 * A token like that arrives by itself, so it cannot be a key the site owner
 * pastes. It is kept in the database, outside configuration, so it never
 * reaches a configuration export.
 */
class TokenStore {

  public function __construct(protected KeyValueFactoryInterface $keyValue) {}

  /**
   * A connection's tokens.
   *
   * @return array<string, string>
   *   The tokens, keyed as the adapter named them.
   */
  public function get(string $connection_id): array {
    return (array) $this->keyValue->get('crosspost.tokens')->get($connection_id, []);
  }

  /**
   * Saves a connection's tokens.
   *
   * @param string $connection_id
   *   The connection.
   * @param array<string, string> $tokens
   *   The tokens.
   */
  public function set(string $connection_id, array $tokens): void {
    $this->keyValue->get('crosspost.tokens')->set($connection_id, $tokens);
  }

  /**
   * Forgets a connection's tokens.
   */
  public function delete(string $connection_id): void {
    $this->keyValue->get('crosspost.tokens')->delete($connection_id);
  }

}
