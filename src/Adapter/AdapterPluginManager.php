<?php

declare(strict_types=1);

namespace Drupal\crosspost\Adapter;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\crosspost\Attribute\CrosspostAdapter;

/**
 * Finds the installed adapters.
 *
 * Definitions come back sorted by label, so every list in the interface is
 * alphabetical and the module ranks no platform.
 */
class AdapterPluginManager extends DefaultPluginManager {

  /**
   * Constructs the manager.
   *
   * @param \Traversable $namespaces
   *   The namespaces to look in.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The discovery cache.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/CrosspostAdapter', $namespaces, $module_handler, AdapterInterface::class, CrosspostAdapter::class);
    $this->alterInfo('crosspost_adapter_info');
    $this->setCacheBackend($cache_backend, 'crosspost_adapter_plugins');
  }

  /**
   * {@inheritdoc}
   */
  protected function findDefinitions(): array {
    $definitions = parent::findDefinitions();
    uasort($definitions, static fn(array $a, array $b): int => strnatcasecmp((string) $a['label'], (string) $b['label']));
    return $definitions;
  }

}
