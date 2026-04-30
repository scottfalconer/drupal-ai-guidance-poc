<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\Prompt\GuidanceRedactor;
use Drupal\ai_guidance\State\GuidanceStateAggregator;
use Drupal\ai_guidance\State\GuidanceStateProviderInterface;
use Drupal\ai_guidance\Value\GuidanceRequest;

/**
 * Tests state aggregation.
 *
 * @group ai_guidance
 */
final class GuidanceStateAggregatorTest extends UnitTestCase {

  /**
   * Tests repeated equivalent requests reuse aggregated state.
   */
  public function testBuildCachesEquivalentRequests(): void {
    $provider = new class implements GuidanceStateProviderInterface {

      public int $calls = 0;

      public function getState(GuidanceRequest $request): array {
        $this->calls++;
        return [
          'question' => $request->question,
        ];
      }

    };
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('7');
    $account->method('getRoles')->willReturn(['authenticated', 'content_editor']);

    $aggregator = new GuidanceStateAggregator([$provider], new GuidanceRedactor());
    $request = new GuidanceRequest('What can I do here?', $account, ['current_route' => '/admin/content']);

    $this->assertSame(['question' => 'What can I do here?'], $aggregator->build($request)->toArray());
    $this->assertSame(['question' => 'What can I do here?'], $aggregator->build($request)->toArray());
    $this->assertSame(1, $provider->calls);

    $aggregator->build(new GuidanceRequest('What can editors do here?', $account, ['current_route' => '/admin/content']));
    $this->assertSame(2, $provider->calls);
  }

}
