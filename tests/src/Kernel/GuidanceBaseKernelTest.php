<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\ai_guidance\Evidence\AccessEvidenceProvider;
use Drupal\ai_guidance\Evidence\GuidanceEvidenceCollector;
use Drupal\ai_guidance\Prompt\GuidanceRedactor;
use Drupal\ai_guidance\Source\HelpTopicsSourceProvider;
use Drupal\ai_guidance\Source\HookHelpSourceProvider;
use Drupal\ai_guidance\Source\LessonSourceProvider;
use Drupal\ai_guidance\Source\SiteConfigurationSourceProvider;
use Drupal\ai_guidance\State\CurrentEntityStateProvider;
use Drupal\ai_guidance\State\CurrentRouteStateProvider;
use Drupal\ai_guidance\State\CurrentUserStateProvider;
use Drupal\ai_guidance\State\EnabledModulesStateProvider;
use Drupal\ai_guidance\State\GuidanceStateAggregator;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel smoke tests for the base AI Guidance module.
 *
 * @group ai_guidance
 */
#[RunTestsInSeparateProcesses]
final class GuidanceBaseKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'help',
    'ai',
    'ai_assistant_api',
    'ai_guidance',
  ];

  /**
   * Tests that the base services compile without optional bridges.
   */
  public function testBaseServicesCompile(): void {
    foreach ([
      'logger.channel.ai_guidance',
      'ai_guidance.redactor',
      'ai_guidance.state_aggregator',
      'ai_guidance.domain_classifier',
      'ai_guidance.evidence_collector',
      GuidanceStateAggregator::class,
      GuidanceEvidenceCollector::class,
      AccessEvidenceProvider::class,
      CurrentRouteStateProvider::class,
      CurrentEntityStateProvider::class,
      CurrentUserStateProvider::class,
      EnabledModulesStateProvider::class,
      HookHelpSourceProvider::class,
      HelpTopicsSourceProvider::class,
      LessonSourceProvider::class,
      SiteConfigurationSourceProvider::class,
    ] as $service_id) {
      $this->assertTrue($this->container->has($service_id), sprintf('Service %s is registered.', $service_id));
      $this->assertIsObject($this->container->get($service_id));
    }
  }

  /**
   * Tests packaged Markdown lessons are discoverable in Drupal.
   */
  public function testBundledMarkdownLessonsAreDiscoverable(): void {
    $provider = $this->container->get(LessonSourceProvider::class);
    $this->assertInstanceOf(LessonSourceProvider::class, $provider);

    $sources = iterator_to_array($provider->getSources(
      new GuidanceRequest('Show me the Lesson 1 overview.', new AnonymousUserSession()),
      new GuidanceState([]),
    ));

    $this->assertCount(1, $sources);
    $this->assertSame('lesson_package', $sources[0]->type);
    $this->assertSame('ai_guidance.lesson_1_safe_draft_content', $sources[0]->canonicalId);
    $this->assertStringContainsString('What You Will Learn', $sources[0]->text);
  }

  /**
   * Tests that the read-only AI Assistant context action plugins are discovered.
   */
  public function testAiAssistantContextActionsAreDiscoverable(): void {
    $this->assertTrue($this->container->has('ai_assistant_api.action_plugin.manager'));
    $definitions = $this->container
      ->get('ai_assistant_api.action_plugin.manager')
      ->getDefinitions();

    foreach ([
      'ai_guidance_site_state_context',
      'ai_guidance_site_config_context',
      'ai_guidance_help_context',
    ] as $plugin_id) {
      $this->assertArrayHasKey($plugin_id, $definitions);
    }
  }

  /**
   * Tests tagged evidence providers can produce a sanitized report.
   */
  public function testEvidenceCollectorBuildsReport(): void {
    $collector = $this->container->get('ai_guidance.evidence_collector');
    $this->assertInstanceOf(GuidanceEvidenceCollector::class, $collector);

    $state = new GuidanceState([
      'route' => [
        'name' => 'system.admin_content',
        'path' => '/admin/content',
        'parameters' => [],
        'access_allowed' => FALSE,
      ],
      'request_context' => [
        'source' => 'current_request',
        'visible_page_messages' => [],
      ],
      'user' => [
        'roles' => ['anonymous'],
        'relevant_permissions' => [],
        'can_administer_ai' => FALSE,
        'can_administer_permissions' => FALSE,
        'content_type_permissions' => [],
      ],
    ]);
    $request = new GuidanceRequest(
      question: 'What can I do on this page?',
      account: new AnonymousUserSession(),
    );

    $report = $collector->collect($request, $state);
    $this->assertContains('access', $report['classified_domains']);
    $this->assertIsArray($report['evidence_providers']);
    $this->assertIsArray($report['evidence']);

    $debug_text = json_encode($report, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('Exception', $debug_text);
    $this->assertStringNotContainsString('Trace', $debug_text);
  }

  /**
   * Tests the redactor service is available for prompt-visible text.
   */
  public function testRedactorServiceIsAvailable(): void {
    $redactor = $this->container->get('ai_guidance.redactor');
    $this->assertInstanceOf(GuidanceRedactor::class, $redactor);
    $redacted = $redactor->redactText('Token github_pat_abcdefghijklmnopqrstuvwxyz1234567890');
    $this->assertStringContainsString('[redacted]', $redacted['value']);
  }

}
