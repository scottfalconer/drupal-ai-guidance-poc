<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance_cms_demo\Unit;

use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance_cms_demo\Plugin\AiAssistantAction\GuidanceContextAction;

/**
 * Tests the legacy context action.
 *
 * @group ai_guidance_cms_demo
 */
final class GuidanceContextActionTest extends UnitTestCase {

  /**
   * Tests the legacy action no longer injects model-visible setup language.
   */
  public function testLegacyActionIsInert(): void {
    $action = new GuidanceContextAction([], $this->createMock(PrivateTempStoreFactory::class));

    $this->assertSame([], $action->listActions());
    $this->assertSame([], $action->listContexts());
  }

}
