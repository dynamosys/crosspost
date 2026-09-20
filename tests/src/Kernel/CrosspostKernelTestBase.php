<?php

declare(strict_types=1);

namespace Drupal\Tests\crosspost\Kernel;

use Drupal\crosspost\Entity\ConnectionInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

/**
 * Base class for Crosspost kernel tests: a content type, and a mocked web.
 */
abstract class CrosspostKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'taxonomy',
    'key',
    'crosspost',
    'crosspost_test',
  ];

  /**
   * The answers the mocked web gives, in order.
   */
  protected MockHandler $web;

  /**
   * The requests that were made.
   *
   * @var array<int, array<string, mixed>>
   */
  protected array $requests = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('crosspost_announcement');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'filter', 'node', 'crosspost']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();

    $this->web = new MockHandler();
    $stack = HandlerStack::create($this->web);
    $stack->push(Middleware::history($this->requests));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $this->config('crosspost.settings')
      ->set('base_url', 'https://www.example.com')
      ->set('types', [
        'article' => [
          'enabled' => TRUE,
          'tag_field' => '',
          'all_connections' => TRUE,
          'connections' => [],
          'auto' => FALSE,
        ],
      ])
      ->save();
  }

  /**
   * Creates a connection to the Memory platform.
   */
  protected function connection(string $name, string $adapter = 'memory'): ConnectionInterface {
    $connection = $this->container->get('entity_type.manager')->getStorage('crosspost_connection')->create([
      'id' => $adapter . '_' . $name,
      'label' => $name,
      'adapter' => $adapter,
      'settings' => ['name' => $name],
      'keys' => [],
    ]);
    $connection->save();
    return $connection;
  }

  /**
   * Creates an article.
   */
  protected function article(string $title = 'A page', bool $published = TRUE): NodeInterface {
    $node = Node::create(['type' => 'article', 'title' => $title, 'status' => $published]);
    $node->save();
    return $node;
  }

  /**
   * A public page with share tags.
   */
  protected function publicPage(): string {
    return '<html><head><title>x</title><meta property="og:title" content="The share title"><meta property="og:description" content="The summary"><meta property="og:image" content="https://www.example.com/picture.jpg"></head><body></body></html>';
  }

}
