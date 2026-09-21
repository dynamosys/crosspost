<?php

declare(strict_types=1);

namespace Drupal\crosspost;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\crosspost\Adapter\AdapterPluginManager;
use Drupal\crosspost\Adapter\Approval;
use Drupal\crosspost\Adapter\OAuthAdapterInterface;

/**
 * Carries a person through approving an app on a platform and back.
 *
 * What is known between the steps is kept in the person's private temporary
 * store: the app's ID and key, the state value, and, after the platform sent
 * them back, the accounts to choose from.
 */
class OAuthFlow {

  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected AdapterPluginManager $adapters,
    protected Credentials $credentials,
  ) {}

  /**
   * Where a platform sends the person back to. Goes into the platform's app.
   */
  public function redirectUri(string $adapter_id): string {
    return Url::fromRoute('crosspost.callback', ['adapter' => $adapter_id], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl();
  }

  /**
   * Starts the flow and returns the address on the platform to go to.
   *
   * @param string $adapter_id
   *   The adapter.
   * @param array<string, string> $settings
   *   The app's values that are not secret.
   * @param array<string, string> $keys
   *   Key IDs of the app's secret values.
   * @param string|null $reconnect
   *   (optional) The ID of the connection that is being connected again.
   */
  public function begin(string $adapter_id, array $settings, array $keys, ?string $reconnect = NULL): string {
    $adapter = $this->adapters->createInstance($adapter_id);
    assert($adapter instanceof OAuthAdapterInterface);
    $state = Crypt::randomBytesBase64(32);
    $this->store()->set($adapter_id, [
      'settings' => $settings,
      'keys' => $keys,
      'state' => $state,
      'reconnect' => $reconnect,
      'approval' => NULL,
    ]);
    return $adapter->authorizeUrl($settings + $this->credentials->secrets($keys), $this->redirectUri($adapter_id), $state);
  }

  /**
   * What is known about a flow in progress.
   *
   * @return array|null
   *   Keys: settings, keys, state, reconnect, approval.
   */
  public function pending(string $adapter_id): ?array {
    return $this->store()->get($adapter_id);
  }

  /**
   * Finishes the platform's part: turns the code into accounts to choose from.
   *
   * @throws \Drupal\crosspost\Adapter\AdapterException
   *   When the platform refuses, in its own words.
   */
  public function complete(string $adapter_id, string $code): Approval {
    $pending = $this->pending($adapter_id) ?? [];
    $adapter = $this->adapters->createInstance($adapter_id);
    assert($adapter instanceof OAuthAdapterInterface);
    $credentials = ($pending['settings'] ?? []) + $this->credentials->secrets($pending['keys'] ?? []);
    $approval = $adapter->approval($code, $credentials, $this->redirectUri($adapter_id));
    $this->store()->set($adapter_id, ['approval' => $approval, 'state' => NULL] + $pending);
    return $approval;
  }

  /**
   * Forgets a flow.
   */
  public function clear(string $adapter_id): void {
    $this->store()->delete($adapter_id);
  }

  /**
   * The person's temporary store.
   */
  protected function store() {
    return $this->tempStoreFactory->get('crosspost_oauth');
  }

}
