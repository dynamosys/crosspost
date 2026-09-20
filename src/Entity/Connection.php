<?php

declare(strict_types=1);

namespace Drupal\crosspost\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the connection: one account or page on one platform.
 *
 * A platform can have several. Secrets are not stored here; only the IDs of
 * the keys that hold them.
 *
 * @ConfigEntityType(
 *   id = "crosspost_connection",
 *   label = @Translation("Crosspost connection"),
 *   label_collection = @Translation("Crosspost connections"),
 *   label_singular = @Translation("connection"),
 *   label_plural = @Translation("connections"),
 *   label_count = @PluralTranslation(
 *     singular = "@count connection",
 *     plural = "@count connections",
 *   ),
 *   handlers = {
 *     "form" = {
 *       "delete" = "Drupal\crosspost\Form\ConnectionDeleteForm",
 *     },
 *   },
 *   config_prefix = "connection",
 *   admin_permission = "administer crosspost",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *   },
 *   links = {
 *     "delete-form" = "/admin/config/services/crosspost/{crosspost_connection}/disconnect",
 *     "collection" = "/admin/config/services/crosspost",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "adapter",
 *     "settings",
 *     "keys",
 *   },
 * )
 */
class Connection extends ConfigEntityBase implements ConnectionInterface {

  /**
   * The connection ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The account's name as the platform gave it.
   *
   * @var string
   */
  protected $label;

  /**
   * The adapter plugin ID.
   *
   * @var string
   */
  protected $adapter = '';

  /**
   * The values that are not secret.
   *
   * @var array<string, string>
   */
  protected $settings = [];

  /**
   * The key IDs of the secret values.
   *
   * @var array<string, string>
   */
  protected $keys = [];

  /**
   * {@inheritdoc}
   */
  public function getAdapterId(): string {
    return $this->adapter;
  }

  /**
   * {@inheritdoc}
   */
  public function getSettings(): array {
    return $this->settings;
  }

  /**
   * {@inheritdoc}
   */
  public function getKeys(): array {
    return $this->keys;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    foreach ($this->keys as $key_id) {
      if ($key = \Drupal::entityTypeManager()->getStorage('key')->load($key_id)) {
        $this->addDependency($key->getConfigDependencyKey(), $key->getConfigDependencyName());
      }
    }
    $definition = \Drupal::service('plugin.manager.crosspost_adapter')->getDefinition($this->adapter, FALSE);
    if ($definition) {
      $this->addDependency('module', $definition['provider']);
    }
    return $this;
  }

}
