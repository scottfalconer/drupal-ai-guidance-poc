<?php

declare(strict_types=1);

namespace Drupal\ai_guidance_ai_context\Plugin\AiAssistantAction;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\ai_assistant_api\Attribute\AiAssistantAction;
use Drupal\ai_guidance\Plugin\AiAssistantAction\GuidanceReadOnlyActionBase;
use Drupal\ai_guidance\Prompt\GuidanceRedactor;
use Drupal\ai_guidance\State\GuidanceStateAggregator;
use Drupal\ai_guidance_ai_context\Source\AiContextSourceProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides optional CCC/site architecture context to AI Assistant.
 */
#[AiAssistantAction(
  id: 'ai_guidance_ai_context_bridge',
  label: new TranslatableMarkup('Drupal Guidance: Context Control Center bridge'),
)]
final class AiContextBridgeContextAction extends GuidanceReadOnlyActionBase {

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
    private readonly AiContextSourceProvider $sourceProvider,
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
      $container->get(AiContextSourceProvider::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function listContexts(): array {
    $request = $this->guidanceRequest();
    $state = $this->stateAggregator->build($request);
    $sources = iterator_to_array($this->sourceProvider->getSources($request, $state), FALSE);

    if ($sources === []) {
      return [];
    }

    return [
      $this->contextItem('Site policy and architecture context', array_merge([
        'Prefer generated site behavior contracts over fallback configuration inventory when both are present.',
      ], $this->sourceLines($sources, 'C', 4))),
    ];
  }

}
