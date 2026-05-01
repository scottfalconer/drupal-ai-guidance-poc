<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Evidence;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\ai_guidance\Value\GuidanceEvidence;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Collects evidence about the current entity form.
 */
final class CurrentFormEvidenceProvider implements GuidanceEvidenceProviderInterface {

  /**
   * Logger for sanitized form diagnostics.
   */
  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    ?LoggerInterface $logger = NULL,
  ) {
    $this->logger = $logger ?? new NullLogger();
  }

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'drupal.current_form';
  }

  /**
   * {@inheritdoc}
   */
  public function domains(): array {
    return [
      'access',
      'field_model',
      'form_submission',
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

    $request_context = (array) $state->get('request_context', []);
    if (!empty($request_context['current_form'])) {
      return TRUE;
    }

    return $this->targetFromState($state) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function collect(GuidanceRequest $request, GuidanceState $state, array $domains): GuidanceEvidence {
    $request_context = (array) $state->get('request_context', []);
    $browser_form = $this->browserFormSummary((array) ($request_context['current_form'] ?? []));
    $target = $this->targetFromState($state);

    $drupal_evidence = [
      'browser_visible_form' => $browser_form,
    ];
    $known_unknowns = [];
    $next_steps = [];
    $sources = [];
    $confidence = 'low';

    if ($target === NULL) {
      $known_unknowns[] = 'The current route could not be resolved to an entity add/edit form.';
      $known_unknowns[] = 'Visible browser form fields may be available, but Drupal entity form display configuration was not matched.';
      $next_steps[] = 'If this is a content task, open the content add or edit form and ask again.';

      return new GuidanceEvidence(
        providerId: $this->id(),
        domain: 'form_submission',
        confidence: $confidence,
        drupalEvidence: $drupal_evidence,
        knownUnknowns: $known_unknowns,
        nextDiagnosticSteps: $next_steps,
        sources: $browser_form === [] ? [] : ['Visible browser form'],
      );
    }

    $field_definitions = $this->fieldDefinitions($target['entity_type'], $target['bundle']);
    $form_display = $this->formDisplay($target['entity_type'], $target['bundle']);
    $visible_components = $this->visibleComponents($form_display, $field_definitions);
    $all_field_summaries = $this->fieldSummaries($visible_components, $field_definitions);
    $field_summaries = $this->compactFieldSummaries($all_field_summaries, $browser_form);
    $required_fields = array_values(array_filter($all_field_summaries, static fn(array $field): bool => !empty($field['required'])));

    $drupal_evidence += [
      'entity_form_target' => $target,
      'form_display' => [
        'id' => $form_display['id'] ?? $target['entity_type'] . '.' . $target['bundle'] . '.default',
        'mode' => $form_display['mode'] ?? 'default',
        'status' => $form_display['status'] ?? NULL,
        'visible_component_count' => count($visible_components),
      ],
      'visible_field_summaries' => $field_summaries,
      'required_fields' => array_slice($required_fields, 0, 4),
    ];

    if ($browser_form !== []) {
      $confidence = 'high';
      $sources[] = 'Visible browser form';
    }
    if ($form_display !== []) {
      $confidence = $browser_form === [] ? 'medium' : 'high';
      $sources[] = 'Entity form display: ' . $target['entity_type'] . '.' . $target['bundle'] . '.default';
    }
    if ($field_definitions !== []) {
      $sources[] = 'Field definitions: ' . $target['entity_type'] . '.' . $target['bundle'];
    }

    if ($browser_form === []) {
      $known_unknowns[] = 'The browser did not provide visible form fields or buttons, so runtime form alterations and exact submit buttons cannot be confirmed.';
    }
    if ($form_display === []) {
      $known_unknowns[] = 'The default entity form display configuration was not found or could not be inspected.';
    }
    if ($field_definitions === []) {
      $known_unknowns[] = 'Field definitions were not available for the current entity bundle.';
    }

    if ($required_fields !== []) {
      $labels = array_values(array_map(static fn(array $field): string => (string) ($field['label'] ?: $field['name']), array_slice($required_fields, 0, 6)));
      $next_steps[] = 'Fill the required fields first: ' . implode(', ', $labels) . '.';
    }
    if (!empty($browser_form['submit_buttons'])) {
      $next_steps[] = 'Use one of the visible submit buttons only after required fields are complete: ' . implode(', ', array_slice((array) $browser_form['submit_buttons'], 0, 4)) . '.';
    }
    else {
      $next_steps[] = 'Check the visible form buttons before deciding whether the page supports Save, Preview, Publish, or workflow-specific actions.';
    }

    return new GuidanceEvidence(
      providerId: $this->id(),
      domain: $this->primaryDomain($domains),
      confidence: $confidence,
      drupalEvidence: $drupal_evidence,
      knownUnknowns: array_values(array_unique($known_unknowns)),
      nextDiagnosticSteps: array_values(array_unique($next_steps)),
      sources: array_values(array_unique($sources)),
    );
  }

  /**
   * Resolves the current entity form target from safe state.
   *
   * @return array<string, string>|null
   *   Entity form target.
   */
  private function targetFromState(GuidanceState $state): ?array {
    $route = (array) $state->get('route', []);
    $request_context = (array) $state->get('request_context', []);
    $entity = (array) $state->get('entity', []);
    $route_name = (string) ($route['name'] ?? '');
    $parameters = (array) ($route['parameters'] ?? []);

    if ($route_name === '') {
      $requested_path_access = (array) ($request_context['requested_path_access'] ?? []);
      $route_name = (string) ($requested_path_access['route_name'] ?? '');
    }

    if ($route_name === 'node.add') {
      $bundle = $this->bundleIdFromRouteParameter($parameters['node_type'] ?? NULL);
      if ($bundle === '') {
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
          'operation' => 'create',
          'route_name' => $route_name,
        ];
      }
    }

    if (str_starts_with($route_name, 'entity.') && str_ends_with($route_name, '.edit_form')) {
      $entity_type = (string) ($entity['type'] ?? '');
      $bundle = (string) ($entity['bundle'] ?? '');
      if ($entity_type !== '' && $bundle !== '') {
        return [
          'entity_type' => $entity_type,
          'bundle' => $bundle,
          'operation' => 'update',
          'route_name' => $route_name,
        ];
      }
    }

    if (!empty($entity['type']) && !empty($entity['bundle'])) {
      return [
        'entity_type' => (string) $entity['type'],
        'bundle' => (string) $entity['bundle'],
        'operation' => empty($entity['is_new']) ? 'update' : 'create',
        'route_name' => $route_name,
      ];
    }

    return NULL;
  }

  /**
   * Returns browser-visible form evidence.
   *
   * @param array<string, mixed> $form
   *   Caller-provided form summary.
   *
   * @return array<string, mixed>
   *   Browser form evidence.
   */
  private function browserFormSummary(array $form): array {
    if ($form === []) {
      return [];
    }

    return [
      'form_id' => $form['form_id'] ?? NULL,
      'action' => $form['action'] ?? NULL,
      'method' => $form['method'] ?? NULL,
      'visible_fields' => array_values(array_slice((array) ($form['fields'] ?? []), 0, 10)),
      'required_visible_fields' => array_values(array_filter((array) ($form['fields'] ?? []), static fn(mixed $field): bool => is_array($field) && !empty($field['required']))),
      'submit_buttons' => array_values(array_slice((array) ($form['submit_buttons'] ?? []), 0, 8)),
    ];
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
   * Loads the default form display config as a safe array.
   *
   * @return array<string, mixed>
   *   Form display config.
   */
  private function formDisplay(string $entity_type, string $bundle): array {
    try {
      return $this->configFactory->get('core.entity_form_display.' . $entity_type . '.' . $bundle . '.default')->getRawData();
    }
    catch (\Throwable $exception) {
      $this->logger->debug('Entity form display lookup for @entity_type.@bundle failed with @class.', [
        '@entity_type' => $entity_type,
        '@bundle' => $bundle,
        '@class' => get_debug_type($exception),
      ]);
      return [];
    }
  }

  /**
   * Loads field definitions for the current bundle.
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   Field definitions keyed by field name.
   */
  private function fieldDefinitions(string $entity_type, string $bundle): array {
    try {
      return $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);
    }
    catch (\Throwable $exception) {
      $this->logger->debug('Field definition lookup for @entity_type.@bundle failed with @class.', [
        '@entity_type' => $entity_type,
        '@bundle' => $bundle,
        '@class' => get_debug_type($exception),
      ]);
      return [];
    }
  }

  /**
   * Summarizes visible form components.
   *
   * @param array<string, mixed> $form_display
   *   Form display config.
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $field_definitions
   *   Field definitions.
   *
   * @return array<int, array<string, mixed>>
   *   Visible components.
   */
  private function visibleComponents(array $form_display, array $field_definitions): array {
    $content = (array) ($form_display['content'] ?? []);
    uasort($content, static fn(mixed $a, mixed $b): int => ((int) (($a['weight'] ?? 0))) <=> ((int) (($b['weight'] ?? 0))));

    $components = [];
    foreach ($content as $name => $component) {
      if (!is_array($component)) {
        continue;
      }
      $definition = $field_definitions[(string) $name] ?? NULL;
      if (!$definition instanceof FieldDefinitionInterface && empty($component['type'])) {
        continue;
      }
      if (!$this->isUsefulComponentName((string) $name)) {
        continue;
      }
      $components[] = [
        'name' => (string) $name,
        'label' => $definition instanceof FieldDefinitionInterface ? (string) $definition->getLabel() : (string) $name,
        'widget_type' => (string) ($component['type'] ?? ''),
        'weight' => (int) ($component['weight'] ?? 0),
        'region' => (string) ($component['region'] ?? 'content'),
      ];
      if (count($components) >= 12) {
        break;
      }
    }

    return $components;
  }

  /**
   * Builds field summaries for visible components.
   *
   * @param array<int, array<string, mixed>> $components
   *   Visible components.
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $field_definitions
   *   Field definitions.
   *
   * @return array<int, array<string, mixed>>
   *   Field summaries.
   */
  private function fieldSummaries(array $components, array $field_definitions): array {
    $summaries = [];
    foreach ($components as $component) {
      $name = (string) ($component['name'] ?? '');
      $definition = $field_definitions[$name] ?? NULL;
      if (!$definition instanceof FieldDefinitionInterface) {
        $summaries[] = [
          'name' => $name,
          'label' => (string) ($component['label'] ?? $name),
          'widget_type' => (string) ($component['widget_type'] ?? ''),
        ];
        continue;
      }

      $summaries[] = [
        'name' => $name,
        'label' => (string) $definition->getLabel(),
        'description' => mb_substr(trim((string) $definition->getDescription()), 0, 240),
        'field_type' => $definition->getType(),
        'widget_type' => (string) ($component['widget_type'] ?? ''),
        'required' => $definition->isRequired(),
        'cardinality' => $definition->getFieldStorageDefinition()->getCardinality(),
        'target_type' => $this->fieldSetting($definition, 'target_type'),
        'translatable' => $definition->isTranslatable(),
      ];
    }
    return $summaries;
  }

  /**
   * Keeps form-display field evidence compact for end-user guidance.
   *
   * The full form display can be useful for debugging, but model-visible context
   * should emphasize the controls a user can reasonably act on in the browser.
   *
   * @param array<int, array<string, mixed>> $field_summaries
   *   Field summaries from form display configuration.
   * @param array<string, mixed> $browser_form
   *   Browser-visible form evidence.
   *
   * @return array<int, array<string, mixed>>
   *   Compact field summaries.
   */
  private function compactFieldSummaries(array $field_summaries, array $browser_form): array {
    $browser_labels = [];
    foreach ((array) ($browser_form['visible_fields'] ?? []) as $field) {
      if (is_array($field) && !empty($field['label'])) {
        $browser_labels[] = mb_strtolower((string) $field['label']);
      }
    }

    $ranked = $field_summaries;
    usort($ranked, static function (array $a, array $b) use ($browser_labels): int {
      $a_label = mb_strtolower((string) ($a['label'] ?? $a['name'] ?? ''));
      $b_label = mb_strtolower((string) ($b['label'] ?? $b['name'] ?? ''));
      $a_required = !empty($a['required']) ? 0 : 1;
      $b_required = !empty($b['required']) ? 0 : 1;
      if ($a_required !== $b_required) {
        return $a_required <=> $b_required;
      }
      $a_browser = in_array($a_label, $browser_labels, TRUE) ? 0 : 1;
      $b_browser = in_array($b_label, $browser_labels, TRUE) ? 0 : 1;
      if ($a_browser !== $b_browser) {
        return $a_browser <=> $b_browser;
      }
      return 0;
    });

    return array_slice($ranked, 0, 4);
  }

  /**
   * Filters admin/metadata controls that distract from form guidance.
   */
  private function isUsefulComponentName(string $name): bool {
    foreach ([
      'created',
      'path',
      'promote',
      'revision_log',
      'search_api_exclude',
      'status',
      'sticky',
      'uid',
    ] as $blocked) {
      if ($name === $blocked || str_starts_with($name, $blocked . '_')) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Safely reads a field setting.
   */
  private function fieldSetting(FieldDefinitionInterface $definition, string $setting): mixed {
    try {
      return $definition->getSetting($setting);
    }
    catch (\Throwable $exception) {
      $this->logger->debug('Field setting lookup for @setting failed with @class.', [
        '@setting' => $setting,
        '@class' => get_debug_type($exception),
      ]);
      return NULL;
    }
  }

  /**
   * Chooses the most specific domain.
   *
   * @param string[] $domains
   *   Classified domains.
   */
  private function primaryDomain(array $domains): string {
    foreach (['form_submission', 'field_model', 'workflow', 'access'] as $domain) {
      if (in_array($domain, $domains, TRUE)) {
        return $domain;
      }
    }
    return 'form_submission';
  }

}
