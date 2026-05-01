<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Value;

/**
 * Safe normalized state for AI Assistant guidance contexts.
 *
 * GuidanceState is the permission-filtered, redacted snapshot shared with
 * evidence and source providers for one assistant request. Treat values as
 * current-account evidence, not as a cross-site configuration dump.
 *
 * @api
 */
final class GuidanceState {

  /**
   * Constructs a guidance state snapshot.
   */
  public function __construct(
    private readonly array $values,
    private readonly array $redactions = [],
  ) {
  }

  /**
   * Gets a state value.
   */
  public function get(string $key, mixed $default = NULL): mixed {
    return $this->values[$key] ?? $default;
  }

  /**
   * Returns a copy with merged values.
   */
  public function with(array $values): self {
    return new self(array_replace_recursive($this->values, $values), $this->redactions);
  }

  /**
   * Returns all state values.
   */
  public function toArray(): array {
    return $this->values;
  }

  /**
   * Returns state redaction notes.
   */
  public function redactions(): array {
    return $this->redactions;
  }

}
