<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\State;

use Drupal\ai_guidance\Value\GuidanceRequest;

/**
 * Provides safe, deterministic state for AI Assistant guidance contexts.
 *
 * @internal
 *   State providers are implementation details behind the public AI Assistant
 *   context actions.
 */
interface GuidanceStateProviderInterface {

  /**
   * Returns state values.
   *
   * Providers must not emit secrets, raw config dumps, hidden prompts, or
   * inaccessible entity data.
   */
  public function getState(GuidanceRequest $request): array;

}
