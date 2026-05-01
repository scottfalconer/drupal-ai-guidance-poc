<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\State;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;

/**
 * Provides access-safe current entity state.
 */
final class CurrentEntityStateProvider implements GuidanceStateProviderInterface {

  /**
   * Logger for sanitized entity diagnostics.
   */
  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly CurrentPathStack $currentPath,
    private readonly UrlMatcherInterface $router,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    ?LoggerInterface $logger = NULL,
  ) {
    $this->logger = $logger ?? new NullLogger();
  }

  /**
   * {@inheritdoc}
   */
  public function getState(GuidanceRequest $request): array {
    $entity = $this->entityFromParameters($this->routeMatch->getParameters()->all());
    if ($entity instanceof EntityInterface) {
      return $this->entityState($entity, $request);
    }

    $path = $this->sanitizeContextPath($request->getContextValue('current_route'))
      ?? $this->currentPath->getPath();
    $entity = $this->entityFromPath($path);
    if ($entity instanceof EntityInterface) {
      return $this->entityState($entity, $request);
    }

    return [
      'entity' => [
        'type' => NULL,
        'id' => NULL,
        'bundle' => NULL,
      ],
    ];
  }

  /**
   * Builds an access-safe state entry for an entity.
   */
  private function entityState(EntityInterface $entity, GuidanceRequest $request): array {
    $account = $request->account;
    if (!$entity->access('view', $account)) {
      return [
        'entity' => [
          'access' => 'not_included',
          'access_note' => 'The current entity was not included because the current user cannot view it.',
        ],
      ];
    }

    $summary = [
      'type' => $entity->getEntityTypeId(),
      'id' => $entity->id(),
      'label' => $entity->label(),
      'is_new' => $entity->isNew(),
      'bundle' => $entity instanceof ContentEntityInterface ? $entity->bundle() : NULL,
      'language' => $entity instanceof ContentEntityInterface ? $entity->language()->getId() : NULL,
      'access' => [
        'view' => TRUE,
        'update' => $entity->access('update', $account),
        'delete' => $entity->access('delete', $account),
      ],
    ];

    if ($entity instanceof EntityOwnerInterface) {
      $summary['owner_is_current_user'] = (string) $entity->getOwnerId() === (string) $account->id();
    }
    if ($entity instanceof ContentEntityInterface && $entity->getEntityType()->hasKey('published')) {
      $published_key = $entity->getEntityType()->getKey('published');
      $summary['published'] = $published_key ? (bool) $this->fieldScalarValue($entity, $published_key) : NULL;
    }
    if ($entity instanceof ContentEntityInterface && $entity->hasField('moderation_state') && !$entity->get('moderation_state')->isEmpty()) {
      $summary['moderation_state'] = (string) $this->fieldScalarValue($entity, 'moderation_state');
    }
    if ($entity instanceof ContentEntityInterface) {
      foreach (['created', 'changed'] as $key) {
        if (!$entity->getEntityType()->hasKey($key)) {
          continue;
        }
        $field_name = $entity->getEntityType()->getKey($key);
        if ($field_name) {
          $summary[$key . '_timestamp'] = $this->fieldScalarValue($entity, $field_name);
        }
      }
    }

    return ['entity' => $summary];
  }

  /**
   * Resolves a current entity from route parameters.
   *
   * @param array<string, mixed> $parameters
   *   Route parameters, possibly from the active route or a caller path match.
   */
  private function entityFromParameters(array $parameters): ?EntityInterface {
    $route_name = isset($parameters['_route']) ? (string) $parameters['_route'] : '';
    if ($route_name === 'node.add' || str_ends_with($route_name, '.add_form')) {
      return NULL;
    }

    foreach ($parameters as $key => $parameter) {
      if (is_string($key) && str_starts_with($key, '_')) {
        continue;
      }
      if (is_string($key) && str_ends_with($key, '_type')) {
        continue;
      }
      if ($parameter instanceof EntityInterface) {
        return $parameter;
      }
    }

    if (!preg_match('/^entity\.([a-z0-9_]+)\./', $route_name, $matches)) {
      return NULL;
    }

    $entity_type_id = $matches[1];
    $id = $parameters[$entity_type_id] ?? NULL;
    if (!is_scalar($id) || $id === '') {
      return NULL;
    }
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return NULL;
    }

    try {
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
    }
    catch (\Throwable $exception) {
      $this->logger->debug('Entity load from route parameter for @entity_type failed with @class.', [
        '@entity_type' => $entity_type_id,
        '@class' => get_debug_type($exception),
      ]);
      return NULL;
    }

    return $entity instanceof EntityInterface ? $entity : NULL;
  }

  /**
   * Resolves a current entity from a local path.
   */
  private function entityFromPath(string $path): ?EntityInterface {
    try {
      return $this->entityFromParameters($this->router->match($path));
    }
    catch (ResourceNotFoundException | MethodNotAllowedException) {
      return NULL;
    }
  }

  /**
   * Returns a safe local path from caller context.
   */
  private function sanitizeContextPath(mixed $path): ?string {
    if (!is_string($path)) {
      return NULL;
    }

    $path = trim($path);
    if ($path === ''
      || !str_starts_with($path, '/')
      || str_starts_with($path, '//')
      || str_contains($path, '\\')
      || preg_match('/[[:cntrl:]]/', $path)
    ) {
      return NULL;
    }

    $parts = parse_url($path);
    if ($parts === FALSE || isset($parts['scheme']) || isset($parts['host'])) {
      return NULL;
    }

    $safe_path = (string) ($parts['path'] ?? '');
    if ($safe_path === '' || !str_starts_with($safe_path, '/')) {
      return NULL;
    }

    return $safe_path;
  }

  /**
   * Returns a scalar field value without exposing raw field item internals.
   */
  private function fieldScalarValue(ContentEntityInterface $entity, string $field_name): mixed {
    if (!$entity->hasField($field_name)) {
      return NULL;
    }
    $items = $entity->get($field_name);
    if ($items->isEmpty()) {
      return NULL;
    }
    $item = $items->first();
    if (!$item) {
      return NULL;
    }
    $value = $item->getValue();
    if (array_key_exists('value', $value)) {
      return $value['value'];
    }
    foreach ($value as $candidate) {
      if (is_scalar($candidate) || $candidate === NULL) {
        return $candidate;
      }
    }
    return NULL;
  }

}
