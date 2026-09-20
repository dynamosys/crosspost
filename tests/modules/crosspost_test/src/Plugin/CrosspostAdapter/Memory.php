<?php

declare(strict_types=1);

namespace Drupal\crosspost_test\Plugin\CrosspostAdapter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\crosspost\Adapter\AdapterBase;
use Drupal\crosspost\Adapter\AdapterException;
use Drupal\crosspost\Adapter\CredentialField;
use Drupal\crosspost\Adapter\Guide;
use Drupal\crosspost\Adapter\Limits;
use Drupal\crosspost\Adapter\Message;
use Drupal\crosspost\Adapter\Outcome;
use Drupal\crosspost\Adapter\Result;
use Drupal\crosspost\Attribute\CrosspostAdapter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A platform that exists only in the site's state.
 *
 * Posts are kept under the state key "crosspost_test.posted". Setting
 * "crosspost_test.outcome" to "refused" or "failed" makes the next posts end
 * that way.
 */
#[CrosspostAdapter(
  id: 'memory',
  label: new TranslatableMarkup('Memory'),
)]
class Memory extends AdapterBase implements ContainerFactoryPluginInterface {

  /**
   * The state, where this platform lives.
   */
  protected StateInterface $state;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $adapter = new static($configuration, $plugin_id, $plugin_definition);
    $adapter->state = $container->get('state');
    return $adapter;
  }

  /**
   * {@inheritdoc}
   */
  public function guide(): Guide {
    return new Guide('Nothing', 'None', 'Nothing', 'Sixty characters', ['Enter any name but "nobody".'], 'https://example.com/docs', '2026-09-20');
  }

  /**
   * {@inheritdoc}
   */
  public function limits(): Limits {
    return new Limits(textLength: 60, linkLength: 10);
  }

  /**
   * {@inheritdoc}
   */
  public function credentialFields(): array {
    return [
      new CredentialField('name', 'Name', 'Any name but "nobody".'),
      new CredentialField('token', 'Token', 'When given, it has to be "right".', secret: TRUE, required: FALSE),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function identify(array $credentials): string {
    if (($credentials['name'] ?? 'nobody') === 'nobody') {
      throw new AdapterException('Nobody by that name.');
    }
    if (($credentials['token'] ?? '') !== '' && $credentials['token'] !== 'right') {
      throw new AdapterException('Wrong token.');
    }
    return $credentials['name'];
  }

  /**
   * {@inheritdoc}
   */
  public function post(Message $message, array $credentials): Result {
    $state = $this->state;
    switch ($state->get('crosspost_test.outcome', 'posted')) {
      case 'refused':
        return new Result(Outcome::Refused, 'Not allowed.', 403);

      case 'failed':
        return new Result(Outcome::Failed, 'Gateway timeout.', 504);
    }
    $posted = $state->get('crosspost_test.posted', []);
    $posted[] = [
      'to' => $credentials['name'] ?? '',
      'text' => $message->text,
      'url' => $message->url,
      'title' => $message->title,
      'image' => $message->image,
    ];
    $state->set('crosspost_test.posted', $posted);
    $id = (string) count($posted);
    return new Result(Outcome::Posted, 'Accepted.', 200, $id, 'https://example.com/posts/' . $id);
  }

}
