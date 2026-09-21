<?php

declare(strict_types=1);

namespace Drupal\Tests\crosspost\Functional;

use Drupal\crosspost\Entity\AnnouncementInterface;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Walks through connecting by approving an app, and connecting again.
 *
 * @group crosspost
 */
#[Group('crosspost')]
class OAuthFlowTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'key', 'crosspost', 'crosspost_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The whole walk.
   */
  public function testFlow(): void {
    $assert = $this->assertSession();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->config('crosspost.settings')
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
    $this->drupalLogin($this->drupalCreateUser([
      'administer crosspost',
      'share content with crosspost',
      'bypass node access',
      'access content',
    ]));
    $guide = ['query' => ['guide' => 'gate']];

    // Step 1: the guide, the redirect address, and a button that says where
    // it goes.
    $this->drupalGet('admin/config/services/crosspost', $guide);
    $assert->pageTextContains('Your app');
    $assert->fieldValueEquals('redirect', $this->baseUrl . '/crosspost/callback/gate');
    $assert->buttonExists('Continue with Gate');

    // A person who presses Cancel on the platform: its words, nothing saved.
    $this->submitForm(['settings[app_id]' => 'deny'], 'Continue with Gate');
    $assert->pageTextContains('Gate did not approve. Its words: Permissions error. Nothing was saved.');
    $assert->buttonExists('Continue with Gate');

    // A code the platform does not accept.
    $this->submitForm(['settings[app_id]' => 'broken'], 'Continue with Gate');
    $assert->pageTextContains('Its words: This code was used before.');

    // A callback nobody started is turned away.
    $this->drupalGet('crosspost/callback/gate', ['query' => ['code' => 'good', 'state' => 'made-up']]);
    $assert->pageTextContains('That did not come from a connection started here.');

    // Approved: choose the pages. The one without rights cannot be ticked.
    $this->submitForm(['settings[app_id]' => 'app-1'], 'Continue with Gate');
    $assert->pageTextContains('Gate approved the app for Test Person.');
    $assert->pageTextContains('Your role does not allow posting.');
    $assert->fieldDisabled('accounts[p3]');
    $this->submitForm([], 'Connect what is ticked');
    $assert->pageTextContains('Tick at least one.');
    $this->submitForm(['accounts[p1]' => TRUE, 'accounts[p2]' => TRUE], 'Connect what is ticked');
    $assert->pageTextContains('Connected as Page One.');
    $assert->pageTextContains('Connected as Page Two.');

    $storage = $this->container->get('entity_type.manager')->getStorage('crosspost_connection');
    $one = $storage->load('gate_page_one');
    $this->assertSame(['app_id' => 'app-1', 'account_id' => 'p1'], $one->getSettings());
    $this->assertSame(['page_token' => 'token-one-1'], $this->container->get('crosspost.tokens')->get('gate_page_one'));
    $this->assertStringNotContainsString('token-one', json_encode($one->toArray()), 'No token is in configuration.');
    $this->assertSame('token-one-1', $this->container->get('crosspost.credentials')->resolve($one)['page_token']);

    // Adding another page: the app is remembered.
    $this->clickLink('Add another page');
    $assert->fieldValueEquals('settings[app_id]', 'app-1');

    // The platform withdraws access: the post is refused, the page needs
    // attention, and Reconnect is offered.
    $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'A page']);
    $this->container->get('state')->set('crosspost_test.outcome', 'lost');
    $this->drupalGet('node/' . $node->id() . '/share');
    $this->submitForm(['connections[gate_page_one]' => TRUE, 'connections[gate_page_two]' => FALSE], 'Share now');
    $assert->pageTextContains('nothing was posted. The session has been invalidated.');
    $this->drupalGet('admin/config/services/crosspost');
    $assert->pageTextContains('Needs attention');
    $assert->linkExists('Reconnect');

    // Reconnecting renews the token, keeps the connection, and puts the
    // refused post back in line.
    $this->container->get('state')->set('crosspost_test.outcome', 'posted');
    $this->clickLink('Reconnect');
    $assert->checkboxChecked('accounts[p1]');
    $assert->checkboxNotChecked('accounts[p2]');
    $this->submitForm([], 'Connect what is ticked');
    $assert->pageTextContains('Access renewed for Page One.');
    $assert->pageTextContains('1 post that was refused for lack of access is in line again.');
    $assert->pageTextNotContains('Needs attention');
    $this->assertSame(['page_token' => 'token-one-2'], $this->container->get('crosspost.tokens')->get('gate_page_one'));
    $this->assertCount(2, $storage->loadMultiple());

    $announcements = $this->container->get('entity_type.manager')->getStorage('crosspost_announcement')->loadMultiple();
    $this->assertSame(AnnouncementInterface::WAITING, reset($announcements)->getState());
    $this->container->get('crosspost.sharer')->processDue();
    $this->container->get('state')->resetCache();
    $posted = $this->container->get('state')->get('crosspost_test.posted');
    $this->assertSame('token-one-2', $posted[0]['to']);

    // Disconnecting forgets the token.
    $this->drupalGet('admin/config/services/crosspost/gate_page_one/disconnect');
    $this->submitForm([], 'Disconnect');
    $this->assertSame([], $this->container->get('crosspost.tokens')->get('gate_page_one'));
  }

}
