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
   * @param bool $imageRequired
   *   Whether the platform takes a post only with an image.
   * @param bool $linksClickable
   *   Whether a link in the post can be followed.
   */
  public function __construct(
    public readonly int $textLength,
    public readonly bool $imageRequired = FALSE,
    public readonly bool $linksClickable = TRUE,
  ) {}

}
