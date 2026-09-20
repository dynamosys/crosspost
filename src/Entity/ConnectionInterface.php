<?php

declare(strict_types=1);

namespace Drupal\crosspost\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * One connected account or page on a platform.
 */
interface ConnectionInterface extends ConfigEntityInterface {

  /**
   * The ID of the adapter plugin this connection uses.
   */
  public function getAdapterId(): string;

  /**
   * The values that are not secret, keyed by credential field name.
   *
   * @return array<string, string>
   *   The values.
   */
  public function getSettings(): array;

  /**
   * The Key module key IDs of the secret values, keyed by field name.
   *
   * @return array<string, string>
   *   The key IDs.
   */
  public function getKeys(): array;

}
