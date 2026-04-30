<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\State;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ai_guidance\Value\GuidanceRequest;

/**
 * Provides access-safe current user state.
 */
final class CurrentUserStateProvider implements GuidanceStateProviderInterface {

  /**
   * Permission names relevant to AI guidance.
   */
  private const RELEVANT_PERMISSIONS = [
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

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getState(GuidanceRequest $request): array {
    $account = $request->account ?? $this->currentUser;
    $permissions = [];
    foreach (self::RELEVANT_PERMISSIONS as $permission) {
      if ($account->hasPermission($permission)) {
        $permissions[] = $permission;
      }
    }

    $user = [
      'is_authenticated' => $account->isAuthenticated(),
      'roles' => $account->getRoles(),
      'relevant_permissions' => $permissions,
      'can_access_administration_pages' => $account->hasPermission('access administration pages'),
      'can_administer_ai' => $account->hasPermission('administer ai'),
      'can_administer_ai_providers' => $account->hasPermission('administer ai providers'),
      'can_administer_assistants' => $account->hasPermission('administer ai_assistant'),
      'can_administer_permissions' => $account->hasPermission('administer permissions'),
      'can_administer_site_configuration' => $account->hasPermission('administer site configuration'),
      'content_type_permissions' => $this->contentTypePermissions($account),
    ];

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
   * Checks whether the current account may inspect the role matrix.
   */
  private function canInspectRoleMatrix(AccountInterface $account): bool {
    if ($account->hasPermission('administer permissions')) {
      return TRUE;
    }

    return in_array('administrator', $account->getRoles(), TRUE);
  }

  /**
   * Builds a compact role capability summary for role-comparison answers.
   *
   * @return array<int, array<string, mixed>>
   *   Role capability summaries.
   */
  private function roleCapabilitySummary(): array {
    $summaries = [];
    foreach ($this->configFactory->listAll('user.role.') as $name) {
      $data = $this->configFactory->get($name)->getRawData();
      $id = (string) ($data['id'] ?? substr($name, strlen('user.role.')));
      if ($id === '') {
        continue;
      }

      $permissions = array_values(array_map('strval', (array) ($data['permissions'] ?? [])));
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
      ];
    }

    usort($summaries, static fn(array $a, array $b): int => strcmp((string) $a['role'], (string) $b['role']));
    return $summaries;
  }

}
