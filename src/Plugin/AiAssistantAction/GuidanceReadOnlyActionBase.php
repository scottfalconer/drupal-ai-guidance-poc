<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Plugin\AiAssistantAction;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\ai_assistant_api\Base\AiAssistantActionBase;
use Drupal\ai_guidance\Prompt\GuidanceRedactor;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceSource;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Base class for read-only Drupal Guidance AI Assistant context actions.
 *
 * These plugins intentionally expose deterministic context through the existing
 * AI Assistant API instead of providing executable actions.
 */
abstract class GuidanceReadOnlyActionBase extends AiAssistantActionBase {

  /**
   * Decoded request body for the current plugin invocation.
   */
  private ?array $requestData = NULL;

  /**
   * Latest user question for the current plugin invocation.
   */
  private ?string $currentQuestion = NULL;

  /**
   * Caller context for the current plugin invocation.
   */
  private ?array $requestContext = NULL;

  /**
   * Logger for sanitized read-only action diagnostics.
   */
  protected readonly LoggerInterface $logger;

  /**
   * Constructs a read-only guidance action.
   */
  public function __construct(
    array $configuration,
    PrivateTempStoreFactory $tmpStore,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly RequestStack $requestStack,
    protected readonly GuidanceRedactor $redactor,
    ?LoggerInterface $logger = NULL,
  ) {
    parent::__construct($configuration, $tmpStore);
    $this->logger = $logger ?? new NullLogger();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['read_only_notice'] = [
      '#markup' => '<p>' . $this->t('This context source is read-only. It injects deterministic context before generation and exposes no executable actions.') . '</p>',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function listActions(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function listUsageInstructions(): array {
    return [
      'Drupal Guidance context sources are read-only evidence. Use them to explain and guide, not to mutate the site.',
      'If a context has no matching trusted sources, do not invent or cite a placeholder source. Say when an answer is based only on site state or inference.',
      'In Sources, distinguish live Drupal evidence from linked documentation. Live evidence labels such as [A1], [R1], [E1], [F1], and [W1] come from this site/request and are not public links. Help Topics, route Help, package guidance, and Drupal.org documentation should be linked when their source bullet provides a URL.',
      'For configuration, publishing, permission, AI setup, front-page, Canvas, and outside-agent questions, answer role-first: what the current user can do; what the current user cannot do; who can do the blocked task; what to ask them to do; Sources.',
      'For other practical questions, prefer this compact order: Direct answer; What you can do now; What requires admin or site-builder help; Why this is true on your site; How to verify; Sources.',
      'Lead with the next concrete step. Put site context after the action unless the context materially changes the answer.',
      'When recommending an admin task, include the admin URL or breadcrumb path. End practical guidance with one verification sentence.',
      'Do not narrate authentication state unless it blocks the user. For permission or safety questions, name at least one permission to avoid granting and why.',
      'Do not tell the current user to perform an admin-only task when their safe state lacks the relevant permission; phrase that as an administrator or site-builder request.',
      'For role or permission questions, compare the current user content_type_permissions with AI and admin capability flags; explain what the role can do, what it cannot do, and who should handle the blocked task.',
      'If a role_capability_summary is present, use it for concise cross-role comparisons. If only role_capability_note is present, say the full role matrix is not available to the current account.',
      'For current form questions, use current_form evidence for the entity form target, required fields, visible field labels, submit buttons, and form display. Do not enumerate every field; mention the required field first and at most three other user-facing fields or buttons. If more fields are present in context, select the most important ones and omit the rest. Omit search indexing, URL alias, revision log, machine/admin controls, and implementation details unless the user specifically asks about them. If browser-visible buttons are missing, say the exact runtime buttons cannot be confirmed.',
      'For workflow questions, use workflow evidence for the current bundle, current moderation state, states, transitions, and transition permissions. Do not infer publish access from edit access alone.',
      'If current-route Help names a button, link, form, or path, repeat that exact label or path.',
      'For front-page placement questions, do not recommend the generic "Promoted to front page" checkbox unless deterministic context explicitly says the front page is a default/promoted-content listing or a View filter uses that flag. If front-page ownership is unknown, say so and ask a site builder to inspect the front-page route, View, block, or page composition.',
      'For Learn Drupal AI lesson questions, treat matching packaged lesson Markdown as the lesson source of truth for goals, practice tasks, prompts, success criteria, and recap. Use lesson-specific instructions below only to shape the answer and evaluation labels.',
      'All Learn Drupal AI lesson flows use three stages: overview, guided task, and recap. For lesson overview questions, answer with: What you will learn; What you will practice; How Drupal will check your work; When ready, say "Ok, start Lesson N."; Sources. Do not start the task during the overview.',
      'For Lesson 1 start questions, including "Ok, start Lesson 1", answer as a lesson challenge with: Goal; Practice task; What Drupal concept this teaches; Success criteria; Start here; Sources. The Lesson 1 task is to create exactly one draft Article, verify it in /admin/content, open or preview it, and ask for evaluation. Mention administrator/site-builder work only once as follow-up context for permissions, workflows, Views, and page composition.',
      'For Lesson 1 evaluation questions, answer from lesson_1_evaluation evidence when present. Use result labels: Fully verified, Core task complete, Partially complete, or Cannot confirm. If the draft Article entity is confirmed but /admin/content or preview verification is missing, say Core task complete, not Complete. If current entity or content-list evidence is missing, say Cannot confirm and ask the user to open the draft Article edit page or /admin/content before asking again.',
      'For Lesson 1 recap questions such as "Recap Lesson 1", answer with: Concepts learned; Evidence checked; Why it matters; Try next; Continue the discussion in Drupal Slack #ai-learners; Sources.',
      'For Lesson 2 start questions, including "Ok, start Lesson 2", answer as a site-policy lesson with: Goal; Practice task; What Drupal concept this teaches; What Context Control Center provides; Success criteria; Start here; Sources. Explain that Context Control Center is the Drupal project at https://www.drupal.org/project/ai_context and that it manages reusable site policy/context items for Drupal AI features. The Lesson 2 task is to create or verify one Context Control Center policy context for this site, then test it as a content editor on draft Article content. In the Umami demo, the example context is food-focused editorial guidance. Phrase the boundary as "context guides suggestions; Drupal permissions and workflow authorize actions."',
      'For Lesson 2 evaluation questions, use result labels: Complete, Partially complete, or Cannot confirm. Confirm whether CCC policy context covers brand voice, editorial standards, accessibility or governance, editor-facing scope, and draft-assistance-only boundaries. For editor-use evaluation, focus on current Article/draft state, visible draft text, policy context availability, and the current user role boundary; do not require proof of the exact prior chat prompt or a separate browser session. If CCC source evidence is missing, say Cannot confirm and ask the user to open the saved CCC context item or listing.',
      'For Lesson 2 recap questions such as "Recap Lesson 2", answer with: Concepts learned; Evidence checked; Why it matters; Try next; Continue the discussion in Drupal Slack #ai-learners; Sources.',
      'Site policy context guides suggestions. Drupal permissions, workflows, and editorial review remain authoritative for saving, publishing, configuring AI, changing site structure, and approving content.',
      'If visible_page_messages includes error or warning messages, mention them under Evidence I can confirm and treat the lesson as Partially complete until the user resolves or explains them.',
      'Keep user capability and assistant capability separate: say "What you can do with your role" for Drupal permissions, and "What I can help with" only for the assistant V1 read-only boundary. Do not say "my current role" when referring to the user.',
      'For outside coding agent handoff questions, start with "Paste this to the outside coding agent:" and produce a pasteable operational checklist. Include review-only unless explicitly authorized; do not mutate production; do not change AI providers, assistants, roles, permissions, workflows, or page composition without admin review; preserve front-page/listing behavior; use Drupal APIs and config management; add or update tests. Omit generic backup or CI advice unless source evidence supports it. Do not recommend beginner content exercises unless the user explicitly asks for a learning exercise.',
      'For current-page help, describe actions supported by this page or its route Help. Do not suggest generic administration exploration unless a source names that setting or the user asks for implementation details.',
      'Avoid internal implementation terms such as Guidance Engine, DeepChat, prompt package, source package, module machine names, service names, class names, and plugin IDs unless the user asks how this assistant is implemented.',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function triggerAction(string $action_id, array $parameters = []): void {
    throw new \LogicException('Drupal Guidance context actions are read-only and expose no executable actions.');
  }

  /**
   * {@inheritdoc}
   */
  public function provideFewShotLearningExample(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getFunctionCallSchema(): array {
    return [];
  }

  /**
   * Builds the current guidance request.
   */
  protected function guidanceRequest(): GuidanceRequest {
    return new GuidanceRequest(
      question: $this->currentQuestion(),
      account: $this->currentUser,
      context: $this->requestContext(),
    );
  }

  /**
   * Gets the latest user question from assistant history or request data.
   */
  protected function currentQuestion(): string {
    if ($this->currentQuestion !== NULL) {
      return $this->currentQuestion;
    }

    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      $query_question = trim((string) $request->query->get('question', ''));
      if ($query_question !== '') {
        return $this->currentQuestion = mb_substr($query_question, 0, 2000);
      }

      $data = $this->requestData();
      if (is_array($data)) {
        foreach (array_reverse((array) ($data['messages'] ?? [])) as $message) {
          if (($message['role'] ?? NULL) === 'user') {
            return $this->currentQuestion = mb_substr((string) ($message['text'] ?? $message['content'] ?? $message['message'] ?? ''), 0, 2000);
          }
        }
      }
    }

    if (isset($this->threadId)) {
      try {
        $session = $this->getTempStore()->get($this->threadId) ?: [];
        $messages = $session['messages'] ?? [];
        for ($i = count($messages) - 1; $i >= 0; $i--) {
          if (($messages[$i]['role'] ?? NULL) === 'user') {
            return $this->currentQuestion = (string) ($messages[$i]['message'] ?? '');
          }
        }
      }
      catch (\Throwable $exception) {
        $this->logger->debug('Guidance assistant tempstore question lookup failed with @class.', [
          '@class' => get_debug_type($exception),
        ]);
        return $this->currentQuestion = '';
      }
    }

    return $this->currentQuestion = '';
  }

  /**
   * Reads caller context from DeepChat/AI Assistant request data.
   */
  protected function requestContext(): array {
    if ($this->requestContext !== NULL) {
      return $this->requestContext;
    }

    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return $this->requestContext = [];
    }

    $context = [];
    $data = $this->requestData();
    if (is_array($data['contexts'] ?? NULL)) {
      $context = $data['contexts'];
    }
    if ($request->query->has('current_route')) {
      $context['current_route'] = $request->query->get('current_route');
    }
    return $this->requestContext = $this->sanitizeRequestContext($context);
  }

  /**
   * Keeps only supported caller context keys and redacts visible text.
   *
   * @param array<string, mixed> $context
   *   Raw caller context from the chat request.
   *
   * @return array<string, mixed>
   *   Sanitized context values.
   */
  private function sanitizeRequestContext(array $context): array {
    $safe = [];
    if (isset($context['current_route']) && is_string($context['current_route'])) {
      $safe['current_route'] = mb_substr($context['current_route'], 0, 1024);
    }

    $messages = $context['visible_page_messages'] ?? $context['page_messages'] ?? NULL;
    if (is_array($messages)) {
      $safe_messages = [];
      foreach ($messages as $message) {
        if (!is_array($message)) {
          continue;
        }
        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '' || $this->shouldSkipVisiblePageMessage($text)) {
          continue;
        }
        $type = strtolower((string) ($message['type'] ?? 'status'));
        if (!in_array($type, ['status', 'warning', 'error'], TRUE)) {
          $type = 'status';
        }
        $safe_messages[] = [
          'type' => $type,
          'text' => $this->redactor->redactText(mb_substr($text, 0, 500))['value'],
        ];
        if (count($safe_messages) >= 8) {
          break;
        }
      }
      if ($safe_messages !== []) {
        $safe['visible_page_messages'] = $safe_messages;
      }
    }

    if (is_array($context['current_form'] ?? NULL)) {
      $safe_form = $this->sanitizeCurrentFormContext((array) $context['current_form']);
      if ($safe_form !== []) {
        $safe['current_form'] = $safe_form;
      }
    }

    return $safe;
  }

  /**
   * Sanitizes browser-visible form context.
   *
   * @param array<string, mixed> $form
   *   Raw browser form summary.
   *
   * @return array<string, mixed>
   *   Safe form summary.
   */
  private function sanitizeCurrentFormContext(array $form): array {
    $safe = [];
    foreach (['form_id', 'action', 'method'] as $key) {
      if (!isset($form[$key]) || !is_scalar($form[$key])) {
        continue;
      }
      $value = trim((string) $form[$key]);
      if ($value === '') {
        continue;
      }
      if ($key === 'action') {
        $parts = parse_url($value);
        if ($parts === FALSE || isset($parts['scheme']) || isset($parts['host'])) {
          continue;
        }
        $value = (string) ($parts['path'] ?? '');
        if ($value === '' || !str_starts_with($value, '/')) {
          continue;
        }
      }
      $safe[$key] = $this->redactor->redactText(mb_substr($value, 0, 200))['value'];
    }

    $fields = [];
    foreach ((array) ($form['fields'] ?? []) as $field) {
      if (!is_array($field)) {
        continue;
      }
      $name = trim((string) ($field['name'] ?? ''));
      $label = trim((string) ($field['label'] ?? ''));
      if ($name === '' && $label === '') {
        continue;
      }
      $type = trim((string) ($field['type'] ?? ''));
      if (!$this->isUsefulFormField($name, $label, $type)) {
        continue;
      }
      $fields[] = [
        'name' => $this->redactor->redactText(mb_substr($name, 0, 120))['value'],
        'label' => $this->redactor->redactText(mb_substr($label, 0, 160))['value'],
        'type' => $this->redactor->redactText(mb_substr($type, 0, 40))['value'],
        'required' => !empty($field['required']),
      ] + $this->safeVisibleFieldValue($field, $name, $type);
      if (count($fields) >= 4) {
        break;
      }
    }
    if ($fields !== []) {
      $safe['fields'] = $fields;
    }

    $buttons = [];
    foreach ((array) ($form['submit_buttons'] ?? []) as $button) {
      if (!is_scalar($button)) {
        continue;
      }
      $label = trim((string) $button);
      if ($label === '' || !$this->isUsefulFormButtonLabel($label)) {
        continue;
      }
      $buttons[] = $this->redactor->redactText(mb_substr($label, 0, 120))['value'];
      if (count($buttons) >= 8) {
        break;
      }
    }
    if ($buttons !== []) {
      $safe['submit_buttons'] = $buttons;
    }

    return $safe;
  }

  /**
   * Returns a redacted visible field value when it is safe to include.
   *
   * @param array<string, mixed> $field
   *   Caller-provided field summary.
   * @param string $name
   *   Field input name.
   * @param string $type
   *   Field input type.
   *
   * @return array{value?: string}
   *   Safe value summary.
   */
  private function safeVisibleFieldValue(array $field, string $name, string $type): array {
    $value = trim((string) ($field['value'] ?? ''));
    if ($value === '') {
      return [];
    }

    $sensitive_name = strtolower($name);
    $type = strtolower($type);
    foreach (['password', 'hidden', 'file'] as $blocked_type) {
      if ($type === $blocked_type) {
        return [];
      }
    }
    foreach (['token', 'pass', 'secret', 'key', 'mail'] as $needle) {
      if (str_contains($sensitive_name, $needle)) {
        return [];
      }
    }

    return [
      'value' => $this->redactor->redactText(mb_substr($value, 0, 700))['value'],
    ];
  }

  /**
   * Filters browser page messages that are not useful guidance evidence.
   */
  private function shouldSkipVisiblePageMessage(string $text): bool {
    $text = strtolower($text);
    return str_contains($text, 'one-time login link')
      || str_contains($text, 'set your new password now');
  }

  /**
   * Filters low-value browser-visible form controls from guidance context.
   */
  private function isUsefulFormField(string $name, string $label, string $type): bool {
    $haystack = strtolower($name . ' ' . $label . ' ' . $type);
    foreach ([
      'autosave',
      'form_build_id',
      'form_token',
      'form_id',
      'path[',
      'pathauto',
      'revision_log',
      'search_api_exclude',
    ] as $needle) {
      if (str_contains($haystack, $needle)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Filters editor chrome/tooling buttons from the user-facing form summary.
   */
  private function isUsefulFormButtonLabel(string $label): bool {
    $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $label) ?? $label));
    if ($normalized === '') {
      return FALSE;
    }

    $blocked_exact = [
      'autosave save',
      'paragraph',
      'show more items',
      'update widget',
    ];
    if (in_array($normalized, $blocked_exact, TRUE)) {
      return FALSE;
    }

    foreach (['toggle ', 'close ', 'hide ', 'moves focus'] as $blocked_prefix) {
      if (str_starts_with($normalized, $blocked_prefix)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Gets decoded JSON request data once per action invocation.
   */
  private function requestData(): array {
    if ($this->requestData !== NULL) {
      return $this->requestData;
    }

    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return $this->requestData = [];
    }

    $data = json_decode($request->getContent(), TRUE);
    return $this->requestData = is_array($data) ? $data : [];
  }

  /**
   * Converts an array into one context line.
   */
  protected function jsonLine(string $label, array $data): string {
    return $label . ': ' . Json::encode($data);
  }

  /**
   * Formats trusted source evidence with display-safe citation IDs.
   *
   * @param \Drupal\ai_guidance\Value\GuidanceSource[] $sources
   *   Source objects.
   * @param string $prefix
   *   Display citation ID prefix.
   * @param int $limit
   *   Maximum number of sources to format.
   *
   * @return string[]
   *   Context description lines.
   */
  protected function sourceLines(array $sources, string $prefix = 'S', int $limit = 6): array {
    if ($sources === []) {
      return [
        'No matching trusted sources were found for this context.',
        'Do not cite this line as a source. If you answer from site state only, say that plainly.',
      ];
    }

    $lines = [
      'Source evidence follows. Use the display citation IDs shown in the source bullets; do not expose internal source IDs.',
      'Source bullets include provenance. Linked source bullets point to local Help/Help Topic pages, packaged lesson Markdown, trusted package docs, module-owned context, or public documentation when a safe URL is available.',
    ];
    $sources = array_slice(array_values($sources), 0, $limit);
    $source_bullets = [];
    foreach ($sources as $index => $source) {
      if (!$source instanceof GuidanceSource) {
        continue;
      }
      $source = $this->redactSource($source);
      $citation_id = $prefix . ($index + 1);
      $url = $this->sourceUrl($source);
      $provenance = $this->sourceProvenance($source);
      $source_bullet = $url
        ? sprintf('- [%s] [%s](%s) — %s', $citation_id, $source->title, $url, $provenance)
        : sprintf('- [%s] %s — %s', $citation_id, $source->title, $provenance);
      $source_bullets[] = $source_bullet;
      $lines[] = $source_bullet;
      $lines[] = sprintf('Display citation [%s], source type %s, provenance %s: %s', $citation_id, $source->type, $provenance, $source->title);
      $lines[] = 'SOURCE EVIDENCE START';
      $lines[] = $source->text;
      $lines[] = 'SOURCE EVIDENCE END';
    }
    if ($source_bullets !== []) {
      $lines[] = 'Required final Sources bullets for this context:';
      array_push($lines, ...$source_bullets);
    }
    return $lines;
  }

  /**
   * Redacts a trusted source before formatting it for model-visible context.
   */
  private function redactSource(GuidanceSource $source): GuidanceSource {
    $title = $this->redactor->redactText($source->title)['value'];
    $text = $this->redactor->redactText($source->text)['value'];
    $citations = $this->redactor->redactArray($source->citations)['value'];
    $metadata = $this->redactor->redactArray($source->metadata)['value'];
    $access_notes = $this->redactor->redactArray($source->accessNotes)['value'];

    if (!$this->canInspectInternalSourceIdentifiers()) {
      $title = is_string($title) ? $this->stripInternalConfigIdentifiers($title) : $title;
      $text = is_string($text) ? $this->stripInternalConfigIdentifiers($text) : $text;
      $citations = is_array($citations) ? $this->stripInternalConfigIdentifiersFromArray($citations) : $citations;
      $metadata = is_array($metadata) ? $this->stripInternalConfigIdentifiersFromArray($metadata) : $metadata;
      $access_notes = is_array($access_notes) ? $this->stripInternalConfigIdentifiersFromArray($access_notes) : $access_notes;
    }

    return new GuidanceSource(
      id: $source->id,
      canonicalId: $source->canonicalId,
      title: is_string($title) ? $title : $source->title,
      type: $source->type,
      text: is_string($text) ? $text : '',
      priority: $source->priority,
      citations: is_array($citations) ? $citations : [],
      metadata: is_array($metadata) ? $metadata : [],
      accessNotes: is_array($access_notes) ? $access_notes : [],
      cacheability: $source->cacheability,
      tokenEstimate: GuidanceSource::estimateTokens(is_string($text) ? $text : ''),
      citationId: $source->citationId,
    );
  }

  /**
   * Checks whether model-visible context may include raw config identifiers.
   */
  private function canInspectInternalSourceIdentifiers(): bool {
    foreach ([
      'administer ai guidance',
      'administer site configuration',
    ] as $permission) {
      if ($this->currentUser->hasPermission($permission)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Replaces admin-derived config object IDs in model-visible text.
   */
  private function stripInternalConfigIdentifiers(string $text): string {
    $patterns = [
      '/\b(?:block\.block|canvas\.component|core\.entity_[a-z0-9_]+|eca\.eca|field\.field|field\.storage|node\.type|views\.view|webform\.webform|workflows\.workflow)\.[a-z0-9_.:-]+/i',
      '/drupal:\/\/site\/contracts\/[^\s`)]+/i',
    ];
    return preg_replace($patterns, '[admin-only config identifier]', $text) ?? $text;
  }

  /**
   * Replaces admin-derived config object IDs in nested arrays.
   *
   * @param array<string|int, mixed> $data
   *   Data to sanitize.
   *
   * @return array<string|int, mixed>
   *   Sanitized data.
   */
  private function stripInternalConfigIdentifiersFromArray(array $data): array {
    foreach ($data as $key => $value) {
      if (is_string($value)) {
        $data[$key] = $this->stripInternalConfigIdentifiers($value);
      }
      elseif (is_array($value)) {
        $data[$key] = $this->stripInternalConfigIdentifiersFromArray($value);
      }
    }
    return $data;
  }

  /**
   * Gets a source URL, when available.
   */
  protected function sourceUrl(GuidanceSource $source): ?string {
    $url = $source->citations['url'] ?? $source->citations['source_url'] ?? $source->metadata['source_url'] ?? NULL;
    if (!is_string($url)) {
      return NULL;
    }

    $url = trim($url);
    if ($url === '') {
      return NULL;
    }

    $parts = parse_url($url);
    if ($parts === FALSE) {
      return NULL;
    }

    if (str_starts_with($url, '//')) {
      return NULL;
    }

    if (str_starts_with($url, '/')) {
      $path = (string) ($parts['path'] ?? '');
      return str_starts_with($path, '/') ? $path : NULL;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = (string) ($parts['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], TRUE) || $host === '') {
      return NULL;
    }

    $path = (string) ($parts['path'] ?? '');
    return $scheme . '://' . $host . $path;
  }

  /**
   * Describes where a trusted source came from.
   */
  private function sourceProvenance(GuidanceSource $source): string {
    return match ($source->type) {
      'route_help' => 'installed Drupal module help for the current page',
      'help_topic' => 'Drupal Help Topic from an installed module',
      'lesson_package' => 'packaged Learn Drupal AI lesson Markdown from an installed module',
      'ccc_context_item' => 'Context Control Center policy context selected for this request',
      'site_architecture_context' => 'site architecture summary generated from Drupal configuration',
      'site_configuration_summary' => 'read-only summary of live Drupal configuration',
      'best_practices' => 'trusted Markdown guidance from the AI Best Practices package',
      default => 'trusted guidance source',
    };
  }

  /**
   * Builds a context item.
   *
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   Context title.
   * @param string[] $description
   *   Context lines.
   *
   * @return array<string, mixed>
   *   AI Assistant context item.
   */
  protected function contextItem(string|TranslatableMarkup $title, array $description): array {
    return [
      'title' => (string) $title,
      'description' => array_values(array_filter($description, static fn($line): bool => trim((string) $line) !== '')),
    ];
  }

}
