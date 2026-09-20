<?php

declare(strict_types=1);

namespace Drupal\Tests\crosspost\Kernel;

use Drupal\crosspost\Adapter\AdapterException;
use Drupal\crosspost\Adapter\AdapterInterface;
use Drupal\crosspost\Adapter\Message;
use Drupal\crosspost\Adapter\Outcome;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests adapter discovery and the adapter contract.
 *
 * @group crosspost
 */
#[Group('crosspost')]
class AdapterPluginManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'node', 'key', 'crosspost', 'crosspost_test'];

  /**
   * Adapters are found, and listed alphabetically by label.
   */
  public function testDiscoveryIsAlphabetical(): void {
    $definitions = $this->container->get('plugin.manager.crosspost_adapter')->getDefinitions();
    $this->assertSame(['another', 'bluesky', 'mastodon', 'memory'], array_keys($definitions));
  }

  /**
   * An adapter names its account, takes a post, and reports a refusal.
   */
  public function testAdapterContract(): void {
    $manager = $this->container->get('plugin.manager.crosspost_adapter');
    $adapter = $manager->createInstance('memory');
    $this->assertInstanceOf(AdapterInterface::class, $adapter);
    $this->assertSame('Memory', $adapter->label());
    $this->assertSame('account', $adapter->accountNoun());
    $this->assertSame('page', $manager->createInstance('another')->accountNoun());
    $this->assertSame(60, $adapter->limits()->textLength);
    $this->assertSame(5 + 2 + 10, $adapter->limits()->used('Hello', 'https://example.com/a-long-address'));
    $this->assertCount(1, $adapter->guide()->steps);
    $this->assertCount(2, $adapter->credentialFields());
    $this->assertSame('someone', $adapter->identify(['name' => 'someone']));

    $taken = $adapter->post(new Message('Words', 'https://example.com/a'), ['name' => 'someone']);
    $this->assertSame(Outcome::Posted, $taken->outcome);
    $this->assertSame('https://example.com/posts/1', $taken->remoteUrl);

    $this->container->get('state')->set('crosspost_test.outcome', 'refused');
    $refused = $adapter->post(new Message('Words', 'https://example.com/a'), ['name' => 'someone']);
    $this->assertSame(Outcome::Refused, $refused->outcome);
    $this->assertSame('Not allowed.', $refused->reply);
    $this->assertCount(1, $this->container->get('state')->get('crosspost_test.posted'));

    $this->expectException(AdapterException::class);
    $adapter->identify(['name' => 'nobody']);
  }

}
