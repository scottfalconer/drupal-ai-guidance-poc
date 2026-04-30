<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\State\CurrentUserStateProvider;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\user\PermissionHandlerInterface;

/**
 * Tests current-user guidance state.
 *
 * @group ai_guidance
 */
final class CurrentUserStateProviderTest extends UnitTestCase {

  /**
   * Tests content capabilities are exposed without admin permissions.
   */
  public function testContentTypePermissions(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('listAll')
      ->with('node.type.')
      ->willReturn(['node.type.article', 'node.type.page']);
    $config_factory->method('get')
      ->willReturnCallback(fn(string $name): ImmutableConfig => match ($name) {
        'node.type.article' => $this->config([
          'type' => 'article',
          'name' => 'Article',
        ]),
        'node.type.page' => $this->config([
          'type' => 'page',
          'name' => 'Page',
        ]),
        default => $this->config([]),
      });

    $current_user = $this->createMock(AccountProxyInterface::class);
    $provider = new CurrentUserStateProvider($current_user, $config_factory);

    $state = $provider->getState(new GuidanceRequest(
      'Why can I draft content, but not configure AI providers or permissions?',
      $this->accountWithPermissions(['create article content', 'edit own article content']),
    ));

    $this->assertFalse($state['user']['can_administer_ai']);
    $this->assertFalse($state['user']['can_administer_ai_providers']);
    $this->assertFalse($state['user']['can_administer_permissions']);
    $this->assertSame([
      [
        'type' => 'article',
        'label' => 'Article',
        'allowed_actions' => ['create', 'edit own'],
      ],
    ], $state['user']['content_type_permissions']);
    $this->assertSame([
      'what the current role can do',
      'what the current role cannot do',
      'who should handle blocked tasks',
      'what to ask an administrator or site builder to do',
    ], $state['user']['current_role_guidance']['answer_order']);
    $this->assertContains('Create or edit `article` content according to the listed content permissions.', $state['user']['current_role_guidance']['current_user_can']);
    $this->assertContains('Configure AI providers or provider credentials; this requires `administer ai providers`.', $state['user']['current_role_guidance']['current_user_cannot']);
    $this->assertContains('Change role permissions; this requires `administer permissions`.', $state['user']['current_role_guidance']['current_user_cannot']);
    $this->assertContains('Ask an administrator to configure AI providers and models before enabling editor-facing AI workflows.', $state['user']['current_role_guidance']['what_to_ask_admin']);
    $this->assertContains('`administer ai providers` exposes provider setup and should stay administrator-only for editor rollouts.', $state['user']['current_role_guidance']['permissions_to_avoid_granting_for_editor_rollout']);
    $this->assertSame(
      'Current account cannot inspect the full role permission matrix; answer from current user state and ask an administrator to review /admin/people/roles when cross-role comparison is needed.',
      $state['user']['role_capability_note'],
    );
    $this->assertArrayNotHasKey('role_capability_summary', $state['user']);
  }

