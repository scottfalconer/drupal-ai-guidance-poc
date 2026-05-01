<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\State;

use Drupal\Component\Serialization\Json;
use Drupal\ai_guidance\Prompt\GuidanceRedactor;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Aggregates safe state from tagged providers.
 */
final class GuidanceStateAggregator {

  /**
   * Per-request state cache.
   *
   * @var array<string, \Drupal\ai_guidance\Value\GuidanceState>
   */
  private array $cache = [];

  /**
   * Constructs the state aggregator.
   *
   * @param iterable<\Drupal\ai_guidance\State\GuidanceStateProviderInterface> $providers
   *   State providers.
   */
  public function __construct(
    private readonly iterable $providers,
    private readonly GuidanceRedactor $redactor,
    ?LoggerInterface $logger = NULL,
  ) {
    $this->logger = $logger ?? new NullLogger();
  }

  /**
   * Logger for sanitized provider diagnostics.
   */
  private readonly LoggerInterface $logger;

  /**
   * Builds safe state.
   */
  public function build(GuidanceRequest $request): GuidanceState {
    $cache_key = $this->cacheKey($request);
    if (isset($this->cache[$cache_key])) {
      return $this->cache[$cache_key];
    }

    $state = [];
    foreach ($this->providers as $provider) {
      assert($provider instanceof GuidanceStateProviderInterface);
      try {
        $state = array_replace_recursive($state, $provider->getState($request));
      }
      catch (\Throwable $exception) {
        $this->logger->debug('Guidance state provider @provider failed with @class.', [
          '@provider' => get_debug_type($provider),
          '@class' => get_debug_type($exception),
        ]);
      }
    }

    $redacted = $this->redactor->redactArray($state);
    return $this->cache[$cache_key] = new GuidanceState($redacted['value'], $redacted['redactions']);
  }

  /**
   * Builds a stable cache key for repeated context actions in one request.
   */
  private function cacheKey(GuidanceRequest $request): string {
    return hash('sha256', Json::encode([
      'question' => $request->question,
      'account_id' => $request->account->id(),
      'roles' => $request->account->getRoles(),
      'context' => $request->context,
      'mode' => $request->mode,
      'source_limits' => $request->sourceLimits,
    ]));
  }

}
