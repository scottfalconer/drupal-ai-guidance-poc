<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\Plugin\AiAssistantAction\GuidanceReadOnlyActionBase;
use Drupal\ai_guidance\Prompt\GuidanceRedactor;
use Drupal\ai_guidance\Value\GuidanceSource;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests read-only guidance action formatting helpers.
 *
 * @group ai_guidance
 */
final class GuidanceReadOnlyActionBaseTest extends UnitTestCase {

  /**
   * Tests source links keep visible display citation IDs.
   */
  public function testSourceLinkIncludesDisplayCitationInLinkText(): void {
    $action = new class(
      [],
      $this->createMock(PrivateTempStoreFactory::class),
      $this->createMock(AccountProxyInterface::class),
      new RequestStack(),
      new GuidanceRedactor(),
    ) extends GuidanceReadOnlyActionBase {

      /**
       * {@inheritdoc}
       */
      public function listContexts(): array {
        return [];
      }

      /**
       * Exposes source line formatting for tests.
       */
      public function exposeSourceLines(array $sources, string $prefix = 'H'): array {
        return $this->sourceLines($sources, $prefix, 1);
      }

    };

    $lines = $action->exposeSourceLines([
      new GuidanceSource(
        id: 'help:safe_editor_ai',
        canonicalId: 'help.safe_editor_ai',
        title: 'Safe AI configuration for content editors',
        type: 'help_topic',
        text: 'Keep provider setup administrator-only.',
        citations: ['url' => '/admin/help/topic/ai_guidance.safe_editor_ai'],
      ),
    ]);

    $this->assertContains('- [H1] [Safe AI configuration for content editors](/admin/help/topic/ai_guidance.safe_editor_ai)', $lines);
    $this->assertContains('Final Sources bullets must start with their display citation ID and should copy the relevant bullet shown here.', $lines);
  }

  /**
   * Tests source evidence is redacted before context formatting.
   */
  public function testSourceLinesRedactSourceTextAndUnsafeUrls(): void {
    $action = new class(
      [],
      $this->createMock(PrivateTempStoreFactory::class),
      $this->createMock(AccountProxyInterface::class),
      new RequestStack(),
      new GuidanceRedactor(),
    ) extends GuidanceReadOnlyActionBase {

      /**
       * {@inheritdoc}
       */
      public function listContexts(): array {
        return [];
      }

      /**
       * Exposes source line formatting for tests.
       */
      public function exposeSourceLines(array $sources, string $prefix = 'S'): array {
        return $this->sourceLines($sources, $prefix, 2);
      }

    };

    $lines = $action->exposeSourceLines([
      new GuidanceSource(
        id: 'help:secret',
        canonicalId: 'help.secret',
        title: 'Secret source',
        type: 'help_topic',
        text: 'Do not expose Bearer abcdefghijklmnop or github_pat_abcdefghijklmnopqrstuvwxyz.',
        citations: [
          'url' => '/admin/help/topic/example?token=secret#fragment',
          'access_token' => 'github_pat_abcdefghijklmnopqrstuvwxyz',
        ],
        metadata: [
          'source_url' => 'https://docs.example.com/private?api_key=secret',
          'secret' => 'sk-abcdefghijklmnop',
        ],
      ),
      new GuidanceSource(
        id: 'help:metadata-secret',
        canonicalId: 'help.metadata_secret',
        title: 'Metadata URL source',
        type: 'help_topic',
        text: 'Metadata source URL should be safe.',
        metadata: [
          'source_url' => 'https://docs.example.com/private?api_key=secret#fragment',
        ],
      ),
    ]);

    $context = implode("\n", $lines);
    $this->assertContains('- [S1] [Secret source](/admin/help/topic/example)', $lines);
    $this->assertContains('- [S2] [Metadata URL source](https://docs.example.com/private)', $lines);
    $this->assertStringContainsString('[redacted]', $context);
    $this->assertStringNotContainsString('Bearer abcdefghijklmnop', $context);
    $this->assertStringNotContainsString('github_pat_abcdefghijklmnopqrstuvwxyz', $context);
    $this->assertStringNotContainsString('token=secret', $context);
    $this->assertStringNotContainsString('api_key=secret', $context);
    $this->assertStringNotContainsString('sk-abcdefghijklmnop', $context);
  }

}
