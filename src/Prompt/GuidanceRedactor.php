<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Prompt;

/**
 * Redacts sensitive values before they can enter assistant context.
 */
final class GuidanceRedactor {

  /**
   * Key names that should never be emitted raw.
   */
  private const SENSITIVE_KEY_PATTERN = '/'
    . '(api[_-]?key|access[_-]?token|secret|password|credential|private[_-]?key'
    . '|auth[_-]?token|bearer|cookie|session[_-]?id|csrf|salt'
    . '|system[_-]?prompt|prompt[_-]?template|hidden[_-]?instruction)'
    . '/i';

  /**
   * Value patterns that look like credentials.
   */
  private const SENSITIVE_VALUE_PATTERNS = [
    '/sk-[A-Za-z0-9_\-]{16,}/',
    '/github_pat_[A-Za-z0-9_]{22,}/',
    '/gh[pousr]_[A-Za-z0-9_]{16,}/',
    '/AKIA[0-9A-Z]{16}/',
    '/Bearer\s+[A-Za-z0-9._~+\/=-]{16,}/i',
    '#https?://[^:\s/@]+:[^@\s/]+@#i',
    '/xox[baprs]-[A-Za-z0-9\-]{16,}/',
    '/-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----.*?-----END (?:RSA |OPENSSH |EC )?PRIVATE KEY-----/s',
  ];

  /**
   * Redacts text and returns redaction notes.
   */
  public function redactText(string $text): array {
    $redactions = [];
    foreach (self::SENSITIVE_VALUE_PATTERNS as $pattern) {
      $text = preg_replace_callback($pattern, function () use (&$redactions) {
        $redactions[] = 'credential-like value';
        return '[redacted]';
      }, $text) ?? $text;
    }
    return [
      'value' => $text,
      'redactions' => array_values(array_unique($redactions)),
    ];
  }

  /**
   * Redacts a nested array and returns redaction notes.
   */
  public function redactArray(array $data): array {
    $redactions = [];
    $value = $this->redactArrayValue($data, $redactions);
    return [
      'value' => $value,
      'redactions' => array_values(array_unique($redactions)),
    ];
  }

  /**
   * Recursively redacts an array value.
   */
  private function redactArrayValue(mixed $value, array &$redactions, ?string $key = NULL): mixed {
    if ($key !== NULL && preg_match(self::SENSITIVE_KEY_PATTERN, $key)) {
      $redactions[] = "sensitive key: $key";
      return '[redacted]';
    }

    if (is_array($value)) {
      $redacted = [];
      foreach ($value as $child_key => $child_value) {
        $redacted[$child_key] = $this->redactArrayValue($child_value, $redactions, is_string($child_key) ? $child_key : NULL);
      }
      return $redacted;
    }

    if (is_string($value)) {
      $text = $this->redactText($value);
      $redactions = array_merge($redactions, $text['redactions']);
      return $text['value'];
    }

    return $value;
  }

}
