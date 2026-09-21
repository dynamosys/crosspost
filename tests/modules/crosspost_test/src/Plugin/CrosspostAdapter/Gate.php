<?php

declare(strict_types=1);

namespace Drupal\crosspost_test\Plugin\CrosspostAdapter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\crosspost\Adapter\Account;
use Drupal\crosspost\Adapter\AdapterException;
use Drupal\crosspost\Adapter\Approval;
use Drupal\crosspost\Adapter\CredentialField;
use Drupal\crosspost\Adapter\Message;
use Drupal\crosspost\Adapter\OAuthAdapterInterface;
use Drupal\crosspost\Adapter\Outcome;
use Drupal\crosspost\Adapter\Result;
use Drupal\crosspost\Attribute\CrosspostAdapter;

/**
 * A platform of pages that is connected by approving an app.
 *
 * Its approval screen is skipped: the address to approve at is the way back
 * itself, with a code. The app ID "deny" plays a person pressing Cancel, the
 * app ID "broken" a code the platform does not accept. Setting
 * "crosspost_test.outcome" to "lost" makes posts end with access lost.
 */
#[CrosspostAdapter(
  id: 'gate',
  label: new TranslatableMarkup('Gate'),
  account_noun: new TranslatableMarkup('page'),
)]
class Gate extends Memory implements OAuthAdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function credentialFields(): array {
    return [
      new CredentialField('app_id', 'App ID'),
      new CredentialField('app_secret', 'App secret', secret: TRUE, required: FALSE),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function authorizeUrl(array $credentials, string $redirect_uri, string $state): string {
    $query = match ($credentials['app_id'] ?? '') {
      'deny' => ['error' => 'access_denied', 'error_description' => 'Permissions error.'],
      'broken' => ['code' => 'broken'],
      default => ['code' => 'good'],
    };
    return $redirect_uri . '?' . http_build_query($query + ['state' => $state]);
  }

  /**
   * {@inheritdoc}
   */
  public function approval(string $code, array $credentials, string $redirect_uri): Approval {
    if ($code !== 'good') {
      throw new AdapterException('This code was used before.');
    }
    $round = (int) $this->state->get('crosspost_test.round', 0) + 1;
    $this->state->set('crosspost_test.round', $round);
    return new Approval('Test Person', [
      new Account('p1', 'Page One', [], ['page_token' => 'token-one-' . $round], TRUE, 'Page'),
      new Account('p2', 'Page Two', [], ['page_token' => 'token-two-' . $round], TRUE, 'Page'),
      new Account('p3', 'Page Three', [], [], FALSE, 'Your role does not allow posting.'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function identify(array $credentials): string {
    if (empty($credentials['page_token'])) {
      throw new AdapterException('No token.');
    }
    return $credentials['page_token'];
  }

  /**
   * {@inheritdoc}
   */
  public function post(Message $message, array $credentials): Result {
    if ($this->state->get('crosspost_test.outcome') === 'lost') {
      return new Result(Outcome::Refused, 'The session has been invalidated.', 400, NULL, NULL, TRUE);
    }
    return parent::post($message, ['name' => $credentials['page_token'] ?? ''] + $credentials);
  }

}
