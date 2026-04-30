<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\State\CurrentRouteStateProvider;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;

/**
 * Tests current-route guidance state.
 *
 * @group ai_guidance
 */
final class CurrentRouteStateProviderTest extends UnitTestCase {

  /**
   * Tests role-aware questions include common path access facts.
   */
  public function testCommonPathAccessForRoleQuestion(): void {
    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getRouteName')->willReturn('node.add');
    $route_match->method('getRawParameters')->willReturn(new InputBag(['node_type' => 'article']));

    $current_path = $this->createMock(CurrentPathStack::class);
    $current_path->method('getPath')->willReturn('/node/add/article');

    $router = $this->createMock(UrlMatcherInterface::class);
    $router->method('match')->willReturnCallback(static fn(string $path): array => match ($path) {
      '/node/add/article' => ['_route' => 'node.add', 'node_type' => 'article'],
      '/admin/config/ai' => ['_route' => 'ai.settings'],
      '/admin/people/permissions' => ['_route' => 'user.admin_permissions'],
      default => throw new ResourceNotFoundException(),
    });

    $access_manager = $this->createMock(AccessManagerInterface::class);
    $access_manager->method('checkNamedRoute')
      ->willReturnCallback(static fn(string $route_name): bool => $route_name === 'node.add');

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $permission): bool => $permission === 'create article content');
    $provider = new CurrentRouteStateProvider($route_match, $current_path, $router, $access_manager, $account);

    $state = $provider->getState(new GuidanceRequest(
      'Why can I draft content, but not configure AI providers or permissions?',
      $account,
    ));

    $this->assertTrue($state['route']['access_allowed']);

    $access_by_path = [];
    foreach ($state['common_path_access'] as $access) {
      $access_by_path[$access['path']] = $access;
    }

    $this->assertTrue($access_by_path['/node/add/article']['access_allowed']);
    $this->assertFalse($access_by_path['/admin/config/ai']['access_allowed']);
    $this->assertFalse($access_by_path['/admin/people/permissions']['access_allowed']);
  }

}
