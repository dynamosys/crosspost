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
   * @param string[] $granted
   *   The permissions the platform says the person granted, when it tells.
   */
  public function __construct(
    public readonly string $person,
    public readonly array $accounts,
    public readonly array $granted = [],
  ) {}

}
