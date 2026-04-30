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
    $route_parameters = $this->routeMatch->getRawParameters()->all();
    $resolved_from_context = FALSE;
    $requested_path_access = NULL;
    $account = $request->account ?? $this->currentUser;

    if (is_string($context_path) && str_starts_with($context_path, '/')) {
      $match_path = parse_url($context_path, PHP_URL_PATH) ?: $context_path;
      try {
        $parameters = $this->router->match($match_path);
        if (!empty($parameters['_route'])) {
          $candidate_route_name = (string) $parameters['_route'];
          $candidate_route_parameters = array_filter($parameters, static fn($key): bool => is_string($key) && !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
          $candidate_access = $this->accessForPathOrRoute($match_path, $candidate_route_name, $candidate_route_parameters, $account);
          $requested_path_access = [
            'path' => $match_path,
            'route_name' => $candidate_route_name,
            'access_allowed' => $candidate_access,
          ];
          if ($candidate_access) {
            $route_name = $candidate_route_name;
            $route_parameters = $candidate_route_parameters;
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
        'access_allowed' => $route_name ? $this->accessForPathOrRoute($path, $route_name, $route_parameters, $account) : NULL,
      ],
      'request_context' => [
        'source' => $context_path ? 'caller_context' : 'current_request',
        'route_resolved_from_context' => $resolved_from_context,
        'requested_path_access' => $requested_path_access,
      ],
    ] + ($this->pathAccessQuestion($request->question) ? [
      'common_path_access' => $this->commonPathAccess($path, $request->question, $account),
    ] : []);
  }

  /**
   * Checks access to a route for the supplied account.
   */
  private function checkRouteAccess(?string $route_name, array $parameters, AccountInterface $account): ?bool {
    if (!$route_name) {
      return NULL;
    }

    try {
      return (bool) $this->accessManager->checkNamedRoute($route_name, $parameters, $account);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Checks route access, with direct bundle-permission fallback for node add.
   */
  private function accessForPathOrRoute(string $path, ?string $route_name, array $parameters, AccountInterface $account): ?bool {
    if ($route_name === 'node.add' && preg_match('#^/node/add/([^/]+)$#', $path, $matches)) {
      $bundle = $matches[1];
      return $account->hasPermission('administer nodes') || $account->hasPermission("create $bundle content");
    }

    return $this->checkRouteAccess($route_name, $parameters, $account);
  }

  /**
   * Determines whether to include extra path access facts.
   */
  private function pathAccessQuestion(string $question): bool {
    $question = strtolower($question);
    foreach ([
      'admin',
      'ai provider',
      'ai providers',
      'canvas',
      'configure',
      'front page',
      'permission',
      'permissions',
      'what can i do',
      'why can',
    ] as $needle) {
      if (str_contains($question, $needle)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns access facts for common paths used in guidance answers.
   *
   * @return array<int, array<string, mixed>>
   *   Path access summaries.
   */
  private function commonPathAccess(string $current_path, string $question, AccountInterface $account): array {
    $paths = [
      $current_path,
      '/admin/config/ai',
      '/admin/people/permissions',
    ];

    $lower_question = strtolower($question);
    if (str_contains($lower_question, 'front page') || str_contains($lower_question, 'canvas')) {
      $paths[] = '/canvas/editor/canvas_page/1';
    }

    $summaries = [];
    foreach (array_values(array_unique(array_filter($paths))) as $path) {
      $summaries[] = $this->pathAccess($path, $account);
    }
    return array_values(array_filter($summaries));
  }

  /**
   * Returns route and access information for a path.
   */
  private function pathAccess(string $path, AccountInterface $account): array {
    $match_path = parse_url($path, PHP_URL_PATH) ?: $path;
    try {
      $parameters = $this->router->match($match_path);
    }
    catch (ResourceNotFoundException | MethodNotAllowedException) {
      return [
        'path' => $match_path,
        'route_name' => NULL,
        'access_allowed' => FALSE,
        'note' => 'No matching route was found for this path.',
      ];
    }

    $route_name = !empty($parameters['_route']) ? (string) $parameters['_route'] : NULL;
    $route_parameters = array_filter($parameters, static fn($key): bool => is_string($key) && !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    $access = $this->accessForPathOrRoute($match_path, $route_name, $route_parameters, $account);
    return [
      'path' => $match_path,
      'route_name' => $route_name,
      'access_allowed' => $access,
    ];
  }

}
