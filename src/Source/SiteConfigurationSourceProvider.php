<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Source;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceSource;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Provides a compact, access-safe summary of site configuration.
 */
final class SiteConfigurationSourceProvider implements GuidanceSourceProviderInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?AliasManagerInterface $aliasManager = NULL,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getSources(GuidanceRequest $request, GuidanceState $state): iterable {
    try {
      $site = $this->configFactory->get('system.site');
      $system_theme = $this->configFactory->get('system.theme');
      $full_summary = $this->canUseFullConfigurationSummary($request);
      $node_types = $this->summarizeNodeTypes($full_summary);
      if (!$full_summary) {
        $node_types = $this->filterNodeTypesForCurrentUser($node_types, $request);
      }
      $first_exercise = $this->beginnerQuestion($request->question) ? $this->firstSafeExerciseType($node_types) : NULL;
      $site_terms = $this->siteTerms();
      $front_page_path = (string) ($site->get('page.front') ?? '/');
      $front_page_public_path = $this->publicPath($front_page_path);
      $views = $full_summary ? $this->summarizeViews($request->question, $site_terms, [
        $front_page_path,
        $front_page_public_path,
      ]) : [];
      $workflows = $full_summary ? $this->summarizeWorkflows() : [];
      $front_page_question = $this->frontPageQuestion($request->question);
      $canvas = $full_summary && !$front_page_question ? $this->summarizeCanvasComponents($request->question, $site_terms) : [];
      $front_page = $full_summary
        ? $this->summarizeFrontPage($front_page_path, $request, $node_types, $views)
        : $this->limitedFrontPageSummary($front_page_path, $request);
    }
    catch (\Throwable) {
      return;
    }

    $lines = [
      $full_summary ? '# Safe site configuration summary' : '# Limited site configuration summary',
      '',
      $full_summary
        ? 'This is fallback site inventory for guidance. It is not an authoritative site behavior contract.'
        : 'The current account lacks site-configuration administration permission. This source omits Views, theme, Canvas internals, and other admin-derived configuration.',
      '',
      '- Site name: `' . ((string) ($site->get('name') ?? 'Drupal')) . '`',
      $full_summary
        ? '- Front page: `' . $front_page_public_path . '`' . ($front_page_public_path !== $front_page_path ? ' (configured internal path `' . $front_page_path . '`)' : '') . '.'
        : '- Front page: `' . $front_page_public_path . '`.',
      '',
    ];

    if ($full_summary) {
      $lines[] = '- Default theme: `' . ((string) ($system_theme->get('default') ?? 'unknown')) . '`';
      $lines[] = '';
    }

    if ($node_types !== []) {
      $lines[] = $full_summary ? '## Content model' : '## Content types available to the current account';
      foreach ($node_types as $type) {
        $lines[] = '- `' . $type['id'] . '`: ' . $type['label']
          . ($type['description'] !== '' ? ' - ' . $type['description'] : '')
          . (!empty($type['access_summary']) ? ' Current account: ' . $type['access_summary'] . '.' : '');
        if ($full_summary && !empty($type['fields'])) {
          $lines[] = '  - Fields: ' . implode('; ', array_map([$this, 'formatFieldSummary'], $type['fields'])) . '.';
        }
      }
      $lines[] = '';
    }

    if ($first_exercise !== NULL) {
      $lines[] = '## Beginner first exercise';
      $lines[] = '- Suggested first safe exercise: create exactly one draft `' . $first_exercise['label'] . '` item at `/node/add/' . $first_exercise['id'] . '`.';
      $lines[] = '- Success criteria: the item is saved as a draft or unpublished item, appears in `/admin/content`, can be previewed, and does not need to appear on the public front page.';
      if (!empty($first_exercise['required_fields'])) {
        $lines[] = '- Required fields to fill first: `' . implode('`, `', $first_exercise['required_fields']) . '`.';
      }
      $lines[] = '- Do not list alternative first exercises unless the user asks for options.';
      $lines[] = '';
    }

    if ($front_page !== []) {
      $lines[] = '## Front page';
      $lines[] = '- Public front page path: `' . $front_page['public_path'] . '`.';
      if ($full_summary && $front_page['public_path'] !== $front_page['path']) {
        $lines[] = '- Configured internal front page path: `' . $front_page['path'] . '`. Use the public path in user-facing verification steps.';
      }
      elseif ($full_summary) {
        $lines[] = '- Configured front page path: `' . $front_page['path'] . '`.';
      }
      if (!empty($front_page['entity_type'])) {
        $lines[] = '- Front page entity: `' . $front_page['entity_type'] . ':' . $front_page['entity_id'] . '`'
          . ($front_page['label'] !== '' ? ' "' . $front_page['label'] . '"' : '')
          . ($front_page['status'] !== '' ? ' (' . $front_page['status'] . ')' : '') . '.';
      }
      if (!empty($front_page['bundle'])) {
        $lines[] = '- Front page bundle: `' . $front_page['bundle'] . '`.';
      }
      if (!empty($front_page['edit_path'])) {
        $lines[] = '- Front page edit path for users with access: `' . $front_page['edit_path'] . '`.';
      }
      if (!empty($front_page['description'])) {
        $lines[] = '- Front page description: ' . $front_page['description'];
      }
      if (!empty($front_page['resolution'])) {
        $lines[] = '- Front page resolution: ' . $front_page['resolution'];
      }
      if (!empty($front_page['composition'])) {
        $lines[] = '- Front page composition: ' . $front_page['composition'];
      }
      if (!empty($front_page['inspection_paths'])) {
        $lines[] = '- Front page inspection paths for administrators/site builders: `' . implode('`, `', $front_page['inspection_paths']) . '`.';
      }
      if (!empty($front_page['components'])) {
        $lines[] = '- Front page components:';
        foreach ($front_page['components'] as $component) {
          $label = $component['label'] !== '' ? $component['label'] : $component['component_id'];
          $lines[] = '  - ' . $label . ' (`' . $component['component_id'] . '`)';
        }
      }
      if (!empty($front_page['referenced_sources'])) {
        $lines[] = '- Front page referenced sources: ' . implode('; ', $front_page['referenced_sources']) . '.';
      }
      if (!empty($front_page['listed_content_signals'])) {
        $lines[] = '- Front page listed content signals: ' . implode(', ', $front_page['listed_content_signals']) . '.';
      }
      if (!empty($front_page['guidance'])) {
        foreach ($front_page['guidance'] as $guidance) {
          $lines[] = '- Front page implication: ' . $guidance;
        }
      }
      $lines[] = '';
    }

