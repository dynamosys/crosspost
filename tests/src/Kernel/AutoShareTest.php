<?php

declare(strict_types=1);

namespace Drupal\Tests\crosspost\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\crosspost\Entity\AnnouncementInterface;
use Drupal\crosspost\Hashtags;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests sharing by itself, the starting words and the hashtags.
 *
 * @group crosspost
 */
#[Group('crosspost')]
class AutoShareTest extends CrosspostKernelTestBase {

  /**
   * Hashtags keep a name's own capitals, and give lower-case words one.
   */
  public function testHashtagFromLabel(): void {
    $this->assertSame('#iPhone18', Hashtags::fromLabel('iPhone 18'));
    $this->assertSame('#DrupalMigration', Hashtags::fromLabel('drupal migration'));
    $this->assertSame('#API', Hashtags::fromLabel('API'));
    $this->assertSame('#Section508', Hashtags::fromLabel('Section 508'));
    $this->assertSame('#ÉtudeDeCas', Hashtags::fromLabel('étude de cas'));
    $this->assertSame('', Hashtags::fromLabel(' -- '));
  }

  /**
   * Only the moment of publishing shares, and only where it is switched on.
   */
  public function testSharesOnPublishing(): void {
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Tags',
    ])->save();
    $term = Term::create(['vid' => 'tags', 'name' => 'iPhone 18']);
    $term->save();
    $careers = $this->connection('careers');
    $this->connection('main');
    $storage = $this->container->get('entity_type.manager')->getStorage('crosspost_announcement');

    // Switched off: publishing shares nothing.
    $this->article('Quiet');
    $this->assertCount(0, $storage->loadMultiple());

    $this->config('crosspost.settings')
      ->set('types.article', [
        'enabled' => TRUE,
        'tag_field' => 'field_tags',
        'all_connections' => FALSE,
        'connections' => [$careers->id()],
        'auto' => TRUE,
      ])
      ->save();

    // Editing what was already public shares nothing.
    $old = Node::load(1);
    $old->setTitle('Quiet, edited')->save();
    $this->assertCount(0, $storage->loadMultiple());

    // A draft shares nothing until it is published; then once.
    $node = Node::create(['type' => 'article', 'title' => 'A job', 'status' => FALSE, 'field_tags' => [$term->id()]]);
    $node->save();
    $this->assertCount(0, $storage->loadMultiple());
    $node->setPublished()->save();
    $announcements = array_values($storage->loadMultiple());
    $this->assertCount(1, $announcements);
    $this->assertSame($careers->id(), $announcements[0]->getConnectionId());
    $this->assertSame("A job\n\n#iPhone18", $announcements[0]->getText());
    $this->assertSame(AnnouncementInterface::WAITING, $announcements[0]->getState());
    $this->assertTrue((bool) $announcements[0]->get('automatic')->value);

    $node->setUnpublished()->save();
    $node->setPublished()->save();
    $this->assertCount(1, $storage->loadMultiple(), 'Publishing again does not share again.');
  }

  /**
   * Words written on the Share tab beforehand are used instead of the title.
   */
  public function testDraftWordsWin(): void {
    $this->connection('main');
    $this->config('crosspost.settings')->set('types.article.auto', TRUE)->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('crosspost_announcement');
    $node = $this->article('A job', FALSE);
    $storage->create(['node' => $node->id(), 'text' => 'My own words', 'state' => AnnouncementInterface::DRAFT])->save();
    $node->setPublished()->save();
    $announcements = array_values($storage->loadMultiple());
    $this->assertCount(1, $announcements);
    $this->assertSame('My own words', $announcements[0]->getText());
  }

  /**
   * Deleting content drops what had not gone out and keeps the record.
   */
  public function testDeletingContent(): void {
    $sharer = $this->container->get('crosspost.sharer');
    $storage = $this->container->get('entity_type.manager')->getStorage('crosspost_announcement');
    $node = $this->article();
    $sharer->queue($node, $this->connection('one'), 'Words');
    $posted = $sharer->queue($node, $this->connection('two'), 'Words');
    $posted->setState(AnnouncementInterface::POSTED)->save();
    $node->delete();
    $left = $storage->loadMultiple();
    $this->assertCount(1, $left);
    $this->assertSame(AnnouncementInterface::POSTED, reset($left)->getState());
  }

}
