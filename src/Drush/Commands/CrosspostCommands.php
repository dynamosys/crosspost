<?php

declare(strict_types=1);

namespace Drupal\crosspost\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\crosspost\Sharer;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for Crosspost.
 */
final class CrosspostCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'crosspost.sharer')]
    protected Sharer $sharer,
  ) {
    parent::__construct();
  }

  /**
   * Tries everything that is waiting. Run it after a deploy.
   */
  #[CLI\Command(name: 'crosspost:share', aliases: ['xpost'])]
  #[CLI\Option(name: 'limit', description: 'The most announcements to try in one run.')]
  #[CLI\Usage(name: 'drush crosspost:share', description: 'Checks the waiting pages, and posts the ones that are public now.')]
  #[CLI\FieldLabels(labels: [
    'page' => 'Page',
    'place' => 'Where',
    'state' => 'Result',
    'reply' => 'What the platform said',
  ])]
  public function share(array $options = ['limit' => 50, 'format' => 'table']): RowsOfFields {
    $rows = [];
    foreach ($this->sharer->processDue((int) $options['limit']) as $announcement) {
      $rows[] = [
        'page' => $announcement->getNode()?->label() ?? '',
        'place' => $announcement->get('place')->value,
        'state' => $announcement->getState(),
        'reply' => $announcement->get('reply')->value,
      ];
    }
    if (!$rows) {
      $this->logger()->notice(dt('Nothing was waiting.'));
    }
    return new RowsOfFields($rows);
  }

}
