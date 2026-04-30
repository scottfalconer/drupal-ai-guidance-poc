<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Value;

use Drupal\Core\Session\AccountInterface;

/**
 * Request object for building AI Assistant guidance contexts.
 */
final class GuidanceRequest {

  public const MODE_GUIDANCE = 'guidance';

  /**
   * Constructs a guidance request.
   *
   * @param string $question
   *   The user's question.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the context is being built for.
   * @param array $context
   *   Caller-provided context, such as DeepChat's current_route path.
   * @param string $mode
   *   The request mode.
   * @param array $sourceLimits
   *   Per source-class limits.
   */
  public function __construct(
    public readonly string $question,
    public readonly AccountInterface $account,
    public readonly array $context = [],
    public readonly string $mode = self::MODE_GUIDANCE,
    public readonly array $sourceLimits = [],
  ) {
  }

  /**
   * Gets a caller-supplied context value.
   */
  public function getContextValue(string $key, mixed $default = NULL): mixed {
    return $this->context[$key] ?? $default;
  }

  /**
   * Gets the limit for a source class.
   */
  public function getSourceLimit(string $source_class, int $default): int {
    return isset($this->sourceLimits[$source_class]) ? (int) $this->sourceLimits[$source_class] : $default;
  }

}
