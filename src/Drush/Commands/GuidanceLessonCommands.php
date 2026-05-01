<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Drush\Commands;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\ai_guidance\Source\LessonSourceProvider;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for packaged Learn Drupal AI lessons.
 */
final class GuidanceLessonCommands extends DrushCommands {

  public function __construct(
    private readonly LessonSourceProvider $lessonSourceProvider,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct();
  }

  /**
   * Creates the command from the container.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(LessonSourceProvider::class),
      $container->get('module_handler'),
    );
  }

  /**
   * Lists enabled packaged lessons.
   *
   * @return int
   *   Command exit code.
   */
  #[CLI\Command(name: 'ai-guidance:lesson-list')]
  #[CLI\Usage(name: 'drush ai-guidance:lesson-list', description: 'List packaged Learn Drupal AI lessons from enabled modules.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  public function lessonList(): int {
    $rows = [];
    foreach ($this->lessonSourceProvider->allDocuments() as $document) {
      $metadata = (array) ($document['metadata'] ?? []);
      $rows[] = [
        $metadata['lesson_id'] ?? '',
        $document['title'] ?? '',
        $metadata['status'] ?? '',
        $metadata['kind'] ?? '',
        implode(', ', (array) ($metadata['domains'] ?? [])),
      ];
    }

    if ($rows === []) {
      $this->logger()->warning(dt('No packaged lessons were found in enabled modules.'));
      return 0;
    }

    $this->io()->table(['Lesson ID', 'Title', 'Status', 'Kind', 'Domains'], $rows);
    return 0;
  }

  /**
   * Exports a packaged lesson as normalized JSON.
   *
   * @return int
   *   Command exit code.
   */
  #[CLI\Command(name: 'ai-guidance:lesson-export')]
  #[CLI\Argument(name: 'lesson_id', description: 'Lesson ID such as lesson_1, or a canonical ID.')]
  #[CLI\Option(name: 'format', description: 'Output format. Only json is currently supported.')]
  #[CLI\Usage(name: 'drush ai-guidance:lesson-export lesson_1 --format=json', description: 'Export Lesson 1 as normalized JSON.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  public function lessonExport(string $lesson_id, array $options = ['format' => 'json']): int {
    $format = strtolower((string) ($options['format'] ?? 'json'));
    if ($format !== 'json') {
      $this->logger()->error(dt('Unsupported lesson export format @format. Use json.', [
        '@format' => $format,
      ]));
      return 1;
    }

    $document = $this->lessonSourceProvider->document($lesson_id);
    if ($document === NULL) {
      $this->logger()->error(dt('No packaged lesson matched @id.', [
        '@id' => $lesson_id,
      ]));
      return 1;
    }

    $this->output()->writeln(Json::encode($document));
    return 0;
  }

  /**
   * Validates packaged lesson metadata.
   *
   * @return int
   *   Command exit code.
   */
  #[CLI\Command(name: 'ai-guidance:lesson-validate')]
  #[CLI\Usage(name: 'drush ai-guidance:lesson-validate', description: 'Validate packaged lesson front matter and stage metadata.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  public function lessonValidate(): int {
    $errors = [];
    $warnings = [];
    $count = 0;
    foreach ($this->lessonSourceProvider->allDocuments() as $document) {
      $count++;
      $label = (string) ($document['canonical_id'] ?? $document['id'] ?? 'unknown lesson');
      $metadata = (array) ($document['metadata'] ?? []);

      foreach ([
        'schema_version',
        'title',
        'canonical_id',
        'lesson_id',
        'kind',
        'status',
        'stage_prompts',
        'source_url',
        'domains',
        'evidence_providers',
      ] as $required_key) {
        if (!isset($metadata[$required_key]) && !isset($document[$required_key])) {
          $errors[] = dt('@lesson is missing @key.', [
            '@lesson' => $label,
            '@key' => $required_key,
          ]);
        }
      }

      $stage_prompts = (array) ($metadata['stage_prompts'] ?? []);
      foreach (['overview', 'start', 'evaluate', 'recap'] as $stage) {
        if (empty($stage_prompts[$stage]) || !is_string($stage_prompts[$stage])) {
          $errors[] = dt('@lesson is missing the @stage stage prompt.', [
            '@lesson' => $label,
            '@stage' => $stage,
          ]);
        }
      }

      $sections = (array) ($document['sections'] ?? []);
      foreach (['overview', 'success_criteria', 'recap'] as $section_id) {
        if (!isset($sections[$section_id])) {
          $warnings[] = dt('@lesson does not define a @section section.', [
            '@lesson' => $label,
            '@section' => str_replace('_', ' ', $section_id),
          ]);
        }
      }

      $required_modules = (array) ($metadata['requires']['modules'] ?? []);
      foreach ($required_modules as $module) {
        if (is_string($module) && !$this->moduleHandler->moduleExists($module)) {
          $warnings[] = dt('@lesson requires module @module, which is not currently enabled.', [
            '@lesson' => $label,
            '@module' => $module,
          ]);
        }
      }
    }

    if ($count === 0) {
      $this->logger()->warning(dt('No packaged lessons were found in enabled modules.'));
      return 0;
    }

    foreach ($warnings as $warning) {
      $this->logger()->warning($warning);
    }

    if ($errors !== []) {
      foreach ($errors as $error) {
        $this->logger()->error($error);
      }
      return 1;
    }

    $this->logger()->success(dt('Validated @count packaged lesson(s).', [
      '@count' => $count,
    ]));
    return 0;
  }

}
