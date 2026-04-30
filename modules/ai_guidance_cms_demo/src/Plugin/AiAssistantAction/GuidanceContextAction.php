<?php

declare(strict_types=1);

namespace Drupal\ai_guidance_cms_demo\Plugin\AiAssistantAction;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\ai_assistant_api\Attribute\AiAssistantAction;
use Drupal\ai_assistant_api\Base\AiAssistantActionBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Legacy combined context adapter for AI Assistant chat consumers.
 *
 * The V1 demo now enables granular AI Assistant context actions from the
 * ai_guidance module directly. This legacy action remains read-only for older
 * assistant config but no longer builds a parallel guidance prompt.
 */
#[AiAssistantAction(
  id: 'ai_guidance_context',
  label: new TranslatableMarkup('Drupal Guidance context (legacy)'),
)]
final class GuidanceContextAction extends AiAssistantActionBase {

  /**
   * Constructs the action.
   */
  public function __construct(
    array $configuration,
    PrivateTempStoreFactory $tmpStore,
  ) {
    parent::__construct($configuration, $tmpStore);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $container->get('tempstore.private'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['read_only_notice'] = [
      '#markup' => '<p>' . $this->t('This adapter only injects read-only guidance context before generation. It exposes no executable actions.') . '</p>',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function listActions(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function listContexts(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function listUsageInstructions(): array {
    return [
      'Use Drupal Guidance context as read-only evidence. Do not call actions or claim to modify the site.',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function triggerAction(string $action_id, array $parameters = []): void {
    throw new \LogicException('The Drupal Guidance context adapter is read-only and exposes no executable actions.');
  }

  /**
   * {@inheritdoc}
   */
  public function provideFewShotLearningExample(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getFunctionCallSchema(): array {
    return [];
  }

}
