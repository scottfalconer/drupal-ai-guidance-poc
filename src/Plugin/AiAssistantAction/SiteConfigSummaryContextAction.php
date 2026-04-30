<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Plugin\AiAssistantAction;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\ai_assistant_api\Attribute\AiAssistantAction;
use Drupal\ai_guidance\Prompt\GuidanceRedactor;
use Drupal\ai_guidance\Source\SiteConfigurationSourceProvider;
use Drupal\ai_guidance\State\GuidanceStateAggregator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides fallback site architecture and configuration context.
 */
#[AiAssistantAction(
  id: 'ai_guidance_site_config_context',
  label: new TranslatableMarkup('Drupal Guidance: site configuration summary'),
)]
final class SiteConfigSummaryContextAction extends GuidanceReadOnlyActionBase {

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
    private readonly SiteConfigurationSourceProvider $sourceProvider,
    private readonly ?object $siteArchitectureRepository = NULL,
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
      $container->get(SiteConfigurationSourceProvider::class),
      $container->has('ai_context_site_architecture.contract_repository') ? $container->get('ai_context_site_architecture.contract_repository') : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function listContexts(): array {
    if ($this->authoritativeContractsEnabled()) {
      return [
        $this->contextItem('Fallback site configuration summary', [
          'Generated site architecture contracts are available through the site architecture bridge and should be treated as the authoritative site behavior evidence.',
          'The fallback configuration inventory is intentionally suppressed so it does not compete with or duplicate generated behavior contracts.',
          'If a contract does not answer the question, say what cannot be confirmed and ask a site builder to inspect the relevant route, View, block, or Canvas page.',
        ]),
      ];
    }

    $request = $this->guidanceRequest();
    $state = $this->stateAggregator->build($request);
    $sources = iterator_to_array($this->sourceProvider->getSources($request, $state), FALSE);

    return [
      $this->contextItem('Site configuration summary', array_merge([
        'This is fallback site inventory for guidance. Prefer generated site architecture contracts when the contract bridge provides them.',
        'For unresolved front-page ownership, say what is known, say what cannot be confirmed, and ask a site builder to inspect the owning route, View, block, or Canvas page before changing content.',
      ], $this->sourceLines($sources, 'S', 3))),
    ];
  }

  /**
   * Checks whether generated site architecture contracts are active.
   */
  private function authoritativeContractsEnabled(): bool {
    if ($this->siteArchitectureRepository === NULL || !isset($this->assistant)) {
      return FALSE;
    }

    if (!array_key_exists('ai_guidance_ai_context_bridge', (array) $this->assistant->get('actions_enabled'))
      || !method_exists($this->siteArchitectureRepository, 'list')) {
      return FALSE;
    }

    try {
      $contracts = $this->siteArchitectureRepository->list(FALSE);
    }
    catch (\Throwable) {
      return FALSE;
    }

    return is_array($contracts) && $contracts !== [];
  }

}
