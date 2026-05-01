<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\State\CurrentEntityStateProvider;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;

/**
 * Tests current entity guidance state.
 *
 * @group ai_guidance
 */
final class CurrentEntityStateProviderTest extends UnitTestCase {

  /**
   * Tests current content entity facts used by Lesson 1 evaluation.
   */
  public function testContentEntitySummaryIncludesEvaluationFacts(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('7');

    $entity_type = $this->createMock(EntityTypeInterface::class);
    $entity_type->method('hasKey')
      ->willReturnCallback(static fn(string $key): bool => in_array($key, ['published', 'created', 'changed'], TRUE));
    $entity_type->method('getKey')
      ->willReturnCallback(static fn(string $key): ?string => match ($key) {
        'published' => 'status',
        'created' => 'created',
        'changed' => 'changed',
        default => NULL,
      });

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('id')->willReturn('123');
    $entity->method('label')->willReturn('Lesson 1 test article');
    $entity->method('isNew')->willReturn(FALSE);
    $entity->method('bundle')->willReturn('article');
    $entity->method('language')->willReturn($language);
    $entity->method('getEntityType')->willReturn($entity_type);
    $entity->method('access')
      ->willReturnCallback(static fn(string $operation, $account = NULL, bool $return_as_object = FALSE): bool => match ($operation) {
        'view', 'update' => TRUE,
        'delete' => FALSE,
        default => FALSE,
      });
    $entity->method('hasField')
      ->willReturnCallback(static fn(string $field): bool => in_array($field, ['status', 'moderation_state', 'created', 'changed'], TRUE));
    $entity->method('get')
      ->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
        'status' => $this->fieldList(0),
        'moderation_state' => $this->fieldList('draft'),
        'created' => $this->fieldList(1777500000),
        'changed' => $this->fieldList(1777500300),
        default => $this->emptyFieldList(),
      });

    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameters')
      ->willReturn(new ParameterBag(['node' => $entity]));

    $provider = $this->provider($route_match);
    $state = $provider->getState(new GuidanceRequest('Evaluate my Lesson 1 attempt.', $account));

    $this->assertSame('node', $state['entity']['type']);
    $this->assertSame('123', $state['entity']['id']);
    $this->assertSame('Lesson 1 test article', $state['entity']['label']);
    $this->assertSame('article', $state['entity']['bundle']);
    $this->assertSame('en', $state['entity']['language']);
    $this->assertFalse($state['entity']['is_new']);
    $this->assertFalse($state['entity']['published']);
    $this->assertSame('draft', $state['entity']['moderation_state']);
    $this->assertSame(1777500000, $state['entity']['created_timestamp']);
    $this->assertSame(1777500300, $state['entity']['changed_timestamp']);
    $this->assertTrue($state['entity']['access']['update']);
    $this->assertFalse($state['entity']['access']['delete']);
  }

  /**
   * Tests inaccessible entities are not exposed.
   */
  public function testInaccessibleEntityIsNotIncluded(): void {
    $account = $this->createMock(AccountInterface::class);
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('access')
      ->with('view', $account)
      ->willReturn(FALSE);

    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameters')
      ->willReturn(new ParameterBag(['node' => $entity]));

    $provider = $this->provider($route_match);
    $state = $provider->getState(new GuidanceRequest('Evaluate my Lesson 1 attempt.', $account));

    $this->assertSame('not_included', $state['entity']['access']);
    $this->assertStringContainsString('cannot view', $state['entity']['access_note']);
  }

  /**
   * Tests caller current-route context can resolve the entity being viewed.
   */
  public function testCallerCurrentRouteCanResolveEntity(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('7');

    $entity_type = $this->createMock(EntityTypeInterface::class);
    $entity_type->method('hasKey')->willReturn(FALSE);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('id')->willReturn('123');
    $entity->method('label')->willReturn('Lesson 1 test article');
    $entity->method('isNew')->willReturn(FALSE);
    $entity->method('bundle')->willReturn('article');
    $entity->method('language')->willReturn($language);
    $entity->method('getEntityType')->willReturn($entity_type);
    $entity->method('access')->willReturn(TRUE);
    $entity->method('hasField')->willReturn(FALSE);

    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameters')->willReturn(new ParameterBag());

    $router = $this->createMock(UrlMatcherInterface::class);
    $router->expects($this->once())
      ->method('match')
      ->with('/node/123/edit')
      ->willReturn([
        '_route' => 'entity.node.edit_form',
        'node' => '123',
      ]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with('123')
      ->willReturn($entity);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')
      ->with('node')
      ->willReturn(TRUE);
    $entity_type_manager->method('getStorage')
      ->with('node')
      ->willReturn($storage);

    $provider = $this->provider($route_match, $router, $entity_type_manager);
    $state = $provider->getState(new GuidanceRequest(
      'Evaluate my Lesson 1 attempt.',
      $account,
      ['current_route' => '/node/123/edit?destination=/admin/content'],
    ));

    $this->assertSame('node', $state['entity']['type']);
    $this->assertSame('123', $state['entity']['id']);
    $this->assertSame('Lesson 1 test article', $state['entity']['label']);
    $this->assertSame('article', $state['entity']['bundle']);
  }

  /**
   * Tests bundle route parameters on add forms are not exposed as entities.
   */
  public function testNodeAddBundleParameterIsNotCurrentEntity(): void {
    $account = $this->createMock(AccountInterface::class);
    $node_type = $this->createMock(ContentEntityInterface::class);

    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameters')
      ->willReturn(new ParameterBag([
        '_route' => 'node.add',
        'node_type' => $node_type,
      ]));

    $provider = $this->provider($route_match);
    $state = $provider->getState(new GuidanceRequest('What can I do on this page?', $account));

    $this->assertNull($state['entity']['type']);
    $this->assertNull($state['entity']['id']);
    $this->assertNull($state['entity']['bundle']);
  }

  /**
   * Builds a field item list with one scalar value.
   */
  private function fieldList(mixed $value): FieldItemListInterface {
    $item = $this->createMock(FieldItemInterface::class);
    $item->method('getValue')->willReturn(['value' => $value]);

    $list = $this->createMock(FieldItemListInterface::class);
    $list->method('isEmpty')->willReturn(FALSE);
    $list->method('first')->willReturn($item);
    return $list;
  }

  /**
   * Builds an empty field item list.
   */
  private function emptyFieldList(): FieldItemListInterface {
    $list = $this->createMock(FieldItemListInterface::class);
    $list->method('isEmpty')->willReturn(TRUE);
    $list->method('first')->willReturn(NULL);
    return $list;
  }

  /**
   * Builds a provider with default non-resolving dependencies.
   */
  private function provider(
    RouteMatchInterface $route_match,
    ?UrlMatcherInterface $router = NULL,
    ?EntityTypeManagerInterface $entity_type_manager = NULL,
  ): CurrentEntityStateProvider {
    $current_path = $this->createMock(CurrentPathStack::class);
    $current_path->method('getPath')->willReturn('/chat');

    if ($router === NULL) {
      $router = $this->createMock(UrlMatcherInterface::class);
      $router->method('match')->willThrowException(new ResourceNotFoundException());
    }
    if ($entity_type_manager === NULL) {
      $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
      $entity_type_manager->method('hasDefinition')->willReturn(FALSE);
    }

    return new CurrentEntityStateProvider($route_match, $current_path, $router, $entity_type_manager);
  }

}
