<?php

declare(strict_types=1);

namespace Drupal\crosspost\Adapter;

/**
 * A platform's setup guide: the same four facts for everyone, then the steps.
 *
 * The facts state the platform's conditions and leave the choice to the site
 * owner. They are checked against the platform's documentation when the
 * adapter is written; $checked records when.
 */
final class Guide {

  /**
   * Constructs a Guide.
   *
   * @param string|\Stringable $create
   *   What the site owner creates on the platform.
   * @param string|\Stringable $review
   *   Whether the platform reviews or approves anything first.
   * @param string|\Stringable $cost
   *   What the platform charges for posting access, if anything.
   * @param string|\Stringable $limits
   *   What a post on the platform can and cannot carry.
   * @param array<int, string|\Stringable> $steps
   *   The steps, in order.
   * @param string $documentation
   *   The address of the platform's own documentation.
   * @param string $checked
   *   The date the guide was last checked against it, as YYYY-MM-DD.
   */
  public function __construct(
    public readonly string|\Stringable $create,
    public readonly string|\Stringable $review,
    public readonly string|\Stringable $cost,
    public readonly string|\Stringable $limits,
    public readonly array $steps,
    public readonly string $documentation,
    public readonly string $checked,
  ) {}

}
