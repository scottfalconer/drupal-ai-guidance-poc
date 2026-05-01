<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Source;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceSource;
use Drupal\ai_guidance\Value\GuidanceState;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Provides hand-editable Markdown lesson packages from enabled modules.
 *
 * Any enabled module may ship public lesson packages in a `guidance_lessons`
 * directory. The Markdown file is the portable artifact; Drupal Help Topics may
 * link to or mirror it, but other tools can read the Markdown directly.
 */
final class LessonSourceProvider implements GuidanceSourceProviderInterface {

  /**
   * Logger for sanitized source diagnostics.
   */
  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly MarkdownGuidanceParser $parser,
    ?LoggerInterface $logger = NULL,
  ) {
    $this->logger = $logger ?? new NullLogger();
  }

  /**
   * {@inheritdoc}
   */
  public function getSources(GuidanceRequest $request, GuidanceState $state): iterable {
    $question = strtolower($request->question);
    foreach ($this->allSources() as $source) {
      $score = $this->sourceScore($source, $question);
      if ($score <= 0) {
        continue;
      }
      yield $source;
    }
  }

  /**
   * Returns every enabled lesson package as a source.
   *
   * @return iterable<\Drupal\ai_guidance\Value\GuidanceSource>
   *   Lesson sources.
   */
  public function allSources(): iterable {
    foreach ($this->lessonFiles() as [$module, $path]) {
      $source = $this->parser->parseFile($path, $module, 'lesson_package');
      if ($source instanceof GuidanceSource) {
        yield $source;
      }
    }
  }

  /**
   * Returns every enabled lesson package as normalized documents.
   *
   * @return iterable<array<string,mixed>>
   *   Lesson documents.
   */
  public function allDocuments(): iterable {
    foreach ($this->lessonFiles() as [$module, $path]) {
      $document = $this->parser->parseFileDocument($path, $module, 'lesson_package');
      if (is_array($document)) {
        yield $document;
      }
    }
  }

  /**
   * Finds a lesson document by lesson ID or canonical ID.
   *
   * @return array<string,mixed>|null
   *   Normalized lesson document, or NULL when not found.
   */
  public function document(string $id): ?array {
    $needle = strtolower(str_replace('-', '_', $id));
    foreach ($this->allDocuments() as $document) {
      $metadata = (array) ($document['metadata'] ?? []);
      $lesson_id = isset($metadata['lesson_id']) ? strtolower(str_replace('-', '_', (string) $metadata['lesson_id'])) : '';
      $canonical_id = strtolower((string) ($document['canonical_id'] ?? ''));
      if ($lesson_id === $needle || $canonical_id === strtolower($id)) {
        return $document;
      }
    }
    return NULL;
  }

  /**
   * Returns lesson Markdown files from enabled modules.
   *
   * @return iterable<array{0:string,1:string}>
   *   Module name and absolute or Drupal-root-relative file path.
   */
  private function lessonFiles(): iterable {
    foreach (array_keys($this->moduleHandler->getModuleList()) as $module) {
      try {
        $module_path = $this->moduleExtensionList->getPath($module);
      }
      catch (\Throwable $exception) {
        $this->logger->debug('Skipped lesson package path lookup for @module after @class.', [
          '@module' => (string) $module,
          '@class' => get_debug_type($exception),
        ]);
        continue;
      }

      $directory = rtrim($this->absoluteModulePath($module_path), '/') . '/guidance_lessons';
      if (!is_dir($directory)) {
        continue;
      }
      foreach (glob($directory . '/*.md') ?: [] as $path) {
        if (is_file($path)) {
          yield [$module, $path];
        }
      }
    }
  }

  /**
   * Converts Drupal-root-relative module paths to filesystem paths.
   */
  private function absoluteModulePath(string $module_path): string {
    if (str_starts_with($module_path, '/')) {
      return $module_path;
    }
    if (defined('DRUPAL_ROOT')) {
      return DRUPAL_ROOT . '/' . ltrim($module_path, '/');
    }
    return getcwd() . '/' . ltrim($module_path, '/');
  }

  /**
   * Scores a lesson package for the current question.
   */
  private function sourceScore(GuidanceSource $source, string $question): int {
    if (!$this->isLessonQuestion($question)) {
      return 0;
    }

    $haystack = strtolower($source->title . ' ' . $source->text . ' ' . json_encode($source->metadata));
    $specific_lesson = $this->requestedLessonId($question);
    $source_lesson = $this->sourceLessonId($source);
    if ($specific_lesson !== NULL && $source_lesson !== NULL && $specific_lesson !== $source_lesson) {
      return 0;
    }

    $score = 100 + $source->priority;
    if ($specific_lesson !== NULL && $source_lesson === $specific_lesson) {
      $score += 100;
    }

    foreach ((array) ($source->metadata['stage_prompts'] ?? []) as $prompt) {
      if (is_string($prompt) && trim($prompt) !== '' && str_contains($haystack, strtolower(trim($prompt)))) {
        $score += 10;
      }
    }

    foreach ($this->questionTerms($question) as $term) {
      if (str_contains($haystack, $term)) {
        $score += 5;
      }
    }
    return $score;
  }

  /**
   * Checks whether the question is asking for a lesson.
   */
  private function isLessonQuestion(string $question): bool {
    return str_contains($question, 'lesson')
      || str_contains($question, 'learn drupal ai')
      || str_contains($question, 'learning path');
  }

  /**
   * Gets the requested lesson ID from the question.
   */
  private function requestedLessonId(string $question): ?string {
    if (preg_match('/\blesson\s+([0-9]+)\b/', $question, $matches)) {
      return 'lesson_' . $matches[1];
    }
    return NULL;
  }

  /**
   * Gets a lesson ID from source metadata or title.
   */
  private function sourceLessonId(GuidanceSource $source): ?string {
    $lesson_id = $source->metadata['lesson_id'] ?? NULL;
    if (is_string($lesson_id) && $lesson_id !== '') {
      return strtolower(str_replace('-', '_', $lesson_id));
    }
    if (preg_match('/\blesson\s+([0-9]+)\b/i', $source->title, $matches)) {
      return 'lesson_' . $matches[1];
    }
    return NULL;
  }

  /**
   * Returns meaningful terms for lesson matching.
   *
   * @return string[]
   *   Question terms.
   */
  private function questionTerms(string $question): array {
    $stop = [
      'about',
      'and',
      'drupal',
      'lesson',
      'overview',
      'recap',
      'show',
      'start',
      'the',
    ];
    return array_values(array_unique(array_filter(
      preg_split('/[^a-z0-9_]+/', $question) ?: [],
      static fn(string $term): bool => strlen($term) > 2 && !in_array($term, $stop, TRUE)
    )));
  }

}
