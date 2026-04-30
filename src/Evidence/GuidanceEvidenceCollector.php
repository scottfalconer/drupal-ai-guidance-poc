<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Evidence;

use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Collects evidence from tagged providers.
 */
final class GuidanceEvidenceCollector {

  /**
   * Constructs the evidence collector.
   *
   * @param \Drupal\ai_guidance\Evidence\GuidanceDomainClassifier $classifier
   *   Domain classifier.
   * @param iterable<\Drupal\ai_guidance\Evidence\GuidanceEvidenceProviderInterface> $providers
   *   Evidence providers.
   */
  public function __construct(
    private readonly GuidanceDomainClassifier $classifier,
    private readonly iterable $providers,
  ) {
  }

  /**
   * Collects evidence for a request and state snapshot.
   *
   * @return array<string, mixed>
   *   Evidence report.
   */
  public function collect(GuidanceRequest $request, GuidanceState $state): array {
    $domains = $this->classifier->classify($request->question);
    $external = $this->classifier->externalEvidenceDomains($request->question);
    $provider_reports = [];
    $evidence = [];

    foreach ($this->providers as $provider) {
      assert($provider instanceof GuidanceEvidenceProviderInterface);
      $provider_domains = $provider->domains();
      $applies = array_intersect($provider_domains, $domains) !== []
        && $provider->applies($request, $state, $domains);
      $provider_reports[] = [
        'id' => $provider->id(),
        'domains' => $provider_domains,
        'status' => $applies ? 'run' : 'skipped',
      ];
      if (!$applies) {
        continue;
      }

      try {
        $evidence[] = $provider->collect($request, $state, $domains)->toArray();
      }
      catch (\Throwable $exception) {
        $provider_reports[] = [
          'id' => $provider->id(),
          'domains' => $provider_domains,
          'status' => 'failed',
          'message' => $exception->getMessage(),
        ];
      }
    }

    return [
      'classified_domains' => $domains,
      'evidence_providers' => $provider_reports,
      'evidence' => $evidence,
      'external_evidence_missing' => $external,
      'track_b_boundary' => $external === []
        ? 'No external/platform evidence was requested by this question.'
        : 'External evidence is named as missing only; platform/vendor adapters are out of scope for ai_guidance.',
    ];
  }

}
