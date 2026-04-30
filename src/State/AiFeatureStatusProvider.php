<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\State;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Provides read-only AI feature status.
 */
final class AiFeatureStatusProvider {

  public function __construct(
    private readonly AiProviderPluginManager $providerPluginManager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
  }

  public function getFeatureStatus(GuidanceRequest $request, GuidanceState $state): array {
    $default_provider = $this->providerPluginManager->getDefaultProviderForOperationType('chat');
    return [
      'ai' => [
        'chat_provider_configured' => !empty($default_provider['provider_id']) && !empty($default_provider['model_id']),
        'chatbot_available' => $this->moduleHandler->moduleExists('ai_chatbot'),
        'assistant_api_available' => $this->moduleHandler->moduleExists('ai_assistant_api'),
        'ai_search_available' => $this->moduleHandler->moduleExists('ai_search'),
        'ccc_available' => $this->moduleHandler->moduleExists('ai_context'),
        'ccc_source_available' => $this->moduleHandler->moduleExists('ai_guidance_ai_context'),
        'ai_best_practices_source_available' => $this->moduleHandler->moduleExists('ai_guidance_best_practices'),
      ],
    ];
  }

}
