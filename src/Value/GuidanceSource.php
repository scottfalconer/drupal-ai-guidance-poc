<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Value;

/**
 * Trusted source document used to ground guidance answers.
 *
 * Source documents are a lower-level, read-only bridge for Help Topics,
 * hook_help(), package guidance, and optional module-owned policy context. New
 * integrations should prefer GuidanceEvidenceProviderInterface unless they are
 * contributing a human-authored source document with citations.
 *
 * @api
 */
final class GuidanceSource {

  /**
   * Constructs a guidance source.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $canonicalId,
    public readonly string $title,
    public readonly string $type,
    public readonly string $text,
    public readonly int $priority = 0,
    public readonly array $citations = [],
    public readonly array $metadata = [],
    public readonly array $accessNotes = [],
    public readonly array $cacheability = [],
    public readonly ?int $tokenEstimate = NULL,
    public readonly ?string $citationId = NULL,
  ) {
  }

  /**
   * Returns a copy with a citation ID.
   */
  public function withCitationId(string $citation_id): self {
    return new self(
      $this->id,
      $this->canonicalId,
      $this->title,
      $this->type,
      $this->text,
      $this->priority,
      $this->citations,
      $this->metadata,
      $this->accessNotes,
      $this->cacheability,
      $this->tokenEstimate,
      $citation_id,
    );
  }

  /**
   * Returns a copy with updated text and token estimate.
   */
  public function withText(string $text): self {
    return new self(
      $this->id,
      $this->canonicalId,
      $this->title,
      $this->type,
      $text,
      $this->priority,
      $this->citations,
      $this->metadata,
      $this->accessNotes,
      $this->cacheability,
      self::estimateTokens($text),
      $this->citationId,
    );
  }

  /**
   * Estimates token count without a provider-specific tokenizer.
   */
  public static function estimateTokens(string $text): int {
    return max(1, (int) ceil(mb_strlen($text) / 4));
  }

  /**
   * Converts the source to an array for debug and tests.
   */
  public function toArray(bool $include_text = TRUE): array {
    $data = [
      'id' => $this->id,
      'canonical_id' => $this->canonicalId,
      'citation_id' => $this->citationId,
      'title' => $this->title,
      'type' => $this->type,
      'priority' => $this->priority,
      'citations' => $this->citations,
      'metadata' => $this->metadata,
      'access_notes' => $this->accessNotes,
      'cacheability' => $this->cacheability,
      'token_estimate' => $this->tokenEstimate ?? self::estimateTokens($this->text),
    ];
    if ($include_text) {
      $data['text'] = $this->text;
    }
    return $data;
  }

}
