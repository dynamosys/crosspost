<?php

declare(strict_types=1);

namespace Drupal\crosspost\Adapter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for adapters that talk to a platform over HTTP.
 */
abstract class HttpAdapterBase extends AdapterBase implements ContainerFactoryPluginInterface {

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $adapter = new static($configuration, $plugin_id, $plugin_definition);
    $adapter->httpClient = $container->get('http_client');
    return $adapter;
  }

  /**
   * Sends a request and never throws for the platform's answer.
   *
   * @return \Psr\Http\Message\ResponseInterface|string
   *   The response, or what went wrong when nothing answered.
   */
  protected function send(string $method, string $url, array $options = []): ResponseInterface|string {
    try {
      return $this->httpClient->request($method, $url, $options + ['http_errors' => FALSE, 'timeout' => 20]);
    }
    catch (GuzzleException $e) {
      return $e->getMessage();
    }
  }

  /**
   * Turns an answer that is not a success into a Result.
   *
   * No answer, or an answer that says the platform is in trouble or busy,
   * is a failure worth another try. Anything else is the platform saying
   * no.
   */
  protected function notPosted(ResponseInterface|string $response, string $reply = ''): Result {
    if (is_string($response)) {
      return new Result(Outcome::Failed, $response);
    }
    $status = $response->getStatusCode();
    $reply = $reply !== '' ? $reply : mb_substr(trim(strip_tags((string) $response->getBody())), 0, 500);
    return new Result($status >= 500 || $status === 429 || $status === 408 ? Outcome::Failed : Outcome::Refused, $reply, $status);
  }

  /**
   * Decodes a JSON body.
   */
  protected function json(ResponseInterface $response): array {
    $data = json_decode((string) $response->getBody(), TRUE);
    return is_array($data) ? $data : [];
  }

}
