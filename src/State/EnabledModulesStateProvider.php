<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\State;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\ai_guidance\Value\GuidanceRequest;

/**
 * Provides enabled module and version state.
 */
final class EnabledModulesStateProvider implements GuidanceStateProviderInterface {

  public function __construct(private readonly ModuleHandlerInterface $moduleHandler) {
  }

  /**
   * {@inheritdoc}
   */
  public function getState(GuidanceRequest $request): array {
    $modules = array_keys($this->moduleHandler->getModuleList());
    sort($modules);
    $ai_modules = array_values(array_filter($modules, fn(string $module): bool => str_starts_with($module, 'ai') || in_array($module, [
      'help',
      'node',
      'workflows',
      'content_moderation',
    ], TRUE)));

    return [
      'site' => [
        'drupal_version' => \Drupal::VERSION,
        'enabled_ai_and_relevant_modules' => $ai_modules,
      ],
    ];
  }

}
