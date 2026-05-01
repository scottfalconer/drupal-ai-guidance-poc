<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\Evidence\GuidanceEvidenceCitationProvider;

/**
 * Tests evidence citation labels.
 *
 * @group ai_guidance
 */
final class GuidanceEvidenceCitationProviderTest extends UnitTestCase {

  /**
   * Tests citation labels are derived from state and evidence providers.
   */
  public function testBuildsGranularEvidenceCitationLabels(): void {
    $provider = new GuidanceEvidenceCitationProvider();

    $lines = $provider->citationLines([
      'entity' => [
        'type' => 'node',
        'bundle' => 'article',
        'label' => 'Lesson 1 test article',
      ],
    ], [
      'evidence' => [
        [
          'provider_id' => 'drupal.access',
          'drupal_evidence' => [
            'lesson_1_evaluation' => [
              'result_label' => 'Core task complete',
            ],
            'visible_page_messages' => [
              ['type' => 'status', 'text' => 'Saved.'],
            ],
          ],
        ],
        [
          'provider_id' => 'drupal.current_form',
          'drupal_evidence' => [
            'entity_form_target' => [
              'operation' => 'update',
              'entity_type' => 'node',
              'bundle' => 'article',
            ],
          ],
        ],
        [
          'provider_id' => 'drupal.workflow',
          'drupal_evidence' => [
            'current_workflow' => [
              'label' => 'Basic editorial workflow',
            ],
          ],
        ],
        [
          'provider_id' => 'drupal.webform',
          'drupal_evidence' => [],
        ],
        [
          'provider_id' => 'drupal.eca',
          'drupal_evidence' => [],
        ],
      ],
    ]);

    $this->assertContains('- [A1] Live Drupal evidence: current user roles and permissions for this request.', $lines);
    $this->assertContains('- [R1] Live Drupal evidence: current page route and path for this request.', $lines);
    $this->assertContains('- [E1] Current content item from Drupal Entity API: article "Lesson 1 test article" (live Drupal evidence from this installed site).', $lines);
    $this->assertContains('- [L1] Live Drupal evidence: Lesson 1 evaluation state: Core task complete.', $lines);
    $this->assertContains('- [M1] Live Drupal evidence: visible page status, warning, and error messages.', $lines);
    $this->assertContains('- [F1] Live Drupal evidence: current form fields and buttons: update node article.', $lines);
    $this->assertContains('- [W1] Live Drupal evidence: workflow and moderation: Basic editorial workflow.', $lines);
    $this->assertContains('- [WF1] Live Drupal evidence: Webform configuration from this installed site.', $lines);
    $this->assertContains('- [EC1] Live Drupal evidence: ECA automation models from this installed site.', $lines);
    $this->assertContains('- [D1] [Drupal docs: About nodes](https://www.drupal.org/docs/core-modules-and-themes/core-modules/node-module/about-nodes)', $lines);
    $this->assertContains('- [D2] [Drupal docs: Field UI overview](https://www.drupal.org/docs/8/core/modules/field-ui/overview)', $lines);
    $this->assertContains('- [D3] [Drupal docs: Content moderation overview](https://www.drupal.org/docs/8/core/modules/content-moderation/overview)', $lines);
    $this->assertContains('- [D4] [Drupal docs: Workflows overview](https://www.drupal.org/docs/8/core/modules/workflows/overview)', $lines);
    $this->assertContains('- [D5] [Drupal docs: Webform module](https://www.drupal.org/docs/contributed-modules/webform)', $lines);
    $this->assertContains('- [D6] [Drupal docs: ECA for site builders](https://www.drupal.org/docs/contributed-modules/eca-event-condition-action/eca-for-site-builders)', $lines);
  }

}