    if ($views !== []) {
      $lines[] = '## Views and content listings';
      foreach ($views as $view) {
        $line = '- `' . $view['id'] . '`: ' . $view['label']
          . ($view['description'] !== '' ? ' - ' . $view['description'] : '')
          . ($view['base_table'] !== '' ? ' Base: `' . $view['base_table'] . '`.' : '')
          . ($view['paths'] !== [] ? ' Paths: `' . implode('`, `', $view['paths']) . '`.' : '')
          . ($view['blocks'] !== [] ? ' Blocks: `' . implode('`, `', $view['blocks']) . '`.' : '');
        if ($view['filters'] !== []) {
          $line .= ' Filters: ' . implode('; ', $view['filters']) . '.';
        }
        if ($view['sorts'] !== []) {
          $line .= ' Sorts: ' . implode('; ', $view['sorts']) . '.';
        }
        if ($view['access'] !== []) {
          $line .= ' Access: ' . implode('; ', $view['access']) . '.';
        }
        $lines[] = $line;
      }
      $lines[] = '';
    }

    if ($workflows !== []) {
      $lines[] = '## Workflows and moderation';
      foreach ($workflows as $workflow) {
        $lines[] = '- `' . $workflow['id'] . '`: ' . $workflow['label']
          . ($workflow['type'] !== '' ? ' (`' . $workflow['type'] . '`)' : '')
          . ($workflow['bundles'] !== [] ? ' Bundles: `' . implode('`, `', $workflow['bundles']) . '`.' : '')
          . ($workflow['states'] !== [] ? ' States: ' . implode(', ', $workflow['states']) . '.' : '')
          . ($workflow['transitions'] !== [] ? ' Transitions: ' . implode('; ', $workflow['transitions']) . '.' : '');
      }
      $lines[] = '';
    }

    if ($canvas !== []) {
      $lines[] = '## Canvas and design-system components';
      foreach ($canvas as $component) {
        $lines[] = '- `' . $component['id'] . '`: ' . $component['label']
          . ($component['source'] !== '' ? ' (`' . $component['source'] . '`)' : '');
      }
    }

