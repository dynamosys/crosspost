<?php

declare(strict_types=1);

namespace Drupal\crosspost;

/**
 * What a page's public address answered, and the link card it offers.
 */
final class PageCheck {

  /**
   * Constructs a PageCheck.
   *
   * @param string $url
   *   The address that was asked.
   * @param int $status
   *   The HTTP status, or 0 when nothing answered.
   * @param string $title
   *   The page's share title.
   * @param string $summary
   *   The page's share description.
   * @param string|null $image
   *   The address of the page's share picture.
   */
  public function __construct(
    public readonly string $url,
    public readonly int $status,
    public readonly string $title = '',
    public readonly string $summary = '',
    public readonly ?string $image = NULL,
  ) {}

  /**
   * Whether the page is public.
   */
  public function isPublic(): bool {
    return $this->status === 200;
  }

}
