<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\State;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Session\AccountInterface;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;

/**
 * Provides current route/path state.
 */
final class CurrentRouteStateProvider implements GuidanceStateProviderInterface {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly CurrentPathStack $currentPath,
    private readonly UrlMatcherInterface $router,
    private readonly AccessManagerInterface $accessManager,
    private readonly AccountInterface $currentUser,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getState(GuidanceRequest $request): array {
    $context_path = $request->getContextValue('current_route');
    $path = is_string($context_path) && $context_path !== '' ? $context_path : $this->currentPath->getPath();
    $route_name = $this->routeMatch->getRouteName();
    $resolved_from_context = FALSE;

    if (is_string($context_path) && str_starts_with($context_path, '/')) {
      $match_path = parse_url($context_path, PHP_URL_PATH) ?: $context_path;
      try {
        $parameters = $this->router->match($match_path);
        if (!empty($parameters['_route'])) {
          $candidate_route_name = (string) $parameters['_route'];
          $route_parameters = array_filter($parameters, static fn($key): bool => is_string($key) && !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
          $account = $request->account ?? $this->currentUser;
          if ($this->accessManager->checkNamedRoute($candidate_route_name, $route_parameters, $account)) {
            $route_name = $candidate_route_name;
            $resolved_from_context = TRUE;
          }
        }
      }
      catch (ResourceNotFoundException | MethodNotAllowedException) {
        // Keep the request route if the caller path cannot be resolved.
      }
    }

    return [
      'route' => [
        'name' => $route_name,
        'path' => $path,
      ],
      'request_context' => [
        'source' => $context_path ? 'caller_context' : 'current_request',
        'route_resolved_from_context' => $resolved_from_context,
      ],
    ];
  }

}
