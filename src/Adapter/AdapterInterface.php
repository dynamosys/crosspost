<?php

declare(strict_types=1);

namespace Drupal\crosspost\Adapter;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * One social platform: how to set it up, what it accepts, how to post to it.
 *
 * An adapter takes no view on whether its platform is worth using. It states
 * the platform's conditions in its guide, carries the message, and reports
 * the platform's answer as it came.
 */
interface AdapterInterface extends PluginInspectionInterface {

  /**
   * The platform's name.
   */
  public function label(): string;

  /**
   * What the site owner has to do on the platform before connecting.
   */
  public function guide(): Guide;

  /**
   * What the platform accepts in one post.
   */
  public function limits(): Limits;

  /**
   * What the adapter needs to know to connect one account.
   *
   * @return \Drupal\crosspost\Adapter\CredentialField[]
   *   The fields, in the order to ask for them.
   */
  public function credentialFields(): array;

  /**
   * What one connection is called on this platform: an account, a page.
   */
  public function accountNoun(): string;

  /**
   * Asks the platform which account the credentials belong to.
   *
   * Sends nothing public. Used by the Test operation and after connecting.
   *
   * @param array<string, string> $credentials
   *   The connection's resolved credentials, keyed as the adapter asked.
   *
   * @return string
   *   The account's name as the platform shows it.
   *
   * @throws \Drupal\crosspost\Adapter\AdapterException
   *   When the platform refuses the credentials or cannot be reached.
   */
  public function identify(array $credentials): string;

  /**
   * Posts a message.
   *
   * Never throws for the platform's answer: a refusal or a failure comes
   * back as a Result, in the platform's own words.
   *
   * @param \Drupal\crosspost\Adapter\Message $message
   *   What to post.
   * @param array<string, string> $credentials
   *   The connection's resolved credentials.
   */
  public function post(Message $message, array $credentials): Result;

}
