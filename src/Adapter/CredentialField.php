<?php

declare(strict_types=1);

namespace Drupal\crosspost\Adapter;

/**
 * One thing an adapter needs to know to connect an account.
 *
 * A secret field is never stored by Crosspost: the site owner picks a key
 * from the Key module, and only the key's ID is kept.
 */
final class CredentialField {

  /**
   * Constructs a CredentialField.
   *
   * @param string $name
   *   The machine name; the key in the credentials array.
   * @param string|\Stringable $label
   *   The field's label.
   * @param string|\Stringable $description
   *   Help shown under the field.
   * @param bool $secret
   *   Whether the value is a secret, kept in the Key module.
   * @param string $default
   *   The default value, for fields that are not secret.
   * @param bool $required
   *   Whether the field must be filled in.
   */
  public function __construct(
    public readonly string $name,
    public readonly string|\Stringable $label,
    public readonly string|\Stringable $description = '',
    public readonly bool $secret = FALSE,
    public readonly string $default = '',
    public readonly bool $required = TRUE,
  ) {}

}
