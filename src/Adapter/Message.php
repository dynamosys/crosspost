<?php

declare(strict_types=1);

namespace Drupal\crosspost\Adapter;

/**
 * What goes to a platform: the words, the page's address, and its card.
 */
final class Message {

  /**
   * Constructs a Message.
   *
   * @param string $text
   *   The words, without the link.
   * @param string $url
   *   The page's public address.
   * @param string $title
   *   The page's title, for platforms that build the link card from fields.
   * @param string $summary
   *   The page's description, for the same.
   * @param string|null $image
   *   The address of the page's share picture, if it has one.
   * @param string[] $tags
   *   Hashtags, without the sign.
   */
  public function __construct(
    public readonly string $text,
    public readonly string $url,
    public readonly string $title = '',
    public readonly string $summary = '',
    public readonly ?string $image = NULL,
    public readonly array $tags = [],
  ) {}

}
