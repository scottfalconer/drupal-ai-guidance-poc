<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ai_assistant_api\AiAssistantActionPluginManager;
use Drupal\ai_assistant_api\Entity\AiAssistant;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Shows debug-safe AI Assistant guidance context output.
 */
final class GuidanceDebugController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $guidanceEntityTypeManager,
    private readonly AiAssistantActionPluginManager $actionPluginManager,
    private readonly RequestStack $requestStack,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('ai_assistant_api.action_plugin.manager'),
      $container->get('request_stack'),
    );
  }

  /**
   * Builds the debug page.
   */
  public function debug(): array {
    $request = $this->requestStack->getCurrentRequest();
    $question = trim((string) $request->query->get('question', 'How do I configure Drupal AI safely for content editors?'));
    $question = mb_substr($question, 0, 2000);
    $assistant = $this->guidanceEntityTypeManager->getStorage('ai_assistant')->load('drupal_guidance_assistant');
    $contexts = [];
    $enabled_actions = [];
    if ($assistant instanceof AiAssistant) {
      $enabled_actions = $assistant->get('actions_enabled') ?: [];
      $contexts = $this->actionPluginManager->listAllContexts($assistant, 'ai_guidance_debug', $enabled_actions);
    }

    $data = [
      'assistant_exists' => $assistant instanceof AiAssistant,
      'assistant_id' => 'drupal_guidance_assistant',
      'enabled_actions' => array_keys((array) $enabled_actions),
      'question' => $question,
      'contexts' => $contexts,
    ];

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-guidance-debug']],
      'intro' => [
        '#markup' => '<p>' . $this->t('This page shows the debug-safe read-only AI Assistant contexts for a sample question. It is not a custom chat UI.') . '</p>',
      ],
      'question' => [
        '#type' => 'item',
        '#title' => $this->t('Question'),
        '#markup' => Html::escape($question),
      ],
      'package' => [
        '#type' => 'details',
        '#title' => $this->t('AI Assistant contexts'),
        '#open' => TRUE,
        'json' => [
          '#type' => 'html_tag',
          '#tag' => 'pre',
          '#value' => Html::escape(Json::encode($data)),
          '#attributes' => ['style' => 'white-space: pre-wrap;'],
        ],
      ],
      '#cache' => [
        'contexts' => ['user.permissions', 'route', 'url.query_args'],
        'max-age' => 0,
      ],
    ];
  }

}
