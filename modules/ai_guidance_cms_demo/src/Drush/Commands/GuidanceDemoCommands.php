<?php

declare(strict_types=1);

namespace Drupal\ai_guidance_cms_demo\Drush\Commands;

use Drupal\ai_guidance_cms_demo\Service\GuidanceAssistantSetupManager;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for the guidance demo.
 */
final class GuidanceDemoCommands extends DrushCommands {

  public function __construct(
    private readonly GuidanceAssistantSetupManager $setupManager,
  ) {
    parent::__construct();
  }

  /**
   * Creates the command from the container.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_guidance_cms_demo.setup_manager'),
    );
  }

  /**
   * Create or update the read-only Drupal Guidance Assistant.
   */
  #[CLI\Command(name: 'ai-guidance:setup-demo')]
  #[CLI\Usage(name: 'drush ai-guidance:setup-demo', description: 'Create or update the Drupal Guidance Assistant.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  public function setupDemo(): void {
    $result = $this->setupManager->createOrUpdate();
    $this->logger()->success(dt('Drupal Guidance Assistant @operation: @id', [
      '@operation' => $result['created'] ? 'created' : 'updated',
      '@id' => $result['assistant_id'],
    ]));
    if (!$result['provider_configured']) {
      $this->logger()->warning(dt('No default chat provider/model is configured yet.'));
    }
  }

  /**
   * Removes prior Lesson 1 demo Article drafts.
   */
  #[CLI\Command(name: 'ai-guidance:reset-lesson-1')]
  #[CLI\Usage(name: 'drush ai-guidance:reset-lesson-1', description: 'Delete prior "Lesson 1 test article" nodes before recording the lesson demo.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  public function resetLessonOne(): void {
    $result = $this->setupManager->resetLessonOneArticles();
    if (!$result['available']) {
      $this->logger()->warning(dt('No node entity type is available; there is no Lesson 1 Article content to reset.'));
      return;
    }

    if ($result['deleted'] === 0) {
      $this->logger()->success(dt('No prior Lesson 1 test articles were found.'));
      return;
    }

    $this->logger()->success(dt('Deleted @count prior Lesson 1 test article(s).', [
      '@count' => $result['deleted'],
    ]));
  }

  /**
   * Removes prior Lesson 2 demo content and policy context.
   */
  #[CLI\Command(name: 'ai-guidance:reset-lesson-2')]
  #[CLI\Usage(name: 'drush ai-guidance:reset-lesson-2', description: 'Delete prior Lesson 2 Article fixtures and starter CCC policy context.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  public function resetLessonTwo(): void {
    $result = $this->setupManager->resetLessonTwo();
    if (!$result['node_available']) {
      $this->logger()->warning(dt('No node entity type is available; there is no Lesson 2 Article content to reset.'));
    }
    if (!$result['ccc_available']) {
      $this->logger()->warning(dt('Context Control Center is not available; no Lesson 2 policy context was reset.'));
    }

    $this->logger()->success(dt('Deleted @articles prior Lesson 2 Article fixture(s) and @contexts Lesson 2 CCC policy context item(s).', [
      '@articles' => $result['deleted_articles'],
      '@contexts' => $result['deleted_contexts'],
    ]));
  }

  /**
   * Creates or updates the starter Lesson 2 CCC policy context.
   */
  #[CLI\Command(name: 'ai-guidance:setup-lesson-2')]
  #[CLI\Usage(name: 'drush ai-guidance:setup-lesson-2', description: 'Create or update the starter Lesson 2 Context Control Center policy context.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  public function setupLessonTwo(): void {
    $result = $this->setupManager->setupLessonTwoContext();
    if (!$result['available']) {
      $this->logger()->warning(dt('Lesson 2 CCC policy context was not created: @reason', [
        '@reason' => $result['reason'] ?? 'Context Control Center is unavailable.',
      ]));
      return;
    }

    $this->logger()->success(dt('Lesson 2 CCC policy context @operation: @id', [
      '@operation' => $result['created'] ? 'created' : 'updated',
      '@id' => $result['context_id'],
    ]));
  }

}
