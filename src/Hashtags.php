<?php

declare(strict_types=1);

namespace Drupal\crosspost;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Suggests hashtags from a content type's tag field.
 */
class Hashtags {

  public function __construct(protected ConfigFactoryInterface $configFactory) {}

  /**
   * The hashtags for a piece of content, each with its sign.
   *
   * @return string[]
   *   The hashtags.
   */
  public function forNode(NodeInterface $node): array {
    $field = (string) $this->configFactory->get('crosspost.settings')->get('types.' . $node->bundle() . '.tag_field');
    if ($field === '' || !$node->hasField($field)) {
      return [];
    }
    $tags = [];
    foreach ($node->get($field)->referencedEntities() as $term) {
      $tag = static::fromLabel((string) $term->label());
      if ($tag !== '') {
        $tags[$tag] = $tag;
      }
    }
    return array_values($tags);
  }

  /**
   * Turns a tag's name into a hashtag: "iPhone 18" becomes "#iPhone18".
   *
   * A word written all in lower case gets a capital, so the words can still
   * be told apart; any other word keeps its own capitals.
   */
  public static function fromLabel(string $label): string {
    $words = preg_split('/[^\p{L}\p{N}]+/u', $label, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $tag = '';
    foreach ($words as $word) {
      $tag .= $word === mb_strtolower($word) ? mb_strtoupper(mb_substr($word, 0, 1)) . mb_substr($word, 1) : $word;
    }
    return $tag === '' ? '' : '#' . $tag;
  }

}
