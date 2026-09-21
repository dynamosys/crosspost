<?php

declare(strict_types=1);

namespace Drupal\Tests\crosspost\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Share button in the Navigation module's top bar.
 *
 * @group crosspost
 */
#[Group('crosspost')]
class TopBarTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['navigation', 'node', 'key', 'crosspost'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The button shows on a page of a switched-on type, to those who may share.
   */
  public function testShareButton(): void {
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);
    $this->config('crosspost.settings')
      ->set('types', ['article' => ['enabled' => TRUE, 'tag_field' => '', 'all_connections' => TRUE, 'connections' => [], 'auto' => FALSE]])
      ->save();
    $article = $this->drupalCreateNode(['type' => 'article']);
    $page = $this->drupalCreateNode(['type' => 'page']);
    $button = '.top-bar a[href$="/node/' . $article->id() . '/share"]';

    $this->drupalLogin($this->drupalCreateUser(['access navigation', 'access content', 'edit any article content', 'edit any page content', 'share content with crosspost']));
    $this->drupalGet('node/' . $article->id());
    $this->assertSession()->elementExists('css', $button);
    $this->drupalGet('node/' . $page->id());
    $this->assertSession()->elementNotExists('css', '.top-bar a[href$="/share"]');

    $this->drupalLogin($this->drupalCreateUser(['access navigation', 'access content', 'edit any article content']));
    $this->drupalGet('node/' . $article->id());
    $this->assertSession()->elementNotExists('css', $button);
  }

}
