<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance_cms_demo\Kernel;

use Drupal\ai_guidance_cms_demo\Service\GuidanceAssistantSetupManager;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel smoke tests for the experimental CMS demo submodule.
 *
 * @group ai_guidance
 */
#[RunTestsInSeparateProcesses]
final class GuidanceCmsDemoKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'help',
    'ai',
    'ai_assistant_api',
    'ai_chatbot',
    'ai_guidance',
    'ai_guidance_cms_demo',
  ];

  /**
   * Tests the demo service compiles and exposes only read-only action IDs.
   */
  public function testDemoSetupServiceCompiles(): void {
    $this->assertTrue($this->container->has('ai_guidance_cms_demo.setup_manager'));
    $manager = $this->container->get('ai_guidance_cms_demo.setup_manager');
    $this->assertInstanceOf(GuidanceAssistantSetupManager::class, $manager);

    $this->assertSame([
      'ai_guidance_site_state_context',
      'ai_guidance_site_config_context',
      'ai_guidance_help_context',
    ], GuidanceAssistantSetupManager::CORE_ACTION_IDS);
  }

  /**
   * Tests the generated assistant describes the reusable lesson flow.
   */
  public function testDemoAssistantInstructionsDescribeLessonFlow(): void {
    $manager = $this->container->get('ai_guidance_cms_demo.setup_manager');
    $manager->createOrUpdate();

    $assistant = $this->container
      ->get('entity_type.manager')
      ->getStorage('ai_assistant')
      ->load(GuidanceAssistantSetupManager::ASSISTANT_ID);
    $this->assertNotNull($assistant);

    $instructions = (string) $assistant->get('instructions');
    $this->assertStringContainsString('overview, guided task, and recap', $instructions);
    $this->assertStringContainsString('Practice task', $instructions);
    $this->assertStringContainsString('What Drupal concept this teaches', $instructions);
    $this->assertStringContainsString('Ok, start Lesson 1', $instructions);
    $this->assertStringContainsString('Recap Lesson 1', $instructions);
    $this->assertStringContainsString('Ok, start Lesson 2', $instructions);
    $this->assertStringContainsString('https://www.drupal.org/project/ai_context', $instructions);
    $this->assertStringContainsString('context guides suggestions; Drupal permissions and workflow authorize actions', $instructions);
    $this->assertStringContainsString('Recap Lesson 2', $instructions);
    $this->assertStringContainsString('#ai-learners', $instructions);
  }

}
