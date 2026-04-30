<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\Evidence\EcaEvidenceProvider;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Tests ECA evidence collection.
 *
 * @group ai_guidance
 */
final class EcaEvidenceProviderTest extends UnitTestCase {

  /**
   * Tests ECA config evidence is summarized without raw config dumping.
   */
  public function testCollectsEcaEvidence(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('listAll')
      ->with('eca.eca.')
      ->willReturn(['eca.eca.publish_notice']);
    $config_factory->method('get')
      ->with('eca.eca.publish_notice')
      ->willReturn($this->config([
        'id' => 'publish_notice',
        'label' => 'Publish notice',
        'status' => TRUE,
        'events' => [
          'node_update' => [
            'plugin' => 'content_entity:update',
          ],
        ],
        'conditions' => [
          'published' => [
            'plugin' => 'eca_entity_field_value',
          ],
        ],
        'actions' => [
          'send_mail' => [
            'plugin' => 'action_send_email',
            'message' => 'Published [node:title]',
          ],
        ],
      ]));

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')
      ->with('eca')
      ->willReturn(TRUE);

    $provider = new EcaEvidenceProvider($config_factory, $module_handler);
    $request = new GuidanceRequest(
      'What automation runs when this content is published?',
      $this->accountWithPermissions(['administer site configuration']),
    );

    $this->assertTrue($provider->applies($request, new GuidanceState([]), ['automation']));

    $evidence = $provider->collect($request, new GuidanceState([]), ['automation'])->toArray();

    $this->assertSame('drupal.eca', $evidence['provider_id']);
    $this->assertSame(1, $evidence['drupal_evidence']['model_count']);
    $model = $evidence['drupal_evidence']['models'][0];
    $this->assertSame('publish_notice', $model['id']);
    $this->assertContains('node:title', $model['token_names']);
    $this->assertContains('email', $model['mutating_or_outbound_signals']);
    $this->assertContains('ECA model: Publish notice', $evidence['sources']);
    $this->assertArrayNotHasKey('config_name', $model);
  }

  /**
   * Tests accounts without admin access receive limited ECA evidence.
   */
  public function testLimitedEvidenceForNonAdminAccount(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->expects($this->never())->method('listAll');

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')
      ->with('eca')
      ->willReturn(TRUE);

    $provider = new EcaEvidenceProvider($config_factory, $module_handler);
    $request = new GuidanceRequest(
      'What should an outside coding agent know before changing automation?',
      $this->accountWithPermissions([]),
    );

    $evidence = $provider->collect($request, new GuidanceState([]), ['automation'])->toArray();

    $this->assertSame('omitted_for_current_account', $evidence['drupal_evidence']['configuration_details']);
    $this->assertSame([], $evidence['sources']);
    $this->assertStringContainsString('cannot inspect ECA model configuration', implode(' ', $evidence['known_unknowns']));
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
