<?php

declare(strict_types=1);

namespace Drupal\crosspost\Plugin\TopBarItem;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\crosspost\Access\ShareAccess;
use Drupal\navigation\Attribute\TopBarItem;
use Drupal\navigation\TopBarItemBase;
use Drupal\navigation\TopBarRegion;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A Share button beside Edit in the Navigation module's top bar.
 *
 * On a page's own address the top bar shows Edit and folds every other tab
 * into a menu. Sharing is what comes after editing, so it gets a button.
 */
#[TopBarItem(
  id: 'crosspost_share',
  region: TopBarRegion::Actions,
  label: new TranslatableMarkup('Share with Crosspost'),
  weight: -10,
)]
final class Share extends TopBarItemBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected RouteMatchInterface $routeMatch,
    protected AccountInterface $currentUser,
    protected ShareAccess $shareAccess,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('current_user'),
      ShareAccess::create($container),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $build = [];
    $cache = (new CacheableMetadata())->addCacheContexts(['route', 'user.permissions']);
    $node = $this->routeMatch->getParameter('node');
    $on_the_page = in_array($this->routeMatch->getRouteName(), ['entity.node.canonical', 'entity.node.latest_version'], TRUE);
    if ($on_the_page && $node instanceof NodeInterface) {
      $access = $this->shareAccess->access($this->currentUser, $node);
      $cache->addCacheableDependency($access)->addCacheableDependency($node);
      if ($access->isAllowed()) {
        $build = [
          '#type' => 'component',
          '#component' => 'navigation:toolbar-button',
          '#props' => [
            'text' => $this->t('Share'),
            'html_tag' => 'a',
            'attributes' => [
              'href' => Url::fromRoute('crosspost.share', ['node' => $node->id()])->toString(),
            ],
          ],
        ];
      }
    }
    $cache->applyTo($build);
    return $build;
  }

}
