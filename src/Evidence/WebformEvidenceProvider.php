<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Evidence;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\ai_guidance\Value\GuidanceEvidence;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Collects generic Webform evidence when Webform is installed.
 */
final class WebformEvidenceProvider implements GuidanceEvidenceProviderInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'drupal.webform';
  }

  /**
   * {@inheritdoc}
   */
  public function domains(): array {
    return ['form_submission', 'outside_agent_handoff'];
  }

  /**
   * {@inheritdoc}
   */
  public function applies(GuidanceRequest $request, GuidanceState $state, array $domains): bool {
    return $this->moduleHandler->moduleExists('webform');
  }

  /**
   * {@inheritdoc}
   */
  public function collect(GuidanceRequest $request, GuidanceState $state, array $domains): GuidanceEvidence {
    $config_names = $this->configFactory->listAll('webform.webform.');
    sort($config_names, SORT_STRING);

    $forms = [];
    foreach (array_slice($config_names, 0, 8) as $name) {
      $forms[] = $this->formSummary($name, $this->configFactory->get($name)->getRawData());
    }

    $known_unknowns = [];
    if ($config_names === []) {
      $known_unknowns[] = 'Webform is installed, but no `webform.webform.*` configuration was found.';
    }
    $known_unknowns[] = 'This generic provider summarizes Webform configuration only; it does not inspect stored submissions.';

    return new GuidanceEvidence(
      providerId: $this->id(),
      domain: 'form_submission',
      confidence: $config_names === [] ? 'low' : 'medium',
      drupalEvidence: [
        'webform_module_enabled' => TRUE,
        'form_count' => count($config_names),
        'forms' => $forms,
      ],
      knownUnknowns: $known_unknowns,
      nextDiagnosticSteps: [
        'Ask a site builder to review Webform elements, handlers, and submission access before changing submission behavior.',
        'For outside-agent work, preserve handlers that send email, post remotely, or affect submission storage unless explicitly authorized.',
      ],
      sources: $config_names,
    );
  }

  /**
   * Builds a safe Webform summary.
   *
   * @param string $config_name
   *   Config object name.
   * @param array<string, mixed> $data
   *   Raw Webform config.
   *
   * @return array<string, mixed>
   *   Webform summary.
   */
  private function formSummary(string $config_name, array $data): array {
    $id = (string) ($data['id'] ?? substr($config_name, strlen('webform.webform.')));
    $handlers = (array) ($data['handlers'] ?? []);

    return [
      'config_name' => $config_name,
      'id' => $id,
      'title' => (string) ($data['title'] ?? $id),
      'status' => (string) ($data['status'] ?? ''),
      'element_keys' => $this->elementKeys($data['elements'] ?? []),
      'handler_summaries' => $this->handlerSummaries($handlers),
      'handler_risk_signals' => $this->handlerRiskSignals($handlers),
      'access_keys' => array_values(array_slice(array_keys((array) ($data['access'] ?? [])), 0, 10)),
    ];
  }

  /**
   * Extracts top-level Webform element keys.
   *
   * @return string[]
   *   Element keys.
   */
  private function elementKeys(mixed $elements): array {
    if (is_string($elements) && trim($elements) !== '') {
      try {
        $elements = Yaml::decode($elements) ?: [];
      }
      catch (\Throwable) {
        return [];
      }
    }
    if (!is_array($elements)) {
      return [];
    }
    $keys = array_values(array_map('strval', array_keys($elements)));
    sort($keys, SORT_STRING);
    return array_slice($keys, 0, 20);
  }

  /**
   * Summarizes Webform handlers without raw handler configuration.
   *
   * @param array<string, mixed> $handlers
   *   Raw handler config.
   *
   * @return array<int, array<string, mixed>>
   *   Handler summaries.
   */
  private function handlerSummaries(array $handlers): array {
    $summaries = [];
    foreach ($handlers as $id => $handler) {
      if (!is_array($handler)) {
        continue;
      }
      $summaries[] = [
        'id' => (string) $id,
        'label' => (string) ($handler['label'] ?? $id),
        'plugin' => (string) ($handler['id'] ?? $handler['plugin'] ?? ''),
        'status' => (bool) ($handler['status'] ?? TRUE),
      ];
      if (count($summaries) >= 12) {
        break;
      }
    }
    return $summaries;
  }

  /**
   * Flags handlers that may send data or affect submission storage.
   *
   * @param array<string, mixed> $handlers
   *   Raw handler config.
   *
   * @return string[]
   *   Risk signals.
   */
  private function handlerRiskSignals(array $handlers): array {
    $haystack = strtolower(json_encode($handlers) ?: '');
    $signals = [];
    foreach ([
      'email',
      'mail',
      'remote',
      'post',
      'http',
      'webhook',
      'crm',
      'submission',
    ] as $term) {
      if (str_contains($haystack, $term)) {
        $signals[] = $term;
      }
    }
    return $signals;
  }

}
