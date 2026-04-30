<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Value;

/**
 * Structured evidence collected for one guidance domain.
 */
final class GuidanceEvidence {

  /**
   * Constructs a guidance evidence item.
   *
   * @param string $providerId
   *   Evidence provider ID.
   * @param string $domain
   *   Guidance domain.
   * @param string $confidence
   *   Confidence label.
   * @param array<string, mixed> $drupalEvidence
   *   Drupal-side evidence.
   * @param string[] $knownUnknowns
   *   Known unknowns.
   * @param string[] $externalEvidenceRequired
   *   External evidence that would improve the answer.
   * @param string[] $nextDiagnosticSteps
   *   Safe next diagnostic steps.
   * @param string[] $sources
   *   Source labels or config object IDs.
   */
  public function __construct(
    public readonly string $providerId,
    public readonly string $domain,
    public readonly string $confidence,
    public readonly array $drupalEvidence,
    public readonly array $knownUnknowns = [],
    public readonly array $externalEvidenceRequired = [],
    public readonly array $nextDiagnosticSteps = [],
    public readonly array $sources = [],
  ) {
  }

  /**
   * Converts the evidence item to an array for context/debug output.
   *
   * @return array<string, mixed>
   *   Evidence item.
   */
  public function toArray(): array {
    return [
      'provider_id' => $this->providerId,
      'domain' => $this->domain,
      'confidence' => $this->confidence,
      'drupal_evidence' => $this->drupalEvidence,
      'known_unknowns' => $this->knownUnknowns,
      'external_evidence_required' => $this->externalEvidenceRequired,
      'next_diagnostic_steps' => $this->nextDiagnosticSteps,
      'sources' => $this->sources,
    ];
  }

}
