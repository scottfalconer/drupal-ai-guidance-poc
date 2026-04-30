<?php

declare(strict_types=1);

namespace Drupal\ai_guidance_ai_context\Source;

use Drupal\ai_guidance\Source\GuidanceSourceProviderInterface;
use Drupal\ai_guidance\Source\GuidanceTextNormalizer;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceSource;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Provides Context Control Center items as read-only guidance sources.
 */
final class AiContextSourceProvider implements GuidanceSourceProviderInterface {

  /**
   * Constructs the source provider.
   *
   * The selector is intentionally untyped so ai_guidance has no hard compile
   * coupling to CCC classes outside this optional bridge module.
   */
  public function __construct(
    private readonly object $selector,
    private readonly ?object $siteArchitectureRepository = NULL,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getSources(GuidanceRequest $request, GuidanceState $state): iterable {
    yield from $this->getContextSources($request, $state);
    yield from $this->getSiteArchitectureSources($request, $state);
  }

  /**
   * Gets selected Context Control Center sources.
   */
  private function getContextSources(GuidanceRequest $request, GuidanceState $state): iterable {
    if (!method_exists($this->selector, 'select')) {
      return;
    }

    $entity = $state->get('entity', []);
    $current_entity = NULL;
    if (!empty($entity['type']) && !empty($entity['id'])) {
      $current_entity = [
        'entity_type' => $entity['type'],
        'entity_id' => $entity['id'],
      ];
    }

    try {
      if (class_exists('\Drupal\ai_context\Model\AiContextRequest')) {
        $selection_request = new \Drupal\ai_context\Model\AiContextRequest(
          task: $request->question,
          scopeSubscriptions: [],
          alwaysInclude: [],
          neverInclude: [],
          consumerId: 'ai_guidance',
          entityType: $current_entity['entity_type'] ?? NULL,
          entityId: $current_entity['entity_id'] ?? NULL,
          maxItems: 5,
          maxTokens: 1200,
          selectionMode: \Drupal\ai_context\Model\AiContextRequest::SELECTION_MODE_MATCH_ALL,
        );
        $selection = $this->selector->select($selection_request);
        $text = GuidanceTextNormalizer::normalize($selection->getRenderedText());
        $ids = array_values($selection->getSelectedItemIds());
        $cacheability = [
          'contexts' => $selection->getCacheContexts(),
          'tags' => $selection->getCacheTags(),
          'max_age' => $selection->getCacheMaxAge(),
        ];
        $token_estimate = $selection->getTokensUsed();
      }
      else {
        $selection = $this->selector->select($request->question, '', [], 5, $current_entity);
        $text = GuidanceTextNormalizer::normalize((string) ($selection['text'] ?? ''));
        $ids = array_values((array) ($selection['ids'] ?? []));
        $cacheability = [];
        $token_estimate = GuidanceSource::estimateTokens($text);
      }
    }
    catch (\Throwable) {
      return;
    }
    if ($text === '' || $ids === []) {
      return;
    }

    yield new GuidanceSource(
      id: 'ccc_context:' . implode(',', $ids),
      canonicalId: 'ccc_context.' . implode('.', $ids),
      title: 'Context Control Center guidance',
      type: 'ccc_context_item',
      text: $text,
      priority: 70,
      citations: [
        'ai_context_item_ids' => $ids,
      ],
      metadata: [
        'scope' => 'selected_by_ccc',
        'entity_target' => $current_entity,
        'always_include_or_selected' => 'selected',
        'access_result' => 'access_checked_by_ai_context_selector',
        'source_class' => 'ccc_context',
      ],
      accessNotes: ['CCC selector applied access checks and target scoping.'],
      cacheability: $cacheability,
      tokenEstimate: $token_estimate,
    );
  }

  /**
   * Gets generated site architecture context when the optional module exists.
   */
  private function getSiteArchitectureSources(GuidanceRequest $request, GuidanceState $state): iterable {
    $repository = $this->siteArchitectureRepository;
    if (!$repository || !method_exists($repository, 'list')) {
      return;
    }

    try {
      $contracts = $repository->list(FALSE);
    }
    catch (\Throwable) {
      return;
    }
    if (!is_array($contracts) || $contracts === []) {
      return;
    }

    $route = $state->get('route', []);
    $path = (string) ($route['path'] ?? '');
    $matching_path = array_values(array_filter($contracts, static function (array $contract) use ($path): bool {
      return $path !== ''
        && (($contract['path'] ?? NULL) === $path || ($contract['path_pattern'] ?? NULL) === $path);
    }));

    $selected_contract_ids = [];
    foreach (array_slice($matching_path, 0, 2) as $contract) {
      $selected_contract_ids[] = $this->contractStableId($contract);
      yield $this->contractSource($this->contractPayload($contract), 'Current page site architecture', 90);
    }

    $public_contracts = array_values(array_filter($contracts, static function (array $contract): bool {
      $contract_path = (string) ($contract['path'] ?? $contract['path_pattern'] ?? '');
      if ($contract_path === '' || str_starts_with($contract_path, '/admin')) {
        return FALSE;
      }
      $audience = array_map('strval', (array) ($contract['audience']['tags'] ?? $contract['audience'] ?? []));
      return in_array('public_candidate', $audience, TRUE) || in_array('content_agent', $audience, TRUE);
    }));

    $query_matches = $this->matchingContracts($public_contracts, $request->question);
    $matched_count = 0;
    foreach ($query_matches as $contract) {
      if (in_array($this->contractStableId($contract), $selected_contract_ids, TRUE)) {
        continue;
      }
      yield $this->contractSource($this->contractPayload($contract), 'Relevant site architecture', 78);
      $matched_count++;
      if ($matched_count >= 3) {
        break;
      }
    }

    if ($public_contracts !== []) {
      $surface_index_text = $this->surfaceIndexText($public_contracts);
      yield new GuidanceSource(
        id: 'site_architecture:surface_index',
        canonicalId: 'site_architecture.surface_index',
        title: 'Generated site architecture surface index',
        type: 'site_architecture_context',
        text: $surface_index_text,
        priority: 68,
        citations: [
          'source' => 'ai_context_site_architecture.contract_repository',
        ],
        metadata: [
          'scope' => 'generated_site_architecture',
          'source_class' => 'site_architecture',
          'surface_count' => count($public_contracts),
        ],
        accessNotes: ['Generated from read-only site architecture contracts.'],
        tokenEstimate: GuidanceSource::estimateTokens($surface_index_text),
      );
    }
  }

  /**
   * Finds contracts whose labels, paths, or source data match the question.
   *
   * @param array<int, array<string, mixed>> $contracts
   *   Site behavior contracts.
   *
   * @return array<int, array<string, mixed>>
   *   Matching contracts.
   */
  private function matchingContracts(array $contracts, string $question): array {
    $terms = preg_split('/[^a-z0-9_]+/', strtolower($question)) ?: [];
    $stop = ['the', 'and', 'for', 'with', 'how', 'what', 'can', 'this', 'that', 'you', 'your', 'drupal', 'site'];
    $terms = array_values(array_filter($terms, static fn(string $term): bool => strlen($term) > 2 && !in_array($term, $stop, TRUE)));

    $matches = [];
    foreach ($contracts as $contract) {
      $haystack = strtolower(implode(' ', [
        $contract['label'] ?? '',
        $contract['path'] ?? '',
        $contract['path_pattern'] ?? '',
        $contract['contract_id'] ?? '',
        $this->scalarOrType($contract['content_source'] ?? NULL),
        $this->scalarOrType($contract['semantic_surface'] ?? NULL),
      ]));
      $score = 0;
      foreach ($terms as $term) {
        if (str_contains($haystack, $term)) {
          $score++;
        }
      }
      if ($score > 0) {
        $matches[] = ['score' => $score, 'contract' => $contract];
      }
    }

    usort($matches, static fn(array $a, array $b): int => ($b['score'] <=> $a['score'])
      ?: strcmp((string) ($a['contract']['contract_id'] ?? ''), (string) ($b['contract']['contract_id'] ?? '')));

    return array_map(static fn(array $match): array => $match['contract'], $matches);
  }

  /**
   * Builds a guidance source for one contract.
   *
   * @param array<string, mixed> $contract
   *   Site behavior contract.
   */
  private function contractSource(array $contract, string $title_prefix, int $priority): GuidanceSource {
    $id = (string) ($contract['contract_id'] ?? hash('sha256', json_encode($contract) ?: 'site_architecture'));
    $path = (string) ($contract['path'] ?? $contract['path_pattern'] ?? 'unknown path');
    $text = $this->contractText($contract);

    return new GuidanceSource(
      id: 'site_architecture:' . $id,
      canonicalId: 'site_architecture.' . preg_replace('/[^a-z0-9_]+/', '_', strtolower($id)),
      title: $title_prefix . ': ' . $path,
      type: 'site_architecture_context',
      text: $text,
      priority: $priority,
      citations: [
        'contract_id' => $id,
        'resource_uri' => $contract['resource_uri'] ?? NULL,
      ],
      metadata: [
        'scope' => 'generated_site_architecture',
        'source_class' => 'site_architecture',
        'path' => $path,
        'contract_type' => $contract['contract_type'] ?? NULL,
        'schema_version' => $contract['schema_version'] ?? NULL,
        'source_hash' => $contract['source_hash'] ?? NULL,
        'confidence' => $contract['confidence']['level'] ?? NULL,
        'audience' => $contract['audience']['tags'] ?? $contract['audience'] ?? [],
        'projection_source' => 'ai_context_site_architecture.contract_repository',
        'preserves_operating_contract_fields' => TRUE,
      ],
      accessNotes: ['Generated from read-only site behavior contracts; includes operating-contract fields when present.'],
      tokenEstimate: GuidanceSource::estimateTokens($text),
    );
  }

  /**
   * Gets a stable identifier for de-duplicating contract selections.
   *
   * @param array<string, mixed> $contract
   *   Site behavior contract.
   */
  private function contractStableId(array $contract): string {
    return (string) ($contract['contract_id'] ?? $contract['id'] ?? $contract['resource_uri'] ?? hash('sha256', json_encode($contract) ?: 'site_architecture'));
  }

  /**
   * Gets the repository's default structured payload when available.
   *
   * @param array<string, mixed> $contract
   *   Contract from the repository list.
   *
   * @return array<string, mixed>
   *   Default payload or the original contract.
   */
  private function contractPayload(array $contract): array {
    $repository = $this->siteArchitectureRepository;
    if (!$repository) {
      return $contract;
    }

    try {
      $contract_id = (string) ($contract['contract_id'] ?? '');
      if ($contract_id !== '' && method_exists($repository, 'getPayload')) {
        $payload = $repository->getPayload($contract_id, FALSE);
        if (is_array($payload)) {
          return $payload;
        }
      }

      $resource_uri = (string) ($contract['resource_uri'] ?? '');
      if ($resource_uri !== '' && method_exists($repository, 'getPayloadByResourceUri')) {
        $payload = $repository->getPayloadByResourceUri($resource_uri, FALSE);
        if (is_array($payload)) {
          return $payload;
        }
      }
    }
    catch (\Throwable) {
      return $contract;
    }

    return $contract;
  }

  /**
   * Builds compact text for one generated contract.
   *
   * @param array<string, mixed> $contract
   *   Site behavior contract.
   */
  private function contractText(array $contract): string {
    $content_source = $contract['content_source'] ?? [];
    $audience = (array) ($contract['audience']['tags'] ?? $contract['audience'] ?? []);
    $resource_uri = (string) ($contract['resource_uri'] ?? '');
    $lines = [
      '# Site architecture contract: ' . ($contract['path'] ?? $contract['path_pattern'] ?? 'unknown path'),
      '',
      '- Contract ID: `' . ($contract['contract_id'] ?? 'unknown') . '`',
      '- Schema version: `' . ($contract['schema_version'] ?? 'unknown') . '`',
      '- Contract type: `' . ($contract['contract_type'] ?? 'unknown') . '`',
      $resource_uri !== '' ? '- Resource URI: `' . $resource_uri . '`' : NULL,
      '- Effective responder: `' . $this->scalarOrType($contract['effective_responder'] ?? NULL) . '`',
      '- Semantic surface: `' . $this->scalarOrType($contract['semantic_surface'] ?? NULL) . '`',
      '- Content source: ' . $this->contentSourceText($content_source),
      '- Audience tags: `' . implode('`, `', array_map('strval', $audience)) . '`',
      '- Confidence: `' . $this->scalarOrType($contract['confidence'] ?? NULL) . '`',
    ];
    $lines = array_values(array_filter($lines, static fn ($line): bool => $line !== NULL));

    if (!empty($contract['page_composition']['component_count'])) {
      $lines[] = '- Canvas page composition: `' . $contract['page_composition']['component_count'] . '` component(s)';
    }
    if (!empty($contract['actionability_gaps'])) {
      $lines[] = '- Actionability gaps detected: `' . count($contract['actionability_gaps']) . '`';
    }
    if (!empty($contract['action_owners']) && is_array($contract['action_owners'])) {
      $lines[] = '';
      $lines[] = '## Action owners';
      foreach ($this->summarizeActionOwners($contract['action_owners']) as $summary) {
        $lines[] = '- ' . $summary;
      }
    }
    if (!empty($contract['negative_contracts']) && is_array($contract['negative_contracts'])) {
      $lines[] = '';
      $lines[] = '## Negative contracts';
      foreach ($this->compactStrings($contract['negative_contracts'], 4) as $negative_contract) {
        $lines[] = '- ' . $negative_contract;
      }
    }
    if (!empty($contract['validation']['checks']) && is_array($contract['validation']['checks'])) {
      $lines[] = '';
      $lines[] = '## Validation checks';
      foreach ($this->summarizeValidationChecks($contract['validation']['checks']) as $summary) {
        $lines[] = '- ' . $summary;
      }
    }
    if (!empty($contract['known_unknowns']) && is_array($contract['known_unknowns'])) {
      $lines[] = '';
      $lines[] = '## Known unknowns';
      foreach ($this->summarizeKnownUnknowns($contract['known_unknowns']) as $summary) {
        $lines[] = '- ' . $summary;
      }
    }
    if (!empty($contract['provenance']) && is_array($contract['provenance'])) {
      $lines[] = '';
      $lines[] = '## Provenance';
      foreach ($this->compactStrings($contract['provenance'], 5) as $provenance) {
        $lines[] = '- `' . $provenance . '`';
      }
    }
    if (!empty($contract['source_hash'])) {
      $lines[] = '';
      $lines[] = '- Source hash: `' . $contract['source_hash'] . '`';
    }

    return implode("\n", $lines);
  }

  /**
   * Builds compact surface index text.
   *
   * @param array<int, array<string, mixed>> $contracts
   *   Public site behavior contracts.
   */
  private function surfaceIndexText(array $contracts): string {
    $lines = [
      '# Generated site architecture surface index',
      '',
      'These generated contracts summarize public or content-agent-relevant Drupal surfaces.',
      '',
    ];

    foreach (array_slice($contracts, 0, 12) as $contract) {
      $content_source = $contract['content_source'] ?? [];
      $source = (string) ($content_source['entity_type'] ?? 'unknown');
      if (!empty($content_source['bundle'])) {
        $source .= ':' . $content_source['bundle'];
      }
      $lines[] = '- `' . ($contract['path'] ?? $contract['path_pattern'] ?? 'unknown path') . '`: '
        . '`' . ($contract['contract_type'] ?? 'unknown') . '`, source `' . $source . '`, responder `'
        . $this->scalarOrType($contract['effective_responder'] ?? NULL) . '`';
    }

    return implode("\n", $lines);
  }

  /**
   * Builds compact content source text.
   *
   * @param array<string, mixed>|mixed $content_source
   *   Content source data.
   */
  private function contentSourceText(mixed $content_source): string {
    if (!is_array($content_source)) {
      return '`' . $this->scalarOrType($content_source) . '`';
    }
    $text = '`' . ($content_source['entity_type'] ?? 'unknown') . '`';
    if (!empty($content_source['bundle'])) {
      return $text . ' bundle `' . $content_source['bundle'] . '`';
    }
    if (!empty($content_source['bundles']) && is_array($content_source['bundles'])) {
      return $text . ' bundles `' . implode('`, `', $this->compactStrings($content_source['bundles'], 8)) . '`';
    }
    return $text;
  }

  /**
   * Summarizes action owners.
   *
   * @param array<string, mixed> $action_owners
   *   Contract action owner map.
   *
   * @return string[]
   *   Human-readable summaries.
   */
  private function summarizeActionOwners(array $action_owners): array {
    $summaries = [];
    foreach (array_slice($action_owners, 0, 6, TRUE) as $intent => $owner) {
      if (!is_array($owner)) {
        $summaries[] = '`' . (string) $intent . '`: ' . $this->scalarOrType($owner);
        continue;
      }
      $parts = [];
      foreach (['owner', 'action', 'reason'] as $key) {
        if (!empty($owner[$key]) && is_scalar($owner[$key])) {
          $parts[] = $key . '=' . (string) $owner[$key];
        }
      }
      if (!empty($owner['allowed_bundles']) && is_array($owner['allowed_bundles'])) {
        $parts[] = 'allowed_bundles=' . implode(',', $this->compactStrings($owner['allowed_bundles'], 6));
      }
      $summaries[] = '`' . (string) $intent . '`: ' . ($parts !== [] ? implode('; ', $parts) : 'declared');
    }
    return $summaries;
  }

  /**
   * Summarizes validation checks.
   *
   * @param array<int, mixed> $checks
   *   Validation checks.
   *
   * @return string[]
   *   Human-readable summaries.
   */
  private function summarizeValidationChecks(array $checks): array {
    $summaries = [];
    foreach (array_slice($checks, 0, 6) as $check) {
      if (!is_array($check)) {
        $summaries[] = $this->scalarOrType($check);
        continue;
      }
      $parts = [];
      $summary_keys = [
        'type',
        'entity_type',
        'bundle',
        'view_id',
        'display_id',
        'path',
        'expected',
        'expected_status',
      ];
      foreach ($summary_keys as $key) {
        if (isset($check[$key]) && is_scalar($check[$key])) {
          $parts[] = $key . '=' . (string) $check[$key];
        }
      }
      $summaries[] = $parts !== [] ? implode('; ', $parts) : 'validation check';
    }
    return $summaries;
  }

  /**
   * Summarizes known unknowns.
   *
   * @param array<int, mixed> $known_unknowns
   *   Known unknown records.
   *
   * @return string[]
   *   Human-readable summaries.
   */
  private function summarizeKnownUnknowns(array $known_unknowns): array {
    $summaries = [];
    foreach (array_slice($known_unknowns, 0, 5) as $known_unknown) {
      if (!is_array($known_unknown)) {
        $summaries[] = $this->scalarOrType($known_unknown);
        continue;
      }
      $summary = (string) ($known_unknown['type'] ?? 'unknown');
      foreach (['message', 'reason', 'guidance'] as $key) {
        if (!empty($known_unknown[$key]) && is_scalar($known_unknown[$key])) {
          $summary .= ': ' . (string) $known_unknown[$key];
          break;
        }
      }
      $summaries[] = $summary;
    }
    return $summaries;
  }

  /**
   * Converts scalar list values to strings and caps the result.
   *
   * @param array<int, mixed> $values
   *   Values to convert.
   *
   * @return string[]
   *   String values.
   */
  private function compactStrings(array $values, int $limit): array {
    $strings = [];
    foreach ($values as $value) {
      if (is_scalar($value) && (string) $value !== '') {
        $strings[] = (string) $value;
      }
      if (count($strings) >= $limit) {
        break;
      }
    }
    return $strings;
  }

  /**
   * Formats scalar values or typed arrays from site architecture contracts.
   */
  private function scalarOrType(mixed $value): string {
    if (is_array($value)) {
      return (string) ($value['type'] ?? $value['level'] ?? 'array');
    }
    return (string) ($value ?? 'unknown');
  }

}
