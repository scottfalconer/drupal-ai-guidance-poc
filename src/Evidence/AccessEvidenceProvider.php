<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Evidence;

use Drupal\ai_guidance\Value\GuidanceEvidence;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Collects structured access, role, and permission evidence.
 */
final class AccessEvidenceProvider implements GuidanceEvidenceProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'drupal.access';
  }

  /**
   * {@inheritdoc}
   */
  public function domains(): array {
    return [
      'access',
      'ai_feature_access',
      'workflow',
      'outside_agent_handoff',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function applies(GuidanceRequest $request, GuidanceState $state, array $domains): bool {
    return array_intersect($this->domains(), $domains) !== [];
  }

  /**
   * {@inheritdoc}
   */
  public function collect(GuidanceRequest $request, GuidanceState $state, array $domains): GuidanceEvidence {
    $user = (array) $state->get('user', []);
    $route = (array) $state->get('route', []);
    $request_context = (array) $state->get('request_context', []);
    $entity = (array) $state->get('entity', []);
    $common_path_access = (array) $state->get('common_path_access', []);

    $role_guidance = (array) ($user['current_role_guidance'] ?? []);
    $permission_catalog = (array) ($user['relevant_permission_catalog'] ?? []);

    $drupal_evidence = [
      'current_user' => [
        'is_authenticated' => (bool) ($user['is_authenticated'] ?? $request->account->isAuthenticated()),
        'roles' => array_values(array_map('strval', (array) ($user['roles'] ?? $request->account->getRoles()))),
        'admin_capabilities' => $this->adminCapabilities($user),
        'content_type_permissions' => array_slice((array) ($user['content_type_permissions'] ?? []), 0, 12),
        'granted_relevant_permissions' => array_values(array_map('strval', (array) ($user['relevant_permissions'] ?? []))),
        'granted_restricted_permissions' => $this->restrictedGrantedPermissions($permission_catalog),
      ],
      'current_route' => $this->routeEvidence($route),
      'requested_path_access' => $this->requestedPathAccess($request_context),
      'checked_path_access' => $this->checkedPathAccess($common_path_access),
      'current_entity' => $this->entityEvidence($entity),
      'role_guidance' => [
        'current_user_can' => array_slice((array) ($role_guidance['current_user_can'] ?? []), 0, 8),
        'current_user_cannot' => array_slice((array) ($role_guidance['current_user_cannot'] ?? []), 0, 8),
        'what_to_ask_admin' => array_slice((array) ($role_guidance['what_to_ask_admin'] ?? []), 0, 6),
        'permissions_to_avoid_granting_for_editor_rollout' => array_slice((array) ($role_guidance['permissions_to_avoid_granting_for_editor_rollout'] ?? []), 0, 6),
      ],
    ];

    if (!empty($user['role_capability_summary'])) {
      $drupal_evidence['role_capability_summary'] = array_slice((array) $user['role_capability_summary'], 0, 8);
    }
    if (!empty($user['role_capability_note'])) {
      $drupal_evidence['role_capability_note'] = (string) $user['role_capability_note'];
    }

    $known_unknowns = [
      'Local task/tab visibility is not yet collected as structured evidence.',
    ];
    if (in_array('workflow', $domains, TRUE) && empty($entity['moderation_state'])) {
      $known_unknowns[] = 'Workflow transition access can only be explained when moderation/workflow state is available in the current request or site summary.';
    }
    if (($route['access_allowed'] ?? NULL) === NULL) {
      $known_unknowns[] = 'Current route access could not be resolved for this request.';
    }

    $next_steps = [
      'Answer role-first: what the current user can do, what the current user cannot do, who can do blocked tasks, and what to ask them to change.',
    ];
    if (empty($user['can_administer_permissions'])) {
      $next_steps[] = 'For permission changes, ask an administrator to review `/admin/people/permissions`; do not tell the current user to grant permissions directly.';
    }
    if (empty($user['can_administer_ai_providers']) && in_array('ai_feature_access', $domains, TRUE)) {
      $next_steps[] = 'For AI provider or model setup, ask an administrator to configure providers and keep provider administration out of editor roles.';
    }

    return new GuidanceEvidence(
      providerId: $this->id(),
      domain: $this->primaryDomain($domains),
      confidence: $user === [] && $route === [] && $entity === [] ? 'low' : 'high',
      drupalEvidence: $drupal_evidence,
      knownUnknowns: array_values(array_unique($known_unknowns)),
      nextDiagnosticSteps: array_values(array_unique($next_steps)),
      sources: [
        'Current user roles and permissions',
        'Current route access',
        'Current entity access',
      ],
    );
  }

  /**
   * Gets admin capability flags from current-user state.
   *
   * @param array<string, mixed> $user
   *   Current-user state.
   *
   * @return array<string, bool>
   *   Capability flags.
   */
  private function adminCapabilities(array $user): array {
    $keys = [
      'can_access_administration_pages',
      'can_administer_ai',
      'can_administer_ai_providers',
      'can_administer_assistants',
      'can_administer_permissions',
      'can_administer_site_configuration',
    ];

    $capabilities = [];
    foreach ($keys as $key) {
      $capabilities[$key] = !empty($user[$key]);
    }
    return $capabilities;
  }

  /**
   * Returns restricted permissions granted to the current user.
   *
   * @param array<string, array<string, mixed>> $permission_catalog
   *   Granted relevant permission metadata.
   *
   * @return string[]
   *   Restricted permission IDs.
   */
  private function restrictedGrantedPermissions(array $permission_catalog): array {
    $permissions = [];
    foreach ($permission_catalog as $permission => $definition) {
      if (!empty($definition['restrict_access'])) {
        $permissions[] = (string) $permission;
      }
    }
    sort($permissions, SORT_STRING);
    return $permissions;
  }

  /**
   * Returns current-route evidence.
   *
   * @param array<string, mixed> $route
   *   Route state.
   *
   * @return array<string, mixed>
   *   Route evidence.
   */
  private function routeEvidence(array $route): array {
    return [
      'name' => $route['name'] ?? NULL,
      'path' => $route['path'] ?? NULL,
      'access_allowed' => $route['access_allowed'] ?? NULL,
    ];
  }

  /**
   * Returns caller-requested path access evidence.
   *
   * @param array<string, mixed> $request_context
   *   Request context state.
   *
   * @return array<string, mixed>|null
   *   Requested path access evidence.
   */
  private function requestedPathAccess(array $request_context): ?array {
    $access = $request_context['requested_path_access'] ?? NULL;
    return is_array($access) ? [
      'path' => $access['path'] ?? NULL,
      'route_name' => $access['route_name'] ?? NULL,
      'access_allowed' => $access['access_allowed'] ?? NULL,
    ] : NULL;
  }

  /**
   * Returns common path access facts.
   *
   * @param array<int, mixed> $path_access
   *   Path access state.
   *
   * @return array<int, array<string, mixed>>
   *   Compact path access evidence.
   */
  private function checkedPathAccess(array $path_access): array {
    $items = [];
    foreach ($path_access as $item) {
      if (!is_array($item)) {
        continue;
      }
      $items[] = [
        'path' => $item['path'] ?? NULL,
        'route_name' => $item['route_name'] ?? NULL,
        'access_allowed' => $item['access_allowed'] ?? NULL,
        'note' => $item['note'] ?? NULL,
      ];
      if (count($items) >= 8) {
        break;
      }
    }
    return $items;
  }

  /**
   * Returns current-entity evidence.
   *
   * @param array<string, mixed> $entity
   *   Entity state.
   *
   * @return array<string, mixed>
   *   Entity evidence.
   */
  private function entityEvidence(array $entity): array {
    $evidence = [
      'type' => $entity['type'] ?? NULL,
      'id' => $entity['id'] ?? NULL,
      'bundle' => $entity['bundle'] ?? NULL,
      'access' => is_array($entity['access'] ?? NULL) ? $entity['access'] : NULL,
      'published' => $entity['published'] ?? NULL,
      'moderation_state' => $entity['moderation_state'] ?? NULL,
    ];
    if (!empty($entity['access_note'])) {
      $evidence['access_note'] = $entity['access_note'];
    }
    return $evidence;
  }

  /**
   * Chooses the most specific domain for this evidence item.
   *
   * @param string[] $domains
   *   Classified domains.
   */
  private function primaryDomain(array $domains): string {
    foreach (['access', 'ai_feature_access', 'workflow', 'outside_agent_handoff'] as $domain) {
      if (in_array($domain, $domains, TRUE)) {
        return $domain;
      }
    }
    return 'access';
  }

}
