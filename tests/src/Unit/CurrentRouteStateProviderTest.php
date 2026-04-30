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

  /**
   * Tests caller route context is stored without query strings or fragments.
   */
  public function testCurrentRouteContextStripsQueryAndFragment(): void {
    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getRouteName')->willReturn('system.admin_content');
    $route_match->method('getRawParameters')->willReturn(new InputBag());

    $current_path = $this->createMock(CurrentPathStack::class);
    $current_path->method('getPath')->willReturn('/admin/content');

    $router = $this->createMock(UrlMatcherInterface::class);
    $router->expects($this->once())
      ->method('match')
      ->with('/node/add/article')
      ->willReturn(['_route' => 'node.add', 'node_type' => 'article']);

    $access_manager = $this->createMock(AccessManagerInterface::class);
    $access_manager->method('checkNamedRoute')->willReturn(FALSE);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $permission): bool => $permission === 'create article content');
    $provider = new CurrentRouteStateProvider($route_match, $current_path, $router, $access_manager, $account);

    $state = $provider->getState(new GuidanceRequest(
      'What is this page?',
      $account,
      ['current_route' => '/node/add/article?token=secret#fragment'],
    ));

    $this->assertSame('/node/add/article', $state['route']['path']);
    $this->assertSame('caller_context', $state['request_context']['source']);
    $this->assertTrue($state['request_context']['route_resolved_from_context']);
    $this->assertSame('/node/add/article', $state['request_context']['requested_path_access']['path']);
    $this->assertTrue($state['request_context']['requested_path_access']['access_allowed']);
  }

  /**
   * Tests external caller route context is rejected.
   */
  public function testExternalCurrentRouteContextIsRejected(): void {
    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getRouteName')->willReturn('system.admin_content');
    $route_match->method('getRawParameters')->willReturn(new InputBag());

    $current_path = $this->createMock(CurrentPathStack::class);
    $current_path->method('getPath')->willReturn('/admin/content');

    $router = $this->createMock(UrlMatcherInterface::class);
    $router->expects($this->never())->method('match');

    $access_manager = $this->createMock(AccessManagerInterface::class);
    $access_manager->method('checkNamedRoute')->willReturn(TRUE);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    $provider = new CurrentRouteStateProvider($route_match, $current_path, $router, $access_manager, $account);

    $state = $provider->getState(new GuidanceRequest(
      'What is this page?',
      $account,
      ['current_route' => 'https://example.com/node/add/article?token=secret'],
    ));

    $this->assertSame('/admin/content', $state['route']['path']);
    $this->assertSame('current_request', $state['request_context']['source']);
    $this->assertFalse($state['request_context']['route_resolved_from_context']);
    $this->assertNull($state['request_context']['requested_path_access']);
  }

}
