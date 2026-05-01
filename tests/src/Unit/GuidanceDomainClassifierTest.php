<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\Evidence\GuidanceDomainClassifier;

/**
 * Tests guidance domain classification.
 *
 * @group ai_guidance
 */
final class GuidanceDomainClassifierTest extends UnitTestCase {

  /**
   * Tests Drupal domains and external evidence boundaries.
   */
  public function testClassificationAndExternalBoundary(): void {
    $classifier = new GuidanceDomainClassifier();

    $domains = $classifier->classify('Why can I draft content but not publish it?');
    $this->assertContains('access', $domains);
    $this->assertContains('workflow', $domains);

    $domains = $classifier->classify('What automation runs when this content is published?');
    $this->assertContains('automation', $domains);
    $this->assertContains('workflow', $domains);

    $domains = $classifier->classify('What happens when I submit this form?');
    $this->assertContains('form_submission', $domains);
    $this->assertNotContains('automation', $domains);

    $domains = $classifier->classify('Evaluate my Lesson 1 attempt. Did I complete it safely?');
    $this->assertContains('access', $domains);
    $this->assertContains('workflow', $domains);
    $this->assertContains('field_model', $domains);
    $this->assertContains('content_visibility', $domains);

    $domains = $classifier->classify('What editorial guidance applies from our site policy context?');
    $this->assertContains('ai_feature_access', $domains);

    $external = $classifier->externalEvidenceDomains('Why do visitors see stale content behind the CDN?');
    $this->assertSame(['cdn_edge_headers', 'cdn_purge_status'], $external);
  }

}
