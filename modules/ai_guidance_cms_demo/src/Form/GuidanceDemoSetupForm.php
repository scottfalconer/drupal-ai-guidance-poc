<?php

declare(strict_types=1);

namespace Drupal\ai_guidance_cms_demo\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ai_guidance_cms_demo\Service\GuidanceAssistantSetupManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Setup form for the Drupal Guidance Assistant demo.
 */
final class GuidanceDemoSetupForm extends FormBase {

  /**
   * Creates or updates the demo assistant.
   */
  protected GuidanceAssistantSetupManager $setupManager;

  /**
   * Constructs the form.
   */
  public function __construct(GuidanceAssistantSetupManager $setup_manager) {
    $this->setupManager = $setup_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('ai_guidance_cms_demo.setup_manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_guidance_cms_demo_setup_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $status = $this->setupManager->status();

    $form['intro'] = [
      '#markup' => '<p><strong>' . $this->t('Demo claim:') . '</strong> '
        . $this->t('Drupal chat can answer from this site\'s route, content model, Help, permissions, and guidance sources through read-only AI Assistant context actions.')
        . '</p><p>'
        . $this->t('Start here, refresh the assistant, then open chat and ask: "What can I do on this page?"')
        . '</p>',
    ];

    $form['readiness'] = [
      '#type' => 'details',
      '#title' => $this->t('Demo readiness'),
      '#open' => TRUE,
    ];
    $form['readiness']['checks'] = [
      '#theme' => 'item_list',
      '#items' => $this->readinessItems($status),
    ];

    $form['prompts'] = [
      '#type' => 'details',
      '#title' => $this->t('Try these prompts'),
      '#open' => TRUE,
    ];
    $form['prompts']['items'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('What can I do on this page?'),
        $this->t('How do I configure Drupal AI safely for content editors?'),
        $this->t('Why can content editors draft content, but not configure AI providers or permissions?'),
        $this->t('Compare what anonymous, content editor, and administrator can do on this page.'),
        $this->t('How is this site built?'),
        $this->t('How should an outside coding agent work on this site?'),
      ],
    ];

    $form['bridge_note'] = [
      '#markup' => '<p><em>'
        . $this->t('The V1 integration path is AI Assistant context actions. Generated site architecture contracts remain the source of truth when available.')
        . '</em></p>',
    ];
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Refresh demo assistant'),
      '#button_type' => 'primary',
    ];
    $form['actions']['debug'] = [
      '#type' => 'link',
      '#title' => $this->t('Review assistant contexts'),
      '#url' => Url::fromRoute('ai_guidance.debug', [], [
        'query' => [
          'question' => 'How do I configure Drupal AI safely for content editors?',
        ],
      ]),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];
    return $form;
  }

  /**
   * Builds readiness checklist items from setup status.
   */
  private function readinessItems(array $status): array {
    $best_practices_status = $this->t('Optional');
    $best_practices_value = $this->t('Editor prompts still use local state and Help. Developer prompts get stronger when this package is installed.');
    if ($status['best_practices_available']) {
      $best_practices_status = $this->t('Ready');
      $best_practices_value = $this->t('Learn Drupal AI / AI Best Practices Markdown can be cited when relevant.');
    }
    elseif ($status['best_practices_module_enabled']) {
      $best_practices_status = $this->t('Package missing');
      $best_practices_value = $this->t('The bridge module is enabled, but the Composer package was not found.');
    }

    $rows = [
      [
        'label' => $this->t('Guidance assistant'),
        'status' => $status['assistant_exists'] ? $this->t('Ready') : $this->t('Not created yet'),
        'value' => $this->t('One dedicated assistant is used for the read-only demo.'),
      ],
      [
        'label' => $this->t('Default chat provider'),
        'status' => $status['provider_configured'] ? $this->t('Ready') : $this->t('Needs setup'),
        'value' => $this->t('A provider/model is needed before the chat can answer.'),
      ],
      [
        'label' => $this->t('Local Help sources'),
        'status' => $status['help_available'] ? $this->t('Ready') : $this->t('Unavailable'),
        'value' => $this->t('Route-aware Help and hook_help() text ground the current-page answer.'),
      ],
      [
        'label' => $this->t('AI Best Practices bridge'),
        'status' => $best_practices_status,
        'value' => $best_practices_value,
      ],
      [
        'label' => $this->t('Context Control Center bridge'),
        'status' => $status['ccc_source_available'] ? $this->t('Ready') : $this->t('Optional'),
        'value' => $status['ccc_available']
          ? $this->t('CCC is installed; enable the guidance bridge to include scoped context items.')
          : $this->t('The demo works without CCC. Site policy context is richer when CCC is available.'),
      ],
      [
        'label' => $this->t('Actions'),
        'status' => empty($status['mutation_capable_actions_enabled'])
          ? $this->t('Read-only')
          : $this->t('Review needed'),
        'value' => $this->t('V1 guides users with manual steps and does not enable mutation-capable actions.'),
      ],
    ];

    $items = [];
    foreach ($rows as $row) {
      $items[] = [
        '#markup' => '<strong>' . $row['label'] . ':</strong> ' . $row['status'] . ' - ' . $row['value'],
      ];
    }

    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->setupManager->createOrUpdate();
    $this->messenger()->addStatus($this->t('Drupal Guidance Assistant @operation: @id', [
      '@operation' => $result['created'] ? 'created' : 'updated',
      '@id' => $result['assistant_id'],
    ]));
    if (!$result['provider_configured']) {
      $this->messenger()->addWarning($this->t('Configure a default chat provider/model before using the demo assistant.'));
    }
    if (!$result['best_practices_available']) {
      $this->messenger()->addStatus($this->t(
        'Optional AI Best Practices Markdown was not detected. The assistant will still use local Help and site state.'
      ));
    }
  }

}
