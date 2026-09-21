<?php

declare(strict_types=1);

namespace Drupal\crosspost\Adapter;

/**
 * One account or page a person may connect after approving on the platform.
 */
final class Account {

  /**
   * Constructs an Account.
   *
   * @param string $id
   *   The platform's ID for the account.
   * @param string $label
   *   The account's name as the platform shows it.
   * @param array<string, string> $settings
   *   Values to keep with the connection that are not secret.
   * @param array<string, string> $tokens
   *   Access tokens the platform handed over for this account.
   * @param bool $connectable
   *   Whether the person's role on the account allows posting.
   * @param string $note
   *   One line under the name: the kind of account, or why it cannot be
   *   connected.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $label,
    public readonly array $settings = [],
    public readonly array $tokens = [],
    public readonly bool $connectable = TRUE,
    public readonly string $note = '',
  ) {}

}
