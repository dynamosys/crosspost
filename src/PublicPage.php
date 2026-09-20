<?php

declare(strict_types=1);

namespace Drupal\crosspost;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Knows a page's public address, and asks whether it answers.
 *
 * This is the module's reason to exist: on a static or decoupled site,
 * saving in the CMS does not mean the page is there yet.
 */
class PublicPage {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
  ) {}

  /**
   * The address visitors see a piece of content at.
   */
  public function url(NodeInterface $node): string {
    $base = rtrim((string) $this->configFactory->get('crosspost.settings')->get('base_url'), '/');
    if ($base === '') {
      return $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    }
    return $base . $node->toUrl('canonical')->toString();
  }

  /**
   * Asks the public address, and reads the link card from the answer.
   */
  public function check(NodeInterface $node): PageCheck {
    $url = $this->url($node);
    try {
      $response = $this->httpClient->request('GET', $url, [
        'http_errors' => FALSE,
        'timeout' => 10,
        'headers' => ['Accept' => 'text/html'],
      ]);
    }
    catch (GuzzleException) {
      return new PageCheck($url, 0);
    }
    $status = $response->getStatusCode();
    if ($status !== 200) {
      return new PageCheck($url, $status);
    }
    $tags = $this->shareTags((string) $response->getBody());
    return new PageCheck(
      $url,
      $status,
      $tags['og:title'] ?? (string) $node->label(),
      $tags['og:description'] ?? $tags['description'] ?? '',
      $tags['og:image'] ?? NULL,
    );
  }

  /**
   * Reads the share tags from a page.
   *
   * @return array<string, string>
   *   Tag contents keyed by property or name.
   */
  protected function shareTags(string $html): array {
    if ($html === '') {
      return [];
    }
    $document = new \DOMDocument();
    // Pages in the wild are rarely valid; read what can be read.
    @$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    $tags = [];
    foreach ($document->getElementsByTagName('meta') as $meta) {
      $name = $meta->getAttribute('property') ?: $meta->getAttribute('name');
      $content = trim($meta->getAttribute('content'));
      if ($name !== '' && $content !== '' && !isset($tags[$name])) {
        $tags[$name] = $content;
      }
    }
    return $tags;
  }

}
