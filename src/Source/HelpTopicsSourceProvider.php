<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Source;

use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\help\HelpTopicPluginInterface;
use Drupal\help\HelpTopicPluginManagerInterface;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceSource;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Provides source documents from core Help Topics.
 */
final class HelpTopicsSourceProvider implements GuidanceSourceProviderInterface {

  public function __construct(
    private readonly HelpTopicPluginManagerInterface $helpTopicPluginManager,
    private readonly RendererInterface $renderer,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getSources(GuidanceRequest $request, GuidanceState $state): iterable {
    $definitions = $this->helpTopicPluginManager->getDefinitions();
    $candidates = [];
    foreach ($definitions as $id => $definition) {
      $score = $this->candidateScore($request, $state, $id, $definition);
      if ($score <= 0) {
        continue;
      }
      $candidates[] = [
        'id' => $id,
        'definition' => $definition,
        'score' => $score,
      ];
    }

    usort($candidates, static fn(array $a, array $b): int => ($b['score'] <=> $a['score'])
      ?: strcmp((string) $a['id'], (string) $b['id']));

    foreach (array_slice($candidates, 0, 12) as $candidate) {
      $id = $candidate['id'];
      $definition = $candidate['definition'];

      $topic = $this->helpTopicPluginManager->createInstance($id);
      if (!$topic instanceof HelpTopicPluginInterface) {
        continue;
      }

      try {
        $body_build = $topic->getBody();
        [$body, $cacheability] = $this->renderBody($body_build);
      }
      catch (\Throwable) {
        continue;
      }
      $text = GuidanceTextNormalizer::normalize($body);
      if ($text === '') {
        continue;
      }

      yield new GuidanceSource(
        id: 'help_topic:' . $id,
        canonicalId: 'help_topic.' . $id,
        title: (string) $topic->getLabel(),
        type: 'help_topic',
        text: $text,
        priority: !empty($definition['top_level']) ? 35 : 20,
        citations: [
          'help_topic_id' => $id,
          'url' => $topic->toUrl()->toString(),
        ],
        metadata: [
          'provider' => $definition['provider'] ?? NULL,
          'top_level' => (bool) ($definition['top_level'] ?? FALSE),
          'related' => $definition['related'] ?? [],
          'source_class' => 'help_topics',
        ],
        accessNotes: ['Help Topic is public module/theme help content.'],
        cacheability: $cacheability,
        tokenEstimate: GuidanceSource::estimateTokens($text),
      );
    }
  }

  /**
   * Renders a Help Topic body and returns cacheability metadata.
   *
   * @return array{0:string,1:array}
   *   The rendered body and cacheability metadata.
   */
  private function renderBody(array $body_build): array {
    $context = new RenderContext();
    $body = $this->renderer->executeInRenderContext($context, function () use ($body_build): string {
      return (string) $this->renderer->render($body_build);
    });
    $cacheability = [];
    if (!$context->isEmpty()) {
      $metadata = $context->pop();
      $cacheability = [
        'contexts' => $metadata->getCacheContexts(),
        'tags' => $metadata->getCacheTags(),
        'max_age' => $metadata->getCacheMaxAge(),
      ];
    }
    return [$body, $cacheability];
  }

  /**
   * Scores Help Topics before rendering.
   */
  private function candidateScore(GuidanceRequest $request, GuidanceState $state, string $id, array $definition): int {
    $question = strtolower($request->question);
    $label = strtolower((string) ($definition['label'] ?? ''));
    $provider = strtolower((string) ($definition['provider'] ?? ''));
    $haystack = strtolower($id . ' ' . $label . ' ' . $provider);
    $score = 0;

    $modules = $state->get('site')['enabled_ai_and_relevant_modules'] ?? [];
    if (in_array($definition['provider'] ?? '', $modules, TRUE)) {
      $score += 40;
    }

    foreach ($this->questionTerms($question) as $term) {
      if (str_contains($haystack, $term)) {
        $score += 10;
      }
    }

    return $score;
  }

  /**
   * Returns terms specific enough to score Help Topics.
   *
   * @return string[]
   *   Question terms.
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
      'first',
      'front',
      'help',
      'how',
      'items',
      'just',
      'learn',
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
      'your',
    ];

    return array_values(array_unique(array_filter(
      preg_split('/[^a-z0-9_]+/', $question) ?: [],
      static fn(string $term): bool => strlen($term) > 2 && !in_array($term, $stop, TRUE)
    )));
  }

}
