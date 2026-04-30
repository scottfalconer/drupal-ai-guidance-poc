<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Plugin\AiAssistantAction;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\ai_assistant_api\Base\AiAssistantActionBase;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceSource;
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
   * Constructs a read-only guidance action.
   */
  public function __construct(
    array $configuration,
    PrivateTempStoreFactory $tmpStore,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly RequestStack $requestStack,
  ) {
    parent::__construct($configuration, $tmpStore);
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
      'For configuration, publishing, permission, AI setup, front-page, Canvas, and outside-agent questions, answer role-first: what the current role can do; what it cannot do; who can do the blocked task; what to ask them to do; Sources.',
      'For other practical questions, prefer this compact order: Direct answer; Why this is true on your site; What you can do now; If you need admin or site-builder help; Sources.',
      'Lead with the next concrete step. Put site context after the action unless the context materially changes the answer.',
      'When recommending an admin task, include the admin URL or breadcrumb path. End practical guidance with one verification sentence.',
      'Do not narrate authentication state unless it blocks the user. For permission or safety questions, name at least one permission to avoid granting and why.',
      'Do not tell the current user to perform an admin-only task when their safe state lacks the relevant permission; phrase that as an administrator or site-builder request.',
      'For role or permission questions, compare the current user content_type_permissions with AI and admin capability flags; explain what the role can do, what it cannot do, and who should handle the blocked task.',
      'If a role_capability_summary is present, use it for concise cross-role comparisons. If only role_capability_note is present, say the full role matrix is not available to the current account.',
      'If current-route Help names a button, link, form, or path, repeat that exact label or path.',
      'For outside coding agent handoff questions, produce a pasteable review/checklist. Do not recommend beginner content exercises unless the user explicitly asks for a learning exercise.',
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
      catch (\Throwable) {
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
    return $this->requestContext = $context;
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
      'Source evidence follows. Use display citation IDs such as [S1], [H1], [BP1], or [C1]; do not expose internal source IDs.',
      'Final Sources bullets must start with their display citation ID and should copy the relevant bullet shown here.',
    ];
    $sources = array_slice(array_values($sources), 0, $limit);
    $source_bullets = [];
    foreach ($sources as $index => $source) {
      if (!$source instanceof GuidanceSource) {
        continue;
      }
      $citation_id = $prefix . ($index + 1);
      $url = $this->sourceUrl($source);
      $source_bullet = $url
        ? sprintf('- [%s] [%s](%s)', $citation_id, $source->title, $url)
        : sprintf('- [%s] %s', $citation_id, $source->title);
      $source_bullets[] = $source_bullet;
      $lines[] = $source_bullet;
      $lines[] = sprintf('Display citation [%s], source type %s: %s', $citation_id, $source->type, $source->title);
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
   * Gets a source URL, when available.
   */
  protected function sourceUrl(GuidanceSource $source): ?string {
    $url = $source->citations['url'] ?? $source->metadata['source_url'] ?? NULL;
    return is_string($url) && $url !== '' ? $url : NULL;
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
