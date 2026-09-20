<?php

declare(strict_types=1);

namespace Drupal\crosspost\Adapter;

use Drupal\Core\Plugin\PluginBase;

/**
 * Base class for Crosspost adapters.
 */
abstract class AdapterBase extends PluginBase implements AdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function accountNoun(): string {
    return (string) ($this->pluginDefinition['account_noun'] ?? $this->t('account'));
  }

}
