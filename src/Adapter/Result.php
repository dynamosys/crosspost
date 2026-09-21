<?php

declare(strict_types=1);

namespace Drupal\crosspost\Adapter;

/**
 * A platform's answer to one post, kept as it came.
 */
final class Result {

  /**
   * Constructs a Result.
   *
   * @param \Drupal\crosspost\Adapter\Outcome $outcome
   *   How the post ended.
   * @param string $reply
   *   What the platform said, in its own words.
   * @param int|null $status
   *   The HTTP status of the platform's answer, if there was one.
   * @param string|null $remoteId
   *   The platform's ID for the post, when it was taken.
   * @param string|null $remoteUrl
   *   Where the post can be seen, when it was taken.
   * @param bool $accessLost
   *   Whether the platform says the connection's access is no longer valid,
   *   so the account has to be connected again.
   */
  public function __construct(
    public readonly Outcome $outcome,
    public readonly string $reply = '',
    public readonly ?int $status = NULL,
    public readonly ?string $remoteId = NULL,
    public readonly ?string $remoteUrl = NULL,
    public readonly bool $accessLost = FALSE,
  ) {}

}