  /**
   * Tests role comparison summaries are gated to permission administrators.
   */
  public function testRoleCapabilitySummaryForPermissionAdministrator(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('listAll')
      ->willReturnCallback(static fn(string $prefix): array => match ($prefix) {
        'node.type.' => ['node.type.article'],
        'user.role.' => ['user.role.anonymous', 'user.role.content_editor', 'user.role.administrator'],
        default => [],
      });
    $config_factory->method('get')
      ->willReturnCallback(fn(string $name): ImmutableConfig => match ($name) {
        'node.type.article' => $this->config([
          'type' => 'article',
          'name' => 'Article',
        ]),
        'user.role.anonymous' => $this->config([
          'id' => 'anonymous',
          'label' => 'Anonymous user',
          'permissions' => ['access content'],
        ]),
        'user.role.content_editor' => $this->config([
          'id' => 'content_editor',
          'label' => 'Content editor',
          'permissions' => [
            'access administration pages',
            'create article content',
            'edit own article content',
            'use editorial transition publish',
            'use text format content_format',
          ],
        ]),
        'user.role.administrator' => $this->config([
          'id' => 'administrator',
          'label' => 'Administrator',
          'is_admin' => TRUE,
          'permissions' => [],
        ]),
        default => $this->config([]),
      });

    $current_user = $this->createMock(AccountProxyInterface::class);
    $provider = new CurrentUserStateProvider($current_user, $config_factory);

    $state = $provider->getState(new GuidanceRequest(
      'Compare what anonymous, content editor, and administrator can do on this page.',
      $this->accountWithPermissions(['administer permissions'], ['authenticated', 'administrator']),
    ));

    $summary_by_role = [];
    foreach ($state['user']['role_capability_summary'] as $summary) {
      $summary_by_role[$summary['role']] = $summary;
    }

    $this->assertArrayHasKey('anonymous', $summary_by_role);
    $this->assertArrayHasKey('content_editor', $summary_by_role);
    $this->assertArrayHasKey('administrator', $summary_by_role);
    $this->assertFalse($summary_by_role['content_editor']['admin_capabilities']['can_administer_permissions']);
    $this->assertTrue($summary_by_role['administrator']['admin_capabilities']['can_administer_permissions']);
    $this->assertSame([
      [
        'type' => 'article',
        'label' => 'Article',
        'allowed_actions' => ['create', 'edit own'],
      ],
    ], $summary_by_role['content_editor']['content_type_permissions']);
    $this->assertSame(
      ['use editorial transition publish'],
      $summary_by_role['content_editor']['workflow_transitions'],
    );
    $this->assertSame(
      ['use text format content_format'],
      $summary_by_role['content_editor']['text_formats'],
    );
  }

  /**
   * Tests administrator role names alone do not unlock role matrix inspection.
   */
  public function testAdministratorRoleWithoutPermissionCannotInspectRoleMatrix(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('listAll')->willReturn([]);

    $current_user = $this->createMock(AccountProxyInterface::class);
    $provider = new CurrentUserStateProvider($current_user, $config_factory);

    $state = $provider->getState(new GuidanceRequest(
      'Compare what anonymous, content editor, and administrator can do on this page.',
      $this->accountWithPermissions([], ['authenticated', 'administrator']),
    ));

    $this->assertArrayNotHasKey('role_capability_summary', $state['user']);
    $this->assertSame(
      'Current account cannot inspect the full role permission matrix; answer from current user state and ask an administrator to review /admin/people/roles when cross-role comparison is needed.',
      $state['user']['role_capability_note'],
    );
  }

  /**
   * Tests registry-derived restricted permissions.
   */
  public function testPermissionRegistryAddsRestrictedPermissions(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('listAll')->willReturn([]);

    $permission_handler = $this->createMock(PermissionHandlerInterface::class);
    $permission_handler->method('getPermissions')->willReturn([
      'administer custom workflow' => [
        'title' => 'Administer custom workflow',
        'provider' => 'custom_workflow',
        'restrict access' => TRUE,
      ],
      'use custom workflow' => [
        'title' => 'Use custom workflow',
        'provider' => 'custom_workflow',
      ],
    ]);

    $current_user = $this->createMock(AccountProxyInterface::class);
    $provider = new CurrentUserStateProvider($current_user, $config_factory, $permission_handler);

    $state = $provider->getState(new GuidanceRequest(
      'Why can I use the workflow but not administer it?',
      $this->accountWithPermissions(['use custom workflow', 'administer custom workflow']),
    ));

    $this->assertContains('administer custom workflow', $state['user']['relevant_permissions']);
    $this->assertArrayHasKey('administer custom workflow', $state['user']['relevant_permission_catalog']);
    $this->assertTrue($state['user']['relevant_permission_catalog']['administer custom workflow']['restrict_access']);
  }

  /**
   * Builds an immutable config mock.
   */
  private function config(array $data): ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('getRawData')->willReturn($data);
    return $config;
  }

  /**
   * Builds an account mock with the specified permissions.
   *
   * @param string[] $permissions
   *   Granted permissions.
   * @param string[] $roles
   *   Granted roles.
   */
  private function accountWithPermissions(
    array $permissions,
    array $roles = [
      'authenticated',
      'content_editor',
    ],
  ): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('getRoles')->willReturn($roles);
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));
    return $account;
  }

}
