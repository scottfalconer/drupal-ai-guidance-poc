<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Evidence;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\ai_guidance\Value\GuidanceEvidence;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Collects generic ECA model evidence when ECA is installed.
 */
final class EcaEvidenceProvider implements GuidanceEvidenceProviderInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'drupal.eca';
  }

  /**
   * {@inheritdoc}
   */
  public function domains(): array {
    return ['automation', 'outside_agent_handoff'];
  }

  /**
   * {@inheritdoc}
   */
  public function applies(GuidanceRequest $request, GuidanceState $state, array $domains): bool {
    return $this->moduleHandler->moduleExists('eca');
  }

  /**
   * {@inheritdoc}
   */
  public function collect(GuidanceRequest $request, GuidanceState $state, array $domains): GuidanceEvidence {
    if (!$this->canInspectEcaConfiguration($request)) {
      return new GuidanceEvidence(
        providerId: $this->id(),
        domain: 'automation',
        confidence: 'low',
        drupalEvidence: [
          'eca_module_enabled' => TRUE,
          'configuration_details' => 'omitted_for_current_account',
        ],
        knownUnknowns: [
          'ECA is installed, but this account cannot inspect ECA model configuration details.',
          'A site builder should review automation models before an outside agent changes event, condition, or action behavior.',
        ],
        nextDiagnosticSteps: [
          'Ask a site builder to review ECA models and identify any event, condition, or action that could affect the requested workflow.',
        ],
      );
    }

    $config_names = $this->configFactory->listAll('eca.eca.');
    sort($config_names, SORT_STRING);

    $models = [];
    foreach (array_slice($config_names, 0, 8) as $name) {
      $data = $this->configFactory->get($name)->getRawData();
      $models[] = $this->modelSummary($name, $data);
    }

    $known_unknowns = [];
    if ($config_names === []) {
      $known_unknowns[] = 'ECA is installed, but no `eca.eca.*` model configuration was found.';
    }
    $known_unknowns[] = 'This generic provider summarizes ECA configuration only; it does not execute or validate models.';

    return new GuidanceEvidence(
      providerId: $this->id(),
      domain: 'automation',
      confidence: $config_names === [] ? 'low' : 'medium',
      drupalEvidence: [
        'eca_module_enabled' => TRUE,
        'model_count' => count($config_names),
        'models' => $models,
      ],
      knownUnknowns: $known_unknowns,
      nextDiagnosticSteps: [
        'Ask a site builder to review ECA model configuration before changing automation behavior.',
        'For outside-agent work, preserve event, condition, and action ordering unless explicitly asked to change it.',
      ],
      sources: array_map(static fn(array $model): string => 'ECA model: ' . (string) ($model['label'] ?? $model['id'] ?? 'unnamed'), $models),
    );
  }

  /**
   * Builds a safe model summary without dumping raw configuration.
   *
   * @param string $config_name
   *   Config object name.
   * @param array<string, mixed> $data
   *   Raw model config.
   *
   * @return array<string, mixed>
   *   Safe model summary.
   */
  private function modelSummary(string $config_name, array $data): array {
    $id = (string) ($data['id'] ?? substr($config_name, strlen('eca.eca.')));
    $label = (string) ($data['label']
      ?? $data['third_party_settings']['modeler_api']['label']
      ?? $data['name']
      ?? $id);
    $serialized = json_encode($data) ?: '';

    return [
      'id' => $id,
      'label' => $label,
      'enabled' => !array_key_exists('status', $data) || (bool) $data['status'],
      'top_level_keys' => array_values(array_slice(array_keys($data), 0, 12)),
      'events' => $this->pluginSummaries((array) ($data['events'] ?? [])),
      'conditions' => $this->pluginSummaries((array) ($data['conditions'] ?? [])),
      'actions' => $this->pluginSummaries((array) ($data['actions'] ?? [])),
      'token_names' => $this->tokenNames($serialized),
      'mutating_or_outbound_signals' => $this->mutatingOrOutboundSignals($serialized),
    ];
  }

  /**
   * Summarizes ECA event, condition, or action plugins.
   *
   * @param array<string, mixed> $items
   *   Raw plugin item config.
   *
   * @return array<int, array<string, mixed>>
   *   Plugin summaries.
   */
  private function pluginSummaries(array $items): array {
    $summaries = [];
    foreach ($items as $id => $item) {
      if (!is_array($item)) {
        continue;
      }
      $configuration = (array) ($item['configuration'] ?? []);
      $summaries[] = [
        'id' => (string) $id,
        'label' => (string) ($item['label'] ?? $id),
        'plugin' => (string) ($item['plugin'] ?? ''),
        'configuration_keys' => array_values(array_slice(array_keys($configuration), 0, 8)),
        'successor_count' => count((array) ($item['successors'] ?? [])),
      ];
      if (count($summaries) >= 12) {
        break;
      }
    }

    return $summaries;
  }

  /**
   * Extracts Drupal token names without exposing replacement values.
   *
   * @return string[]
   *   Token names.
   */
  private function tokenNames(string $serialized): array {
    preg_match_all('/\\[([a-z0-9_:-]+)\\]/i', $serialized, $matches);
    $tokens = array_values(array_unique($matches[1] ?? []));
    sort($tokens, SORT_STRING);
    return array_slice($tokens, 0, 12);
  }

  /**
   * Flags action terms that often mutate state or call outside systems.
   *
   * @return string[]
   *   Signals.
   */
  private function mutatingOrOutboundSignals(string $serialized): array {
    $haystack = strtolower($serialized);
    $signals = [];
    foreach ([
      'create',
      'delete',
      'email',
      'http',
      'mail',
      'queue',
      'save',
      'send',
      'update',
      'webhook',
    ] as $term) {
      if (str_contains($haystack, $term)) {
        $signals[] = $term;
      }
    }
    return $signals;
  }

  /**
   * Checks whether the current account may inspect ECA model configuration.
   */
  private function canInspectEcaConfiguration(GuidanceRequest $request): bool {
    foreach ([
      'administer ai guidance',
      'administer site configuration',
      'administer eca',
    ] as $permission) {
      if ($request->account->hasPermission($permission)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
