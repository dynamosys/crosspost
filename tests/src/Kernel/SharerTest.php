<?php

declare(strict_types=1);

namespace Drupal\Tests\crosspost\Kernel;

use Drupal\crosspost\Entity\AnnouncementInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that a page goes out once, only when public, and is recorded.
 *
 * @group crosspost
 */
#[Group('crosspost')]
class SharerTest extends CrosspostKernelTestBase {

  /**
   * A page that is not public waits; once public it is posted, once.
   */
  public function testWaitsUntilPublicAndPostsOnce(): void {
    $sharer = $this->container->get('crosspost.sharer');
    $node = $this->article();
    $connection = $this->connection('someone');

    $announcement = $sharer->queue($node, $connection, 'Words');
    $this->assertNotNull($announcement);
    $this->assertNull($sharer->queue($node, $connection, 'Words again'), 'Nothing is queued twice for one account.');

    $this->web->append(new Response(404));
    $announcement = $sharer->process($announcement);
    $this->assertSame(AnnouncementInterface::WAITING, $announcement->getState());
    $this->assertStringContainsString('answered 404', $announcement->get('reply')->value);
    $this->assertGreaterThan(time(), (int) $announcement->get('next_try')->value);
    $this->assertSame('https://www.example.com/node/' . $node->id(), (string) $this->requests[0]['request']->getUri());
    $this->assertEmpty($this->container->get('state')->get('crosspost_test.posted'));

    // Not due yet: a cron run leaves it alone.
    $this->assertCount(0, $sharer->processDue());

    $announcement->set('next_try', time() - 1)->save();
    $this->web->append(new Response(200, [], $this->publicPage()));
    $tried = $sharer->processDue();
    $this->assertCount(1, $tried);
    $this->assertSame(AnnouncementInterface::POSTED, $tried[0]->getState());
    $this->assertSame('https://example.com/posts/1', $tried[0]->get('remote_url')->value);
    $this->assertSame('Memory · someone', $tried[0]->get('place')->value);
    $posted = $this->container->get('state')->get('crosspost_test.posted');
    $this->assertSame('Words', $posted[0]['text']);
    $this->assertSame('The share title', $posted[0]['title']);
    $this->assertSame('https://www.example.com/picture.jpg', $posted[0]['image']);

    // Posted is final: processing it again does nothing, and it stays queued
    // for nobody.
    $sharer->process($tried[0]);
    $this->assertCount(1, $this->container->get('state')->get('crosspost_test.posted'));
    $this->assertNull($sharer->queue($node, $connection, 'Words'));
  }

  /**
   * One check of a page serves all its announcements in a run.
   */
  public function testOneCheckPerPagePerRun(): void {
    $sharer = $this->container->get('crosspost.sharer');
    $node = $this->article();
    $sharer->queue($node, $this->connection('one'), 'Words');
    $sharer->queue($node, $this->connection('two'), 'Words');
    $this->web->append(new Response(200, [], $this->publicPage()));
    $this->assertCount(2, $sharer->processDue());
    $this->assertCount(1, $this->requests);
    $this->assertCount(2, $this->container->get('state')->get('crosspost_test.posted'));
  }

  /**
   * A refusal is final and flags the account; a failure is tried again.
   */
  public function testRefusedAndFailed(): void {
    $sharer = $this->container->get('crosspost.sharer');
    $state = $this->container->get('state');
    $connection = $this->connection('someone');

    $state->set('crosspost_test.outcome', 'refused');
    $this->web->append(new Response(200, [], $this->publicPage()));
    $refused = $sharer->process($sharer->queue($this->article('One'), $connection, 'Words'));
    $this->assertSame(AnnouncementInterface::REFUSED, $refused->getState());
    $this->assertSame('Not allowed.', $refused->get('reply')->value);
    $this->assertSame(403, (int) $refused->get('http_status')->value);
    $this->assertSame('Not allowed.', $state->get('crosspost.problem.' . $connection->id()));
    $this->assertCount(0, $sharer->processDue(), 'A refusal is not retried by itself.');

    $state->set('crosspost_test.outcome', 'failed');
    $this->config('crosspost.settings')->set('retries', 1)->save();
    $this->web->append(new Response(200, [], $this->publicPage()), new Response(200, [], $this->publicPage()));
    $failed = $sharer->process($sharer->queue($this->article('Two'), $connection, 'Words'));
    $this->assertSame(AnnouncementInterface::WAITING, $failed->getState());
    $this->assertStringContainsString('Gateway timeout.', $failed->get('reply')->value);
    $failed->set('next_try', time() - 1)->save();
    $failed = $sharer->process($failed);
    $this->assertSame(AnnouncementInterface::FAILED, $failed->getState());

    // Someone asks for another try, and the platform is back.
    $state->set('crosspost_test.outcome', 'posted');
    $this->web->append(new Response(200, [], $this->publicPage()));
    $sharer->requeue($failed);
    $this->assertSame(AnnouncementInterface::POSTED, $sharer->process($failed)->getState());
    $this->assertNull($state->get('crosspost.problem.' . $connection->id()));
  }

  /**
   * A page that never becomes public is given up on.
   */
  public function testGivesUpWaiting(): void {
    $sharer = $this->container->get('crosspost.sharer');
    $announcement = $sharer->queue($this->article(), $this->connection('someone'), 'Words');
    $announcement->set('queued', time() - 25 * 3600)->save();
    $this->web->append(new Response(404));
    $announcement = $sharer->process($announcement);
    $this->assertSame(AnnouncementInterface::FAILED, $announcement->getState());
    $this->assertStringContainsString('did not become public within 24 hours', $announcement->get('reply')->value);
  }

  /**
   * Cancelling stops a waiting announcement; a disconnected account too.
   */
  public function testCancelAndDisconnect(): void {
    $sharer = $this->container->get('crosspost.sharer');
    $node = $this->article();
    $one = $sharer->queue($node, $this->connection('one'), 'Words');
    $two_connection = $this->connection('two');
    $two = $sharer->queue($node, $two_connection, 'Words');
    $sharer->cancel($one);
    $this->assertSame(AnnouncementInterface::CANCELLED, $one->getState());
    $two_connection->delete();
    $two = $sharer->process($two);
    $this->assertSame(AnnouncementInterface::CANCELLED, $two->getState());
    $this->assertStringContainsString('disconnected', $two->get('reply')->value);
    $this->assertEmpty($this->requests);
  }

}
