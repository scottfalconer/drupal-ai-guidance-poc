<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Source;

use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceSource;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Provides trusted source documents for AI Assistant guidance contexts.
 *
 * @internal
 *   Source providers are implementation details behind the public AI Assistant
 *   context actions.
 */
interface GuidanceSourceProviderInterface {

  /**
   * Returns source documents.
   *
   * @return iterable<\Drupal\ai_guidance\Value\GuidanceSource>
   *   Source documents the current account may see.
   */
  public function getSources(GuidanceRequest $request, GuidanceState $state): iterable;

}
