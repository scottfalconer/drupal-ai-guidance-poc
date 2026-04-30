<?php

declare(strict_types=1);

namespace Drupal\ai_guidance_best_practices\Plugin\AiAssistantAction;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\ai_assistant_api\Attribute\AiAssistantAction;
use Drupal\ai_guidance\Plugin\AiAssistantAction\GuidanceReadOnlyActionBase;
use Drupal\ai_guidance\Prompt\GuidanceRedactor;
use Drupal\ai_guidance\State\GuidanceStateAggregator;
use Drupal\ai_guidance_best_practices\Source\BestPracticesSourceProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides optional AI Best Practices context for developer/agent questions.
 */
#[AiAssistantAction(
  id: 'ai_guidance_best_practices_context',
  label: new TranslatableMarkup('Drupal Guidance: AI Best Practices'),
)]
final class BestPracticesContextAction extends GuidanceReadOnlyActionBase {

  /**
   * Constructs the action.
   */
  public function __construct(
    array $configuration,
    PrivateTempStoreFactory $tmpStore,
    AccountProxyInterface $currentUser,
    RequestStack $requestStack,
    GuidanceRedactor $redactor,
    private readonly GuidanceStateAggregator $stateAggregator,
    private readonly BestPracticesSourceProvider $sourceProvider,
  ) {
    parent::__construct($configuration, $tmpStore, $currentUser, $requestStack, $redactor);
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
      $container->get('ai_guidance.redactor'),
      $container->get('ai_guidance.state_aggregator'),
      $container->get(BestPracticesSourceProvider::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function listContexts(): array {
    if (!$this->isRelevant(strtolower($this->currentQuestion()))) {
      return [];
    }

    $request = $this->guidanceRequest();
    $state = $this->stateAggregator->build($request);
    $sources = iterator_to_array($this->sourceProvider->getSources($request, $state), FALSE);
    $sources = $this->selectSources($sources, strtolower($request->question));

    return [
      $this->contextItem('AI Best Practices corpus', array_merge([
        'Use this optional corpus only for developer, outside-agent, testing, documentation, or code-review questions.',
      ], $this->sourceLines($sources, 'BP', 3))),
    ];
  }

  /**
   * Checks whether AI Best Practices are relevant.
   */
  private function isRelevant(string $question): bool {
    foreach ([
      'agent',
      'outside',
      'claude',
      'codex',
      'cursor',
      'mcp',
      'developer',
      'code',
      'coding',
      'module',
      'theme',
      'composer',
      'drush',
      'php',
      'repository',
      'repo',
      'test',
      'tests',
      'testing',
      'documentation',
      'contribution',
      'code review',
      'merge request',
      'pull request',
      'issue fork',
      'deploy',
      'deployment',
      'config management',
      'configuration management',
      'agents.md',
    ] as $needle) {
      if (str_contains($question, $needle)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Selects the most relevant Best Practices sources.
   *
   * @param \Drupal\ai_guidance\Value\GuidanceSource[] $sources
   *   Candidate sources.
   * @param string $question
   *   Current user question.
   *
   * @return \Drupal\ai_guidance\Value\GuidanceSource[]
   *   Selected sources.
   */
  private function selectSources(array $sources, string $question): array {
    $terms = array_values(array_filter(
      preg_split('/[^a-z0-9_]+/', $question) ?: [],
      static fn(string $term): bool => strlen($term) > 2 && !in_array($term, [
        'the',
        'and',
        'for',
        'this',
        'that',
        'with',
        'what',
        'how',
        'should',
        'site',
        'drupal',
      ], TRUE)
    ));

    $scored = [];
    foreach ($sources as $source) {
      $haystack = strtolower($source->title . ' ' . $source->text . ' ' . json_encode($source->metadata));
      $score = 0;
      foreach ($terms as $term) {
        if (str_contains($haystack, $term)) {
          $score += 10;
        }
      }
      foreach ([
        'agent' => 25,
        'skill' => 15,
        'test' => 15,
        'documentation' => 15,
        'config' => 10,
        'deploy' => 10,
        'review' => 10,
      ] as $needle => $boost) {
        if (str_contains($question, $needle) && str_contains($haystack, $needle)) {
          $score += $boost;
        }
      }
      $scored[] = ['source' => $source, 'score' => $score];
    }

    usort($scored, static fn(array $a, array $b): int => ($b['score'] <=> $a['score'])
      ?: strcmp($a['source']->title, $b['source']->title));

    $selected = [];
    foreach ($scored as $item) {
      if ($item['score'] <= 0 && $selected !== []) {
        continue;
      }
      $selected[] = $item['source'];
      if (count($selected) >= 3) {
        break;
      }
    }

    return $selected;
  }

}
