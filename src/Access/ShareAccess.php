<?php

declare(strict_types=1);

namespace Drupal\crosspost\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\crosspost\Entity\AnnouncementInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Who sees the Share tab, and who may act on an announcement.
 */
class ShareAccess implements ContainerInjectionInterface {

  public function __construct(protected ConfigFactoryInterface $configFactory) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('config.factory'));
  }

  /**
   * The Share tab: the type is switched on, and the person may edit the page.
   */
  public function access(AccountInterface $account, NodeInterface $node): AccessResultInterface {
    $config = $this->configFactory->get('crosspost.settings');
    return AccessResult::allowedIf((bool) $config->get('types.' . $node->bundle() . '.enabled'))
      ->andIf(AccessResult::allowedIfHasPermission($account, 'share content with crosspost'))
      ->andIf($node->access('update', $account, TRUE))
      ->addCacheableDependency($config);
  }

  /**
   * Try now, try again, cancel.
   */
  public function actAccess(AccountInterface $account, AnnouncementInterface $crosspost_announcement): AccessResultInterface {
    $node = $crosspost_announcement->getNode();
    return AccessResult::allowedIfHasPermission($account, 'share content with crosspost')
      ->andIf($node ? $node->access('update', $account, TRUE) : AccessResult::allowedIfHasPermission($account, 'administer crosspost'));
  }

}
