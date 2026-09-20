<?php

declare(strict_types=1);

namespace Drupal\crosspost_test\Plugin\CrosspostAdapter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\crosspost\Adapter\AdapterBase;
use Drupal\crosspost\Adapter\AdapterException;
use Drupal\crosspost\Adapter\Guide;
use Drupal\crosspost\Adapter\Limits;
use Drupal\crosspost\Adapter\Message;
use Drupal\crosspost\Adapter\Outcome;
use Drupal\crosspost\Adapter\Result;
use Drupal\crosspost\Attribute\CrosspostAdapter;

/**
 * A platform that exists only in memory.
 */
#[CrosspostAdapter(
  id: 'another',
  label: new TranslatableMarkup('Another platform'),
)]
class Another extends AdapterBase {

  /**
   * What has been posted, newest last.
   *
   * @var \Drupal\crosspost\Adapter\Message[]
   */
  public static array $posted = [];

  /**
   * {@inheritdoc}
   */
  public function guide(): Guide {
    return new Guide('Nothing', 'None', 'Nothing', 'Twenty characters', ['Enter any name but "nobody".'], 'https://example.com/docs', '2026-09-20');
  }

  /**
   * {@inheritdoc}
   */
  public function limits(): Limits {
    return new Limits(textLength: 20);
  }

  /**
   * {@inheritdoc}
   */
  public function identify(array $credentials): string {
    if (($credentials['name'] ?? 'nobody') === 'nobody') {
      throw new AdapterException('Nobody by that name.');
    }
    return $credentials['name'];
  }

  /**
   * {@inheritdoc}
   */
  public function post(Message $message, array $credentials): Result {
    if (mb_strlen($message->text) > $this->limits()->textLength) {
      return new Result(Outcome::Refused, 'Too long.', 422);
    }
    static::$posted[] = $message;
    $id = (string) count(static::$posted);
    return new Result(Outcome::Posted, 'Accepted.', 200, $id, 'https://example.com/posts/' . $id);
  }

}
