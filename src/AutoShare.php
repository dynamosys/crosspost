<?php

declare(strict_types=1);

namespace Drupal\crosspost;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Shares content by itself, for the content types set up that way.
 */
class AutoShare {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Sharer $sharer,
    protected Hashtags $hashtags,
  ) {}

  /**
   * The connections that start ticked for a content type.
   *
   * @return \Drupal\crosspost\Entity\ConnectionInterface[]
   *   The connections, keyed by ID.
   */
  public function defaultConnections(string $bundle): array {
    $type = $this->configFactory->get('crosspost.settings')->get('types.' . $bundle) ?: [];
    $connections = $this->entityTypeManager->getStorage('crosspost_connection')->loadMultiple();
    if (!empty($type['all_connections'])) {
      return $connections;
    }
    return array_intersect_key($connections, array_flip($type['connections'] ?? []));
  }

  /**
   * The words a piece of content starts with: its title, then its hashtags.
   */
  public function defaultText(NodeInterface $node): string {
    $tags = $this->hashtags->forNode($node);
    return trim($node->label() . ($tags ? "\n\n" . implode(' ', $tags) : ''));
  }

  /**
   * Queues a piece of content when it has just been published.
   *
   * Only the moment of publishing counts, so editing old content shares
   * nothing, and switching the setting on shares nothing from the past.
   */
  public function consider(NodeInterface $node, ?NodeInterface $original = NULL): void {
    $type = $this->configFactory->get('crosspost.settings')->get('types.' . $node->bundle()) ?: [];
    if (empty($type['enabled']) || empty($type['auto']) || !$node->isPublished() || !$node->isDefaultTranslation()) {
      return;
    }
    if ($original && $original->isPublished()) {
      return;
    }
    // Words someone already wrote on the Share tab win over the title.
    $storage = $this->entityTypeManager->getStorage('crosspost_announcement');
    $drafts = $storage->loadByProperties(['node' => $node->id(), 'state' => 'draft']);
    $draft = reset($drafts);
    $text = $draft ? $draft->getText() : $this->defaultText($node);
    foreach ($this->defaultConnections($node->bundle()) as $connection) {
      $this->sharer->queue($node, $connection, $text, TRUE);
    }
    if ($draft) {
      $storage->delete($drafts);
    }
  }

}