    $text = GuidanceTextNormalizer::normalize(implode("\n", $lines));
    yield new GuidanceSource(
      id: 'site_configuration:summary',
      canonicalId: 'site_configuration.summary',
      title: 'Safe site configuration summary',
      type: 'site_configuration_summary',
      text: $text,
      priority: 55,
      citations: [
        'source' => 'active Drupal configuration summary',
        'url' => '/admin/config/ai/guidance/debug',
      ],
      metadata: [
        'scope' => 'safe_configuration_summary',
        'source_class' => 'safe_configuration_summary',
        'authoritative_contract_source' => FALSE,
        'relationship_to_site_architecture' => 'fallback_inventory_not_behavior_contract',
        'exposure_level' => $full_summary ? 'full_for_site_configuration_admin' : 'limited_for_current_account',
        'node_type_count' => count($node_types),
        'view_count' => count($views),
        'workflow_count' => count($workflows),
        'canvas_component_count' => count($canvas),
      ],
      accessNotes: [
        $full_summary
          ? 'Fallback configuration inventory only; prefer generated site behavior contracts when ai_context_site_architecture is available.'
          : 'Limited summary because the current account lacks permission to administer site configuration.',
      ],
      cacheability: [
        'tags' => [
          'config:system.site',
          'config:system.theme',
          'config:node_type_list',
          'config:views_view_list',
          'config:canvas_component_list',
        ],
      ],
      tokenEstimate: GuidanceSource::estimateTokens($text),
    );
  }

  /**
   * Determines whether the question is specifically about front-page placement.
   */
  private function frontPageQuestion(string $question): bool {
    $question = strtolower($question);
    foreach ([
      'front page',
      'homepage',
      'home page',
      'shown on the front',
      'shown on front',
      'items shown',
      'add it to the items',
    ] as $needle) {
      if (str_contains($question, $needle)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Determines whether the question asks for a beginner exercise.
   */
  private function beginnerQuestion(string $question): bool {
    $question = strtolower($question);
    if (str_contains($question, 'outside') || str_contains($question, 'agent') || str_contains($question, 'code')) {
      return FALSE;
    }

    foreach ([
      'new to drupal',
      'new builder',
      'beginner',
      'first safe exercise',
      'first exercise',
      'what should i learn',
      'safe exercise',
    ] as $needle) {
      if (str_contains($question, $needle)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks whether the current account can receive admin-derived config.
   */
  private function canUseFullConfigurationSummary(GuidanceRequest $request): bool {
    $account = $request->account;
    if (!$account instanceof AccountInterface) {
      return FALSE;
    }

    foreach ([
      'administer ai guidance',
      'administer ai',
      'administer ai_assistant',
      'administer site configuration',
    ] as $permission) {
      if ($account->hasPermission($permission)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Keeps only content types the current account can work with.
   *
   * @param array<int, array{id: string, label: string, description: string}> $node_types
   *   Node type summaries.
   * @param \Drupal\ai_guidance\Value\GuidanceRequest $request
   *   Guidance request.
   *
   * @return array<int, array{id: string, label: string, description: string, access_summary?: string}>
   *   Node type summaries with current-account access notes.
   */
  private function filterNodeTypesForCurrentUser(array $node_types, GuidanceRequest $request): array {
    $account = $request->account;
    if (!$account instanceof AccountInterface) {
      return [];
    }

    $visible = [];
    foreach ($node_types as $type) {
      $id = (string) ($type['id'] ?? '');
      if ($id === '') {
        continue;
      }

      $allowed = [];
      if ($account->hasPermission('administer nodes')) {
        $allowed[] = 'administer content';
      }
      foreach ([
        'create' => "create $id content",
        'edit own' => "edit own $id content",
        'edit any' => "edit any $id content",
        'delete own' => "delete own $id content",
        'delete any' => "delete any $id content",
      ] as $label => $permission) {
        if ($account->hasPermission($permission)) {
          $allowed[] = $label;
        }
      }

      if ($allowed === []) {
        continue;
      }

      $type['access_summary'] = implode(', ', $allowed);
      $visible[] = $type;
    }

    return $visible;
  }

  /**
   * Returns a front-page summary for users without site-config access.
   *
   * @return array<string, mixed>
   *   Limited safe front-page summary.
   */
  private function limitedFrontPageSummary(string $front_path, GuidanceRequest $request): array {
    $summary = [
      'path' => $front_path !== '' ? $front_path : '/',
      'public_path' => $this->publicPath($front_path),
      'guidance' => [],
    ];

    if ($this->frontPageQuestion($request->question)) {
      $summary['resolution'] = 'Configured front page path is known, but this account cannot inspect admin-derived front-page architecture.';
      $summary['guidance'][] = 'The current account cannot inspect Views, Canvas composition, block layout, or route ownership for the front page.';
      $summary['guidance'][] = 'Ask a site builder to identify whether the front page is controlled by Canvas, a View, a block, a menu, a node body, or custom route before recommending a placement method.';
      $summary['guidance'][] = 'Do not assume the generic "Promoted to front page" checkbox controls front-page placement without that site-builder confirmation.';
    }

    return $summary;
  }

  /**
   * Summarizes the configured front page without exposing raw content bodies.
   *
   * @return array<string, mixed>
   *   Safe front-page summary.
   */
  private function summarizeFrontPage(string $front_path, GuidanceRequest $request, array $node_types, array $views): array {
    $summary = [
      'path' => $front_path !== '' ? $front_path : '/',
      'public_path' => $this->publicPath($front_path),
      'guidance' => [],
    ];

    if (preg_match('#^/page/(\d+)$#', $front_path, $matches) && $this->entityTypeManager->hasDefinition('canvas_page')) {
      $entity = $this->entityTypeManager->getStorage('canvas_page')->load($matches[1]);
      if ($entity instanceof EntityInterface && $this->entityViewAllowed($entity, $request)) {
        $summary += $this->summarizeEntity($entity);
        $summary['edit_path'] = $this->entityEditPath($entity, $request);
        $summary['composition'] = 'Canvas page composed from component-tree entries.';
        $summary['components'] = $this->summarizeCanvasPageComponents($entity);
        $summary['listed_content_signals'] = $this->frontPageContentSignals($summary['components'], $node_types, $views);
        $summary['referenced_sources'] = $this->summarizeEntityReferenceFields($entity);
        $summary['guidance'][] = 'A newly created node appears on this front page only when a Canvas component or its underlying query selects it.';
        $summary['guidance'][] = 'To add a standalone link or card, open the front page edit path when available, then add or edit the relevant Canvas component by label.';
        $summary['guidance'][] = 'For verification steps, send users to the public front page path `' . $summary['public_path'] . '`, not the configured internal path when those differ.';
        $summary['guidance'][] = 'The generic "Promoted to front page" checkbox does not by itself change this Canvas page composition.';
        if ($summary['edit_path'] === '') {
          $summary['guidance'][] = 'No front page edit path is exposed for this user in the safe summary; phrase edits as a site-builder request.';
        }
        if ($this->hasNodeType($node_types, 'page') && !in_array('utility pages', $summary['listed_content_signals'], TRUE)) {
          $summary['guidance'][] = 'The content model describes `page` as a utility/static page type, and the current front-page component labels do not indicate a utility-page listing.';
        }
      }
      return $summary;
    }

    if (preg_match('#^/node/(\d+)$#', $front_path, $matches) && $this->entityTypeManager->hasDefinition('node')) {
      $entity = $this->entityTypeManager->getStorage('node')->load($matches[1]);
      if ($entity instanceof EntityInterface && $this->entityViewAllowed($entity, $request)) {
        $summary += $this->summarizeEntity($entity);
        $summary['edit_path'] = $this->entityEditPath($entity, $request);
        $summary['guidance'][] = 'This site uses a specific node as the front page.';
        $summary['guidance'][] = 'Other newly created content will not automatically appear there unless the node body, an embedded component, or a referenced listing is edited.';
      }
      return $summary;
    }

    foreach ($views as $view) {
      if (in_array($summary['path'], $view['paths'] ?? [], TRUE)) {
        $summary['guidance'][] = 'The configured front page path matches a View/listing in this safe configuration summary.';
        $summary['guidance'][] = 'A newly created node appears there only if it satisfies that View display configuration, filters, sorting, and access rules.';
        $summary['guidance'][] = 'Do not assume the generic "Promoted to front page" checkbox is enough unless the View explicitly uses that flag.';
        return $summary;
      }
    }

    if ($this->frontPageQuestion($request->question)) {
      $summary['resolution'] = 'Configured front page path is known, but this safe summary could not identify the owning node, Canvas page, View display, route controller, block, or menu.';
      $summary['inspection_paths'] = [
        '/admin/config/system/site-information',
        '/admin/structure/views',
        '/admin/structure/block',
      ];
      $summary['guidance'][] = 'This safe configuration summary could not resolve the configured front page to a specific node, Canvas page, or matching View.';
      $summary['guidance'][] = 'For this site, do not assume the generic "Promoted to front page" checkbox controls front-page placement.';
      $summary['guidance'][] = 'Say explicitly that the exact front-page item owner cannot be confirmed from the current evidence.';
      $summary['guidance'][] = 'The safe next step is to ask a site builder to inspect the owner of the configured front page path and determine whether it is a Canvas page, View, custom route, menu, or block before recommending a content placement method.';
    }

    return $summary;
  }

  /**
   * Returns the user-facing alias for an internal path when available.
   */
  private function publicPath(string $path): string {
    $path = $path !== '' ? $path : '/';
    if ($this->aliasManager === NULL || $path === '/') {
      return $path;
    }

    try {
      return $this->aliasManager->getAliasByPath($path);
    }
    catch (\Throwable) {
      return $path;
    }
  }

  /**
   * Checks safe view access for an entity summary.
   */
  private function entityViewAllowed(EntityInterface $entity, GuidanceRequest $request): bool {
    $account = $request->account;
    try {
      return $entity->access('view', $account, TRUE)->isAllowed();
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Returns an edit path only when the current user can update the entity.
   */
  private function entityEditPath(EntityInterface $entity, GuidanceRequest $request): string {
    try {
      if (!$entity->access('update', $request->account, TRUE)->isAllowed()) {
        return '';
      }
      if (!$entity->hasLinkTemplate('edit-form')) {
        return '';
      }
      return $entity->toUrl('edit-form')->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Builds an access-safe entity summary.
   *
   * @return array<string, mixed>
   *   Entity summary.
   */
  private function summarizeEntity(EntityInterface $entity): array {
    $description = '';
    if ($entity->hasField('description') && !$entity->get('description')->isEmpty()) {
      $description = GuidanceTextNormalizer::normalize((string) $entity->get('description')->value);
    }
    elseif ($entity->hasField('field_description') && !$entity->get('field_description')->isEmpty()) {
      $description = GuidanceTextNormalizer::normalize((string) $entity->get('field_description')->value);
    }

    $status = '';
    if (method_exists($entity, 'isPublished')) {
      $status = $entity->isPublished() ? 'published' : 'unpublished';
    }

    return [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => (string) $entity->id(),
      'bundle' => method_exists($entity, 'bundle') ? (string) $entity->bundle() : '',
      'label' => (string) $entity->label(),
      'status' => $status,
      'description' => $description,
    ];
  }

  /**
   * Summarizes entity-reference fields on a content entity.
   *
   * @return string[]
   *   Human-readable referenced source summaries.
   */
  private function summarizeEntityReferenceFields(EntityInterface $entity): array {
    if (!$entity instanceof ContentEntityInterface) {
      return [];
    }

    $sources = [];
    foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
      $type = $definition->getType();
      if (!in_array($type, ['entity_reference', 'entity_reference_revisions'], TRUE)) {
        continue;
      }
      $settings = $definition->getSettings();
      $target_type = (string) ($settings['target_type'] ?? '');
      $target_bundles = array_keys((array) ($settings['handler_settings']['target_bundles'] ?? []));
      $label = (string) $definition->getLabel();
      $summary = '`' . $field_name . '`'
        . ($label !== '' ? ' (' . $label . ')' : '')
        . ($target_type !== '' ? ' references `' . $target_type . '`' : '');
      if ($target_bundles !== []) {
        $summary .= ' bundles `' . implode('`, `', $target_bundles) . '`';
      }
      $sources[] = $summary;
    }

    sort($sources, SORT_STRING);
    return array_slice($sources, 0, 8);
  }

  /**
   * Summarizes Canvas page component-tree entries.
   *
   * @return array<int, array{component_id: string, label: string}>
   *   Component summaries.
   */
  private function summarizeCanvasPageComponents(EntityInterface $entity): array {
    if (!$entity->hasField('components') || $entity->get('components')->isEmpty()) {
      return [];
    }

    $components = [];
    foreach ($entity->get('components')->getValue() as $item) {
      $component_id = (string) ($item['component_id'] ?? '');
      if ($component_id === '') {
        continue;
      }
      $components[] = [
        'component_id' => $component_id,
        'label' => GuidanceTextNormalizer::normalize((string) ($item['label'] ?? '')),
      ];
    }

    return array_slice($components, 0, 12);
  }

  /**
   * Infers high-level content signals from front-page component labels.
   *
   * @param array<int, array{component_id: string, label: string}> $components
   *   Component summaries.
   * @param array<int, array<string, mixed>> $node_types
   *   Node type summaries.
   * @param array<int, array<string, mixed>> $views
   *   View summaries.
   *
   * @return string[]
   *   Human-readable signals.
   */
  private function frontPageContentSignals(array $components, array $node_types, array $views): array {
    $signals = [];
    foreach ($components as $component) {
      $haystack = strtolower($component['component_id'] . ' ' . $component['label']);
      foreach ($node_types as $node_type) {
        if ($this->haystackMatchesContentType($haystack, $node_type)) {
          $label = strtolower((string) ($node_type['label'] ?? ''));
          $id = strtolower((string) ($node_type['id'] ?? ''));
          $signals[] = $label !== '' ? $label : $id;
        }
      }
      foreach ($views as $view) {
        $view_haystack = strtolower($view['id'] . ' ' . $view['label'] . ' '
          . implode(' ', $view['paths']) . ' ' . implode(' ', $view['blocks']));
        if (!$this->sharesMeaningfulToken($haystack, $view_haystack)) {
          continue;
        }
        foreach ((array) ($view['bundle_filters'] ?? []) as $bundle) {
          $label = $this->nodeTypeLabel((string) $bundle, $node_types);
          $signals[] = $label !== '' ? strtolower($label) : (string) $bundle;
        }
      }
    }

    return array_values(array_unique(array_filter($signals)));
  }

  /**
   * Checks whether component text matches a content type.
   *
   * @param string $haystack
   *   Text to inspect.
   * @param array<string, mixed> $node_type
   *   Node type summary.
   */
  private function haystackMatchesContentType(string $haystack, array $node_type): bool {
    foreach ($this->contentTypeTerms($node_type) as $term) {
      if ($term !== '' && str_contains($haystack, $term)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns normalized content-type terms with simple singular/plural variants.
   *
   * @param array<string, mixed> $node_type
   *   Node type summary.
   *
   * @return string[]
   *   Terms.
   */
  private function contentTypeTerms(array $node_type): array {
    $source = strtolower(implode(' ', [
      (string) ($node_type['id'] ?? ''),
      (string) ($node_type['label'] ?? ''),
      (string) ($node_type['description'] ?? ''),
    ]));
    $terms = [];
    foreach (preg_split('/[^a-z0-9_]+/', $source) ?: [] as $term) {
      if (strlen($term) < 3) {
        continue;
      }
      $terms[] = $term;
      $terms[] = rtrim($term, 's');
      $terms[] = $term . 's';
      if (str_ends_with($term, 'y')) {
        $terms[] = substr($term, 0, -1) . 'ies';
      }
    }
    return array_values(array_unique(array_filter($terms)));
  }

  /**
   * Checks whether two identifiers share a useful token.
   */
  private function sharesMeaningfulToken(string $a, string $b): bool {
    $stop = ['block', 'canvas', 'content', 'display', 'front', 'home', 'latest', 'listing', 'page', 'view'];
    foreach (preg_split('/[^a-z0-9_]+/', $a) ?: [] as $term) {
      if (strlen($term) > 3 && !in_array($term, $stop, TRUE) && str_contains($b, $term)) {
        return TRUE;
      }
      if (str_ends_with($term, 'ies')) {
        $singular = substr($term, 0, -3) . 'y';
        if (strlen($singular) > 3 && str_contains($b, $singular)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Returns the label for a node type ID in an existing summary list.
   */
  private function nodeTypeLabel(string $bundle, array $node_types): string {
    foreach ($node_types as $node_type) {
      if (($node_type['id'] ?? NULL) === $bundle) {
        return (string) ($node_type['label'] ?? $bundle);
      }
    }
    return '';
  }

  /**
   * Checks whether a node type summary exists.
   */
  private function hasNodeType(array $node_types, string $id): bool {
    foreach ($node_types as $node_type) {
      if (($node_type['id'] ?? NULL) === $id) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Summarizes node types from active configuration.
   *
   * @return array<int, array<string, mixed>>
   *   Node type summaries.
   */
  private function summarizeNodeTypes(bool $include_fields = FALSE): array {
    $types = [];
    foreach ($this->configFactory->listAll('node.type.') as $name) {
      $data = $this->configFactory->get($name)->getRawData();
      $id = (string) ($data['type'] ?? substr($name, strlen('node.type.')));
      $types[] = [
        'id' => $id,
        'label' => (string) ($data['name'] ?? $id),
        'description' => GuidanceTextNormalizer::normalize((string) ($data['description'] ?? '')),
        'fields' => $include_fields ? $this->summarizeBundleFields($id) : [],
      ];
    }
    usort($types, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
    return array_slice($types, 0, 12);
  }

  /**
   * Summarizes configured fields for a node bundle.
   *
   * @return array<int, array<string, mixed>>
   *   Field summaries.
   */
  private function summarizeBundleFields(string $bundle): array {
    $form_display = $this->configFactory->get('core.entity_form_display.node.' . $bundle . '.default')->getRawData();
    $view_display = $this->configFactory->get('core.entity_view_display.node.' . $bundle . '.default')->getRawData();
    $form_fields = array_keys((array) ($form_display['content'] ?? []));
    $view_fields = array_keys((array) ($view_display['content'] ?? []));

    $fields = [];
    foreach ($this->configFactory->listAll('field.field.node.' . $bundle . '.') as $name) {
      $data = $this->configFactory->get($name)->getRawData();
      $field_name = (string) ($data['field_name'] ?? substr($name, strlen('field.field.node.' . $bundle . '.')));
      if ($field_name === '') {
        continue;
      }
      $storage = $this->configFactory->get('field.storage.node.' . $field_name)->getRawData();
      $target_bundles = array_values(array_map('strval', array_keys((array) ($data['settings']['handler_settings']['target_bundles'] ?? []))));
      $fields[] = [
        'name' => $field_name,
        'label' => (string) ($data['label'] ?? $field_name),
        'description' => GuidanceTextNormalizer::normalize((string) ($data['description'] ?? '')),
        'type' => (string) ($storage['type'] ?? $data['field_type'] ?? 'unknown'),
        'required' => !empty($data['required']),
        'cardinality' => (int) ($storage['cardinality'] ?? 1),
        'target_type' => (string) ($storage['settings']['target_type'] ?? ''),
        'target_bundles' => $target_bundles,
        'on_form' => in_array($field_name, $form_fields, TRUE),
        'on_display' => in_array($field_name, $view_fields, TRUE),
      ];
    }

    usort($fields, static function (array $a, array $b): int {
      return ((int) $b['required'] <=> (int) $a['required'])
        ?: strcmp((string) $a['name'], (string) $b['name']);
    });
    return array_slice($fields, 0, 8);
  }

  /**
   * Formats a field summary for source text.
   *
   * @param array<string, mixed> $field
   *   Field summary.
   */
  private function formatFieldSummary(array $field): string {
    $parts = [
      '`' . $field['name'] . '`',
      (string) $field['label'],
      '`' . $field['type'] . '`',
      !empty($field['required']) ? 'required' : 'optional',
    ];
    if ((int) ($field['cardinality'] ?? 1) === -1) {
      $parts[] = 'multi-value';
    }
    if (!empty($field['target_type'])) {
      $parts[] = 'references `' . $field['target_type'] . '`';
    }
    if (!empty($field['target_bundles'])) {
      $parts[] = 'target bundles `' . implode('`, `', (array) $field['target_bundles']) . '`';
    }
    $parts[] = !empty($field['on_form']) ? 'shown on form' : 'hidden on default form';
    $parts[] = !empty($field['on_display']) ? 'shown on display' : 'hidden on default display';
    return implode(', ', array_filter($parts));
  }

  /**
   * Picks one concrete content type for a beginner's first exercise.
   *
   * @param array<int, array{id: string, label: string, description: string}> $node_types
   *   Node type summaries.
   *
   * @return array{id: string, label: string, description: string}|null
   *   Suggested type.
   */
  private function firstSafeExerciseType(array $node_types): ?array {
    foreach ($node_types as $type) {
      $haystack = strtolower(($type['id'] ?? '') . ' ' . ($type['label'] ?? '') . ' ' . ($type['description'] ?? ''));
      if (str_contains($haystack, 'utility') || str_contains($haystack, 'static')) {
        continue;
      }
      if (($type['id'] ?? '') === 'page') {
        continue;
      }
      $type['required_fields'] = $this->requiredFormFields($type);
      return $type;
    }

    if (!empty($node_types[0])) {
      $node_types[0]['required_fields'] = $this->requiredFormFields($node_types[0]);
      return $node_types[0];
    }

    return NULL;
  }

  /**
   * Returns required fields visible on the default form.
   *
   * @param array<string, mixed> $type
   *   Node type summary.
   *
   * @return string[]
   *   Required field machine names.
   */
  private function requiredFormFields(array $type): array {
    $fields = [];
    foreach ((array) ($type['fields'] ?? []) as $field) {
      if (!empty($field['required']) && !empty($field['on_form'])) {
        $fields[] = (string) ($field['name'] ?? '');
      }
    }
    return array_values(array_filter($fields));
  }

  /**
   * Summarizes relevant Views from active configuration.
   *
   * @return array<int, array<string, mixed>>
   *   View summaries.
   */
  private function summarizeViews(string $question, array $site_terms, array $front_page_paths = []): array {
    $views = [];
    $front_page_paths = array_values(array_filter(array_unique(array_map(
      static fn(string $path): string => '/' . ltrim($path, '/'),
      $front_page_paths,
    ))));

    foreach ($this->configFactory->listAll('views.view.') as $name) {
      $data = $this->configFactory->get($name)->getRawData();
      $id = (string) ($data['id'] ?? substr($name, strlen('views.view.')));
      $label = (string) ($data['label'] ?? $id);
      $description = GuidanceTextNormalizer::normalize((string) ($data['description'] ?? ''));
      $base_table = (string) ($data['base_table'] ?? '');
      $haystack = strtolower($id . ' ' . $label . ' ' . $description);

      $paths = [];
      $blocks = [];
      $filters = [];
      $sorts = [];
      $access = [];
      $bundle_filters = [];
      foreach ((array) ($data['display'] ?? []) as $display_id => $display) {
        $display_options = (array) ($display['display_options'] ?? []);
        if (!empty($display_options['path'])) {
          $paths[] = '/' . ltrim((string) $display_options['path'], '/');
        }
        if (($display['display_plugin'] ?? NULL) === 'block') {
          $blocks[] = (string) $display_id;
        }
        foreach ((array) ($display_options['filters'] ?? []) as $filter_id => $filter) {
          $summary = $this->summarizeViewPlugin((string) $filter_id, (array) $filter);
          if ($summary !== '') {
            $filters[] = $summary;
          }
          foreach ($this->bundleFilterValues((string) $filter_id, (array) $filter) as $bundle) {
            $bundle_filters[] = $bundle;
          }
        }
        foreach ((array) ($display_options['sorts'] ?? []) as $sort_id => $sort) {
          $summary = $this->summarizeViewPlugin((string) $sort_id, (array) $sort);
          if ($summary !== '') {
            $sorts[] = $summary;
          }
        }
        if (!empty($display_options['access'])) {
          $access_summary = $this->summarizeViewAccess((array) $display_options['access']);
          if ($access_summary !== '') {
            $access[] = $access_summary;
          }
        }
      }
      $paths = array_values(array_unique($paths));
      $blocks = array_values(array_unique($blocks));
      $filters = array_values(array_unique($filters));
      $sorts = array_values(array_unique($sorts));
      $access = array_values(array_unique($access));
      $bundle_filters = array_values(array_unique($bundle_filters));

      $front_page_match = array_intersect($paths, $front_page_paths) !== [];
      if (!$front_page_match && !$this->looksRelevant($haystack, $question, $site_terms)) {
        continue;
      }

      $views[] = [
        'id' => $id,
        'label' => $label,
        'description' => $description,
        'base_table' => $base_table,
        'paths' => $paths,
        'blocks' => $blocks,
        'filters' => array_slice($filters, 0, 8),
        'sorts' => array_slice($sorts, 0, 5),
        'access' => array_slice($access, 0, 4),
        'bundle_filters' => $bundle_filters,
      ];
    }

    usort($views, fn(array $a, array $b): int => $this->siteSpecificScore($b['id'], $site_terms) <=> $this->siteSpecificScore($a['id'], $site_terms)
      ?: strcmp($a['id'], $b['id']));
    return array_slice($views, 0, 10);
  }

  /**
   * Summarizes a Views filter/sort plugin in user-readable form.
   *
   * @param string $id
   *   Views plugin ID.
   * @param array<string, mixed> $plugin
   *   Raw Views plugin config.
   */
  private function summarizeViewPlugin(string $id, array $plugin): string {
    $field = (string) ($plugin['field'] ?? $id);
    $operator = (string) ($plugin['operator'] ?? '');
    $value = $plugin['value'] ?? NULL;
    if (is_array($value)) {
      $value = implode(', ', array_map('strval', array_filter(
        $value,
        static fn($item): bool => $item !== '' && $item !== NULL,
      )));
    }
    elseif ($value !== NULL && $value !== '') {
      $value = (string) $value;
    }
    else {
      $value = '';
    }

    $summary = '`' . $field . '`';
    if ($operator !== '') {
      $summary .= ' ' . $operator;
    }
    if ($value !== '') {
      $summary .= ' `' . $value . '`';
    }
    if (!empty($plugin['exposed'])) {
      $summary .= ' (exposed)';
    }
    if (!empty($plugin['order'])) {
      $summary .= ' ' . strtolower((string) $plugin['order']);
    }
    return $summary;
  }

  /**
   * Extracts bundle values from common Views bundle filters.
   *
   * @param string $id
   *   Views filter ID.
   * @param array<string, mixed> $filter
   *   Raw Views filter config.
   *
   * @return string[]
   *   Bundle IDs.
   */
  private function bundleFilterValues(string $id, array $filter): array {
    $field = (string) ($filter['field'] ?? $id);
    if (!in_array($field, ['type', 'bundle'], TRUE)) {
      return [];
    }
    $value = $filter['value'] ?? [];
    if (is_string($value)) {
      return [$value];
    }
    if (!is_array($value)) {
      return [];
    }
    return array_values(array_filter(array_map('strval', $value), static fn(string $item): bool => $item !== ''));
  }

  /**
   * Summarizes a Views access plugin.
   *
   * @param array<string, mixed> $access
   *   Raw access plugin config.
   */
  private function summarizeViewAccess(array $access): string {
    $type = (string) ($access['type'] ?? $access['plugin_id'] ?? '');
    if ($type === '') {
      return '';
    }
    $summary = '`' . $type . '`';
    if (!empty($access['options']['perm'])) {
      $summary .= ' permission `' . $access['options']['perm'] . '`';
    }
    elseif (!empty($access['options']['role'])) {
      $summary .= ' roles `' . implode('`, `', array_map('strval', (array) $access['options']['role'])) . '`';
    }
    return $summary;
  }

  /**
   * Summarizes configured workflows and moderation transitions.
   *
   * @return array<int, array<string, mixed>>
   *   Workflow summaries.
   */
  private function summarizeWorkflows(): array {
    $workflows = [];
    foreach ($this->configFactory->listAll('workflows.workflow.') as $name) {
      $data = $this->configFactory->get($name)->getRawData();
      $id = (string) ($data['id'] ?? substr($name, strlen('workflows.workflow.')));
      $type_settings = (array) ($data['type_settings'] ?? []);
      $states = [];
      foreach ((array) ($type_settings['states'] ?? []) as $state_id => $state) {
        $states[] = ((string) ($state['label'] ?? $state_id)) . ' (`' . $state_id . '`)';
      }
      $transitions = [];
      foreach ((array) ($type_settings['transitions'] ?? []) as $transition_id => $transition) {
        $from = implode(', ', array_map('strval', (array) ($transition['from'] ?? [])));
        $to = (string) ($transition['to'] ?? '');
        $transitions[] = ((string) ($transition['label'] ?? $transition_id)) . ' (`' . $transition_id . '`' . ($from !== '' || $to !== '' ? ': ' . $from . ' -> ' . $to : '') . ')';
      }
      $bundles = [];
      foreach ((array) ($type_settings['entity_types'] ?? []) as $entity_type => $entity_bundles) {
        foreach ((array) $entity_bundles as $bundle) {
          $bundles[] = (string) $entity_type . ':' . (string) $bundle;
        }
      }

      $workflows[] = [
        'id' => $id,
        'label' => (string) ($data['label'] ?? $id),
        'type' => (string) ($data['type'] ?? ''),
        'states' => array_slice($states, 0, 8),
        'transitions' => array_slice($transitions, 0, 10),
        'bundles' => array_slice(array_values(array_unique($bundles)), 0, 12),
      ];
    }

    usort($workflows, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
    return array_slice($workflows, 0, 8);
  }

  /**
   * Summarizes relevant Canvas component definitions from active config.
   *
   * @return array<int, array{id: string, label: string, source: string}>
   *   Canvas component summaries.
   */
  private function summarizeCanvasComponents(string $question, array $site_terms): array {
    $components = [];
    foreach ($this->configFactory->listAll('canvas.component.') as $name) {
      $data = $this->configFactory->get($name)->getRawData();
      $id = (string) ($data['id'] ?? substr($name, strlen('canvas.component.')));
      $label = (string) ($data['label'] ?? $id);
      $source = (string) ($data['source_local_id'] ?? $data['source'] ?? '');
      $haystack = strtolower($id . ' ' . $label . ' ' . $source);
      if (!$this->looksRelevant($haystack, $question, $site_terms)) {
        continue;
      }
      $components[] = [
        'id' => $id,
        'label' => $label,
        'source' => $source,
      ];
    }

    usort($components, fn(array $a, array $b): int => $this->siteSpecificScore($b['id'] . ' ' . $b['source'], $site_terms) <=> $this->siteSpecificScore($a['id'] . ' ' . $a['source'], $site_terms)
      ?: strcmp($a['id'], $b['id']));
    return array_slice($components, 0, 14);
  }

  /**
   * Returns site-specific terms from the site name and content model.
   *
   * @return string[]
   *   Terms.
   */
  private function siteTerms(): array {
    $name = strtolower((string) ($this->configFactory->get('system.site')->get('name') ?? ''));
    $terms = preg_split('/[^a-z0-9_]+/', $name) ?: [];
    foreach ($this->configFactory->listAll('node.type.') as $name) {
      $data = $this->configFactory->get($name)->getRawData();
      $terms[] = (string) ($data['type'] ?? substr($name, strlen('node.type.')));
      $terms = array_merge($terms, preg_split('/[^a-z0-9_]+/', strtolower((string) ($data['name'] ?? ''))) ?: []);
    }
    $generic = [
      'block',
      'canvas',
      'content',
      'file',
      'media',
      'node',
      'page',
      'recent',
      'search',
      'system',
      'taxonomy',
      'user',
      'view',
    ];
    return array_values(array_unique(array_filter($terms, static fn(string $term): bool => strlen($term) > 2
      && !in_array($term, $generic, TRUE))));
  }

  /**
   * Scores identifiers that look more site-specific than admin-generic.
   */
  private function siteSpecificScore(string $value, array $site_terms): int {
    $score = 0;
    $haystack = strtolower($value);
    foreach ($site_terms as $term) {
      if (str_contains($haystack, $term)) {
        $score++;
      }
    }
    return $score;
  }

  /**
   * Checks whether a config summary is relevant enough to include.
   *
   * @param string $haystack
   *   Summary text to inspect.
   * @param string $question
   *   User question.
   * @param string[] $fallback_terms
   *   Site-builder-oriented fallback terms.
   */
  private function looksRelevant(string $haystack, string $question, array $fallback_terms): bool {
    foreach ($fallback_terms as $term) {
      if (str_contains($haystack, $term)) {
        return TRUE;
      }
    }

    $stop = [
      'and',
      'built',
      'can',
      'drupal',
      'from',
      'here',
      'how',
      'learn',
      'power',
      'site',
      'that',
      'the',
      'this',
      'unlock',
      'what',
      'with',
      'you',
      'your',
    ];
    foreach (preg_split('/[^a-z0-9_]+/', strtolower($question)) ?: [] as $term) {
      if (strlen($term) > 2 && !in_array($term, $stop, TRUE) && str_contains($haystack, $term)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
