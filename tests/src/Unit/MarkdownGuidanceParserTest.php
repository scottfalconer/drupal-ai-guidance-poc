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

}
