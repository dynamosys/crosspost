<?php

declare(strict_types=1);

namespace Drupal\crosspost\Plugin\CrosspostAdapter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\crosspost\Adapter\Account;
use Drupal\crosspost\Adapter\AdapterException;
use Drupal\crosspost\Adapter\Approval;
use Drupal\crosspost\Adapter\CredentialField;
use Drupal\crosspost\Adapter\Guide;
use Drupal\crosspost\Adapter\HttpAdapterBase;
use Drupal\crosspost\Adapter\Limits;
use Drupal\crosspost\Adapter\Message;
use Drupal\crosspost\Adapter\OAuthAdapterInterface;
use Drupal\crosspost\Adapter\Outcome;
use Drupal\crosspost\Adapter\Result;
use Drupal\crosspost\Attribute\CrosspostAdapter;
use Psr\Http\Message\ResponseInterface;

/**
 * Posts to Facebook Pages through the site owner's own Meta app.
 */
#[CrosspostAdapter(
  id: 'facebook_page',
  label: new TranslatableMarkup('Facebook Page'),
  account_noun: new TranslatableMarkup('page'),
  platform: new TranslatableMarkup('Facebook'),
)]
class FacebookPage extends HttpAdapterBase implements OAuthAdapterInterface {

  /**
   * The Graph API version the adapter was written and checked against.
   */
  const VERSION = 'v25.0';

  /**
   * To list the person's pages, to read them, and to publish on them.
   */
  const SCOPE = 'pages_show_list,pages_read_engagement,pages_manage_posts';

  /**
   * Meta's error codes that mean the token or its permissions are gone.
   *
   * 190: the access token is invalid or expired. 10 and 200 to 299: the
   * permission is missing or was taken away. 102: the session is invalid.
   */
  const ACCESS_LOST = [10, 102, 190];

