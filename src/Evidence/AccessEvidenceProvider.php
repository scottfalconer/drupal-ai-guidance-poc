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
    $visible_page_messages = $this->visiblePageMessages($request_context);
    $lesson_evaluation = $this->lessonEvaluationEvidence($request->question, $route, $request_context, $entity, $visible_page_messages);

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
      'visible_page_messages' => $visible_page_messages,
      'checked_path_access' => $this->checkedPathAccess($common_path_access),
      'current_entity' => $this->entityEvidence($entity),
      'role_guidance' => [
        'current_user_can' => array_slice((array) ($role_guidance['current_user_can'] ?? []), 0, 8),
        'current_user_cannot' => array_slice((array) ($role_guidance['current_user_cannot'] ?? []), 0, 8),
        'what_to_ask_admin' => array_slice((array) ($role_guidance['what_to_ask_admin'] ?? []), 0, 6),
        'permissions_to_avoid_granting_for_editor_rollout' => array_slice((array) ($role_guidance['permissions_to_avoid_granting_for_editor_rollout'] ?? []), 0, 6),
      ],
    ];
    if ($lesson_evaluation !== []) {
      $drupal_evidence['lesson_1_evaluation'] = $lesson_evaluation;
    }

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
    if ($this->isLessonEvaluationQuestion($request->question) && empty($entity['type'])) {
      $known_unknowns[] = 'Lesson 1 completion cannot be confirmed without current entity evidence from the draft content page or a content listing.';
    }
    if ($lesson_evaluation !== []) {
      foreach ((array) ($lesson_evaluation['missing_evidence'] ?? []) as $missing) {
        $known_unknowns[] = (string) $missing;
      }
    }
    if ($this->hasPageWarningOrError($visible_page_messages)) {
      $known_unknowns[] = 'Visible warning or error messages may affect whether the current page is safe to treat as complete.';
    }

    $next_steps = [
      'Answer role-first: what the current user can do, what the current user cannot do, who can do blocked tasks, and what to ask them to change.',
    ];
    if ($lesson_evaluation !== []) {
      $next_steps[] = 'For Lesson 1, use the lesson_1_evaluation result label exactly: ' . $lesson_evaluation['result_label'] . '.';
      foreach ((array) ($lesson_evaluation['next_verification_steps'] ?? []) as $step) {
        $next_steps[] = (string) $step;
      }
    }
    if ($this->isLessonEvaluationQuestion($request->question) && empty($entity['type'])) {
      $next_steps[] = 'If asked to evaluate Lesson 1 without current entity evidence, say "Cannot confirm" and ask the user to open the draft content edit page or `/admin/content`, then ask again.';
    }
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
      'parameters' => is_array($route['parameters'] ?? NULL) ? $route['parameters'] : [],
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
   * Returns visible page messages supplied by the chat page.
   *
   * @param array<string, mixed> $request_context
   *   Request context state.
   *
   * @return array<int, array{type:string,text:string}>
   *   Visible page messages.
   */
  private function visiblePageMessages(array $request_context): array {
    $messages = $request_context['visible_page_messages'] ?? [];
    if (!is_array($messages)) {
      return [];
    }
    $safe = [];
    foreach ($messages as $message) {
      if (!is_array($message)) {
        continue;
      }
      $text = trim((string) ($message['text'] ?? ''));
      if ($text === '') {
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
      'label' => $entity['label'] ?? NULL,
      'is_new' => $entity['is_new'] ?? NULL,
      'bundle' => $entity['bundle'] ?? NULL,
      'language' => $entity['language'] ?? NULL,
      'owner_is_current_user' => $entity['owner_is_current_user'] ?? NULL,
      'access' => is_array($entity['access'] ?? NULL) ? $entity['access'] : NULL,
      'published' => $entity['published'] ?? NULL,
      'moderation_state' => $entity['moderation_state'] ?? NULL,
      'created_timestamp' => $entity['created_timestamp'] ?? NULL,
      'changed_timestamp' => $entity['changed_timestamp'] ?? NULL,
    ];
    if (!empty($entity['access_note'])) {
      $evidence['access_note'] = $entity['access_note'];
    }
    return $evidence;
  }

  /**
   * Builds explicit Lesson 1 evaluation evidence.
   *
   * @param string $question
   *   User question.
   * @param array<string, mixed> $route
   *   Route state.
   * @param array<string, mixed> $entity
   *   Entity state.
   * @param array<int, array{type:string,text:string}> $visible_page_messages
   *   Visible page messages.
   *
   * @return array<string, mixed>
   *   Lesson evaluation facts.
   */
  private function lessonEvaluationEvidence(string $question, array $route, array $request_context, array $entity, array $visible_page_messages): array {
    if (!$this->isLessonEvaluationQuestion($question)) {
      return [];
    }

    $confirmed = [];
    $missing = [];
    $next_steps = [];
    $result = 'cannot_confirm';
    $result_label = 'Cannot confirm';

    $is_content = ($entity['type'] ?? NULL) === 'node';
    $bundle = trim((string) ($entity['bundle'] ?? ''));
    $content_label = $bundle !== '' ? $bundle : 'content';
    $is_saved = $is_content && empty($entity['is_new']) && !empty($entity['id']);
    $is_non_public = ($entity['published'] ?? NULL) === FALSE
      || in_array((string) ($entity['moderation_state'] ?? ''), ['draft', 'unpublished'], TRUE);

    if ($is_content) {
      $confirmed[] = sprintf('Current entity is a Drupal content item (%s).', $content_label);
    }
    else {
      $missing[] = 'Open the saved draft content edit page so the assistant can confirm the content type.';
    }

    if ($is_saved) {
      $confirmed[] = 'Current content item is saved and has an entity ID.';
    }
    else {
      $missing[] = 'The assistant cannot confirm a saved content entity from the current evidence.';
    }

    if ($is_non_public) {
      $confirmed[] = 'Current content item is draft or unpublished.';
    }
    elseif ($is_content) {
      $missing[] = 'The current content item is not confirmed as draft or unpublished.';
    }

    $requested_path_access = is_array($request_context['requested_path_access'] ?? NULL)
      ? (array) $request_context['requested_path_access']
      : [];
    $route_name = (string) ($route['name'] ?? $requested_path_access['route_name'] ?? '');
    if ($route_name === 'entity.node.edit_form') {
      $confirmed[] = 'Current page is the saved content edit form.';
    }
    else {
      $missing[] = 'Open the saved content edit page to evaluate the draft directly.';
    }

    $missing[] = 'The assistant cannot confirm from this page alone that the content row was checked in `/admin/content`.';
    $missing[] = 'The assistant cannot confirm from this page alone that Preview or public view was opened.';
    $missing[] = 'The assistant cannot audit from this page alone that no unrelated configuration, workflow, View, permission, or front-page changes were made.';

    if ($this->hasPageWarningOrError($visible_page_messages)) {
      $result = 'partially_complete';
      $result_label = 'Partially complete';
      $next_steps[] = 'Resolve or explain the visible page warning/error before treating the lesson as fully complete.';
    }
    elseif ($is_saved && $is_non_public) {
      $result = 'core_task_complete';
      $result_label = 'Core task complete';
      $next_steps[] = 'To fully verify Lesson 1, confirm the content item appears in `/admin/content` as Draft or Unpublished.';
      $next_steps[] = 'Open Preview or the public view once, then return to the edit page if you want the assistant to evaluate entity state again.';
    }
    elseif ($is_content) {
      $result = 'partially_complete';
      $result_label = 'Partially complete';
      $next_steps[] = 'Keep the content item in a draft or unpublished state before marking the safe lesson task complete.';
    }
    else {
      $next_steps[] = 'Open the saved draft content edit page or `/admin/content`, then ask for Lesson 1 evaluation again.';
    }

    return [
      'result' => $result,
      'result_label' => $result_label,
      'confirmed_evidence' => array_values(array_unique($confirmed)),
      'missing_evidence' => array_values(array_unique($missing)),
      'next_verification_steps' => array_values(array_unique($next_steps)),
    ];
  }

  /**
   * Checks whether visible page messages include warnings or errors.
   *
   * @param array<int, array{type:string,text:string}> $messages
   *   Visible page messages.
   */
  private function hasPageWarningOrError(array $messages): bool {
    foreach ($messages as $message) {
      if (in_array($message['type'] ?? '', ['warning', 'error'], TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
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

  /**
   * Checks whether this is a Lesson 1 evaluation question.
   */
  private function isLessonEvaluationQuestion(string $question): bool {
    $question = strtolower($question);
    return str_contains($question, 'evaluate')
      || str_contains($question, 'did i complete')
      || str_contains($question, 'lesson 1 attempt');
  }

}
