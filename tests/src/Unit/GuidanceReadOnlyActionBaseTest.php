<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\Plugin\AiAssistantAction\GuidanceReadOnlyActionBase;
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
    ) extends GuidanceReadOnlyActionBase {

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

}
