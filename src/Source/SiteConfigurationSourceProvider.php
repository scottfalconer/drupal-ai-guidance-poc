<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Source;

use Drupal\Core\Config\ConfigFactoryInterface;
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
      $node_types = $this->summarizeNodeTypes();
      if (!$full_summary) {
        $node_types = $this->filterNodeTypesForCurrentUser($node_types, $request);
      }
      $first_exercise = $this->beginnerQuestion($request->question) ? $this->firstSafeExerciseType($node_types) : NULL;
      $site_terms = $this->siteTerms();
      $views = $full_summary ? $this->summarizeViews($request->question, $site_terms) : [];
      $front_page_question = $this->frontPageQuestion($request->question);
      $canvas = $full_summary && !$front_page_question ? $this->summarizeCanvasComponents($request->question, $site_terms) : [];
      $front_page_path = (string) ($site->get('page.front') ?? '/');
      $front_page_public_path = $this->publicPath($front_page_path);
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
      }
      $lines[] = '';
    }

    if ($first_exercise !== NULL) {
      $lines[] = '## Beginner first exercise';
      $lines[] = '- Suggested first safe exercise: create exactly one draft `' . $first_exercise['label'] . '` item at `/node/add/' . $first_exercise['id'] . '`.';
      $lines[] = '- Success criteria: the item is saved as a draft or unpublished item, appears in `/admin/content`, can be previewed, and does not need to appear on the public front page.';
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
        $lines[] = '- `' . $view['id'] . '`: ' . $view['label']
          . ($view['description'] !== '' ? ' - ' . $view['description'] : '')
          . ($view['paths'] !== [] ? ' Paths: `' . implode('`, `', $view['paths']) . '`.' : '')
          . ($view['blocks'] !== [] ? ' Blocks: `' . implode('`, `', $view['blocks']) . '`.' : '');
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

    if (array_intersect(['administrator', 'site_builder'], $account->getRoles()) !== []) {
      return TRUE;
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
        $summary['listed_content_signals'] = $this->frontPageContentSignals($summary['components'], $node_types);
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
   *
   * @return string[]
   *   Human-readable signals.
   */
  private function frontPageContentSignals(array $components, array $node_types): array {
    $signals = [];
    foreach ($components as $component) {
      $haystack = strtolower($component['component_id'] . ' ' . $component['label']);
      foreach ($node_types as $node_type) {
        $id = strtolower((string) ($node_type['id'] ?? ''));
        $label = strtolower((string) ($node_type['label'] ?? ''));
        if ($id !== '' && str_contains($haystack, $id)) {
          $signals[] = $label !== '' ? $label : $id;
        }
        elseif ($label !== '' && str_contains($haystack, $label)) {
          $signals[] = $label;
        }
      }
      if (str_contains($haystack, 'recipe')) {
        $signals[] = 'recipes';
      }
      if (str_contains($haystack, 'story') || str_contains($haystack, 'stories') || str_contains($haystack, 'article')) {
        $signals[] = 'stories/articles';
      }
      if (str_contains($haystack, 'collection')) {
        $signals[] = 'collections';
      }
      if (str_contains($haystack, 'topic')) {
        $signals[] = 'topics';
      }
      if (str_contains($haystack, 'page')) {
        $signals[] = 'utility pages';
      }
    }

    return array_values(array_unique($signals));
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
   * @return array<int, array{id: string, label: string, description: string}>
   *   Node type summaries.
   */
  private function summarizeNodeTypes(): array {
    $types = [];
    foreach ($this->configFactory->listAll('node.type.') as $name) {
      $data = $this->configFactory->get($name)->getRawData();
      $id = (string) ($data['type'] ?? substr($name, strlen('node.type.')));
      $types[] = [
        'id' => $id,
        'label' => (string) ($data['name'] ?? $id),
        'description' => GuidanceTextNormalizer::normalize((string) ($data['description'] ?? '')),
      ];
    }
    usort($types, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
    return array_slice($types, 0, 12);
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
      return $type;
    }

    return $node_types[0] ?? NULL;
  }

  /**
   * Summarizes relevant Views from active configuration.
   *
   * @return array<int, array{id: string, label: string, description: string, paths: array<int, string>, blocks: array<int, string>}>
   *   View summaries.
   */
  private function summarizeViews(string $question, array $site_terms): array {
    $views = [];
    foreach ($this->configFactory->listAll('views.view.') as $name) {
      $data = $this->configFactory->get($name)->getRawData();
      $id = (string) ($data['id'] ?? substr($name, strlen('views.view.')));
      $label = (string) ($data['label'] ?? $id);
      $description = GuidanceTextNormalizer::normalize((string) ($data['description'] ?? ''));
      $haystack = strtolower($id . ' ' . $label . ' ' . $description);
      if (!$this->looksRelevant($haystack, $question, $site_terms)) {
        continue;
      }

      $paths = [];
      $blocks = [];
      foreach ((array) ($data['display'] ?? []) as $display_id => $display) {
        $display_options = (array) ($display['display_options'] ?? []);
        if (!empty($display_options['path'])) {
          $paths[] = '/' . ltrim((string) $display_options['path'], '/');
        }
        if (($display['display_plugin'] ?? NULL) === 'block') {
          $blocks[] = (string) $display_id;
        }
      }

      $views[] = [
        'id' => $id,
        'label' => $label,
        'description' => $description,
        'paths' => array_values(array_unique($paths)),
        'blocks' => array_values(array_unique($blocks)),
      ];
    }

    usort($views, fn(array $a, array $b): int => $this->siteSpecificScore($b['id'], $site_terms) <=> $this->siteSpecificScore($a['id'], $site_terms)
      ?: strcmp($a['id'], $b['id']));
    return array_slice($views, 0, 10);
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
    $generic = ['block', 'canvas', 'content', 'file', 'media', 'node', 'page', 'recent', 'search', 'system', 'taxonomy', 'user', 'view'];
    return array_values(array_unique(array_filter($terms, static fn(string $term): bool => strlen($term) > 2 && !in_array($term, $generic, TRUE))));
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
   * @param string[] $fallback_terms
   *   Site-builder-oriented fallback terms.
   */
  private function looksRelevant(string $haystack, string $question, array $fallback_terms): bool {
    foreach ($fallback_terms as $term) {
      if (str_contains($haystack, $term)) {
        return TRUE;
      }
    }

    $stop = ['and', 'built', 'can', 'drupal', 'from', 'here', 'how', 'learn', 'power', 'site', 'that', 'the', 'this', 'unlock', 'what', 'with', 'you', 'your'];
    foreach (preg_split('/[^a-z0-9_]+/', strtolower($question)) ?: [] as $term) {
      if (strlen($term) > 2 && !in_array($term, $stop, TRUE) && str_contains($haystack, $term)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
