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
 * Posts to a Bluesky account.
 */
#[CrosspostAdapter(
  id: 'bluesky',
  label: new TranslatableMarkup('Bluesky'),
)]
class Bluesky extends HttpAdapterBase {

  /**
   * The largest picture Bluesky takes, in bytes.
   */
  const BLOB_LIMIT = 1000000;

  /**
   * {@inheritdoc}
   */
  public function guide(): Guide {
    return new Guide(
      $this->t('An app password, made in your Bluesky settings'),
      $this->t('None'),
      $this->t('Bluesky does not charge for this'),
      $this->t("300 characters of words; the link rides in the card, which carries the page's title, description and picture, and a picture over 1 MB is left out"),
      [
        $this->t('Sign in to Bluesky. Open Settings, then Privacy and security, then App passwords, and add an app password. Give it a name, such as the name of this site.'),
        $this->t('Copy the password Bluesky shows. It is shown only once. It is not your account password, and you can revoke it there at any time.'),
        $this->t("In this site's Key module, add a key and paste the app password as its value."),
        $this->t('Below, enter your handle, select the key, and press Connect.'),
      ],
      'https://docs.bsky.app/docs/advanced-guides/posts',
      '2026-09-20',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function limits(): Limits {
    // The link rides in the card, not in the text.
    return new Limits(textLength: 300, linkLength: 0);
  }

  /**
   * {@inheritdoc}
   */
  public function credentialFields(): array {
    return [
      new CredentialField('handle', $this->t('Handle'), $this->t('For example example.bsky.social, without the @.')),
      new CredentialField('password', $this->t('App password'), secret: TRUE),
      new CredentialField('service', $this->t('Service'), $this->t('Leave as it is unless your account lives on another server.'), default: 'https://bsky.social'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function identify(array $credentials): string {
    $session = $this->session($credentials);
    if ($session instanceof Result) {
      throw new AdapterException($session->reply);
    }
    return $session['handle'];
  }

  /**
   * {@inheritdoc}
   */
  public function post(Message $message, array $credentials): Result {
    $session = $this->session($credentials);
    if ($session instanceof Result) {
      return $session;
    }
    $service = $this->service($credentials);
    $auth = ['Authorization' => 'Bearer ' . $session['accessJwt']];

    // The card below the post carries the link, so the text keeps its 300
    // characters for words. Facets mark the hashtags.
    $text = trim($message->text);
    $external = [
      'uri' => $message->url,
      'title' => $message->title,
      'description' => $message->summary,
    ];
    if ($thumb = $this->thumb($message->image, $service, $auth)) {
      $external['thumb'] = $thumb;
    }
    $record = [
      '$type' => 'app.bsky.feed.post',
      'text' => $text,
      'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'),
      'facets' => static::facets($text, ''),
      'embed' => ['$type' => 'app.bsky.embed.external', 'external' => $external],
    ];
    $response = $this->send('POST', $service . '/xrpc/com.atproto.repo.createRecord', [
      'headers' => $auth,
      'json' => ['repo' => $session['did'], 'collection' => 'app.bsky.feed.post', 'record' => $record],
    ]);
    if (is_string($response)) {
      return $this->notPosted($response);
    }
    $data = $this->json($response);
    if ($response->getStatusCode() !== 200 || empty($data['uri'])) {
      return $this->notPosted($response, (string) ($data['message'] ?? $data['error'] ?? ''));
    }
    $key = substr($data['uri'], strrpos($data['uri'], '/') + 1);
    return new Result(Outcome::Posted, (string) $this->t('Accepted.'), 200, $data['uri'], 'https://bsky.app/profile/' . $session['handle'] . '/post/' . $key);
  }

  /**
   * Makes the links and hashtags in a text work.
   *
   * Bluesky does not find them by itself: a post says where each one is,
   * counted in bytes of UTF-8.
   *
   * @return array<int, array<string, mixed>>
   *   The facets.
   */
  public static function facets(string $text, string $url): array {
    $facets = [];
    $start = $url === '' ? FALSE : strrpos($text, $url);
    if ($start !== FALSE) {
      $facets[] = [
        'index' => ['byteStart' => $start, 'byteEnd' => $start + strlen($url)],
        'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]],
      ];
    }
    // A hashtag starts the text or follows white space, and is not a number.
    preg_match_all('/(?<=^|\s)#([\p{L}\p{N}_]*[\p{L}_][\p{L}\p{N}_]*)/u', $text, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as $i => [$tag, $offset]) {
      $facets[] = [
        'index' => ['byteStart' => $offset, 'byteEnd' => $offset + strlen($tag)],
        'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $matches[1][$i][0]]],
      ];
    }
    return $facets;
  }

  /**
   * Signs in.
   *
   * @return array|\Drupal\crosspost\Adapter\Result
   *   The session (did, handle, accessJwt), or why there is none.
   */
  protected function session(array $credentials): array|Result {
    $response = $this->send('POST', $this->service($credentials) . '/xrpc/com.atproto.server.createSession', [
      'json' => [
        'identifier' => ltrim(trim($credentials['handle'] ?? ''), '@'),
        'password' => $credentials['password'] ?? '',
      ],
    ]);
    if (is_string($response)) {
      return $this->notPosted($response);
    }
    $data = $this->json($response);
    if ($response->getStatusCode() !== 200 || empty($data['accessJwt']) || empty($data['did'])) {
      return $this->notPosted($response, (string) ($data['message'] ?? $data['error'] ?? ''));
    }
    return $data;
  }

  /**
   * Uploads the page's picture for the link card.
   *
   * The card works without one, so any trouble here just leaves it out.
   *
   * @return array|null
   *   The blob to reference, or NULL.
   */
  protected function thumb(?string $image, string $service, array $auth): ?array {
    if (!$image) {
      return NULL;
    }
    $picture = $this->send('GET', $image);
    if (is_string($picture) || $picture->getStatusCode() !== 200) {
      return NULL;
    }
    $bytes = (string) $picture->getBody();
    $type = strtok($picture->getHeaderLine('Content-Type'), ';');
    if ($bytes === '' || strlen($bytes) > static::BLOB_LIMIT || !str_starts_with((string) $type, 'image/')) {
      return NULL;
    }
    $upload = $this->send('POST', $service . '/xrpc/com.atproto.repo.uploadBlob', [
      'headers' => $auth + ['Content-Type' => $type],
      'body' => $bytes,
    ]);
    if (is_string($upload) || $upload->getStatusCode() !== 200) {
      return NULL;
    }
    return $this->json($upload)['blob'] ?? NULL;
  }

  /**
   * The service's address, without a trailing slash.
   */
  protected function service(array $credentials): string {
    $service = rtrim(trim($credentials['service'] ?? ''), '/');
    return preg_match('#^https://[^/\s]+$#i', $service) ? $service : 'https://bsky.social';
  }

}
