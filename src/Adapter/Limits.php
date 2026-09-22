<?php

declare(strict_types=1);

namespace Drupal\crosspost\Adapter;

/**
 * What a platform accepts in one post.
 */
final class Limits {

  /**
   * Constructs Limits.
   *
   * @param int $textLength
   *   The most characters a post's text may have, the link included.
   * @param int|null $linkLength
   *   What a link counts for, on platforms that count every link the same;
   *   NULL when a link counts for its own length; 0 when the link does not
   *   go into the text at all because the platform carries it beside it.
   * @param bool $imageRequired
   *   Whether the platform takes a post only with an image.
   * @param bool $linksClickable
   *   Whether a link in the post can be followed.
   */
  public function __construct(
    public readonly int $textLength,
    public readonly ?int $linkLength = NULL,
    public readonly bool $imageRequired = FALSE,
    public readonly bool $linksClickable = TRUE,
  ) {}

  /**
   * How many characters a text and its link use up on this platform.
   *
   * The link goes on its own line after the text.
   */
  public function used(string $text, string $url): int {
    if ($this->linkLength === 0) {
      return mb_strlen($text);
    }
    return mb_strlen($text) + 2 + ($this->linkLength ?? mb_strlen($url));
  }

}
