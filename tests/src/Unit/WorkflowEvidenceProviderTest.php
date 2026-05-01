<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\Evidence\WorkflowEvidenceProvider;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Tests workflow evidence collection.
 *
 * @group ai_guidance
 */
final class WorkflowEvidenceProviderTest extends UnitTestCase {

  /**
   * Tests workflow evidence for the current entity bundle.
   */
  public function testCollectsCurrentBundleWorkflowEvidence(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('listAll')
      ->with('workflows.workflow.')
      ->willReturn(['workflows.workflow.editorial']);
    $config_factory->method('get')
      ->with('workflows.workflow.editorial')
      ->willReturn($this->config([
        'id' => 'editorial',
        'label' => 'Basic editorial workflow',
        'type' => 'content_moderation',
        'type_settings' => [
          'entity_types' => [
            'node' => ['article'],
          ],
          'states' => [
            'draft' => [
              'label' => 'Draft',
              'published' => FALSE,
              'default_revision' => TRUE,
            ],
            'published' => [
              'label' => 'Published',
              'published' => TRUE,
              'default_revision' => TRUE,
            ],
          ],
          'transitions' => [
            'publish' => [
              'label' => 'Publish',
              'from' => ['draft'],
              'to' => 'published',
            ],
          ],
        ],
      ]));

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')
      ->willReturnCallback(static fn(string $module): bool => in_array($module, ['workflows', 'content_moderation'], TRUE));

    $provider = new WorkflowEvidenceProvider($config_factory, $module_handler);
    $request = new GuidanceRequest(
      'Who can publish this content?',
      $this->accountWithPermissions(['use editorial transition publish']),
    );
    $state = new GuidanceState([
      'entity' => [
        'type' => 'node',
        'bundle' => 'article',
        'published' => FALSE,
        'moderation_state' => 'draft',
      ],
    ]);

    $this->assertTrue($provider->applies($request, $state, ['workflow']));

    $evidence = $provider->collect($request, $state, ['workflow'])->toArray();

    $this->assertSame('drupal.workflow', $evidence['provider_id']);
    $this->assertSame('workflow', $evidence['domain']);
    $this->assertSame('high', $evidence['confidence']);
    $this->assertSame('Basic editorial workflow', $evidence['drupal_evidence']['current_workflow']['label']);
    $this->assertSame('draft', $evidence['drupal_evidence']['current_workflow']['current_state']);
    $transition = $evidence['drupal_evidence']['current_workflow']['transitions'][0];
    $this->assertTrue($transition['from_current_state']);
    $this->assertTrue($transition['current_user_has_transition_permission']);
    $this->assertTrue($transition['available_to_current_user_from_current_state']);
    $this->assertContains('Workflow: Basic editorial workflow', $evidence['sources']);
  }

  /**
   * Tests missing workflow assignment is called out.
   */
  public function testMissingCurrentBundleWorkflowIsKnownUnknown(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('listAll')
      ->with('workflows.workflow.')
      ->willReturn([]);

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')
      ->willReturnCallback(static fn(string $module): bool => $module === 'workflows');

    $provider = new WorkflowEvidenceProvider($config_factory, $module_handler);
    $request = new GuidanceRequest('Why can I save as draft?', $this->accountWithPermissions([]));
    $state = new GuidanceState([
      'entity' => [
        'type' => 'node',
        'bundle' => 'article',
      ],
    ]);

    $evidence = $provider->collect($request, $state, ['workflow'])->toArray();

    $this->assertSame('low', $evidence['confidence']);
    $this->assertNull($evidence['drupal_evidence']['current_workflow']);
    $this->assertStringContainsString('No workflow configuration', implode(' ', $evidence['known_unknowns']));
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
   * Builds an account mock with specified permissions.
   *
   * @param string[] $permissions
   *   Granted permissions.
   */
  private function accountWithPermissions(array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));
    return $account;
  }

}
