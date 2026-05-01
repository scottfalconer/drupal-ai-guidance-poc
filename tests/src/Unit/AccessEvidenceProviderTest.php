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
        'parameters' => ['node_type' => 'article'],
        'access_allowed' => TRUE,
      ],
      'request_context' => [
        'visible_page_messages' => [
          [
            'type' => 'status',
            'text' => 'Article Lesson 1 test article has been created.',
          ],
        ],
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
        'label' => 'Lesson 1 test article',
        'is_new' => FALSE,
        'bundle' => 'article',
        'language' => 'en',
        'access' => [
          'view' => TRUE,
          'update' => TRUE,
          'delete' => FALSE,
        ],
        'published' => FALSE,
        'moderation_state' => 'draft',
        'created_timestamp' => 1777500000,
        'changed_timestamp' => 1777500300,
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
    $this->assertSame(['node_type' => 'article'], $evidence['drupal_evidence']['current_route']['parameters']);
    $this->assertSame('article', $evidence['drupal_evidence']['current_entity']['bundle']);
    $this->assertSame('Lesson 1 test article', $evidence['drupal_evidence']['current_entity']['label']);
    $this->assertFalse($evidence['drupal_evidence']['current_entity']['published']);
    $this->assertSame('status', $evidence['drupal_evidence']['visible_page_messages'][0]['type']);
    $this->assertStringContainsString('administrator', implode(' ', $evidence['next_diagnostic_steps']));
    $this->assertContains('Current user roles and permissions', $evidence['sources']);
  }

  /**
   * Tests Lesson 1 evaluation questions avoid unsupported grading.
   */
  public function testLessonEvaluationWithoutEntityRequiresCannotConfirm(): void {
    $provider = new AccessEvidenceProvider();
    $request = new GuidanceRequest(
      'Evaluate my Lesson 1 attempt. Did I complete the task safely?',
      $this->account(['authenticated', 'content_editor']),
    );
    $state = new GuidanceState([
      'user' => [
        'roles' => ['authenticated', 'content_editor'],
      ],
      'route' => [
        'name' => 'system.admin_content',
        'path' => '/admin/content',
        'access_allowed' => TRUE,
      ],
      'entity' => [
        'type' => NULL,
        'id' => NULL,
        'bundle' => NULL,
      ],
    ]);

    $evidence = $provider->collect($request, $state, ['access', 'workflow'])->toArray();

    $this->assertStringContainsString('cannot be confirmed', implode(' ', $evidence['known_unknowns']));
    $this->assertStringContainsString('Cannot confirm', implode(' ', $evidence['next_diagnostic_steps']));
    $this->assertStringContainsString('/admin/content', implode(' ', $evidence['next_diagnostic_steps']));
  }

  /**
   * Tests Lesson 1 evaluation separates core completion from full verification.
   */
  public function testLessonEvaluationWithDraftArticleIsCoreTaskComplete(): void {
    $provider = new AccessEvidenceProvider();
    $request = new GuidanceRequest(
      'Evaluate my Lesson 1 attempt. Did I complete the task safely?',
      $this->account(['authenticated', 'content_editor']),
    );
    $state = new GuidanceState([
      'user' => [
        'roles' => ['authenticated', 'content_editor'],
      ],
      'route' => [
        'name' => 'entity.node.edit_form',
        'path' => '/node/28/edit',
        'access_allowed' => TRUE,
      ],
      'entity' => [
        'type' => 'node',
        'id' => '28',
        'label' => 'Lesson 1 test article',
        'is_new' => FALSE,
        'bundle' => 'article',
        'access' => [
          'view' => TRUE,
          'update' => TRUE,
          'delete' => TRUE,
        ],
        'published' => FALSE,
        'moderation_state' => 'draft',
      ],
    ]);

    $evidence = $provider->collect($request, $state, ['access', 'workflow'])->toArray();
    $lesson = $evidence['drupal_evidence']['lesson_1_evaluation'];

    $this->assertSame('core_task_complete', $lesson['result']);
    $this->assertSame('Core task complete', $lesson['result_label']);
    $this->assertContains('Current content item is draft or unpublished.', $lesson['confirmed_evidence']);
    $this->assertStringContainsString('/admin/content', implode(' ', $lesson['missing_evidence']));
    $this->assertStringContainsString('Core task complete', implode(' ', $evidence['next_diagnostic_steps']));
  }

  /**
   * Tests visible warnings keep Lesson 1 in a partial state.
   */
  public function testLessonEvaluationWithVisibleWarningIsPartial(): void {
    $provider = new AccessEvidenceProvider();
    $request = new GuidanceRequest(
      'Evaluate my Lesson 1 attempt. Did I complete the task safely?',
      $this->account(['authenticated', 'content_editor']),
    );
    $state = new GuidanceState([
      'request_context' => [
        'visible_page_messages' => [
          [
            'type' => 'warning',
            'text' => 'There is a visible warning.',
          ],
        ],
      ],
      'route' => [
        'name' => 'entity.node.edit_form',
        'path' => '/node/28/edit',
        'access_allowed' => TRUE,
      ],
      'entity' => [
        'type' => 'node',
        'id' => '28',
        'is_new' => FALSE,
        'bundle' => 'article',
        'published' => FALSE,
      ],
    ]);

    $evidence = $provider->collect($request, $state, ['access', 'workflow'])->toArray();
    $lesson = $evidence['drupal_evidence']['lesson_1_evaluation'];

    $this->assertSame('partially_complete', $lesson['result']);
    $this->assertSame('Partially complete', $lesson['result_label']);
    $this->assertStringContainsString('warning', implode(' ', $evidence['known_unknowns']));
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