  /**
   * {@inheritdoc}
   */
  public function guide(): Guide {
    return new Guide(
      $this->t('A Meta developer app of your own, and you need a role on the pages you connect'),
      $this->t("None for your own pages. Meta reviews apps only when they serve other people's pages"),
      $this->t('Meta does not charge for this'),
      $this->t('The link shows as a card built from the page. While the app is in Development mode, only people with a role on the app can see its posts'),
      [
        $this->t('Sign in at <a href="https://developers.facebook.com/apps">developers.facebook.com</a>, open My Apps and create an app. When asked what it is for, choose managing a Page.'),
        $this->t("In the app's Facebook Login settings, add the redirect address shown below to <strong>Valid OAuth Redirect URIs</strong>."),
        $this->t("In the app's basic settings, enter your privacy policy address and switch the app to <strong>Live</strong>. Posts made in Development mode are visible only to people with a role on the app."),
        $this->t("Copy the <strong>App ID</strong> into the field below. In this site's Key module, add a key with the <strong>App secret</strong> as its value, and select it."),
        $this->t('Press Continue. Facebook asks you to approve the app for the pages you choose, then sends you back here. The site asks for three permissions and nothing more: to list the pages you manage, to read them, and to publish posts on them.'),
      ],
      'https://developers.facebook.com/docs/pages-api/posts',
      '2026-09-20',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function limits(): Limits {
    // Meta documents no limit for a post's text; this is the long-standing
    // practical one.
    return new Limits(textLength: 63206, linkLength: 0);
  }

  /**
   * {@inheritdoc}
   */
  public function credentialFields(): array {
    return [
      new CredentialField('app_id', $this->t('App ID')),
      new CredentialField('app_secret', $this->t('App secret'), secret: TRUE),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function authorizeUrl(array $credentials, string $redirect_uri, string $state): string {
    return 'https://www.facebook.com/' . static::VERSION . '/dialog/oauth?' . http_build_query([
      'client_id' => $credentials['app_id'] ?? '',
      'redirect_uri' => $redirect_uri,
      'state' => $state,
      'scope' => static::SCOPE,
      'response_type' => 'code',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function approval(string $code, array $credentials, string $redirect_uri): Approval {
    $app = ['client_id' => $credentials['app_id'] ?? '', 'client_secret' => $credentials['app_secret'] ?? ''];
    $short = $this->ask('oauth/access_token', $app + ['redirect_uri' => $redirect_uri, 'code' => $code]);
    // A page token made from a long-lived user token does not run out by
    // date, so the short-lived one is traded in first.
    $long = $this->ask('oauth/access_token', $app + [
      'grant_type' => 'fb_exchange_token',
      'fb_exchange_token' => $short['access_token'] ?? '',
    ]);
    $token = $long['access_token'] ?? '';
    $me = $this->ask('me', ['fields' => 'name', 'access_token' => $token]);
    $pages = $this->ask('me/accounts', [
      'fields' => 'id,name,access_token,tasks',
      'limit' => 100,
      'access_token' => $token,
    ]);

    $accounts = [];
    foreach ($pages['data'] ?? [] as $page) {
      $may_post = in_array('CREATE_CONTENT', $page['tasks'] ?? [], TRUE);
      $accounts[] = new Account(
        (string) $page['id'],
        (string) $page['name'],
        [],
        ['page_token' => (string) ($page['access_token'] ?? '')],
        $may_post && !empty($page['access_token']),
        (string) ($may_post ? $this->t('Page · you can create content') : $this->t('Your role on this page does not allow posting, so it cannot be connected.')),
      );
    }
    return new Approval((string) ($me['name'] ?? ''), $accounts);
  }

  /**
   * {@inheritdoc}
   */
  public function identify(array $credentials): string {
    $page = $this->ask($credentials['account_id'] ?? '', [
      'fields' => 'name',
      'access_token' => $credentials['page_token'] ?? '',
    ]);
    return (string) ($page['name'] ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function post(Message $message, array $credentials): Result {
    $page_id = $credentials['account_id'] ?? '';
    $response = $this->send('POST', $this->endpoint($page_id . '/feed'), [
      'form_params' => [
        'message' => trim($message->text),
        'link' => $message->url,
        'access_token' => $credentials['page_token'] ?? '',
      ],
    ]);
    if (is_string($response)) {
      return $this->notPosted($response);
    }
    $data = $this->json($response);
    if ($response->getStatusCode() !== 200 || empty($data['id'])) {
      return $this->refusal($response, $data);
    }
    // The ID reads "pageid_postid".
    [$page, $post] = explode('_', (string) $data['id'], 2) + [1 => ''];
    $url = $post !== '' ? 'https://www.facebook.com/' . $page . '/posts/' . $post : 'https://www.facebook.com/' . $data['id'];
    return new Result(Outcome::Posted, (string) $this->t('Accepted.'), 200, (string) $data['id'], $url);
  }

  /**
   * Turns Meta's error answer into a Result, in Meta's words.
   */
  protected function refusal(ResponseInterface $response, array $data): Result {
    $error = $data['error'] ?? [];
    $result = $this->notPosted($response, (string) ($error['message'] ?? ''));
    $code = (int) ($error['code'] ?? 0);
    // Meta's own "try again later" codes: 1, 2, 4, 17 and 341.
    if (in_array($code, [1, 2, 4, 17, 341], TRUE)) {
      return new Result(Outcome::Failed, $result->reply, $result->status);
    }
    $lost = in_array($code, static::ACCESS_LOST, TRUE) || ($code >= 200 && $code <= 299);
    return new Result($result->outcome, $result->reply, $result->status, NULL, NULL, $lost);
  }

  /**
   * Asks the Graph API and returns the answer, or throws Meta's words.
   *
   * @throws \Drupal\crosspost\Adapter\AdapterException
   */
  protected function ask(string $path, array $query): array {
    $response = $this->send('GET', $this->endpoint($path), ['query' => $query]);
    if (is_string($response)) {
      throw new AdapterException($response);
    }
    $data = $this->json($response);
    if ($response->getStatusCode() !== 200 || isset($data['error'])) {
      // Shown to the person connecting, in Meta's own words.
      $words = (string) ($data['error']['message'] ?? $this->t('Meta answered @status.', ['@status' => $response->getStatusCode()]));
      throw new AdapterException($words);
    }
    return $data;
  }

  /**
   * A Graph API address.
   */
  protected function endpoint(string $path): string {
    return 'https://graph.facebook.com/' . static::VERSION . '/' . ltrim($path, '/');
  }

}
