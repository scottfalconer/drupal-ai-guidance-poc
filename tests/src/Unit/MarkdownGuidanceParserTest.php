<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\Source\MarkdownGuidanceParser;

/**
 * Tests Markdown guidance source parsing.
 *
 * @group ai_guidance
 */
final class MarkdownGuidanceParserTest extends UnitTestCase {

  /**
   * Tests front matter and fallback parsing.
   */
  public function testParsesFrontMatterAndFallbacks(): void {
    $parser = new MarkdownGuidanceParser();
    $source = $parser->parse(<<<'MD'
---
title: Safe AI configuration for content editors
canonical_id: ai_best_practices.site_builder.safe_editor_ai
audience: site_builder
track: inside_drupal
source_url: https://example.com/source
priority: 80
version: 1
---

# Ignored fallback title

Use safe manual steps.
MD, 'ai_best_practices', '/app/vendor/drupal/ai_best_practices/guidance/safe-ai.md', 'best_practices');

    $this->assertNotNull($source);
    $this->assertSame('Safe AI configuration for content editors', $source->title);
    $this->assertSame('ai_best_practices.site_builder.safe_editor_ai', $source->canonicalId);
    $this->assertSame('best_practices', $source->type);
    $this->assertSame(80, $source->priority);
    $this->assertSame('https://example.com/source', $source->citations['source_url']);
    $this->assertStringContainsString('Use safe manual steps.', $source->text);

    $fallback = $parser->parse("# A fallback title\n\nBody", 'ai_best_practices', '/app/vendor/drupal/ai_best_practices/docs/example.md');
    $this->assertNotNull($fallback);
    $this->assertSame('A fallback title', $fallback->title);
    $this->assertSame('ai_best_practices.docs.example.md', $fallback->canonicalId);

    $without_trailing_newline = $parser->parse("---\ntitle: No trailing newline\n---\nBody", 'ai_best_practices', '/app/vendor/drupal/ai_best_practices/docs/no-newline.md');
    $this->assertNotNull($without_trailing_newline);
    $this->assertSame('No trailing newline', $without_trailing_newline->title);
  }

  /**
   * Tests normalized document output for tool-facing lesson exports.
   */
  public function testParsesGuidanceDocumentSectionsAndMetadata(): void {
    $parser = new MarkdownGuidanceParser();
    $document = $parser->parseDocument(<<<'MD'
---
schema_version: 1
title: "Lesson 1: Create and verify draft content using your role"
canonical_id: ai_guidance.lesson_1_safe_draft_content
lesson_id: lesson_1
kind: guided_task
status: draft
domains:
  - content_creation
evidence_providers:
  - access_explain
stage_prompts:
  overview: "Show me the Lesson 1 overview."
source_url: /admin/help/topic/ai_guidance.lesson_1_safe_draft_content
---

# Lesson 1: Create and verify draft content using your role

## Overview

Learn how draft content works.

## Success Criteria

- The content is a draft.
MD, 'ai_guidance', '/app/web/modules/contrib/ai_guidance/guidance_lessons/lesson_1.md', 'lesson_package');

    $this->assertNotNull($document);
    $this->assertSame('ai_guidance.lesson_1_safe_draft_content', $document['canonical_id']);
    $this->assertSame('lesson_package', $document['type']);
    $this->assertSame('draft', $document['metadata']['status']);
    $this->assertSame(['content_creation'], $document['metadata']['domains']);
    $this->assertSame(['access_explain'], $document['metadata']['evidence_providers']);
    $this->assertSame('/admin/help/topic/ai_guidance.lesson_1_safe_draft_content', $document['citations']['source_url']);
    $this->assertArrayHasKey('lesson_1_create_and_verify_draft_content_using_your_role', $document['sections']);
    $this->assertArrayHasKey('overview', $document['sections']);
    $this->assertArrayHasKey('success_criteria', $document['sections']);
    $this->assertStringContainsString('Learn how draft content works.', $document['sections']['overview']['text']);
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $document['source_hash']);
  }

}
