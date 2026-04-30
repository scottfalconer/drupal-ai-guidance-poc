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

  public function __construct(private readonly GuidanceAssistantSetupManager $setupManager) {
    parent::__construct();
  }

  /**
   * Creates the command from the container.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('ai_guidance_cms_demo.setup_manager'));
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

}
