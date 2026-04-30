<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\Evidence\AccessEvidenceProvider;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Tests access evidence collection.
 *
 * @group ai_guidance
 */
final class AccessEvidenceProviderTest extends UnitTestCase {

  /**
   * Tests the provider turns safe state into structured access evidence.
   */
  public function testCollectsRoleRouteAndEntityEvidence(): void {
    $provider = new AccessEvidenceProvider();
    $request = new GuidanceRequest(
      'Why can I draft content, but not configure AI providers or permissions?',
      $this->account(['authenticated', 'content_editor']),
    );
    $state = new GuidanceState([
      'user' => [
        'is_authenticated' => TRUE,
        'roles' => ['authenticated', 'content_editor'],
        'relevant_permissions' => [
          'access administration pages',
          'create article content',
        ],
        'relevant_permission_catalog' => [
          'access administration pages' => [
            'title' => 'Use the administration pages',
            'restrict_access' => FALSE,
          ],
          'create article content' => [
            'title' => 'Article: Create new content',
            'restrict_access' => FALSE,
          ],
        ],
        'can_access_administration_pages' => TRUE,
        'can_administer_ai' => FALSE,
        'can_administer_ai_providers' => FALSE,
        'can_administer_assistants' => FALSE,
        'can_administer_permissions' => FALSE,
        'can_administer_site_configuration' => FALSE,
        'content_type_permissions' => [
          [
            'type' => 'article',
            'label' => 'Article',
            'allowed_actions' => ['create', 'edit own'],
          ],
        ],
        'current_role_guidance' => [
          'current_user_can' => [
            'Create or edit `article` content according to the listed content permissions.',
          ],
          'current_user_cannot' => [
            'Configure AI providers or provider credentials; this requires `administer ai providers`.',
            'Change role permissions; this requires `administer permissions`.',
          ],
          'what_to_ask_admin' => [
            'Ask an administrator to review role permissions at `/admin/people/permissions`.',
          ],
          'permissions_to_avoid_granting_for_editor_rollout' => [
            '`administer ai providers` exposes provider setup and should stay administrator-only for editor rollouts.',
          ],
        ],
      ],
      'route' => [
        'name' => 'node.add',
        'path' => '/node/add/article',
        'access_allowed' => TRUE,
      ],
      'request_context' => [
        'requested_path_access' => [
          'path' => '/node/add/article',
          'route_name' => 'node.add',
          'access_allowed' => TRUE,
        ],
      ],
      'common_path_access' => [
        [
          'path' => '/admin/config/ai',
          'route_name' => 'ai.admin_settings',
          'access_allowed' => FALSE,
        ],
        [
          'path' => '/admin/people/permissions',
          'route_name' => 'user.admin_permissions',
          'access_allowed' => FALSE,
        ],
      ],
      'entity' => [
        'type' => 'node',
        'id' => NULL,
        'bundle' => 'article',
        'access' => [
          'view' => TRUE,
          'update' => TRUE,
          'delete' => FALSE,
        ],
        'moderation_state' => 'draft',
      ],
    ]);

    $this->assertTrue($provider->applies($request, $state, ['access', 'ai_feature_access']));

    $evidence = $provider->collect($request, $state, ['access', 'ai_feature_access'])->toArray();

    $this->assertSame('drupal.access', $evidence['provider_id']);
    $this->assertSame('access', $evidence['domain']);
    $this->assertSame('high', $evidence['confidence']);
    $this->assertSame(['authenticated', 'content_editor'], $evidence['drupal_evidence']['current_user']['roles']);
    $this->assertFalse($evidence['drupal_evidence']['current_user']['admin_capabilities']['can_administer_ai_providers']);
    $this->assertSame('/node/add/article', $evidence['drupal_evidence']['current_route']['path']);
    $this->assertFalse($evidence['drupal_evidence']['checked_path_access'][0]['access_allowed']);
    $this->assertSame('article', $evidence['drupal_evidence']['current_entity']['bundle']);
    $this->assertStringContainsString('administrator', implode(' ', $evidence['next_diagnostic_steps']));
    $this->assertContains('Current user roles and permissions', $evidence['sources']);
  }

  /**
   * Tests the provider is skipped for unrelated domains.
   */
  public function testSkipsUnrelatedDomains(): void {
    $provider = new AccessEvidenceProvider();
    $request = new GuidanceRequest(
      'What fields does an article have?',
      $this->account(['authenticated']),
    );

    $this->assertFalse($provider->applies($request, new GuidanceState([]), ['field_model']));
  }

  /**
   * Builds an account mock.
   *
   * @param string[] $roles
   *   Roles.
   */
  private function account(array $roles): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')
      ->willReturn(!in_array('anonymous', $roles, TRUE));
    $account->method('getRoles')
      ->willReturn($roles);
    return $account;
  }

}
