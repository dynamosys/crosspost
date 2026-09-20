<?php

declare(strict_types=1);

namespace Drupal\crosspost\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\node\NodeInterface;

/**
 * One piece of content going to one connection: the module's record.
 */
interface AnnouncementInterface extends ContentEntityInterface {

  /**
   * Written but not sent for sharing.
   */
  const DRAFT = 'draft';

  /**
   * Waiting: for the page to become public, or for another try.
   */
  const WAITING = 'waiting';

  /**
   * The platform took it.
   */
  const POSTED = 'posted';

  /**
   * The platform said no.
   */
  const REFUSED = 'refused';

  /**
   * It could not be delivered.
   */
  const FAILED = 'failed';

  /**
   * Someone cancelled it before it went out.
   */
  const CANCELLED = 'cancelled';

  /**
   * The state, one of the constants above.
   */
  public function getState(): string;

  /**
   * Sets the state.
   */
  public function setState(string $state): static;

  /**
   * The content being shared, if it still exists.
   */
  public function getNode(): ?NodeInterface;

  /**
   * The ID of the connection it goes to.
   */
  public function getConnectionId(): string;

  /**
   * The words to post.
   */
  public function getText(): string;

}
