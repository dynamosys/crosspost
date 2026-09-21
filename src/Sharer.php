<?php

declare(strict_types=1);

namespace Drupal\crosspost;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\crosspost\Adapter\AdapterPluginManager;
use Drupal\crosspost\Adapter\Message;
use Drupal\crosspost\Adapter\Outcome;
use Drupal\crosspost\Entity\AnnouncementInterface;
use Drupal\crosspost\Entity\ConnectionInterface;
use Drupal\node\NodeInterface;

/**
 * Carries announcements to their platforms, and keeps the record straight.
 */
class Sharer {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AdapterPluginManager $adapters,
    protected Credentials $credentials,
    protected PublicPage $publicPage,
    protected ConfigFactoryInterface $configFactory,
    protected LockBackendInterface $lock,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected DateFormatterInterface $dateFormatter,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Queues a piece of content for a connection.
   *
   * Does nothing when the content already went, or is on its way, to that
   * connection: nothing is shared twice.
   *
   * @return \Drupal\crosspost\Entity\AnnouncementInterface|null
   *   The new announcement, or NULL when there was one already.
   */
  public function queue(NodeInterface $node, ConnectionInterface $connection, string $text, bool $automatic = FALSE): ?AnnouncementInterface {
    $storage = $this->entityTypeManager->getStorage('crosspost_announcement');
    $existing = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('node', $node->id())
      ->condition('connection', $connection->id())
      ->condition('state', [AnnouncementInterface::WAITING, AnnouncementInterface::POSTED], 'IN')
      ->range(0, 1)
      ->execute();
    if ($existing) {
      return NULL;
    }
    $now = $this->time->getRequestTime();
    /** @var \Drupal\crosspost\Entity\AnnouncementInterface $announcement */
    $announcement = $storage->create([
      'node' => $node->id(),
      'connection' => $connection->id(),
      'place' => $this->place($connection),
      'text' => $text,
      'state' => AnnouncementInterface::WAITING,
      'automatic' => $automatic,
      'queued' => $now,
      'next_try' => $now,
      'uid' => $this->currentUser->id(),
    ]);
    $announcement->save();
    return $announcement;
  }

  /**
   * What a connection is called in lists and in the log.
   */
  public function place(ConnectionInterface $connection): string {
    $definition = $this->adapters->getDefinition($connection->getAdapterId(), FALSE);
    return ($definition ? $definition['label'] : $connection->getAdapterId()) . ' · ' . $connection->label();
  }

  /**
   * Tries every announcement that is due.
   *
   * @return \Drupal\crosspost\Entity\AnnouncementInterface[]
   *   The announcements that were tried.
   */
  public function processDue(int $limit = 50): array {
    $storage = $this->entityTypeManager->getStorage('crosspost_announcement');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', AnnouncementInterface::WAITING)
      ->condition('next_try', $this->time->getCurrentTime(), '<=')
      ->sort('next_try')
      ->range(0, $limit)
      ->execute();
    $tried = [];
    // One check of a page serves all its announcements in this run.
    $checks = [];
    foreach ($storage->loadMultiple($ids) as $announcement) {
      $tried[] = $this->process($announcement, $checks);
    }
    return $tried;
  }

  /**
   * Tries one announcement now.
   *
   * @param \Drupal\crosspost\Entity\AnnouncementInterface $announcement
   *   The announcement; it must be waiting.
   * @param \Drupal\crosspost\PageCheck[] $checks
   *   (optional) Page checks already made in this run, keyed by node ID.
   */
  public function process(AnnouncementInterface $announcement, array &$checks = []): AnnouncementInterface {
    $lock = 'crosspost_announcement:' . $announcement->id();
    if ($announcement->getState() !== AnnouncementInterface::WAITING || !$this->lock->acquire($lock, 60)) {
      return $announcement;
    }
    try {
      // Read it again under the lock: another run may have been quicker.
      $storage = $this->entityTypeManager->getStorage('crosspost_announcement');
      $storage->resetCache([$announcement->id()]);
      /** @var \Drupal\crosspost\Entity\AnnouncementInterface $announcement */
      $announcement = $storage->load($announcement->id());
      if ($announcement && $announcement->getState() === AnnouncementInterface::WAITING) {
        $this->attempt($announcement, $checks);
        $announcement->save();
      }
    }
    finally {
      $this->lock->release($lock);
    }
    return $announcement;
  }

