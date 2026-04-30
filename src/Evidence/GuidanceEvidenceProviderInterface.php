<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Evidence;

use Drupal\ai_guidance\Value\GuidanceEvidence;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Collects structured, read-only evidence for guidance domains.
 */
interface GuidanceEvidenceProviderInterface {

  /**
   * Returns the provider ID.
   */
  public function id(): string;

  /**
   * Returns domains this provider can explain.
   *
   * @return string[]
   *   Domain IDs.
   */
  public function domains(): array;

  /**
   * Checks whether this provider should run.
   *
   * @param \Drupal\ai_guidance\Value\GuidanceRequest $request
   *   Guidance request.
   * @param \Drupal\ai_guidance\Value\GuidanceState $state
   *   Safe state.
   * @param string[] $domains
   *   Classified guidance domains.
   */
  public function applies(GuidanceRequest $request, GuidanceState $state, array $domains): bool;

  /**
   * Collects evidence.
   *
   * @param \Drupal\ai_guidance\Value\GuidanceRequest $request
   *   Guidance request.
   * @param \Drupal\ai_guidance\Value\GuidanceState $state
   *   Safe state.
   * @param string[] $domains
   *   Classified guidance domains.
   */
  public function collect(GuidanceRequest $request, GuidanceState $state, array $domains): GuidanceEvidence;

}
