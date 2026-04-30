<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\State;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ai_guidance\Value\GuidanceRequest;

/**
 * Provides access-safe current entity state.
 */
final class CurrentEntityStateProvider implements GuidanceStateProviderInterface {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly AccountInterface $currentUser,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getState(GuidanceRequest $request): array {
    $account = $request->account;
    foreach ($this->routeMatch->getParameters() as $parameter) {
      if (!$parameter instanceof EntityInterface) {
        continue;
      }
      if (!$parameter->access('view', $account)) {
        return [
          'entity' => [
            'access' => 'not_included',
            'access_note' => 'The current entity was not included because the current user cannot view it.',
          ],
        ];
      }

      $summary = [
        'type' => $parameter->getEntityTypeId(),
        'id' => $parameter->id(),
        'label' => $parameter->label(),
        'bundle' => $parameter instanceof ContentEntityInterface ? $parameter->bundle() : NULL,
        'language' => $parameter instanceof ContentEntityInterface ? $parameter->language()->getId() : NULL,
      ];

      if ($parameter instanceof ContentEntityInterface && $parameter->getEntityType()->hasKey('published')) {
        $published_key = $parameter->getEntityType()->getKey('published');
        $summary['published'] = $published_key ? (bool) $parameter->get($published_key)->value : NULL;
      }

      return ['entity' => $summary];
    }

    return [
      'entity' => [
        'type' => NULL,
        'id' => NULL,
        'bundle' => NULL,
      ],
    ];
  }

}