  /**
   * Makes the attempt and writes the outcome onto the announcement.
   */
  protected function attempt(AnnouncementInterface $announcement, array &$checks): void {
    $settings = $this->configFactory->get('crosspost.settings');
    $now = $this->time->getCurrentTime();
    $node = $announcement->getNode();
    /** @var \Drupal\crosspost\Entity\ConnectionInterface|null $connection */
    $connection = $this->entityTypeManager->getStorage('crosspost_connection')->load($announcement->getConnectionId());
    if (!$node) {
      $this->end($announcement, AnnouncementInterface::CANCELLED, (string) $this->t('The content was deleted before this went out.'));
      return;
    }
    if (!$connection || !$this->adapters->hasDefinition($connection->getAdapterId())) {
      $this->end($announcement, AnnouncementInterface::CANCELLED, (string) $this->t('The account was disconnected before this went out.'));
      return;
    }

    $check = $checks[$node->id()] ??= $this->publicPage->check($node);
    if (!$check->isPublic()) {
      $waited = $now - (int) $announcement->get('queued')->value;
      $hours = max(1, (int) $settings->get('check_for'));
      if ($waited > $hours * 3600) {
        $this->end($announcement, AnnouncementInterface::FAILED, (string) $this->formatPlural($hours, 'The page did not become public within 1 hour. Nothing was posted.', 'The page did not become public within @count hours. Nothing was posted.'));
        return;
      }
      $next = $now + max(1, (int) $settings->get('check_every')) * 60;
      $announcement->set('next_try', $next);
      $announcement->set('reply', $check->status
        ? (string) $this->t('The page is not public yet: its address answered @status. Next check @time.', [
          '@status' => $check->status,
          '@time' => $this->dateFormatter->format($next, 'short'),
        ])
        : (string) $this->t('The page is not public yet: its address did not answer. Next check @time.', ['@time' => $this->dateFormatter->format($next, 'short')]));
      return;
    }

    $adapter = $this->adapters->createInstance($connection->getAdapterId());
    $message = new Message($announcement->getText(), $check->url, $check->title, $check->summary, $check->image);
    $result = $adapter->post($message, $this->credentials->resolve($connection));
    $announcement->set('http_status', $result->status);
    $announcement->set('tries', (int) $announcement->get('tries')->value + 1);

    switch ($result->outcome) {
      case Outcome::Posted:
        $announcement->set('posted', $now);
        $announcement->set('remote_id', $result->remoteId);
        $announcement->set('remote_url', $result->remoteUrl);
        $this->end($announcement, AnnouncementInterface::POSTED, $result->reply);
        $this->state->delete('crosspost.problem.' . $connection->id());
        break;

      case Outcome::Refused:
        $this->end($announcement, AnnouncementInterface::REFUSED, $result->reply);
        $announcement->set('access_lost', $result->accessLost);
        if ($result->accessLost || in_array($result->status, [401, 403], TRUE)) {
          $this->state->set('crosspost.problem.' . $connection->id(), $result->reply);
        }
        break;

      case Outcome::Failed:
        $tries = (int) $announcement->get('tries')->value;
        if ($tries > (int) $settings->get('retries')) {
          $this->end($announcement, AnnouncementInterface::FAILED, $result->reply);
          break;
        }
        // Wait longer each time: 5, 10, 20 minutes and so on.
        $next = $now + 300 * (2 ** ($tries - 1));
        $announcement->set('next_try', $next);
        $announcement->set('reply', $result->reply . ' ' . $this->t('Next try @time.', ['@time' => $this->dateFormatter->format($next, 'short')]));
        break;
    }
  }

  /**
   * Puts an announcement into a final state.
   */
  protected function end(AnnouncementInterface $announcement, string $state, string $reply): void {
    $announcement->setState($state);
    $announcement->set('reply', $reply);
    $announcement->set('next_try', NULL);
  }

  /**
   * Puts a refused, failed or cancelled announcement back in line.
   */
  public function requeue(AnnouncementInterface $announcement): void {
    $now = $this->time->getCurrentTime();
    $announcement->setState(AnnouncementInterface::WAITING);
    $announcement->set('tries', 0);
    $announcement->set('queued', $now);
    $announcement->set('next_try', $now);
    $announcement->save();
  }

  /**
   * Puts back in line what a connection refused because it had lost access.
   *
   * Called once the connection has its access again.
   *
   * @return int
   *   How many announcements went back in line.
   */
  public function requeueAccessLost(string $connection_id): int {
    $storage = $this->entityTypeManager->getStorage('crosspost_announcement');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('connection', $connection_id)
      ->condition('state', AnnouncementInterface::REFUSED)
      ->condition('access_lost', 1)
      ->execute();
    foreach ($storage->loadMultiple($ids) as $announcement) {
      $announcement->set('access_lost', FALSE);
      $this->requeue($announcement);
    }
    return count($ids);
  }

  /**
   * Cancels an announcement that has not gone out.
   */
  public function cancel(AnnouncementInterface $announcement): void {
    if ($announcement->getState() === AnnouncementInterface::WAITING) {
      $this->end($announcement, AnnouncementInterface::CANCELLED, (string) $this->t('Cancelled by @name.', ['@name' => (string) $this->currentUser->getDisplayName()]));
      $announcement->save();
    }
  }

}
