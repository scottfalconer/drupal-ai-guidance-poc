<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\Source\SiteConfigurationSourceProvider;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Tests safe site configuration summaries.
 *
 * @group ai_guidance
 */
final class SiteConfigurationSourceProviderTest extends UnitTestCase {

  /**
   * Tests front-page summaries include the user-facing alias.
   */
  public function testFrontPageSummaryIncludesPublicAlias(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->willReturnCallback(fn(string $name): ImmutableConfig => match ($name) {
        'system.site' => $this->config([
          'name' => 'Umami',
          'page.front' => '/page/1',
        ]),
        'system.theme' => $this->config(['default' => 'olivero']),
        'node.type.article' => $this->config([
          'type' => 'article',
          'name' => 'Article',
          'description' => 'Editorial content.',
        ]),
        default => $this->config([]),
      });
    $config_factory->method('listAll')
      ->willReturnCallback(static fn(string $prefix): array => match ($prefix) {
        'node.type.' => ['node.type.article'],
        'views.view.', 'canvas.component.' => [],
        default => [],
      });

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')->willReturn(FALSE);

    $alias_manager = $this->createMock(AliasManagerInterface::class);
    $alias_manager->expects($this->atLeastOnce())
      ->method('getAliasByPath')
      ->with('/page/1')
      ->willReturn('/home');

    $provider = new SiteConfigurationSourceProvider($config_factory, $entity_type_manager, $alias_manager);
    $request = new GuidanceRequest(
      'I just made this page. How can I add it to the items shown on the front page?',
      $this->accountWithPermissions(['administer site configuration'], ['authenticated', 'administrator']),
    );

    $sources = iterator_to_array($provider->getSources($request, new GuidanceState([])));

    $this->assertCount(1, $sources);
    $this->assertStringContainsString('- Front page: `/home` (configured internal path `/page/1`).', $sources[0]->text);
    $this->assertStringContainsString('- Public front page path: `/home`.', $sources[0]->text);
    $this->assertStringContainsString('- Configured internal front page path: `/page/1`. Use the public path in user-facing verification steps.', $sources[0]->text);
  }

  /**
   * Tests non-admin accounts receive a limited configuration summary.
   */
  public function testLimitedSummaryForNonAdminAccount(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->willReturnCallback(fn(string $name): ImmutableConfig => match ($name) {
        'system.site' => $this->config([
          'name' => 'Umami',
          'page.front' => '/page/1',
        ]),
        'system.theme' => $this->config(['default' => 'olivero']),
        'node.type.article' => $this->config([
          'type' => 'article',
          'name' => 'Article',
          'description' => 'Editorial content.',
        ]),
        'node.type.page' => $this->config([
          'type' => 'page',
          'name' => 'Page',
          'description' => 'Utility page.',
        ]),
        'views.view.frontpage' => $this->config([
          'id' => 'frontpage',
          'label' => 'Front page',
        ]),
        default => $this->config([]),
      });
    $config_factory->method('listAll')
      ->willReturnCallback(static fn(string $prefix): array => match ($prefix) {
        'node.type.' => ['node.type.article', 'node.type.page'],
        'views.view.' => ['views.view.frontpage'],
        'canvas.component.' => ['canvas.component.hero'],
        default => [],
      });

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');

    $alias_manager = $this->createMock(AliasManagerInterface::class);
    $alias_manager->method('getAliasByPath')->with('/page/1')->willReturn('/home');

    $provider = new SiteConfigurationSourceProvider($config_factory, $entity_type_manager, $alias_manager);
    $request = new GuidanceRequest(
      'I just made this page. How can I add it to the items shown on the front page?',
      $this->accountWithPermissions(['create article content']),
    );

    $sources = iterator_to_array($provider->getSources($request, new GuidanceState([])));

    $this->assertCount(1, $sources);
    $this->assertStringContainsString('# Limited site configuration summary', $sources[0]->text);
    $this->assertStringContainsString('- Front page: `/home`.', $sources[0]->text);
    $this->assertStringContainsString('- `article`: Article - Editorial content. Current account: create.', $sources[0]->text);
    $this->assertStringContainsString('The current account cannot inspect Views, Canvas composition, block layout, or route ownership for the front page.', $sources[0]->text);
    $this->assertStringNotContainsString('Configured internal front page path', $sources[0]->text);
    $this->assertStringNotContainsString('Default theme', $sources[0]->text);
    $this->assertStringNotContainsString('frontpage', $sources[0]->text);
    $this->assertStringNotContainsString('canvas.component.hero', $sources[0]->text);
    $this->assertStringNotContainsString('`page`: Page', $sources[0]->text);
  }

  /**
   * Tests outside-agent questions do not receive beginner exercises.
   */
  public function testAgentHandoffDoesNotIncludeBeginnerExercise(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->willReturnCallback(fn(string $name): ImmutableConfig => match ($name) {
        'system.site' => $this->config([
          'name' => 'Vision25',
          'page.front' => '/home',
        ]),
        'system.theme' => $this->config(['default' => 'olivero']),
        'node.type.lab' => $this->config([
          'type' => 'lab',
          'name' => 'Lab',
          'description' => 'Innovation lab content.',
        ]),
        default => $this->config([]),
      });
    $config_factory->method('listAll')
      ->willReturnCallback(static fn(string $prefix): array => match ($prefix) {
        'node.type.' => ['node.type.lab'],
        'views.view.', 'canvas.component.' => [],
        default => [],
      });

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')->willReturn(FALSE);

    $provider = new SiteConfigurationSourceProvider($config_factory, $entity_type_manager);
    $request = new GuidanceRequest(
      'If I ask an outside coding agent to work on this site, what guidance should I give it so it follows Drupal best practices?',
      $this->accountWithPermissions(['administer site configuration'], ['authenticated', 'administrator']),
    );

    $sources = iterator_to_array($provider->getSources($request, new GuidanceState([])));

    $this->assertCount(1, $sources);
    $this->assertStringContainsString('`lab`: Lab - Innovation lab content.', $sources[0]->text);
    $this->assertStringNotContainsString('Beginner first exercise', $sources[0]->text);
    $this->assertStringNotContainsString('create exactly one draft', $sources[0]->text);
  }

  /**
   * Builds an immutable config mock.
   */
  private function config(array $data): ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static fn(string $key): mixed => $data[$key] ?? NULL);
    $config->method('getRawData')->willReturn($data);
    return $config;
  }

  /**
   * Builds an account mock with the specified permissions.
   *
   * @param string[] $permissions
   *   Granted permissions.
   */
  private function accountWithPermissions(array $permissions, array $roles = ['authenticated', 'content_editor']): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('getRoles')->willReturn($roles);
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));
    return $account;
  }

}
