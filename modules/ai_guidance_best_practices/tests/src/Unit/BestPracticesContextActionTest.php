<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance_best_practices\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance_best_practices\Plugin\AiAssistantAction\BestPracticesContextAction;

/**
 * Tests AI Best Practices context intent gating.
 *
 * @group ai_guidance
 */
final class BestPracticesContextActionTest extends UnitTestCase {

  /**
   * Tests editor and beginner questions do not pull developer corpus context.
   */
  public function testEditorQuestionsAreNotBestPracticesRelevant(): void {
    self::assertFalse($this->isRelevant('How do I configure Drupal AI safely for content editors?'));
    self::assertFalse($this->isRelevant('What are safe best practices for content editors?'));
    self::assertFalse($this->isRelevant('I am new to Drupal. What is the first safe exercise I should do?'));
  }

  /**
   * Tests developer and outside-agent questions can pull the corpus.
   */
  public function testDeveloperQuestionsAreBestPracticesRelevant(): void {
    self::assertTrue($this->isRelevant('What should I tell an outside coding agent before it works on this site?'));
    self::assertTrue($this->isRelevant('How should a developer add a custom module with Composer and Drush?'));
    self::assertTrue($this->isRelevant('How should a developer review a custom theme change?'));
    self::assertTrue($this->isRelevant('What automated tests and documentation should this merge request include?'));
  }

  /**
   * Calls the private classifier without booting plugin dependencies.
   */
  private function isRelevant(string $question): bool {
    $reflection = new \ReflectionClass(BestPracticesContextAction::class);
    $action = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('isRelevant');
    $method->setAccessible(TRUE);
    return (bool) $method->invoke($action, strtolower($question));
  }

}
