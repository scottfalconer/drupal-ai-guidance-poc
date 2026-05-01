<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Evidence;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\ai_guidance\Value\GuidanceEvidence;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Collects workflow and moderation evidence for the current entity/bundle.
 */
final class WorkflowEvidenceProvider implements GuidanceEvidenceProviderInterface {

  /**
   * Logger for sanitized workflow diagnostics.
   */
  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    ?LoggerInterface $logger = NULL,
  ) {
    $this->logger = $logger ?? new NullLogger();
  }

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'drupal.workflow';
  }

  /**
   * {@inheritdoc}
   */
  public function domains(): array {
    return [
      'access',
      'content_visibility',
      'outside_agent_handoff',
      'workflow',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function applies(GuidanceRequest $request, GuidanceState $state, array $domains): bool {
    if (array_intersect($this->domains(), $domains) === []) {
      return FALSE;
    }
    if (!in_array('workflow', $domains, TRUE)
      && !in_array('content_visibility', $domains, TRUE)
      && !in_array('outside_agent_handoff', $domains, TRUE)
      && $this->targetFromState($state) === NULL
    ) {
      return FALSE;
    }

    if ($this->moduleHandler->moduleExists('workflows') || $this->moduleHandler->moduleExists('content_moderation')) {
      return TRUE;
    }

    try {
      return $this->configFactory->listAll('workflows.workflow.') !== [];
    }
    catch (\Throwable $exception) {
      $this->logger->debug('Workflow config listing failed with @class.', [
        '@class' => get_debug_type($exception),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function collect(GuidanceRequest $request, GuidanceState $state, array $domains): GuidanceEvidence {
    $entity = (array) $state->get('entity', []);
    $target = $this->targetFromState($state);
    $config_names = $this->workflowConfigNames();
    $can_inspect_all = $this->canInspectWorkflowConfiguration($request);

    $workflow_summaries = [];
    foreach ($config_names as $name) {
      $summary = $this->workflowSummary($request, $name, $this->workflowConfig($name), $target);
      if ($summary === []) {
        continue;
      }
      if ($target === NULL || !empty($summary['applies_to_current_bundle']) || $can_inspect_all) {
        $workflow_summaries[] = $summary;
      }
      if (count($workflow_summaries) >= 8) {
        break;
      }
    }

    $current_workflow = NULL;
    foreach ($workflow_summaries as $summary) {
      if (!empty($summary['applies_to_current_bundle'])) {
        $current_workflow = $summary;
        break;
      }
    }

    $known_unknowns = [];
    if ($config_names === []) {
      $known_unknowns[] = 'No workflow configuration was found.';
    }
    if ($target === NULL) {
      $known_unknowns[] = 'No current entity bundle was available, so workflow applicability to this page could not be confirmed.';
    }
    elseif ($current_workflow === NULL) {
      $known_unknowns[] = 'No configured workflow was found for the current entity bundle.';
    }
    if ($target !== NULL && empty($target['moderation_state'])) {
      $known_unknowns[] = 'The current entity moderation state is not available; available transitions from the current state cannot be confirmed.';
    }
    if (!$can_inspect_all && $target === NULL) {
      $known_unknowns[] = 'This account is not receiving a full cross-site workflow inventory.';
    }

    $next_steps = [];
    if ($current_workflow !== NULL) {
      $available = array_values(array_filter((array) ($current_workflow['transitions'] ?? []), static fn(array $transition): bool => !empty($transition['available_to_current_user_from_current_state'])));
      if ($available !== []) {
        $next_steps[] = 'Use only transitions available to the current user from the current moderation state: ' . implode(', ', array_map(static fn(array $transition): string => (string) ($transition['label'] ?: $transition['id']), $available)) . '.';
      }
      else {
        $next_steps[] = 'If publishing or unpublishing is blocked, ask an administrator to review workflow transition permissions for this bundle.';
      }
    }
    else {
      $next_steps[] = 'Ask a site builder to inspect the workflow assigned to this content type before advising publish/unpublish behavior.';
    }

    return new GuidanceEvidence(
      providerId: $this->id(),
      domain: 'workflow',
      confidence: $current_workflow !== NULL ? 'high' : ($workflow_summaries !== [] ? 'medium' : 'low'),
      drupalEvidence: [
        'workflow_module_enabled' => $this->moduleHandler->moduleExists('workflows'),
        'content_moderation_module_enabled' => $this->moduleHandler->moduleExists('content_moderation'),
        'current_entity' => [
          'type' => $target['entity_type'] ?? ($entity['type'] ?? NULL),
          'bundle' => $target['bundle'] ?? ($entity['bundle'] ?? NULL),
          'published' => $entity['published'] ?? NULL,
          'moderation_state' => $target['moderation_state'] ?? ($entity['moderation_state'] ?? NULL),
        ],
        'current_workflow' => $current_workflow,
        'workflows' => $workflow_summaries,
      ],
      knownUnknowns: array_values(array_unique($known_unknowns)),
      nextDiagnosticSteps: array_values(array_unique($next_steps)),
      sources: array_values(array_unique(array_map(static fn(array $workflow): string => 'Workflow: ' . (string) ($workflow['label'] ?: $workflow['id']), $workflow_summaries))),
    );
  }

  /**
   * Gets workflow config names.
   *
   * @return string[]
   *   Workflow config names.
   */
  private function workflowConfigNames(): array {
    try {
      $names = $this->configFactory->listAll('workflows.workflow.');
      sort($names, SORT_STRING);
      return $names;
    }
    catch (\Throwable $exception) {
      $this->logger->debug('Workflow config listing failed with @class.', [
        '@class' => get_debug_type($exception),
      ]);
      return [];
    }
  }

  /**
   * Loads one workflow config object.
   *
   * @return array<string, mixed>
   *   Workflow config.
   */
  private function workflowConfig(string $name): array {
    try {
      return $this->configFactory->get($name)->getRawData();
    }
    catch (\Throwable $exception) {
      $this->logger->debug('Workflow config load for @config failed with @class.', [
        '@config' => $name,
        '@class' => get_debug_type($exception),
      ]);
      return [];
    }
  }

  /**
   * Builds a safe workflow summary.
   *
   * @param array<string, mixed>|null $target
   *   Current entity target.
   *
   * @return array<string, mixed>
   *   Workflow summary.
   */
  private function workflowSummary(GuidanceRequest $request, string $config_name, array $data, ?array $target): array {
    if ($data === []) {
      return [];
    }

    $id = (string) ($data['id'] ?? substr($config_name, strlen('workflows.workflow.')));
    $type_settings = (array) ($data['type_settings'] ?? []);
    $entity_types = (array) ($type_settings['entity_types'] ?? []);
    $current_entity_type = (string) ($target['entity_type'] ?? '');
    $current_bundle = (string) ($target['bundle'] ?? '');
    $bundles = $this->workflowBundles($entity_types);
    $applies = $current_entity_type !== ''
      && $current_bundle !== ''
      && in_array($current_entity_type . ':' . $current_bundle, $bundles, TRUE);
    $current_state = (string) ($target['moderation_state'] ?? '');

    $states = [];
    foreach ((array) ($type_settings['states'] ?? []) as $state_id => $state) {
      if (!is_array($state)) {
        continue;
      }
      $states[] = [
        'id' => (string) $state_id,
        'label' => (string) ($state['label'] ?? $state_id),
        'published' => $state['published'] ?? NULL,
        'default_revision' => $state['default_revision'] ?? NULL,
        'is_current' => $current_state !== '' && (string) $state_id === $current_state,
      ];
    }

    $transitions = [];
    foreach ((array) ($type_settings['transitions'] ?? []) as $transition_id => $transition) {
      if (!is_array($transition)) {
        continue;
      }
      $from = array_values(array_map('strval', (array) ($transition['from'] ?? [])));
      $to = (string) ($transition['to'] ?? '');
      $from_current = $current_state !== '' && in_array($current_state, $from, TRUE);
      $user_can = $this->canUseTransition($request, $id, (string) $transition_id);
      $transitions[] = [
        'id' => (string) $transition_id,
        'label' => (string) ($transition['label'] ?? $transition_id),
        'from' => $from,
        'to' => $to,
        'permission' => 'use ' . $id . ' transition ' . (string) $transition_id,
        'from_current_state' => $from_current,
        'current_user_has_transition_permission' => $user_can,
        'available_to_current_user_from_current_state' => $from_current && $user_can,
      ];
    }

    return [
      'id' => $id,
      'label' => (string) ($data['label'] ?? $id),
      'type' => (string) ($data['type'] ?? ''),
      'applies_to_current_bundle' => $applies,
      'current_state' => $current_state !== '' ? $current_state : NULL,
      'bundles' => $bundles,
      'states' => array_slice($states, 0, 12),
      'transitions' => array_slice($transitions, 0, 16),
    ];
  }

  /**
   * Returns workflow bundles in `entity_type:bundle` form.
   *
   * @param array<string, mixed> $entity_types
   *   Workflow entity type settings.
   *
   * @return string[]
   *   Bundles.
   */
  private function workflowBundles(array $entity_types): array {
    $bundles = [];
    foreach ($entity_types as $entity_type => $bundle_ids) {
      foreach ((array) $bundle_ids as $bundle_id) {
        if (is_scalar($bundle_id) && (string) $bundle_id !== '') {
          $bundles[] = (string) $entity_type . ':' . (string) $bundle_id;
        }
      }
    }
    sort($bundles, SORT_STRING);
    return $bundles;
  }

  /**
   * Resolves current entity type, bundle, and moderation state.
   *
   * @return array<string, mixed>|null
   *   Target entity.
   */
  private function targetFromState(GuidanceState $state): ?array {
    $route = (array) $state->get('route', []);
    if (($route['name'] ?? NULL) === 'node.add') {
      $parameters = (array) ($route['parameters'] ?? []);
      $bundle = $this->bundleIdFromRouteParameter($parameters['node_type'] ?? NULL);
      if ($bundle === '') {
        $request_context = (array) $state->get('request_context', []);
        $requested_path_access = (array) ($request_context['requested_path_access'] ?? []);
        $path = (string) ($requested_path_access['path'] ?? '');
        if (preg_match('#^/node/add/([^/]+)$#', $path, $matches)) {
          $bundle = $matches[1];
        }
      }
      if ($bundle !== '') {
        return [
          'entity_type' => 'node',
          'bundle' => $bundle,
          'moderation_state' => '',
        ];
      }
    }

    $entity = (array) $state->get('entity', []);
    if (empty($entity['type']) || empty($entity['bundle'])) {
      return NULL;
    }
    return [
      'entity_type' => (string) $entity['type'],
      'bundle' => (string) $entity['bundle'],
      'moderation_state' => isset($entity['moderation_state']) ? (string) $entity['moderation_state'] : '',
    ];
  }

  /**
   * Checks whether the account can use a transition.
   */
  private function canUseTransition(GuidanceRequest $request, string $workflow_id, string $transition_id): bool {
    foreach ([
      'administer workflows',
      'administer content moderation',
      'administer site configuration',
      'use ' . $workflow_id . ' transition ' . $transition_id,
    ] as $permission) {
      if ($request->account->hasPermission($permission)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Extracts a bundle ID from a route parameter.
   */
  private function bundleIdFromRouteParameter(mixed $parameter): string {
    if (is_scalar($parameter)) {
      return (string) $parameter;
    }
    if (is_array($parameter) && is_scalar($parameter['id'] ?? NULL)) {
      return (string) $parameter['id'];
    }
    if (is_object($parameter) && method_exists($parameter, 'id')) {
      return (string) $parameter->id();
    }
    return '';
  }

  /**
   * Checks whether this account may inspect the full workflow inventory.
   */
  private function canInspectWorkflowConfiguration(GuidanceRequest $request): bool {
    foreach ([
      'view ai guidance site inventory',
      'administer ai guidance',
      'administer workflows',
      'administer content moderation',
      'administer site configuration',
    ] as $permission) {
      if ($request->account->hasPermission($permission)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
