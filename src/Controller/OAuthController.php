<?php

declare(strict_types=1);

namespace Drupal\crosspost\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\crosspost\Adapter\AdapterException;
use Drupal\crosspost\Adapter\AdapterPluginManager;
use Drupal\crosspost\Adapter\OAuthAdapterInterface;
use Drupal\crosspost\Entity\ConnectionInterface;
use Drupal\crosspost\OAuthFlow;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Where a platform sends a person back to, and the way to connect again.
 */
class OAuthController extends ControllerBase {

  public function __construct(
    protected AdapterPluginManager $adapters,
    protected OAuthFlow $flow,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('plugin.manager.crosspost_adapter'), $container->get('crosspost.oauth_flow'));
  }

  /**
   * The platform sent the person back.
   */
  public function callback(string $adapter, Request $request): RedirectResponse {
    if (!$this->adapters->hasDefinition($adapter) || !$this->adapters->createInstance($adapter) instanceof OAuthAdapterInterface) {
      throw new NotFoundHttpException();
    }
    $label = $this->adapters->createInstance($adapter)->platformName();
    $back = Url::fromRoute('crosspost.connections', [], [
      'query' => ['guide' => $adapter],
      'fragment' => 'crosspost-guide',
    ])->toString(TRUE)->getGeneratedUrl();
    $pending = $this->flow->pending($adapter);
    $state = (string) $request->query->get('state', '');

    if (!$pending || empty($pending['state']) || !hash_equals($pending['state'], $state)) {
      $this->messenger()->addError($this->t('That did not come from a connection started here. Start again from the guide.'));
      $this->flow->clear($adapter);
      return new RedirectResponse($back);
    }
    if ($request->query->has('error') || !$request->query->get('code')) {
      $words = (string) ($request->query->get('error_description') ?: $request->query->get('error') ?: $this->t('No code came back.'));
      $this->messenger()->addError($this->t('@platform did not approve. Its words: %reply Nothing was saved.', [
        '@platform' => $label,
        '%reply' => $words,
      ]));
      $this->flow->clear($adapter);
      return new RedirectResponse($back);
    }
    try {
      $this->flow->complete($adapter, (string) $request->query->get('code'));
    }
    catch (AdapterException $e) {
      $this->messenger()->addError($this->t('@platform did not accept this. Its words: %reply Nothing was saved.', [
        '@platform' => $label,
        '%reply' => $e->getMessage(),
      ]));
      $this->flow->clear($adapter);
    }
    return new RedirectResponse($back);
  }

  /**
   * Sends the person to the platform again for a connection that lost access.
   */
  public function reconnect(ConnectionInterface $crosspost_connection): RedirectResponse {
    $adapter = $this->adapters->createInstance($crosspost_connection->getAdapterId());
    if (!$adapter instanceof OAuthAdapterInterface) {
      throw new NotFoundHttpException();
    }
    $app = array_intersect_key($crosspost_connection->getSettings(), array_flip(array_map(static fn($field) => $field->name, $adapter->credentialFields())));
    $url = $this->flow->begin($adapter->getPluginId(), $app, $crosspost_connection->getKeys(), (string) $crosspost_connection->id());
    return new TrustedRedirectResponse($url);
  }

}
