<?php

declare(strict_types=1);

namespace Drupal\crosspost\Adapter;

/**
 * How a post ended.
 */
enum Outcome: string {

  // The platform took the post.
  case Posted = 'posted';

  // The platform answered and said no. Trying again is the owner's call.
  case Refused = 'refused';

  // The platform could not be reached or broke. Worth another try later.
  case Failed = 'failed';

}
