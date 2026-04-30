<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\Evidence\WebformEvidenceProvider;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Tests Webform evidence collection.
 *
 * @group ai_guidance
 */
final class WebformEvidenceProviderTest extends UnitTestCase {

  /**
   * Tests Webform config evidence is summarized without raw config dumping.
   */
  public function testCollectsWebformEvidence(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('listAll')
      ->with('webform.webform.')
      ->willReturn(['webform.webform.contact']);
    $config_factory->method('get')
      ->with('webform.webform.contact')
      ->willReturn($this->config([
        'id' => 'contact',
        'title' => 'Contact',
        'status' => 'open',
        'elements' => "name:\n  '#type': textfield\nemail:\n  '#type': email\n",
        'handlers' => [
          'email_confirmation' => [
            'id' => 'email',
            'label' => 'Email confirmation',
            'status' => TRUE,
          ],
        ],
        'access' => [
          'create' => [],
          'view_any' => [],
        ],
      ]));

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')
      ->with('webform')
      ->willReturn(TRUE);

    $provider = new WebformEvidenceProvider($config_factory, $module_handler);
    $request = new GuidanceRequest(
      'What happens when I submit this form?',
      $this->createMock(AccountInterface::class),
    );

    $this->assertTrue($provider->applies($request, new GuidanceState([]), ['form_submission']));

    $evidence = $provider->collect($request, new GuidanceState([]), ['form_submission'])->toArray();

    $this->assertSame('drupal.webform', $evidence['provider_id']);
    $this->assertSame(1, $evidence['drupal_evidence']['form_count']);
    $form = $evidence['drupal_evidence']['forms'][0];
    $this->assertSame('contact', $form['id']);
    $this->assertSame(['email', 'name'], $form['element_keys']);
    $this->assertSame('email', $form['handler_summaries'][0]['plugin']);
    $this->assertContains('email', $form['handler_risk_signals']);
  }

  /**
   * Builds an immutable config mock.
   */
  private function config(array $data): ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('getRawData')->willReturn($data);
    return $config;
  }

}
