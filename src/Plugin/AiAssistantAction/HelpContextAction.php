<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Plugin\AiAssistantAction;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\ai_assistant_api\Attribute\AiAssistantAction;
use Drupal\ai_guidance\Source\HelpTopicsSourceProvider;
use Drupal\ai_guidance\Source\HookHelpSourceProvider;
use Drupal\ai_guidance\State\GuidanceStateAggregator;
use Drupal\ai_guidance\Value\GuidanceSource;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides current-route Help and Help Topic context to AI Assistant.
 */
#[AiAssistantAction(
  id: 'ai_guidance_help_context',
  label: new TranslatableMarkup('Drupal Guidance: Help context'),
)]
final class HelpContextAction extends GuidanceReadOnlyActionBase {

  /**
   * Constructs the action.
   */
  public function __construct(
    array $configuration,
    PrivateTempStoreFactory $tmpStore,
    AccountProxyInterface $currentUser,
    RequestStack $requestStack,
    private readonly GuidanceStateAggregator $stateAggregator,
    private readonly HookHelpSourceProvider $hookHelpSourceProvider,
    private readonly HelpTopicsSourceProvider $helpTopicsSourceProvider,
  ) {
    parent::__construct($configuration, $tmpStore, $currentUser, $requestStack);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $container->get('tempstore.private'),
      $container->get('current_user'),
      $container->get('request_stack'),
      $container->get('ai_guidance.state_aggregator'),
      $container->get(HookHelpSourceProvider::class),
      $container->get(HelpTopicsSourceProvider::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function listContexts(): array {
    $request = $this->guidanceRequest();
    $state = $this->stateAggregator->build($request);
    $sources = [];
    foreach ($this->hookHelpSourceProvider->getSources($request, $state) as $source) {
      $sources[] = $source;
    }
    foreach ($this->helpTopicsSourceProvider->getSources($request, $state) as $source) {
      $sources[] = $source;
    }
    $sources = $this->selectSources($sources, $request->question);

    return [
      $this->contextItem('Current page Help and Help Topics', array_merge([
        'Use current-route hook_help() before generic Help Topics for page-specific questions.',
        'If current-route hook_help() names a button, link, form, or path, preserve that exact UI label in the answer.',
        $this->isFrontPageQuestion(strtolower($request->question))
          ? 'For front-page placement questions, generic Node help about "Promoted to front page" is not site-specific evidence; prefer the site configuration summary or site architecture contracts.'
          : NULL,
      ], $this->sourceLines($sources, 'H', 3))),
    ];
  }

  /**
   * Applies a tight source budget for Help context.
   *
   * @param \Drupal\ai_guidance\Value\GuidanceSource[] $sources
   *   Candidate sources.
   *
   * @return \Drupal\ai_guidance\Value\GuidanceSource[]
   *   Selected sources.
   */
  private function selectSources(array $sources, string $question): array {
    $scored = [];
    foreach ($sources as $source) {
      $score = $this->sourceScore($source, $question);
      if ($score <= 0) {
        continue;
      }
      $scored[] = [
        'source' => $source,
        'score' => $score,
      ];
    }

    usort($scored, static fn(array $a, array $b): int => ($b['score'] <=> $a['score'])
      ?: ($b['source']->priority <=> $a['source']->priority)
      ?: strcmp($a['source']->title, $b['source']->title));

    $counts = [
      'route_help' => 0,
      'hook_help' => 0,
      'help_topic' => 0,
    ];
    $limits = [
      'route_help' => 1,
      'hook_help' => 1,
      'help_topic' => 1,
    ];
    $lower_question = strtolower($question);
    if ($this->isCurrentPageQuestion($lower_question)) {
      $limits['hook_help'] = 0;
      $limits['help_topic'] = 0;
    }
    elseif ($this->isEditorAiQuestion($lower_question)) {
      $limits['hook_help'] = 0;
    }
    elseif ($this->isFrontPageQuestion($lower_question)) {
      $limits['hook_help'] = 0;
    }

    $selected = [];
    foreach ($scored as $item) {
      $source = $item['source'];
      $type = $source->type;
      if (!isset($limits[$type])) {
        continue;
      }
      if ($counts[$type] >= $limits[$type]) {
        continue;
      }
      $selected[] = $source;
      $counts[$type]++;
    }

    return $selected;
  }

  /**
   * Scores Help sources against the current question.
   */
  private function sourceScore(GuidanceSource $source, string $question): int {
    $question = strtolower($question);
    $haystack = strtolower($source->title . ' ' . $source->text . ' ' . json_encode($source->metadata));
    $score = 0;

    if ($this->isCurrentPageQuestion($question) && in_array($source->type, ['route_help', 'hook_help'], TRUE)) {
      $score += 100;
    }

    if ($this->isEditorAiQuestion($question) && str_contains($haystack, 'safe ai configuration for content editors')) {
      $score += 100;
    }

    if ($this->isFrontPageQuestion($question) && !str_contains($haystack, 'front page') && !str_contains($haystack, 'homepage')) {
      return 0;
    }
    if ($this->isFrontPageQuestion($question) && $source->type === 'hook_help') {
      return 0;
    }

    foreach ($this->questionTerms($question) as $term) {
      if (str_contains(strtolower($source->title), $term)) {
        $score += 15;
      }
      elseif (str_contains($haystack, $term)) {
        $score += 5;
      }
    }

    return $score;
  }

  /**
   * Returns meaningful question terms for Help matching.
   *
   * @return string[]
   *   Terms.
   */
  private function questionTerms(string $question): array {
    $stop = [
      'about',
      'add',
      'and',
      'can',
      'configure',
      'content',
      'drupal',
      'front',
      'help',
      'how',
      'items',
      'just',
      'made',
      'page',
      'safe',
      'site',
      'shown',
      'that',
      'the',
      'this',
      'what',
      'with',
      'you',
    ];

    return array_values(array_unique(array_filter(
      preg_split('/[^a-z0-9_]+/', $question) ?: [],
      static fn(string $term): bool => strlen($term) > 2 && !in_array($term, $stop, TRUE)
    )));
  }

  /**
   * Checks if the user is asking about the current page.
   */
  private function isCurrentPageQuestion(string $question): bool {
    return str_contains($question, 'this page')
      || str_contains($question, 'current page')
      || str_contains($question, 'on this page')
      || str_contains($question, 'what can i do here');
  }

  /**
   * Checks if the question is about safe editor AI setup.
   */
  private function isEditorAiQuestion(string $question): bool {
    return str_contains($question, 'content editor') && str_contains($question, 'ai');
  }

  /**
   * Checks if the question is about front-page inclusion.
   */
  private function isFrontPageQuestion(string $question): bool {
    return str_contains($question, 'front page')
      || str_contains($question, 'homepage')
      || str_contains($question, 'home page')
      || str_contains($question, 'shown on the front');
  }

}
