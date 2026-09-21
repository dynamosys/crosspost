<?php

declare(strict_types=1);

namespace Drupal\crosspost\Plugin\CrosspostAdapter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\crosspost\Adapter\AdapterException;
use Drupal\crosspost\Adapter\CredentialField;
use Drupal\crosspost\Adapter\Guide;
use Drupal\crosspost\Adapter\HttpAdapterBase;
use Drupal\crosspost\Adapter\Limits;
use Drupal\crosspost\Adapter\Message;
use Drupal\crosspost\Adapter\Outcome;
use Drupal\crosspost\Adapter\Result;
use Drupal\crosspost\Attribute\CrosspostAdapter;

/**
 * Posts to an account on a Mastodon server.
 */
#[CrosspostAdapter(
  id: 'mastodon',
  label: new TranslatableMarkup('Mastodon'),
)]
class Mastodon extends HttpAdapterBase {

  /**
   * {@inheritdoc}
   */
  public function guide(): Guide {
    return new Guide(
      $this->t("An application on your own Mastodon server, made in your account's preferences"),
      $this->t('None'),
      $this->t('Mastodon does not charge for this'),
      $this->t('500 characters on most servers, and every link counts as 23; the server builds the link card from the page'),
      [
        $this->t('Sign in to your Mastodon server. Open Preferences, then Development, and press New application.'),
        $this->t('Give it a name, such as the name of this site. Under Scopes, tick <strong>write:statuses</strong> and <strong>profile</strong>, and clear the rest. On a server that does not offer "profile", tick <strong>read:accounts</strong> instead. Save.'),
        $this->t('Open the application you just made and copy <strong>Your access token</strong>.'),
        $this->t("In this site's Key module, add a key and paste the token as its value."),
        $this->t('Below, enter the address of your server, select the key, and press Connect.'),
      ],
      'https://docs.joinmastodon.org/methods/statuses/',
      '2026-09-20',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function limits(): Limits {
    return new Limits(textLength: 500, linkLength: 23);
  }

  /**
   * {@inheritdoc}
   */
  public function credentialFields(): array {
    return [
      new CredentialField('server', $this->t('Address of your server'), $this->t('For example https://mastodon.social'), default: 'https://'),
      new CredentialField('token', $this->t('Access token'), secret: TRUE),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function identify(array $credentials): string {
    $server = $this->server($credentials);
    $response = $this->send('GET', $server . '/api/v1/accounts/verify_credentials', ['headers' => $this->headers($credentials)]);
    if (is_string($response)) {
      throw new AdapterException($response);
    }
    $data = $this->json($response);
    if ($response->getStatusCode() !== 200 || empty($data['acct'])) {
      // Shown to the person connecting, so it is in their language.
      $words = $data['error'] ?? (string) $this->t('The server answered @status.', ['@status' => $response->getStatusCode()]);
      throw new AdapterException($words);
    }
    return $data['acct'] . ' on ' . parse_url($server, PHP_URL_HOST);
  }

  /**
   * {@inheritdoc}
   */
  public function post(Message $message, array $credentials): Result {
    try {
      $server = $this->server($credentials);
    }
    catch (AdapterException $e) {
      return new Result(Outcome::Refused, $e->getMessage());
    }
    $status = trim($message->text) . "\n\n" . $message->url;
    $response = $this->send('POST', $server . '/api/v1/statuses', [
      // The same words to the same address are the same post: if an answer
      // got lost on the way, trying again does not post twice.
      'headers' => $this->headers($credentials) + ['Idempotency-Key' => hash('sha256', $status)],
      'form_params' => ['status' => $status, 'visibility' => 'public'],
    ]);
    if (is_string($response)) {
      return $this->notPosted($response);
    }
    $data = $this->json($response);
    if ($response->getStatusCode() !== 200 || empty($data['id'])) {
      return $this->notPosted($response, (string) ($data['error'] ?? ''));
    }
    return new Result(Outcome::Posted, (string) $this->t('Accepted.'), 200, (string) $data['id'], $data['url'] ?? NULL);
  }

  /**
   * The server's address, without a trailing slash.
   */
  protected function server(array $credentials): string {
    $server = rtrim(trim($credentials['server'] ?? ''), '/');
    if (!preg_match('#^https://[^/\s]+$#i', $server)) {
      $words = (string) $this->t('The address of the server has to look like https://mastodon.social');
      throw new AdapterException($words);
    }
    return $server;
  }

  /**
   * The request headers.
   */
  protected function headers(array $credentials): array {
    return ['Authorization' => 'Bearer ' . ($credentials['token'] ?? ''), 'Accept' => 'application/json'];
  }

}
