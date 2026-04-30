<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\Source\HookHelpSourceProvider;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Tests hook_help() source collection.
 *
 * @group ai_guidance
 */
final class HookHelpSourceProviderTest extends UnitTestCase {

  /**
   * Tests only current-route hook_help() is collected.
   */
  public function testCollectsRouteHelpOnly(): void {
    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('getModuleList')
      ->willReturn(['demo' => [], 'node' => []]);
    $module_handler->method('hasImplementations')
      ->with('help', $this->isType('string'))
      ->willReturn(TRUE);
    $module_handler->method('invoke')
      ->willReturnCallback(function (string $module, string $hook, array $args): string {
        $this->assertSame('help', $hook);
        $this->assertSame('current.route', $args[0]);
        return $module === 'demo' ? '<p>Use the Refresh button.</p>' : '';
      });

    $route_match = $this->createMock(RouteMatchInterface::class);
    $renderer = $this->createMock(RendererInterface::class);
    $provider = new HookHelpSourceProvider($module_handler, $route_match, $renderer);

    $sources = iterator_to_array($provider->getSources(
      new GuidanceRequest('What can I do on this page?', $this->createMock('Drupal\Core\Session\AccountInterface')),
      new GuidanceState([
        'route' => [
          'name' => 'current.route',
          'path' => '/demo',
        ],
        'site' => [
          'enabled_ai_and_relevant_modules' => ['node'],
        ],
      ]),
    ));

    $this->assertCount(1, $sources);
    $this->assertSame('Help for current page', $sources[0]->title);
    $this->assertSame('route_help', $sources[0]->type);
    $this->assertSame('/demo', $sources[0]->citations['url']);
    $this->assertStringContainsString('Refresh button', $sources[0]->text);
  }

}
