<?php

declare(strict_types=1);

namespace Drupal\Tests\crosspost\Kernel;

use Drupal\crosspost\Adapter\AdapterException;
use Drupal\crosspost\Adapter\AdapterInterface;
use Drupal\crosspost\Adapter\Message;
use Drupal\crosspost\Adapter\Outcome;
use Drupal\crosspost_test\Plugin\CrosspostAdapter\Memory;
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
  protected static $modules = ['crosspost', 'crosspost_test'];

  /**
   * Adapters are found, and listed alphabetically by label.
   */
  public function testDiscoveryIsAlphabetical(): void {
    $definitions = $this->container->get('plugin.manager.crosspost_adapter')->getDefinitions();
    $this->assertSame(['another', 'memory'], array_keys($definitions));
  }

  /**
   * An adapter names its account, takes a post, and reports a refusal.
   */
  public function testAdapterContract(): void {
    $adapter = $this->container->get('plugin.manager.crosspost_adapter')->createInstance('memory');
    $this->assertInstanceOf(AdapterInterface::class, $adapter);
    $this->assertSame('Memory', $adapter->label());
    $this->assertSame(20, $adapter->limits()->textLength);
    $this->assertCount(1, $adapter->guide()->steps);
    $this->assertSame('someone', $adapter->identify(['name' => 'someone']));

    Memory::$posted = [];
    $taken = $adapter->post(new Message('Short enough', 'https://example.com/a'), ['name' => 'someone']);
    $this->assertSame(Outcome::Posted, $taken->outcome);
    $this->assertSame('https://example.com/posts/1', $taken->remoteUrl);
    $this->assertCount(1, Memory::$posted);

    $refused = $adapter->post(new Message('This one is far too long for the platform', 'https://example.com/a'), ['name' => 'someone']);
    $this->assertSame(Outcome::Refused, $refused->outcome);
    $this->assertSame('Too long.', $refused->reply);
    $this->assertCount(1, Memory::$posted);

    $this->expectException(AdapterException::class);
    $adapter->identify(['name' => 'nobody']);
  }

}
