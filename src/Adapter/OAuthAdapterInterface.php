<?php

declare(strict_types=1);

namespace Drupal\crosspost\Adapter;

/**
 * A platform that is connected by approving an app on the platform itself.
 *
 * The site owner enters their own app's ID and secret, is sent to the
 * platform to approve, and comes back with a code. The adapter turns the
 * code into the accounts that person may connect, each with its tokens.
 * Tokens are kept by Crosspost outside configuration; an adapter receives
 * them in the credentials like any other value.
 */
interface OAuthAdapterInterface extends AdapterInterface {

  /**
   * The address on the platform where the person approves the app.
   *
   * @param array<string, string> $credentials
   *   The app's credentials, as asked for by credentialFields().
   * @param string $redirect_uri
   *   Where the platform sends the person back to.
   * @param string $state
   *   A value the platform hands back untouched.
   */
  public function authorizeUrl(array $credentials, string $redirect_uri, string $state): string;

  /**
   * Turns the code the platform sent back into accounts to connect.
   *
   * @param string $code
   *   The code from the platform.
   * @param array<string, string> $credentials
   *   The app's credentials.
   * @param string $redirect_uri
   *   The same address that was given to authorizeUrl().
   *
   * @throws \Drupal\crosspost\Adapter\AdapterException
   *   When the platform refuses, in its own words.
   */
  public function approval(string $code, array $credentials, string $redirect_uri): Approval;

}
