<?php

declare(strict_types=1);

namespace Drupal\crosspost_test\Plugin\CrosspostAdapter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\crosspost\Attribute\CrosspostAdapter;

/**
 * A second platform, of pages, whose label sorts before the first.
 */
#[CrosspostAdapter(
  id: 'another',
  label: new TranslatableMarkup('Another platform'),
  account_noun: new TranslatableMarkup('page'),
)]
class Another extends Memory {}
