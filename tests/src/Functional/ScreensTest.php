<?php

declare(strict_types=1);

namespace Drupal\Tests\crosspost\Functional;

use Drupal\key\Entity\Key;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Walks through the screens: connect, settings, share, log, disconnect.
 *
 * @group crosspost
 */
#[Group('crosspost')]
class ScreensTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'node', 'key', 'crosspost', 'crosspost_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The whole walk.
   */
  public function testScreens(): void {
    $this->drupalPlaceBlock('local_tasks_block');
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);
    Key::create([
      'id' => 'right_token',
      'label' => 'Right token',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'right'],
    ])->save();
    Key::create([
      'id' => 'wrong_token',
      'label' => 'Wrong token',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'wrong'],
    ])->save();
    $assert = $this->assertSession();

    // Nobody but an administrator sees the Connections page.
    $editor = $this->drupalCreateUser([
      'share content with crosspost',
      'edit any article content',
      'edit any page content',
      'access content',
    ]);
    $this->drupalLogin($editor);
    $this->drupalGet('admin/config/services/crosspost');
    $assert->statusCodeEquals(403);

    $admin = $this->drupalCreateUser([
      'administer crosspost',
      'view crosspost log',
      'share content with crosspost',
      'bypass node access',
      'access content',
    ]);
    $this->drupalLogin($admin);

    // Connections: platforms in alphabetical order, none connected.
    $this->drupalGet('admin/config/services/crosspost');
    $assert->pageTextMatches('/Another platform.*Bluesky.*Mastodon.*Memory/s');
    $assert->pageTextContains('Not connected');

    // The guide comes first, then the fields. A refusal is in the platform's
    // words, and nothing is saved.
    $this->clickLink('Set up', 3);
    $assert->pageTextContains('Set up Memory');
    $assert->pageTextContains('What you create');
    $assert->pageTextContains('Enter any name but "nobody".');
    $this->submitForm(['settings[name]' => 'nobody'], 'Connect with Memory');
    $assert->pageTextContains('Memory did not accept this. Its words: Nobody by that name.');
    $this->submitForm(['settings[name]' => 'someone', 'keys[token]' => 'wrong_token'], 'Connect with Memory');
    $assert->pageTextContains('Its words: Wrong token.');
    $this->submitForm(['settings[name]' => 'someone', 'keys[token]' => 'right_token'], 'Connect with Memory');
    $assert->pageTextContains('Connected as someone.');
    $assert->pageTextContains('Add another account');

    // The same account cannot be connected twice; a second one can.
    $this->clickLink('Add another account');
    $this->submitForm(['settings[name]' => 'someone'], 'Connect with Memory');
    $assert->pageTextContains('someone is already connected.');
    $this->submitForm(['settings[name]' => 'careers'], 'Connect with Memory');
    $assert->pageTextContains('Connected as careers.');
    $connection = $this->container->get('entity_type.manager')->getStorage('crosspost_connection')->load('memory_someone');
    $this->assertSame(['token' => 'right_token'], $connection->getKeys());
    $this->assertSame(['name' => 'someone'], $connection->getSettings());

    // Test asks the platform and posts nothing.
    $this->clickLink('Test');
    $assert->pageTextContains('these keys belong to');
    $assert->pageTextContains('Nothing was posted.');

    // No content type has a Share tab until it is ticked.
    $article = $this->drupalCreateNode(['type' => 'article', 'title' => 'A public page']);
    $this->drupalGet('node/' . $article->id() . '/share');
    $assert->statusCodeEquals(403);
    $this->drupalGet('admin/config/services/crosspost/settings');
    $this->submitForm([
      'types[article][enabled]' => TRUE,
      'types[article][all_connections]' => 0,
      'types[article][connections][memory_someone]' => TRUE,
      'check_every' => 5,
    ], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $settings = $this->config('crosspost.settings');
    $this->assertSame(['memory_someone'], $settings->get('types.article.connections'));
    $this->assertNull($settings->get('types.page'));
    $this->assertSame(5, $settings->get('check_every'));
    $page = $this->drupalCreateNode(['type' => 'page']);
    $this->drupalGet('node/' . $page->id() . '/share');
    $assert->statusCodeEquals(403);

    // The Share tab: the page is public, one account starts ticked.
    $this->drupalGet('node/' . $article->id());
    $this->clickLink('Share');
    $assert->pageTextContains('The page is public.');
    $assert->checkboxChecked('connections[memory_someone]');
    $assert->checkboxNotChecked('connections[memory_careers]');
    $assert->fieldValueEquals('text', 'A public page');

    // Too long for the platform: nothing goes out.
    $this->submitForm(['text' => str_repeat('Long words. ', 6)], 'Share now');
    $assert->pageTextContains('Memory · someone takes 60 characters, the link included.');
    $this->assertEmpty($this->container->get('state')->get('crosspost_test.posted'));

    // A draft keeps the words and shares nothing.
    $this->submitForm(['text' => 'My own words'], 'Save as draft');
    $assert->pageTextContains('Draft saved. Nothing was shared.');
    $assert->fieldValueEquals('text', 'My own words');

    $this->submitForm([], 'Share now');
    $assert->pageTextContains('Posted to Memory · someone.');
    $assert->pageTextContains('Where this page went');
    $assert->pageTextContains('Already posted there.');
    $this->container->get('state')->resetCache();
    $posted = $this->container->get('state')->get('crosspost_test.posted');
    $this->assertCount(1, $posted);
    $this->assertSame('My own words', $posted[0]['text']);
    $this->assertSame('someone', $posted[0]['to']);
    $this->assertStringEndsWith('/node/' . $article->id(), $posted[0]['url']);

    // A page that is not public waits, and can be cancelled.
    $hidden = $this->drupalCreateNode(['type' => 'article', 'title' => 'Not yet', 'status' => FALSE]);
    $this->drupalGet('node/' . $hidden->id() . '/share');
    $assert->pageTextContains('The page is not public yet.');
    $this->submitForm([], 'Share when the page is public');
    $assert->pageTextContains('waiting.');
    $assert->pageTextContains('The page is not public yet: its address answered 403.');
    $this->clickLink('Cancel');
    $assert->pageTextContains('Cancelled. Nothing went to Memory · someone.');

    // Published in the meantime: another try goes through.
    $hidden->setPublished()->save();
    $this->clickLink('Try again');
    $assert->pageTextContains('Posted to Memory · someone.');

    // The site-wide log, filtered.
    $this->drupalGet('admin/config/services/crosspost/log');
    $assert->pageTextContains('A public page');
    $assert->pageTextContains('Not yet');
    $this->submitForm(['state' => 'refused'], 'Filter');
    $assert->pageTextContains('Nothing has been shared yet.');
    $assert->addressEquals('admin/config/services/crosspost/log');

    // An editor shares, but does not see the log or the connections.
    $this->drupalLogin($editor);
    $this->drupalGet('node/' . $article->id() . '/share');
    $assert->statusCodeEquals(200);
    $this->drupalGet('admin/config/services/crosspost/log');
    $assert->statusCodeEquals(403);

    // Disconnecting keeps the record.
    $this->drupalLogin($admin);
    $this->drupalGet('admin/config/services/crosspost/memory_someone/disconnect');
    $assert->pageTextContains('Disconnect someone?');
    $this->submitForm([], 'Disconnect');
    $assert->pageTextContains('someone is disconnected.');
    $this->drupalGet('admin/config/services/crosspost/log');
    $assert->pageTextContains('Memory · someone');
  }

}
