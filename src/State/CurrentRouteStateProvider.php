<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\State;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Session\AccountInterface;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;

/**
 * Provides current route/path state.
 */
final class CurrentRouteStateProvider implements GuidanceStateProviderInterface {

  /**
   * Logger for sanitized route diagnostics.
   */
  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly CurrentPathStack $currentPath,
    private readonly UrlMatcherInterface $router,
    private readonly AccessManagerInterface $accessManager,
    private readonly AccountInterface $currentUser,
    ?LoggerInterface $logger = NULL,
  ) {
    $this->logger = $logger ?? new NullLogger();
  }

  /**
   * {@inheritdoc}
   */
  public function getState(GuidanceRequest $request): array {
    $context_path = $request->getContextValue('current_route');
    $safe_context_path = $this->sanitizeContextPath($context_path);
    $path = $safe_context_path ?? $this->currentPath->getPath();
    $route_name = $this->routeMatch->getRouteName();
    $route_parameters = $this->routeMatch->getRawParameters()->all();
    $resolved_from_context = FALSE;
    $requested_path_access = NULL;
    $account = $request->account ?? $this->currentUser;

    if ($safe_context_path !== NULL) {
      $match_path = $safe_context_path;
      try {
        $parameters = $this->router->match($match_path);
        if (!empty($parameters['_route'])) {
          $candidate_route_name = (string) $parameters['_route'];
          $candidate_route_parameters = $this->accessRouteParameters(array_filter($parameters, static fn($key): bool => is_string($key) && !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY));
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
        'parameters' => $this->safeRouteParameters($route_parameters),
        'access_allowed' => $route_name ? $this->accessForPathOrRoute($path, $route_name, $this->accessRouteParameters($route_parameters), $account) : NULL,
      ],
      'request_context' => [
        'source' => $safe_context_path !== NULL ? 'caller_context' : 'current_request',
        'route_resolved_from_context' => $resolved_from_context,
        'requested_path_access' => $requested_path_access,
        'visible_page_messages' => $this->visiblePageMessages($request),
        'current_form' => $this->currentForm($request),
      ],
    ] + ($this->pathAccessQuestion($request->question) ? [
      'common_path_access' => $this->commonPathAccess($path, $request->question, $account),
    ] : []);
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
   * Checks access to a route for the supplied account.
   */
  private function checkRouteAccess(?string $route_name, array $parameters, AccountInterface $account): ?bool {
    if (!$route_name) {
      return NULL;
    }

    try {
      return (bool) $this->accessManager->checkNamedRoute($route_name, $parameters, $account);
    }
    catch (\Throwable $exception) {
      $this->logger->debug('Route access check for @route failed with @class.', [
        '@route' => $route_name,
        '@class' => get_debug_type($exception),
      ]);
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
      'lesson',
      'evaluate',
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
   * Returns visible Drupal status/warning/error messages from caller context.
   *
   * @return array<int, array{type:string,text:string}>
   *   Visible page messages.
   */
  private function visiblePageMessages(GuidanceRequest $request): array {
    $messages = $request->getContextValue('visible_page_messages', []);
    if (!is_array($messages)) {
      return [];
    }

    $safe = [];
    foreach ($messages as $message) {
      if (!is_array($message)) {
        continue;
      }
      $text = trim((string) ($message['text'] ?? ''));
      if ($text === '' || $this->shouldSkipVisiblePageMessage($text)) {
        continue;
      }
      $type = strtolower((string) ($message['type'] ?? 'status'));
      if (!in_array($type, ['status', 'warning', 'error'], TRUE)) {
        $type = 'status';
      }
      $safe[] = [
        'type' => $type,
        'text' => mb_substr($text, 0, 500),
      ];
      if (count($safe) >= 8) {
        break;
      }
    }
    return $safe;
  }

  /**
   * Returns browser-visible form summary from caller context.
   *
   * @return array<string, mixed>
   *   Safe form summary.
   */
  private function currentForm(GuidanceRequest $request): array {
    $form = $request->getContextValue('current_form', []);
    if (!is_array($form)) {
      return [];
    }

    $safe = [];
    foreach (['form_id', 'action', 'method'] as $key) {
      if (!isset($form[$key]) || !is_scalar($form[$key])) {
        continue;
      }
      $value = trim((string) $form[$key]);
      if ($value === '') {
        continue;
      }
      if ($key === 'action') {
        $parts = parse_url($value);
        if ($parts === FALSE || isset($parts['scheme']) || isset($parts['host'])) {
          continue;
        }
        $value = (string) ($parts['path'] ?? '');
        if ($value === '' || !str_starts_with($value, '/')) {
          continue;
        }
      }
      $safe[$key] = mb_substr($value, 0, 200);
    }

    $fields = [];
    foreach ((array) ($form['fields'] ?? []) as $field) {
      if (!is_array($field)) {
        continue;
      }
      $name = trim((string) ($field['name'] ?? ''));
      $label = trim((string) ($field['label'] ?? ''));
      if ($name === '' && $label === '') {
        continue;
      }
      $fields[] = [
        'name' => mb_substr($name, 0, 120),
        'label' => mb_substr($label, 0, 160),
        'type' => mb_substr(trim((string) ($field['type'] ?? '')), 0, 40),
        'required' => !empty($field['required']),
      ] + $this->safeVisibleFieldValue($field, $name, (string) ($field['type'] ?? ''));
      if (count($fields) >= 24) {
        break;
      }
    }
    if ($fields !== []) {
      $safe['fields'] = $fields;
    }

    $buttons = [];
    foreach ((array) ($form['submit_buttons'] ?? []) as $button) {
      if (!is_scalar($button)) {
        continue;
      }
      $label = trim((string) $button);
      if ($label === '' || !$this->isUsefulFormButtonLabel($label)) {
        continue;
      }
      $buttons[] = mb_substr($label, 0, 120);
      if (count($buttons) >= 8) {
        break;
      }
    }
    if ($buttons !== []) {
      $safe['submit_buttons'] = $buttons;
    }

    return $safe;
  }

  /**
   * Returns a visible field value when it is safe to include in form state.
   *
   * @param array<string, mixed> $field
   *   Caller-provided field summary.
   * @param string $name
   *   Field input name.
   * @param string $type
   *   Field input type.
   *
   * @return array{value?: string}
   *   Safe value summary.
   */
  private function safeVisibleFieldValue(array $field, string $name, string $type): array {
    $value = trim((string) ($field['value'] ?? ''));
    if ($value === '') {
      return [];
    }

    $sensitive_name = strtolower($name);
    $type = strtolower($type);
    foreach (['password', 'hidden', 'file'] as $blocked_type) {
      if ($type === $blocked_type) {
        return [];
      }
    }
    foreach (['token', 'pass', 'secret', 'key', 'mail'] as $needle) {
      if (str_contains($sensitive_name, $needle)) {
        return [];
      }
    }

    return ['value' => mb_substr($value, 0, 700)];
  }

  /**
   * Filters browser page messages that are not useful guidance evidence.
   */
  private function shouldSkipVisiblePageMessage(string $text): bool {
    $text = strtolower($text);
    return str_contains($text, 'one-time login link')
      || str_contains($text, 'set your new password now');
  }

  /**
   * Filters editor chrome/tooling buttons from the user-facing form summary.
   */
  private function isUsefulFormButtonLabel(string $label): bool {
    $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $label) ?? $label));
    if ($normalized === '') {
      return FALSE;
    }

    $blocked_exact = [
      'autosave save',
      'paragraph',
      'show more items',
      'update widget',
    ];
    if (in_array($normalized, $blocked_exact, TRUE)) {
      return FALSE;
    }

    foreach (['toggle ', 'close ', 'hide ', 'moves focus'] as $blocked_prefix) {
      if (str_starts_with($normalized, $blocked_prefix)) {
        return FALSE;
      }
    }

    return TRUE;
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
    $route_parameters = $this->accessRouteParameters(array_filter($parameters, static fn($key): bool => is_string($key) && !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY));
    $access = $this->accessForPathOrRoute($match_path, $route_name, $route_parameters, $account);
    return [
      'path' => $match_path,
      'route_name' => $route_name,
      'access_allowed' => $access,
    ];
  }

  /**
   * Returns prompt-safe route parameters.
   *
   * @param array<string, mixed> $parameters
   *   Route parameters.
   *
   * @return array<string, mixed>
   *   Scalar or entity-identifying route parameters.
   */
  private function safeRouteParameters(array $parameters): array {
    $safe = [];
    foreach ($parameters as $key => $value) {
      if (!is_string($key) || str_starts_with($key, '_')) {
        continue;
      }
      if (is_scalar($value) || $value === NULL) {
        $safe[$key] = $value;
      }
      elseif (is_object($value) && method_exists($value, 'getEntityTypeId') && method_exists($value, 'id')) {
        $safe[$key] = [
          'entity_type' => $value->getEntityTypeId(),
          'id' => $value->id(),
          'label' => method_exists($value, 'label') ? $value->label() : NULL,
        ];
      }
    }
    return $safe;
  }

  /**
   * Returns scalar route parameters suitable for access checks.
   *
   * Symfony route matching can include converted entity objects. Passing those
   * objects back through checkNamedRoute() asks Drupal to convert them again and
   * can trigger warnings in entity storage. Route access checks only need the
   * raw identifiers here.
   *
   * @param array<string, mixed> $parameters
   *   Route parameters from the current request or router match.
   *
   * @return array<string, mixed>
   *   Scalar route parameters.
   */
  private function accessRouteParameters(array $parameters): array {
    $safe = [];
    foreach ($parameters as $key => $value) {
      if (!is_string($key) || str_starts_with($key, '_')) {
        continue;
      }
      if (is_scalar($value) || $value === NULL) {
        $safe[$key] = $value;
      }
      elseif (is_object($value) && method_exists($value, 'id')) {
        $safe[$key] = $value->id();
      }
      elseif (is_array($value) && is_scalar($value['id'] ?? NULL)) {
        $safe[$key] = $value['id'];
      }
    }
    return $safe;
  }

}
