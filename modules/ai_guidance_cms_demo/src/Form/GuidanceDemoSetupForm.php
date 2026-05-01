<?php

declare(strict_types=1);

namespace Drupal\ai_guidance_cms_demo\Form;

use Drupal\Component\Utility\Html;
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
        . $this->t('Use this page for administrator setup. Then switch to the learner persona and ask each prompt from the page named in the recording flow.')
        . '</p><p>'
        . $this->t('Each lesson follows the same shape: overview, guided task, evidence-based evaluation, recap. Invite viewers to continue the discussion in the Drupal Slack channel @channel.', [
          '@channel' => '#ai-learners',
        ])
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

    $form['lesson_one'] = [
      '#type' => 'details',
      '#title' => $this->t('Lesson 1 recording flow'),
      '#open' => TRUE,
    ];
    $form['lesson_one']['summary'] = [
      '#markup' => '<p>'
        . $this->t('Lesson 1 teaches the first safe Drupal AI habit: before asking AI to configure, publish, automate, or change permissions, ask what your current role can safely do. The assistant should first give an overview, then wait for the learner to say @start before beginning the task.', [
          '@start' => 'Ok, start Lesson 1.',
        ])
        . '</p>',
    ];
    $form['lesson_one']['links'] = [
      '#theme' => 'item_list',
      '#items' => $this->lessonOneItems($status),
    ];
    $form['lesson_one']['prompts'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-guidance-demo-prompts']],
      'items' => [
        '#theme' => 'item_list',
        '#items' => [
          ['#markup' => $this->promptCode($this->t('Show me the Lesson 1 overview.'))],
          ['#markup' => $this->promptCode($this->t('Ok, start Lesson 1.'))],
          ['#markup' => $this->promptCode($this->t('What can I do on this page?'))],
          ['#markup' => $this->promptCode($this->t('Evaluate my Lesson 1 attempt. Did I complete the task safely?'))],
          ['#markup' => $this->promptCode($this->t('Why can I draft this Article, but not configure AI providers or permissions?'))],
          ['#markup' => $this->promptCode($this->t('How can I add this Article to the items shown on the front page?'))],
          ['#markup' => $this->promptCode($this->t('Recap Lesson 1.'))],
        ],
      ],
    ];

    $form['lesson_two'] = [
      '#type' => 'details',
      '#title' => $this->t('Lesson 2 recording flow'),
      '#open' => TRUE,
    ];
    $form['lesson_two']['summary'] = [
      '#markup' => '<p>'
        . $this->t('Lesson 2 shows module-provided policy context: a site builder adds Context Control Center guidance, then a content editor uses it on draft content without gaining administrator permissions.')
        . ' '
        . $this->t('Context Control Center is the Drupal')
        . ' '
        . $this->linkExternalText($this->t('ai_context'), 'https://www.drupal.org/project/ai_context')
        . ' '
        . $this->t('project; it manages reusable context items that can ground Drupal AI features in site policy, terminology, and editorial rules.')
        . '</p>',
    ];
    $form['lesson_two']['links'] = [
      '#theme' => 'item_list',
      '#items' => $this->lessonTwoItems($status),
    ];
    $form['lesson_two']['prompts'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-guidance-demo-prompts']],
      'items' => [
        '#theme' => 'item_list',
        '#items' => [
          ['#markup' => $this->promptCode($this->t('Show me the Lesson 2 overview.'))],
          ['#markup' => $this->promptCode($this->t('Ok, start Lesson 2.'))],
          ['#markup' => $this->promptCode($this->t('What can I do on this Context Control Center page for Lesson 2?'))],
          ['#markup' => $this->promptCode($this->t('Evaluate my Lesson 2 context setup. Is this policy context safe and usable for content editors?'))],
          ['#markup' => $this->promptCode($this->t('What editorial guidance applies to this Article draft?'))],
          ['#markup' => $this->promptCode($this->t('Using the site\'s editorial policy context, suggest improvements to this draft title and body without changing the meaning.'))],
          ['#markup' => $this->promptCode($this->t('Evaluate my Lesson 2 attempt. Did I use the site policy context safely?'))],
          ['#markup' => $this->promptCode($this->t('Since this policy context exists, can I now publish the Article or change AI provider settings?'))],
          ['#markup' => $this->promptCode($this->t('Recap Lesson 2.'))],
        ],
      ],
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
        $this->t('Show me the Lesson 1 overview.'),
        $this->t('Ok, start Lesson 1.'),
        $this->t('Evaluate my Lesson 1 attempt. Did I complete the task safely?'),
        $this->t('Recap Lesson 1.'),
        $this->t('Show me the Lesson 2 overview.'),
        $this->t('Ok, start Lesson 2.'),
        $this->t('What editorial guidance applies to this Article draft?'),
        $this->t('Since this policy context exists, can I now publish the Article or change AI provider settings?'),
        $this->t('Recap Lesson 2.'),
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
    $form['actions']['reset_lesson_one'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset Lesson 1 content'),
      '#submit' => ['::resetLessonOneSubmit'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['button--secondary'],
      ],
    ];
    $form['actions']['reset_lesson_two'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset Lesson 2 content'),
      '#submit' => ['::resetLessonTwoSubmit'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['button--secondary'],
      ],
    ];
    $form['actions']['setup_lesson_two'] = [
      '#type' => 'submit',
      '#value' => $this->t('Setup Lesson 2 policy context'),
      '#submit' => ['::setupLessonTwoSubmit'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['button--secondary'],
      ],
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
   * Builds Lesson 1 recording links.
   */
  private function lessonOneItems(array $status): array {
    $items = [];
    $items[] = [
      '#markup' => '<strong>' . $this->t('1. Administrator setup') . ':</strong> '
        . $this->t('Click Refresh demo assistant, then Reset Lesson 1 content before recording. Do this setup as an administrator.'),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('2. Switch persona') . ':</strong> '
        . ($status['content_editor_role_available']
          ? $this->t('Switch to the content editor learner account before the lesson flow.')
          : $this->t('The content_editor role was not found; use another non-admin learner role for the lesson flow.')),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('3. Overview, then start') . ':</strong> '
        . $this->t('Ask for the Lesson 1 overview from this setup page, the Lesson 1 Help Topic, or the first learner page the content editor can access. After the overview, reply @prompt to begin the guided task.', [
          '@prompt' => 'Ok, start Lesson 1.',
        ]),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('4. Ask on the real task page') . ':</strong> '
        . $this->linkPathText($this->t('Open /node/add/article'), '/node/add/article')
        . ' '
        . ($status['lesson_one_content_type_available']
          ? $this->t('(Article content type is available.)')
          : $this->t('(Article content type was not found on this site.)'))
        . ' '
        . $this->t('Ask: @prompt', ['@prompt' => 'What can I do on this page?']),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('5. Create the draft manually') . ':</strong> '
        . $this->t('Create one Article titled @title and save it as draft or unpublished.', [
          '@title' => GuidanceAssistantSetupManager::LESSON_ONE_ARTICLE_TITLE,
        ]),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('6. Verify the draft') . ':</strong> '
        . $this->linkPathText($this->t('Open /admin/content'), '/admin/content')
        . ' '
        . $this->t('and open or preview the saved Article.'),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('7. Evaluate on the saved Article edit page') . ':</strong> '
        . $this->t('Open the saved Article edit page, such as /node/{nid}/edit, then ask the Lesson 1 evaluation prompt. This is the page that can expose current entity evidence.'),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('8. Explain the role boundary') . ':</strong> '
        . $this->t('Still on the saved Article edit page, ask why this role can draft the Article but not configure AI providers or permissions.'),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('9. Show the front-page trap') . ':</strong> '
        . $this->linkPathText($this->t('Open /home'), '/home')
        . ' '
        . $this->t('and ask why the Lesson 1 Article is not shown there or how to add it to front-page items.'),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('Optional boundary proof') . ':</strong> '
        . $this->linkPathText($this->t('AI providers'), '/admin/config/ai/providers')
        . ', '
        . $this->linkPathText($this->t('permissions'), '/admin/people/permissions')
        . ', '
        . $this->linkPathText($this->t('Canvas homepage editor'), '/canvas/editor/canvas_page/1')
        . ' '
        . $this->t('are administrator or site-builder paths, not Lesson 1 editor tasks.'),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('Recap') . ':</strong> '
        . $this->t('Ask @prompt and make sure the answer summarizes the role boundary, the Drupal evidence checked, the next safe learning step, and the @channel Slack follow-up.', [
          '@prompt' => 'Recap Lesson 1.',
          '@channel' => '#ai-learners',
        ]),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('Lesson source') . ':</strong> '
        . $this->linkPathText($this->t('Open Lesson 1 Help Topic'), '/admin/help/topic/ai_guidance.lesson_1_safe_drupal_ai'),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('Debug task-page evidence') . ':</strong> '
        . $this->linkText($this->t('Review /node/add/article context'), $this->debugUrl('What can I do on this page?', '/node/add/article'))
        . ', '
        . $this->linkText($this->t('review /home context'), $this->debugUrl('I just made the Lesson 1 test Article. Why is it not shown here, and how can I add it to the front-page items?', '/home')),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('Reset state') . ':</strong> '
        . $this->countStatus(
          (int) $status['lesson_one_existing_count'],
          $this->t('No existing "Lesson 1 test article" Article is present.'),
          $this->t('1 existing "Lesson 1 test article" Article is present.'),
          $this->t('@count existing "Lesson 1 test article" Articles are present.')
        ),
    ];

    return $items;
  }

  /**
   * Builds Lesson 2 recording links.
   */
  private function lessonTwoItems(array $status): array {
    $items = [];
    $items[] = [
      '#markup' => '<strong>' . $this->t('1. Administrator setup') . ':</strong> '
        . $this->t('Click Refresh demo assistant, then Reset Lesson 2 content. If you want a deterministic starter policy, click Setup Lesson 2 policy context.'),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('2. Confirm Context Control Center') . ':</strong> '
        . ($status['ccc_available']
          ? $this->t('Context Control Center is installed on this site.')
          : $this->t('Context Control Center is not installed. Lesson 2 can still be explained, but the recording needs CCC installed to show module-provided policy context.'))
        . ' '
        . $this->t('Project:')
        . ' '
        . $this->linkExternalText($this->t('Context Control Center'), 'https://www.drupal.org/project/ai_context')
        . '.'
        . ' '
        . ($status['ccc_source_available']
          ? $this->t('The AI Guidance CCC bridge is enabled.')
          : $this->t('Enable the AI Guidance CCC bridge to inject scoped policy context into chat.')),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('3. Overview, then start') . ':</strong> '
        . $this->t('Ask for the Lesson 2 overview from this setup page or the Lesson 2 Help Topic. After the overview, reply @prompt to begin the guided task.', [
          '@prompt' => 'Ok, start Lesson 2.',
        ]),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('4. Open Context Control Center') . ':</strong> '
        . $this->linkPathText($this->t('Context item listing'), '/admin/ai/context/items')
        . ', '
        . $this->linkPathText($this->t('add context item'), '/admin/ai/context/items/add')
        . '. '
        . $this->t('Create or verify one policy context named @title.', [
          '@title' => GuidanceAssistantSetupManager::LESSON_TWO_CONTEXT_TITLE,
        ]),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('5. Evaluate the policy context') . ':</strong> '
        . $this->t('On the saved CCC context item page or listing, ask whether the policy context is safe and usable for content editors.'),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('6. Switch persona') . ':</strong> '
        . $this->t('Switch to a content editor account. The editor consumes policy context but should not administer it.'),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('7. Use policy on a draft') . ':</strong> '
        . $this->linkPathText($this->t('Open /node/add/article'), '/node/add/article')
        . ' '
        . $this->t('or the saved draft Article edit page, then ask what editorial guidance applies to the draft.'),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('8. Try a policy-guided revision') . ':</strong> '
        . $this->t('Ask for suggestions using the site policy context, then manually edit and save the draft if appropriate. The assistant should not claim to edit or publish.'),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('9. Evaluate Lesson 2') . ':</strong> '
        . $this->t('Ask whether the policy context was used safely. The expected answer should separate draft guidance from publishing, provider setup, permissions, workflows, Views, page composition, and automation.'),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('10. Recap') . ':</strong> '
        . $this->t('Ask @prompt and make sure the answer summarizes what CCC contributed, what Drupal permissions still controlled, the next safe learning step, and the @channel Slack follow-up.', [
          '@prompt' => 'Recap Lesson 2.',
          '@channel' => '#ai-learners',
        ]),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('Lesson source') . ':</strong> '
        . $this->linkPathText($this->t('Open Lesson 2 Help Topic'), '/admin/help/topic/ai_guidance.lesson_2_context_control_center'),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('Debug Lesson 2 evidence') . ':</strong> '
        . $this->linkText($this->t('Review Article policy context'), $this->debugUrl('What editorial guidance applies to this Article draft?', '/node/add/article'))
        . ', '
        . $this->linkText($this->t('review provider boundary question'), $this->debugUrl('Since this policy context exists, can I now publish the Article or change AI provider settings?', '/node/add/article')),
    ];
    $items[] = [
      '#markup' => '<strong>' . $this->t('Reset state') . ':</strong> '
        . $this->countStatus(
          (int) $status['lesson_two_context_existing_count'],
          $this->t('No starter Lesson 2 CCC policy context is present.'),
          $this->t('1 starter Lesson 2 CCC policy context is present.'),
          $this->t('@count starter Lesson 2 CCC policy contexts are present.')
        )
        . ' '
        . $this->countStatus(
          (int) $status['lesson_two_existing_count'],
          $this->t('No existing "Lesson 2 draft article" Article is present.'),
          $this->t('1 existing "Lesson 2 draft article" Article is present.'),
          $this->t('@count existing "Lesson 2 draft article" Articles are present.')
        ),
    ];

    return $items;
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
        'value' => $this->t('A provider/model is needed before the chat can answer. For recording, use the strongest available chat model; the latest stress run used gpt-5.4.'),
      ],
      [
        'label' => $this->t('Lesson 1'),
        'status' => $status['help_available'] ? $this->t('Ready') : $this->t('Unavailable'),
        'value' => $this->t('A Help Topic turns the Learn Drupal AI pattern into a safe task and evidence-based evaluation loop inside the demo.'),
      ],
      [
        'label' => $this->t('Lesson 2'),
        'status' => $status['ccc_available'] && $status['ccc_source_available'] ? $this->t('Ready') : $this->t('Optional setup'),
        'value' => $status['ccc_available'] && $status['ccc_source_available']
          ? $this->t('Context Control Center can provide site policy context for the Lesson 2 flow.')
          : $this->t('Install/enable Context Control Center and the AI Guidance CCC bridge before recording the full Lesson 2 module-provided-context story.'),
      ],
      [
        'label' => $this->t('Learner persona'),
        'status' => $status['content_editor_role_available'] ? $this->t('Ready') : $this->t('Review needed'),
        'value' => $status['content_editor_role_available']
          ? $this->t('Switch from administrator setup to a content editor account before the learner flow.')
          : $this->t('The content_editor role is missing; choose another non-admin learner role for the recording.'),
      ],
      [
        'label' => $this->t('Article task'),
        'status' => $status['lesson_one_content_type_available'] ? $this->t('Ready') : $this->t('Review needed'),
        'value' => $status['lesson_one_content_type_available']
          ? $this->t('Lesson 1 can use the existing Article content type at /node/add/article.')
          : $this->t('The Article content type is missing, so the Lesson 1 recording script needs a different safe content type.'),
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
        'value' => $status['ccc_source_available']
          ? $this->t('CCC and the guidance bridge are enabled, so scoped context items can be cited when relevant.')
          : ($status['ccc_available']
            ? $this->t('CCC is installed; enable the guidance bridge to include scoped context items.')
            : $this->t('The demo works without CCC. Site policy context is richer when CCC is available.')),
      ],
      [
        'label' => $this->t('Lesson 2 policy context'),
        'status' => $status['lesson_two_context_existing_count'] > 0 ? $this->t('Available') : $this->t('Not created'),
        'value' => $status['lesson_two_context_entity_available']
          ? $this->countStatus(
            (int) $status['lesson_two_context_existing_count'],
            $this->t('No starter Umami editorial policy context was found.'),
            $this->t('1 starter Umami editorial policy context was found.'),
            $this->t('@count starter Umami editorial policy contexts were found.')
          )
          : $this->t('CCC context item entities are not available on this site.'),
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

  /**
   * Resets bounded Lesson 1 demo content.
   */
  public function resetLessonOneSubmit(array &$form, FormStateInterface $form_state): void {
    $result = $this->setupManager->resetLessonOneArticles();
    if (!$result['available']) {
      $this->messenger()->addWarning($this->t('No node entity type is available; there is no Lesson 1 Article content to reset.'));
      return;
    }
    $this->messenger()->addStatus($this->formatPlural(
      $result['deleted'],
      'No prior Lesson 1 test article was found.',
      'Deleted @count prior Lesson 1 test articles.'
    ));
  }

  /**
   * Resets bounded Lesson 2 demo content and policy context.
   */
  public function resetLessonTwoSubmit(array &$form, FormStateInterface $form_state): void {
    $result = $this->setupManager->resetLessonTwo();
    if (!$result['node_available']) {
      $this->messenger()->addWarning($this->t('No node entity type is available; there is no Lesson 2 Article content to reset.'));
    }
    if (!$result['ccc_available']) {
      $this->messenger()->addWarning($this->t('Context Control Center is not available; no Lesson 2 policy context was reset.'));
    }
    $this->messenger()->addStatus($this->t('Deleted @articles prior Lesson 2 Article fixtures and @contexts Lesson 2 policy contexts.', [
      '@articles' => $result['deleted_articles'],
      '@contexts' => $result['deleted_contexts'],
    ]));
  }

  /**
   * Creates or updates the starter Lesson 2 policy context.
   */
  public function setupLessonTwoSubmit(array &$form, FormStateInterface $form_state): void {
    $result = $this->setupManager->setupLessonTwoContext();
    if (!$result['available']) {
      $this->messenger()->addWarning($this->t('Lesson 2 policy context was not created: @reason', [
        '@reason' => $result['reason'] ?? $this->t('Context Control Center is unavailable.'),
      ]));
      return;
    }
    $this->messenger()->addStatus($this->t('Lesson 2 policy context @operation: @id', [
      '@operation' => $result['created'] ? $this->t('created') : $this->t('updated'),
      '@id' => $result['context_id'],
    ]));
  }

  /**
   * Renders a local demo link as text.
   */
  private function linkText($text, Url $url): string {
    return '<a href="' . Html::escape($url->toString()) . '">' . Html::escape((string) $text) . '</a>';
  }

  /**
   * Formats a count without Drupal's singular-for-one trap in status copy.
   */
  private function countStatus(int $count, $zero, $one, $many): string {
    if ($count === 0) {
      return (string) $zero;
    }
    if ($count === 1) {
      return (string) $one;
    }
    return strtr((string) $many, ['@count' => (string) $count]);
  }

  /**
   * Renders an exact local path link.
   */
  private function linkPathText($text, string $path): string {
    return '<a href="' . Html::escape($path) . '">' . Html::escape((string) $text) . '</a>';
  }

  /**
   * Renders an external link.
   */
  private function linkExternalText($text, string $url): string {
    return '<a href="' . Html::escape($url) . '" rel="noopener noreferrer">' . Html::escape((string) $text) . '</a>';
  }

  /**
   * Builds a debug URL for a question and optional route context.
   */
  private function debugUrl(string $question, ?string $current_route = NULL): Url {
    $query = ['question' => $question];
    if ($current_route !== NULL) {
      $query['current_route'] = $current_route;
    }
    return Url::fromRoute('ai_guidance.debug', [], ['query' => $query]);
  }

  /**
   * Renders a prompt in inline-code formatting.
   */
  private function promptCode($prompt): string {
    return '<code>' . Html::escape((string) $prompt) . '</code>';
  }

}
