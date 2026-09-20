<?php

declare(strict_types=1);

namespace Drupal\Tests\crosspost\Kernel;

use Drupal\crosspost\Adapter\AdapterException;
use Drupal\crosspost\Adapter\Message;
use Drupal\crosspost\Adapter\Outcome;
use Drupal\crosspost\Plugin\CrosspostAdapter\Bluesky;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Bluesky and Mastodon adapters against mocked answers.
 *
 * @group crosspost
 */
#[Group('crosspost')]
class PlatformAdaptersTest extends CrosspostKernelTestBase {

  /**
   * Bluesky facets are counted in bytes, and numbers are not hashtags.
   */
  public function testBlueskyFacets(): void {
    $url = 'https://www.example.com/a';
    $text = "Étude #iPhone18 and #1 \n\n" . $url;
    $facets = Bluesky::facets($text, $url);
    $this->assertCount(2, $facets);
    $this->assertSame($url, substr($text, $facets[0]['index']['byteStart'], $facets[0]['index']['byteEnd'] - $facets[0]['index']['byteStart']));
    $this->assertSame('#iPhone18', substr($text, $facets[1]['index']['byteStart'], $facets[1]['index']['byteEnd'] - $facets[1]['index']['byteStart']));
    $this->assertSame('iPhone18', $facets[1]['features'][0]['tag']);
    $this->assertSame(7, $facets[1]['index']['byteStart'], 'É is two bytes.');
  }

  /**
   * Bluesky: signs in, uploads the picture, posts the record.
   */
  public function testBlueskyPost(): void {
    $adapter = $this->container->get('plugin.manager.crosspost_adapter')->createInstance('bluesky');
    $credentials = ['handle' => '@example.bsky.social', 'password' => 'app-pass', 'service' => 'https://bsky.social'];
    $session = json_encode(['did' => 'did:plc:abc', 'handle' => 'example.bsky.social', 'accessJwt' => 'jwt']);

    $this->web->append(new Response(200, [], $session));
    $this->assertSame('example.bsky.social', $adapter->identify($credentials));
    $this->assertSame('example.bsky.social', json_decode((string) $this->requests[0]['request']->getBody(), TRUE)['identifier']);

    $this->web->append(
      new Response(200, [], $session),
      new Response(200, ['Content-Type' => 'image/jpeg'], 'picture-bytes'),
      new Response(200, [], json_encode([
        'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafk'], 'mimeType' => 'image/jpeg', 'size' => 13],
      ])),
      new Response(200, [], json_encode(['uri' => 'at://did:plc:abc/app.bsky.feed.post/3k4duaz5vfs2b', 'cid' => 'bafy'])),
    );
    $result = $adapter->post(new Message('Words #Tag', 'https://www.example.com/a', 'Title', 'Summary', 'https://www.example.com/p.jpg'), $credentials);
    $this->assertSame(Outcome::Posted, $result->outcome);
    $this->assertSame('https://bsky.app/profile/example.bsky.social/post/3k4duaz5vfs2b', $result->remoteUrl);
    $sent = json_decode((string) $this->requests[4]['request']->getBody(), TRUE);
    $this->assertSame('did:plc:abc', $sent['repo']);
    $this->assertSame("Words #Tag\n\nhttps://www.example.com/a", $sent['record']['text']);
    $this->assertSame('app.bsky.embed.external', $sent['record']['embed']['$type']);
    $this->assertSame('bafk', $sent['record']['embed']['external']['thumb']['ref']['$link']);
    $this->assertSame('Title', $sent['record']['embed']['external']['title']);
    $this->assertCount(2, $sent['record']['facets']);
    $this->assertSame('Bearer jwt', $this->requests[4]['request']->getHeaderLine('Authorization'));
    $this->assertSame('image/jpeg', $this->requests[3]['request']->getHeaderLine('Content-Type'));
  }

  /**
   * Bluesky: a wrong password is a refusal in its words; trouble is a failure.
   */
  public function testBlueskyRefusalAndFailure(): void {
    $adapter = $this->container->get('plugin.manager.crosspost_adapter')->createInstance('bluesky');
    $credentials = ['handle' => 'example.bsky.social', 'password' => 'wrong'];
    $this->web->append(new Response(401, [], json_encode([
      'error' => 'AuthenticationRequired',
      'message' => 'Invalid identifier or password',
    ])));
    $result = $adapter->post(new Message('Words', 'https://www.example.com/a'), $credentials);
    $this->assertSame(Outcome::Refused, $result->outcome);
    $this->assertSame('Invalid identifier or password', $result->reply);
    $this->assertSame(401, $result->status);

    $this->web->append(new Response(503, [], 'Service Unavailable'));
    $this->assertSame(Outcome::Failed, $adapter->post(new Message('Words', 'https://www.example.com/a'), $credentials)->outcome);

    $this->web->append(new Response(401, [], json_encode([
      'error' => 'AuthenticationRequired',
      'message' => 'Invalid identifier or password',
    ])));
    $this->expectException(AdapterException::class);
    $this->expectExceptionMessage('Invalid identifier or password');
    $adapter->identify($credentials);
  }

  /**
   * Mastodon: names the account, posts a status, reports a refusal.
   */
  public function testMastodon(): void {
    $adapter = $this->container->get('plugin.manager.crosspost_adapter')->createInstance('mastodon');
    $credentials = ['server' => 'https://mastodon.example/', 'token' => 'tok'];

    $this->web->append(new Response(200, [], json_encode(['acct' => 'someone', 'username' => 'someone'])));
    $this->assertSame('someone on mastodon.example', $adapter->identify($credentials));
    $this->assertSame('https://mastodon.example/api/v1/accounts/verify_credentials', (string) $this->requests[0]['request']->getUri());
    $this->assertSame('Bearer tok', $this->requests[0]['request']->getHeaderLine('Authorization'));

    $this->web->append(new Response(200, [], json_encode([
      'id' => '109',
      'url' => 'https://mastodon.example/@someone/109',
    ])));
    $result = $adapter->post(new Message('Words', 'https://www.example.com/a'), $credentials);
    $this->assertSame(Outcome::Posted, $result->outcome);
    $this->assertSame('https://mastodon.example/@someone/109', $result->remoteUrl);
    parse_str((string) $this->requests[1]['request']->getBody(), $sent);
    $this->assertSame("Words\n\nhttps://www.example.com/a", $sent['status']);
    $this->assertNotEmpty($this->requests[1]['request']->getHeaderLine('Idempotency-Key'));

    $this->web->append(new Response(422, [], json_encode(['error' => 'Validation failed: Text character limit of 500 exceeded'])));
    $refused = $adapter->post(new Message('Words', 'https://www.example.com/a'), $credentials);
    $this->assertSame(Outcome::Refused, $refused->outcome);
    $this->assertSame('Validation failed: Text character limit of 500 exceeded', $refused->reply);

    $refused = $adapter->post(new Message('Words', 'https://www.example.com/a'), [
      'server' => 'mastodon.example',
      'token' => 'tok',
    ]);
    $this->assertSame(Outcome::Refused, $refused->outcome);
    $this->assertCount(3, $this->requests, 'A bad server address asks nothing of anyone.');

    $this->assertSame(10 + 2 + 23, $adapter->limits()->used('Ten chars!', 'https://www.example.com/a-very-long-address-that-counts-as-23'));
  }

}
