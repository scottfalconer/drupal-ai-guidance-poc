<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\State;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\user\PermissionHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Provides access-safe current user state.
 */
final class CurrentUserStateProvider implements GuidanceStateProviderInterface {

  /**
   * Baseline permissions always worth checking before registry filtering.
   *
   * The registry still drives the broader relevant-permission catalog below:
   * restricted permissions and permissions whose title/provider/description
   * match the current guidance domains are included without maintaining a
   * complete hard-coded allowlist here.
   */
  private const BASELINE_CAPABILITY_PERMISSIONS = [
    'administer ai',
    'administer ai providers',
    'administer ai_assistant',
    'administer ai guidance',
    'access administration pages',
    'administer site configuration',
    'administer permissions',
    'administer nodes',
    'view own unpublished content',
  ];

  /**
   * Logger for sanitized permission diagnostics.
   */
  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?PermissionHandlerInterface $permissionHandler = NULL,
    ?LoggerInterface $logger = NULL,
  ) {
    $this->logger = $logger ?? new NullLogger();
  }

  /**
   * {@inheritdoc}
   */
  public function getState(GuidanceRequest $request): array {
    $account = $request->account ?? $this->currentUser;
    $relevant_permission_catalog = $this->relevantPermissionCatalog();
    $permissions = [];
    foreach (array_keys($relevant_permission_catalog) as $permission) {
      if ($account->hasPermission($permission)) {
        $permissions[] = $permission;
      }
    }

    $user = [
      'is_authenticated' => $account->isAuthenticated(),
      'roles' => $account->getRoles(),
      'relevant_permissions' => $permissions,
      'relevant_permission_catalog' => $this->permissionCatalogForGrantedPermissions($permissions, $relevant_permission_catalog),
      'can_access_administration_pages' => $account->hasPermission('access administration pages'),
      'can_administer_ai' => $account->hasPermission('administer ai'),
      'can_administer_ai_providers' => $account->hasPermission('administer ai providers'),
      'can_administer_assistants' => $account->hasPermission('administer ai_assistant'),
      'can_administer_permissions' => $account->hasPermission('administer permissions'),
      'can_administer_site_configuration' => $account->hasPermission('administer site configuration'),
      'content_type_permissions' => $this->contentTypePermissions($account),
    ];

    if ($this->roleGuidanceQuestion($request->question)) {
      $user['current_role_guidance'] = $this->currentRoleGuidance($account, $user);
    }

    if ($this->roleComparisonQuestion($request->question)) {
      if ($this->canInspectRoleMatrix($account)) {
        $user['role_capability_summary'] = $this->roleCapabilitySummary();
      }
      else {
        $user['role_capability_note'] = 'Current account cannot inspect the full role permission matrix; answer from current user state and ask an administrator to review /admin/people/roles when cross-role comparison is needed.';
      }
    }

    return [
      'user' => $user,
    ];
  }

  /**
   * Returns a compact catalog of relevant, registry-derived permissions.
   *
   * @return array<string, array<string, mixed>>
   *   Permission metadata keyed by permission ID.
   */
  private function relevantPermissionCatalog(): array {
    $catalog = [];
    foreach (self::BASELINE_CAPABILITY_PERMISSIONS as $permission) {
      $catalog[$permission] = [
        'title' => $permission,
        'restrict_access' => $this->isKnownRestrictedPermission($permission),
      ];
    }

    if ($this->permissionHandler === NULL) {
      return $catalog;
    }

    try {
      $permissions = $this->permissionHandler->getPermissions();
    }
    catch (\Throwable $exception) {
      $this->logger->debug('Permission registry lookup failed with @class.', [
        '@class' => get_debug_type($exception),
      ]);
      return $catalog;
    }

    foreach ($permissions as $permission => $definition) {
      $permission = (string) $permission;
      if (!$this->permissionLooksRelevant($permission, (array) $definition)) {
        continue;
      }
      $catalog[$permission] = [
        'title' => $this->permissionDefinitionText($definition['title'] ?? $permission),
        'description' => $this->permissionDefinitionText($definition['description'] ?? ''),
        'provider' => $this->permissionDefinitionText($definition['provider'] ?? ''),
        'restrict_access' => !empty($definition['restrict access']),
      ];
    }

    ksort($catalog, SORT_STRING);
    return $catalog;
  }

  /**
   * Checks whether a permission definition is relevant to guidance answers.
   *
   * @param string $permission
   *   Permission machine name.
   * @param array<string, mixed> $definition
   *   Permission definition.
   */
  private function permissionLooksRelevant(string $permission, array $definition): bool {
    if (in_array($permission, self::BASELINE_CAPABILITY_PERMISSIONS, TRUE)) {
      return TRUE;
    }
    if (!empty($definition['restrict access'])) {
      return TRUE;
    }

    $haystack = strtolower($permission . ' '
      . $this->permissionDefinitionText($definition['title'] ?? '') . ' '
      . $this->permissionDefinitionText($definition['description'] ?? '') . ' '
      . $this->permissionDefinitionText($definition['provider'] ?? ''));
    foreach ([
      'administer',
      'ai',
      'assistant',
      'configuration',
      'permission',
      'provider',
      'publish',
      'transition',
      'workflow',
    ] as $term) {
      if (str_contains($haystack, $term)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Converts permission definition metadata into prompt-safe plain text.
   */
  private function permissionDefinitionText(mixed $value): string {
    if (is_scalar($value) || $value === NULL) {
      return (string) $value;
    }
    if (is_object($value) && method_exists($value, '__toString')) {
      return (string) $value;
    }
    if (is_array($value)) {
      $parts = [];
      foreach ($value as $item) {
        $text = $this->permissionDefinitionText($item);
        if ($text !== '') {
          $parts[] = $text;
        }
      }
      return implode(' ', $parts);
    }
    return '';
  }

  /**
   * Returns catalog metadata only for permissions granted to the current user.
   *
   * @param string[] $permissions
   *   Granted relevant permissions.
   * @param array<string, array<string, mixed>> $catalog
   *   Relevant permission catalog.
   *
   * @return array<string, array<string, mixed>>
   *   Granted permission metadata.
   */
  private function permissionCatalogForGrantedPermissions(array $permissions, array $catalog): array {
    $granted = [];
    foreach ($permissions as $permission) {
      if (isset($catalog[$permission])) {
        $granted[$permission] = $catalog[$permission];
      }
    }
    return $granted;
  }

  /**
   * Marks known high-risk permissions as restricted.
   *
   * @param string $permission
   *   Permission machine name.
   */
  private function isKnownRestrictedPermission(string $permission): bool {
    return str_starts_with($permission, 'administer ');
  }

  /**
   * Builds a compact content capability summary for the current account.
   *
   * @return array<int, array{type: string, label: string, allowed_actions: string[]}>
   *   Content type capabilities.
   */
  private function contentTypePermissions(AccountInterface $account): array {
    return $this->contentTypePermissionsFromCheck(static fn(string $permission): bool => $account->hasPermission($permission));
  }

  /**
   * Builds content capabilities from a permission checker.
   *
   * @param callable(string): bool $has_permission
   *   Permission callback.
   *
   * @return array<int, array{type: string, label: string, allowed_actions: string[]}>
   *   Content type capabilities.
   */
  private function contentTypePermissionsFromCheck(callable $has_permission): array {
    $items = [];
    foreach ($this->configFactory->listAll('node.type.') as $name) {
      $data = $this->configFactory->get($name)->getRawData();
      $id = (string) ($data['type'] ?? substr($name, strlen('node.type.')));
      if ($id === '') {
        continue;
      }

      $actions = [];
      if ($has_permission('administer nodes')) {
        $actions[] = 'administer content';
      }
      foreach ([
        'create' => "create $id content",
        'edit own' => "edit own $id content",
        'edit any' => "edit any $id content",
        'delete own' => "delete own $id content",
        'delete any' => "delete any $id content",
      ] as $label => $permission) {
        if ($has_permission($permission)) {
          $actions[] = $label;
        }
      }

      if ($actions === []) {
        continue;
      }

      $items[] = [
        'type' => $id,
        'label' => (string) ($data['name'] ?? $id),
        'allowed_actions' => $actions,
      ];
    }

    usort($items, static fn(array $a, array $b): int => strcmp($a['type'], $b['type']));
    return array_slice($items, 0, 12);
  }

  /**
   * Determines whether the question asks for role comparison.
   */
  private function roleComparisonQuestion(string $question): bool {
    $question = strtolower($question);
    foreach ([
      'compare what',
      'compare roles',
      'what changes if',
      'anonymous',
      'content editor',
      'administrator',
      'why can',
      'why does this user',
      'what can the',
    ] as $needle) {
      if (str_contains($question, $needle)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Determines whether the question needs role-first guidance.
   */
  private function roleGuidanceQuestion(string $question): bool {
    $question = strtolower($question);
    foreach ([
      'ai provider',
      'ai providers',
      'canvas',
      'configure',
      'configuration',
      'draft',
      'front page',
      'permission',
      'permissions',
      'publish',
      'role',
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
   * Builds role-first guidance for the current account.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current account.
   * @param array<string, mixed> $user
   *   Safe current-user state.
   *
   * @return array<string, mixed>
   *   Role guidance.
   */
  private function currentRoleGuidance(AccountInterface $account, array $user): array {
    $can = [];
    foreach ((array) ($user['content_type_permissions'] ?? []) as $content_type) {
      $actions = (array) ($content_type['allowed_actions'] ?? []);
      if (array_intersect($actions, ['create', 'edit own', 'edit any']) !== []) {
        $can[] = 'Create or edit `' . $content_type['type']
          . '` content according to the listed content permissions.';
      }
    }
    if ($account->hasPermission('access administration pages')) {
      $can[] = 'Access administration pages that this role is allowed to use.';
    }
    if ($account->hasPermission('view own unpublished content')) {
      $can[] = 'View own unpublished content.';
    }
    if (!empty($user['can_administer_ai_providers'])) {
      $can[] = 'Configure AI providers and provider credentials because this role has `administer ai providers`.';
    }
    if (!empty($user['can_administer_ai'])) {
      $can[] = 'Administer global AI settings because this role has `administer ai`.';
    }
    if (!empty($user['can_administer_assistants'])) {
      $can[] = 'Administer AI Assistant configuration because this role has `administer ai_assistant`.';
    }
    if (!empty($user['can_administer_permissions'])) {
      $can[] = 'Change role permissions because this role has `administer permissions`.';
    }
    if (!empty($user['can_administer_site_configuration'])) {
      $can[] = 'Change site-wide configuration because this role has `administer site configuration`.';
    }

    $cannot = [];
    $admin_requests = [];
    $avoid_granting = [];
    if (empty($user['can_administer_ai_providers'])) {
      $cannot[] = 'Configure AI providers or provider credentials; this requires `administer ai providers`.';
      $admin_requests[] = 'Ask an administrator to configure AI providers and models before enabling editor-facing AI workflows.';
      $avoid_granting[] = '`administer ai providers` exposes provider setup and should stay administrator-only for editor rollouts.';
    }
    if (empty($user['can_administer_ai'])) {
      $cannot[] = 'Administer global AI settings; this requires `administer ai`.';
      $avoid_granting[] = '`administer ai` should stay administrator-only unless the role owns site-wide AI configuration.';
    }
    if (empty($user['can_administer_assistants'])) {
      $cannot[] = 'Administer AI Assistant configuration; this requires `administer ai_assistant`.';
      $avoid_granting[] = '`administer ai_assistant` controls assistant behavior and should not be granted just to use AI help.';
    }
    if (empty($user['can_administer_permissions'])) {
      $cannot[] = 'Change role permissions; this requires `administer permissions`.';
      $admin_requests[] = 'Ask an administrator to review role permissions at `/admin/people/permissions`.';
      $avoid_granting[] = '`administer permissions` allows changing every role and should remain administrator-only.';
    }
    if (empty($user['can_administer_site_configuration'])) {
      $cannot[] = 'Change site-wide configuration; this requires `administer site configuration`.';
    }

    return [
      'answer_order' => [
        'what the current role can do',
        'what the current role cannot do',
        'who should handle blocked tasks',
        'what to ask an administrator or site builder to do',
      ],
      'current_user_can' => array_values(array_unique($can)),
      'current_user_cannot' => array_values(array_unique($cannot)),
      'what_to_ask_admin' => array_values(array_unique($admin_requests)),
      'permissions_to_avoid_granting_for_editor_rollout' => array_values(array_unique($avoid_granting)),
    ];
  }

  /**
   * Checks whether the current account may inspect the role matrix.
   */
  private function canInspectRoleMatrix(AccountInterface $account): bool {
    return $account->hasPermission('administer permissions');
  }

  /**
   * Builds a compact role capability summary for role-comparison answers.
   *
   * @return array<int, array<string, mixed>>
   *   Role capability summaries.
   */
  private function roleCapabilitySummary(): array {
    $summaries = [];
    $permission_catalog = $this->relevantPermissionCatalog();
    foreach ($this->configFactory->listAll('user.role.') as $name) {
      $data = $this->configFactory->get($name)->getRawData();
      $id = (string) ($data['id'] ?? substr($name, strlen('user.role.')));
      if ($id === '') {
        continue;
      }

      $permissions = array_values(array_map('strval', (array) ($data['permissions'] ?? [])));
      $restricted_permissions = array_values(array_filter($permissions, static fn(string $permission): bool => !empty($permission_catalog[$permission]['restrict_access'])));
      $is_admin = !empty($data['is_admin']);
      $has_permission = static fn(string $permission): bool => $is_admin || in_array($permission, $permissions, TRUE);
      $workflow_transitions = array_values(array_filter($permissions, static fn(string $permission): bool => str_starts_with($permission, 'use ') && str_contains($permission, ' transition ')));
      $text_formats = array_values(array_filter($permissions, static fn(string $permission): bool => str_starts_with($permission, 'use text format ')));

      $summaries[] = [
        'role' => $id,
        'label' => (string) ($data['label'] ?? $id),
        'is_admin_role' => $is_admin,
        'admin_capabilities' => [
          'can_access_administration_pages' => $has_permission('access administration pages'),
          'can_administer_ai' => $has_permission('administer ai'),
          'can_administer_ai_providers' => $has_permission('administer ai providers'),
          'can_administer_assistants' => $has_permission('administer ai_assistant'),
          'can_administer_permissions' => $has_permission('administer permissions'),
          'can_administer_site_configuration' => $has_permission('administer site configuration'),
        ],
        'content_type_permissions' => $this->contentTypePermissionsFromCheck($has_permission),
        'workflow_transitions' => array_slice($workflow_transitions, 0, 12),
        'text_formats' => array_slice($text_formats, 0, 8),
        'restricted_permissions' => array_slice($restricted_permissions, 0, 12),
      ];
    }

    usort($summaries, static fn(array $a, array $b): int => strcmp((string) $a['role'], (string) $b['role']));
    return $summaries;
  }

}
