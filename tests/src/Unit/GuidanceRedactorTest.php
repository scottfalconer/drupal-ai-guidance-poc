<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\Prompt\GuidanceRedactor;

/**
 * Tests guidance redaction.
 *
 * @group ai_guidance
 */
final class GuidanceRedactorTest extends UnitTestCase {

  /**
   * Tests sensitive keys and values are redacted.
   */
  public function testRedactsSensitiveKeysAndValues(): void {
    $redactor = new GuidanceRedactor();
    $result = $redactor->redactArray([
      'provider' => [
        'api_key' => 'sk-abcdefghijklmnopqrstuvwxyz',
        'auth_token' => 'safe-looking but key name is sensitive',
        'session_id' => 'abc123',
        'csrf' => 'csrf-value',
        'label' => 'Configured provider',
      ],
      'cookie' => 'SSESS=value',
      'assistant' => [
        'system_prompt' => 'hidden instructions',
      ],
      'text' => 'token sk-abcdefghijklmnopqrstuvwxyz Bearer abcdefghijklmnopqrstuvwxyz ghp_abcdefghijklmnop1234567890 AKIAABCDEFGHIJKLMNOP https://user:pass@example.com',
    ]);

    $this->assertSame('[redacted]', $result['value']['provider']['api_key']);
    $this->assertSame('[redacted]', $result['value']['provider']['auth_token']);
    $this->assertSame('[redacted]', $result['value']['provider']['session_id']);
    $this->assertSame('[redacted]', $result['value']['provider']['csrf']);
    $this->assertSame('[redacted]', $result['value']['cookie']);
    $this->assertSame('[redacted]', $result['value']['assistant']['system_prompt']);
    $this->assertStringNotContainsString('sk-abcdefghijklmnopqrstuvwxyz', $result['value']['text']);
    $this->assertStringNotContainsString('Bearer abcdefghijklmnopqrstuvwxyz', $result['value']['text']);
    $this->assertStringNotContainsString('ghp_abcdefghijklmnop1234567890', $result['value']['text']);
    $this->assertStringNotContainsString('AKIAABCDEFGHIJKLMNOP', $result['value']['text']);
    $this->assertStringNotContainsString('user:pass@', $result['value']['text']);
    $this->assertNotEmpty($result['redactions']);
  }

}
