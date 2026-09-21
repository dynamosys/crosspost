<?php

declare(strict_types=1);

namespace Drupal\crosspost\Adapter;

/**
 * What came back from the platform after a person approved the app.
 */
final class Approval {

  /**
   * Constructs an Approval.
   *
   * @param string $person
   *   The name of the person who approved, as the platform gives it.
   * @param \Drupal\crosspost\Adapter\Account[] $accounts
   *   The accounts that person may connect.
   */
  public function __construct(
    public readonly string $person,
    public readonly array $accounts,
  ) {}

}
