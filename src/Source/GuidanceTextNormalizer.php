<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Source;

use Drupal\Component\Utility\Html;

/**
 * Normalizes rendered source text for prompt use.
 */
final class GuidanceTextNormalizer {

  /**
   * Normalizes text or HTML into compact plain text.
   */
  public static function normalize(string $text): string {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $text) ?? $text;
    $text = preg_replace('/<\/(p|div|li|h[1-6]|dt|dd|tr)>/i', "\n", $text) ?? $text;
    $text = strip_tags($text);
    $text = Html::decodeEntities($text);
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    return trim($text);
  }

}
