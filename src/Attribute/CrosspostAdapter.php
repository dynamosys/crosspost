<?php

declare(strict_types=1);

namespace Drupal\crosspost\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a Crosspost adapter: one social platform the module can post to.
 *
 * Plugin namespace: Plugin\CrosspostAdapter.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class CrosspostAdapter extends Plugin {

  /**
   * Constructs a CrosspostAdapter attribute.
   *
   * @param string $id
   *   The plugin ID, the platform's machine name.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The platform's name as its owner writes it.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?string $deriver = NULL,
  ) {}

}
