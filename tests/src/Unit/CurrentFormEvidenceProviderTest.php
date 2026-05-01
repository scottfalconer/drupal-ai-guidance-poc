<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\Evidence\CurrentFormEvidenceProvider;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Tests current form evidence collection.
 *
 * @group ai_guidance
 */
final class CurrentFormEvidenceProviderTest extends UnitTestCase {

  /**
   * Tests entity form display and browser-visible form evidence.
   */
  public function testCollectsCurrentEntityFormEvidence(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('core.entity_form_display.node.article.default')
      ->willReturn($this->config([
        'id' => 'node.article.default',
        'mode' => 'default',
        'status' => TRUE,
        'content' => [
          'title' => ['type' => 'string_textfield', 'weight' => 0],
          'body' => ['type' => 'text_textarea_with_summary', 'weight' => 1],
          'moderation_state' => ['type' => 'moderation_state_default', 'weight' => 90],
        ],
      ]));

    $entity_field_manager = $this->createMock(EntityFieldManagerInterface::class);
    $entity_field_manager->method('getFieldDefinitions')
      ->with('node', 'article')
      ->willReturn([
        'title' => $this->fieldDefinition('Title', 'string', TRUE, 1),
        'body' => $this->fieldDefinition('Body', 'text_with_summary', FALSE, 1),
        'moderation_state' => $this->fieldDefinition('Moderation state', 'string', FALSE, 1),
      ]);

    $provider = new CurrentFormEvidenceProvider($config_factory, $entity_field_manager);
    $request = new GuidanceRequest(
      'What can I do on this page?',
      $this->account(),
    );
    $state = new GuidanceState([
      'route' => [
        'name' => 'node.add',
        'path' => '/node/add/article',
        'parameters' => ['node_type' => 'article'],
        'access_allowed' => TRUE,
      ],
      'request_context' => [
        'current_form' => [
          'form_id' => 'node_article_form',
          'action' => '/node/add/article',
          'method' => 'post',
          'fields' => [
            [
              'name' => 'title[0][value]',
              'label' => 'Title',
              'type' => 'text',
              'required' => TRUE,
            ],
            [
              'name' => 'body[0][value]',
              'label' => 'Body',
              'type' => 'textarea',
              'required' => FALSE,
            ],
          ],
          'submit_buttons' => ['Save', 'Preview'],
        ],
      ],
    ]);

    $this->assertTrue($provider->applies($request, $state, ['access']));

    $evidence = $provider->collect($request, $state, ['access'])->toArray();

    $this->assertSame('drupal.current_form', $evidence['provider_id']);
    $this->assertSame('access', $evidence['domain']);
    $this->assertSame('high', $evidence['confidence']);
    $this->assertSame('create', $evidence['drupal_evidence']['entity_form_target']['operation']);
    $this->assertSame('node.article.default', $evidence['drupal_evidence']['form_display']['id']);
    $this->assertArrayNotHasKey('visible_components', $evidence['drupal_evidence']['form_display']);
    $this->assertSame('Title', $evidence['drupal_evidence']['required_fields'][0]['label']);
    $this->assertCount(3, $evidence['drupal_evidence']['visible_field_summaries']);
    $this->assertSame(['Save', 'Preview'], $evidence['drupal_evidence']['browser_visible_form']['submit_buttons']);
    $this->assertContains('Visible browser form', $evidence['sources']);
    $this->assertContains('Field definitions: node.article', $evidence['sources']);
  }

  /**
   * Tests visible browser form evidence can stand alone.
   */
  public function testBrowserFormOnlyWhenEntityTargetIsMissing(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->expects($this->never())->method('get');

    $entity_field_manager = $this->createMock(EntityFieldManagerInterface::class);
    $entity_field_manager->expects($this->never())->method('getFieldDefinitions');

    $provider = new CurrentFormEvidenceProvider($config_factory, $entity_field_manager);
    $request = new GuidanceRequest('What happens when I submit this form?', $this->account());
    $state = new GuidanceState([
      'request_context' => [
        'current_form' => [
          'form_id' => 'search_form',
          'fields' => [
            ['name' => 'keys', 'label' => 'Search', 'type' => 'search', 'required' => FALSE],
          ],
        ],
      ],
    ]);

    $evidence = $provider->collect($request, $state, ['form_submission'])->toArray();

    $this->assertSame('low', $evidence['confidence']);
    $this->assertSame('search_form', $evidence['drupal_evidence']['browser_visible_form']['form_id']);
    $this->assertStringContainsString('could not be resolved', implode(' ', $evidence['known_unknowns']));
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
   * Builds a field definition mock.
   */
  private function fieldDefinition(string $label, string $type, bool $required, int $cardinality): FieldDefinitionInterface {
    $storage = $this->createMock(FieldStorageDefinitionInterface::class);
    $storage->method('getCardinality')->willReturn($cardinality);

    $definition = $this->createMock(FieldDefinitionInterface::class);
    $definition->method('getLabel')->willReturn($label);
    $definition->method('getDescription')->willReturn('');
    $definition->method('getType')->willReturn($type);
    $definition->method('isRequired')->willReturn($required);
    $definition->method('getFieldStorageDefinition')->willReturn($storage);
    $definition->method('getSetting')->willReturn(NULL);
    $definition->method('isTranslatable')->willReturn(FALSE);
    return $definition;
  }

  /**
   * Builds an account mock.
   */
  private function account(): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    return $account;
  }

}
