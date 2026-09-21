<?php

declare(strict_types=1);

namespace Drupal\Tests\crosspost\Kernel;

use Drupal\crosspost\Adapter\AdapterException;
use Drupal\crosspost\Adapter\Message;
use Drupal\crosspost\Adapter\Outcome;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Facebook Page adapter against mocked answers from Meta.
 *
 * @group crosspost
 */
#[Group('crosspost')]
class FacebookPageTest extends CrosspostKernelTestBase {

  /**
   * The approval address asks for three permissions and carries the state.
   */
  public function testAuthorizeUrl(): void {
    $adapter = $this->container->get('plugin.manager.crosspost_adapter')->createInstance('facebook_page');
    $url = $adapter->authorizeUrl(['app_id' => '123', 'app_secret' => 'shh'], 'https://cms.example.com/crosspost/callback/facebook_page', 'abc');
    $this->assertStringStartsWith('https://www.facebook.com/v25.0/dialog/oauth?', $url);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $this->assertSame('123', $query['client_id']);
    $this->assertSame('abc', $query['state']);
    $this->assertSame('pages_show_list,pages_read_engagement,pages_manage_posts', $query['scope']);
    $this->assertSame('https://cms.example.com/crosspost/callback/facebook_page', $query['redirect_uri']);
    $this->assertStringNotContainsString('shh', $url, 'The secret never goes to the browser.');
  }

  /**
   * The code becomes a long-lived token, then the pages with their tokens.
   */
  public function testApproval(): void {
    $adapter = $this->container->get('plugin.manager.crosspost_adapter')->createInstance('facebook_page');
    $this->web->append(
      new Response(200, [], json_encode(['access_token' => 'short', 'token_type' => 'bearer'])),
      new Response(200, [], json_encode(['access_token' => 'long', 'expires_in' => 5183944])),
      new Response(200, [], json_encode(['name' => 'Test Person', 'id' => '1'])),
      new Response(200, [], json_encode([
        'data' => [
          [
            'id' => '111',
            'name' => 'Page One',
            'access_token' => 'page-token-1',
            'tasks' => ['ANALYZE', 'CREATE_CONTENT', 'MANAGE'],
          ],
          ['id' => '222', 'name' => 'Page Two', 'access_token' => 'page-token-2', 'tasks' => ['ANALYZE']],
        ],
      ])),
    );
    $approval = $adapter->approval('the-code', ['app_id' => '123', 'app_secret' => 'shh'], 'https://cms.example.com/cb');
    $this->assertSame('Test Person', $approval->person);
    $this->assertCount(2, $approval->accounts);
    $this->assertSame('111', $approval->accounts[0]->id);
    $this->assertSame(['page_token' => 'page-token-1'], $approval->accounts[0]->tokens);
    $this->assertTrue($approval->accounts[0]->connectable);
    $this->assertFalse($approval->accounts[1]->connectable);

    parse_str($this->requests[0]['request']->getUri()->getQuery(), $first);
    $this->assertSame([
      'client_id' => '123',
      'client_secret' => 'shh',
      'redirect_uri' => 'https://cms.example.com/cb',
      'code' => 'the-code',
    ], $first);
    parse_str($this->requests[1]['request']->getUri()->getQuery(), $second);
    $this->assertSame('fb_exchange_token', $second['grant_type']);
    $this->assertSame('short', $second['fb_exchange_token']);
    $this->assertStringContainsString('access_token=long', $this->requests[3]['request']->getUri()->getQuery());
    $this->assertSame('/v25.0/me/accounts', $this->requests[3]['request']->getUri()->getPath());
  }

  /**
   * A code Meta does not accept is reported in Meta's words.
   */
  public function testApprovalRefused(): void {
    $adapter = $this->container->get('plugin.manager.crosspost_adapter')->createInstance('facebook_page');
    $this->web->append(new Response(400, [], json_encode([
      'error' => ['message' => 'This authorization code has been used.', 'type' => 'OAuthException', 'code' => 100],
    ])));
    $this->expectException(AdapterException::class);
    $this->expectExceptionMessage('This authorization code has been used.');
    $adapter->approval('used', ['app_id' => '123', 'app_secret' => 'shh'], 'https://cms.example.com/cb');
  }

  /**
   * A post goes to the page's feed; refusals keep Meta's words.
   */
  public function testPost(): void {
    $adapter = $this->container->get('plugin.manager.crosspost_adapter')->createInstance('facebook_page');
    $credentials = ['app_id' => '123', 'account_id' => '111', 'page_token' => 'page-token-1'];
    $message = new Message("Words\n\n#Tag", 'https://www.example.com/a', 'Title');

    $this->web->append(new Response(200, [], json_encode(['id' => '111_999'])));
    $result = $adapter->post($message, $credentials);
    $this->assertSame(Outcome::Posted, $result->outcome);
    $this->assertSame('https://www.facebook.com/111/posts/999', $result->remoteUrl);
    $this->assertSame('/v25.0/111/feed', $this->requests[0]['request']->getUri()->getPath());
    parse_str((string) $this->requests[0]['request']->getBody(), $sent);
    $this->assertSame([
      'message' => "Words\n\n#Tag",
      'link' => 'https://www.example.com/a',
      'access_token' => 'page-token-1',
    ], $sent);

    // The token is no longer valid: refused, and marked as access lost.
    $this->web->append(new Response(400, [], json_encode([
      'error' => [
        'message' => 'Error validating access token: The session has been invalidated because the user changed their password.',
        'type' => 'OAuthException',
        'code' => 190,
        'error_subcode' => 460,
      ],
    ])));
    $lost = $adapter->post($message, $credentials);
    $this->assertSame(Outcome::Refused, $lost->outcome);
    $this->assertTrue($lost->accessLost);
    $this->assertStringContainsString('changed their password', $lost->reply);

    // A missing permission is access lost too.
    $this->web->append(new Response(403, [], json_encode([
      'error' => [
        'message' => '(#200) The user has not granted the app the permission.',
        'type' => 'OAuthException',
        'code' => 200,
      ],
    ])));
    $this->assertTrue($adapter->post($message, $credentials)->accessLost);

    // A plain refusal is not.
    $this->web->append(new Response(400, [], json_encode([
      'error' => ['message' => '(#100) The link is not valid.', 'type' => 'OAuthException', 'code' => 100],
    ])));
    $refused = $adapter->post($message, $credentials);
    $this->assertSame(Outcome::Refused, $refused->outcome);
    $this->assertFalse($refused->accessLost);

    // Meta asking to slow down, or being in trouble, is worth another try.
    $this->web->append(new Response(400, [], json_encode([
      'error' => ['message' => '(#4) Application request limit reached', 'code' => 4],
    ])));
    $this->assertSame(Outcome::Failed, $adapter->post($message, $credentials)->outcome);
    $this->web->append(new Response(500, [], 'oops'));
    $this->assertSame(Outcome::Failed, $adapter->post($message, $credentials)->outcome);

    // Test asks for the page's name with the page's token.
    $this->web->append(new Response(200, [], json_encode(['name' => 'Page One', 'id' => '111'])));
    $this->assertSame('Page One', $adapter->identify($credentials));
  }

}
