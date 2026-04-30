<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\State;

use Drupal\ai_guidance\Prompt\GuidanceRedactor;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Aggregates safe state from tagged providers.
 */
final class GuidanceStateAggregator {

  /**
   * Constructs the state aggregator.
   *
   * @param iterable<\Drupal\ai_guidance\State\GuidanceStateProviderInterface> $providers
   *   State providers.
   */
  public function __construct(
    private readonly iterable $providers,
    private readonly GuidanceRedactor $redactor,
  ) {
  }

  /**
   * Builds safe state.
   */
  public function build(GuidanceRequest $request): GuidanceState {
    $state = [];
    foreach ($this->providers as $provider) {
      assert($provider instanceof GuidanceStateProviderInterface);
      $state = array_replace_recursive($state, $provider->getState($request));
    }

    $redacted = $this->redactor->redactArray($state);
    return new GuidanceState($redacted['value'], $redacted['redactions']);
  }

}
