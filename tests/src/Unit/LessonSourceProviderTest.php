<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\Source\LessonSourceProvider;
use Drupal\ai_guidance\Source\MarkdownGuidanceParser;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Tests packaged Markdown lesson source discovery.
 *
 * @group ai_guidance
 */
final class LessonSourceProviderTest extends UnitTestCase {

  /**
   * Tests enabled modules can provide hand-editable Markdown lesson packages.
   */
  public function testCollectsMarkdownLessonPackagesFromEnabledModules(): void {
    $root = $this->lessonModuleRoot();
    mkdir($root . '/guidance_lessons', 0777, TRUE);
    file_put_contents($root . '/guidance_lessons/lesson_3_editor_review.md', <<<'MD'
---
title: "Lesson 3: Review draft content"
canonical_id: "custom_lessons.lesson_3_editor_review"
lesson_id: "lesson_3"
stage_prompts:
  overview: "Show me the Lesson 3 overview."
  start: "Ok, start Lesson 3."
  evaluate: "Evaluate my Lesson 3 attempt."
  recap: "Recap Lesson 3."
tags:
  - learn_drupal_ai
  - editorial_review
source_url: "https://example.com/lessons/lesson-3"
priority: 75
---

# Lesson 3: Review draft content

## What you will learn

You will learn how Drupal separates draft review from publishing.

## What you will practice

Open one draft and leave an editorial note.
MD);

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('getModuleList')->willReturn([
      'custom_lessons' => [],
    ]);

    $module_extension_list = $this->createMock(ModuleExtensionList::class);
    $module_extension_list->method('getPath')
      ->with('custom_lessons')
      ->willReturn($root);

    $provider = new LessonSourceProvider($module_handler, $module_extension_list, new MarkdownGuidanceParser());
    $sources = iterator_to_array($provider->getSources(
      new GuidanceRequest('Show me the Lesson 3 overview.', $this->createMock(AccountInterface::class)),
      new GuidanceState([]),
    ));

    $this->assertCount(1, $sources);
    $this->assertSame('Lesson 3: Review draft content', $sources[0]->title);
    $this->assertSame('lesson_package', $sources[0]->type);
    $this->assertSame('custom_lessons.lesson_3_editor_review', $sources[0]->canonicalId);
    $this->assertSame('lesson_package', $sources[0]->metadata['source_class']);
    $this->assertSame('lesson_3', $sources[0]->metadata['lesson_id']);
    $this->assertSame('Ok, start Lesson 3.', $sources[0]->metadata['stage_prompts']['start']);
    $this->assertSame('https://example.com/lessons/lesson-3', $sources[0]->citations['source_url']);
    $this->assertStringContainsString('What you will learn', $sources[0]->text);
  }

  /**
   * Tests unrelated lesson packages are not selected for specific lesson asks.
   */
  public function testSkipsOtherNumberedLessonsForSpecificLessonQuestions(): void {
    $root = $this->lessonModuleRoot();
    mkdir($root . '/guidance_lessons', 0777, TRUE);
    file_put_contents($root . '/guidance_lessons/lesson_1.md', "---\ntitle: \"Lesson 1\"\nlesson_id: lesson_1\n---\n# Lesson 1\n");
    file_put_contents($root . '/guidance_lessons/lesson_2.md', "---\ntitle: \"Lesson 2\"\nlesson_id: lesson_2\n---\n# Lesson 2\n");

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('getModuleList')->willReturn([
      'custom_lessons' => [],
    ]);

    $module_extension_list = $this->createMock(ModuleExtensionList::class);
    $module_extension_list->method('getPath')->willReturn($root);

    $provider = new LessonSourceProvider($module_handler, $module_extension_list, new MarkdownGuidanceParser());
    $sources = iterator_to_array($provider->getSources(
      new GuidanceRequest('Ok, start Lesson 2.', $this->createMock(AccountInterface::class)),
      new GuidanceState([]),
    ));

    $this->assertCount(1, $sources);
    $this->assertSame('Lesson 2', $sources[0]->title);
  }

  /**
   * Creates an isolated temporary module path.
   */
  private function lessonModuleRoot(): string {
    $root = sys_get_temp_dir() . '/ai_guidance_lessons_' . uniqid('', TRUE);
    mkdir($root, 0777, TRUE);
    return $root;
  }

}
