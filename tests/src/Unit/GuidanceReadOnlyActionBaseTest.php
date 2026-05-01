<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance\Unit;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_guidance\Plugin\AiAssistantAction\GuidanceReadOnlyActionBase;
use Drupal\ai_guidance\Prompt\GuidanceRedactor;
use Drupal\ai_guidance\Value\GuidanceSource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests read-only guidance action formatting helpers.
 *
 * @group ai_guidance
 */
final class GuidanceReadOnlyActionBaseTest extends UnitTestCase {

  /**
   * Tests lesson instructions cover overview, start, recap, and CCC context.
   */
  public function testLessonUsageInstructionsDescribeThreeStageFlow(): void {
    $action = new class(
      [],
      $this->createMock(PrivateTempStoreFactory::class),
      $this->createMock(AccountProxyInterface::class),
      new RequestStack(),
      new GuidanceRedactor(),
    ) extends GuidanceReadOnlyActionBase {

      /**
       * {@inheritdoc}
       */
      public function listContexts(): array {
        return [];
      }

    };

    $instructions = implode("\n", $action->listUsageInstructions());

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

  /**
   * Tests source links keep visible display citation IDs.
   */
  public function testSourceLinkIncludesDisplayCitationInLinkText(): void {
    $action = new class(
      [],
      $this->createMock(PrivateTempStoreFactory::class),
      $this->createMock(AccountProxyInterface::class),
      new RequestStack(),
      new GuidanceRedactor(),
    ) extends GuidanceReadOnlyActionBase {

      /**
       * {@inheritdoc}
       */
      public function listContexts(): array {
        return [];
      }

      /**
       * Exposes source line formatting for tests.
       */
      public function exposeSourceLines(array $sources, string $prefix = 'H'): array {
        return $this->sourceLines($sources, $prefix, 1);
      }

    };

    $lines = $action->exposeSourceLines([
      new GuidanceSource(
        id: 'help:safe_editor_ai',
        canonicalId: 'help.safe_editor_ai',
        title: 'Safe AI configuration for content editors',
        type: 'help_topic',
        text: 'Keep provider setup administrator-only.',
        citations: ['url' => '/admin/help/topic/ai_guidance.safe_editor_ai'],
      ),
    ]);

    $this->assertContains('- [H1] [Safe AI configuration for content editors](/admin/help/topic/ai_guidance.safe_editor_ai) — Drupal Help Topic from an installed module', $lines);
    $this->assertContains('Source evidence follows. Use the display citation IDs shown in the source bullets; do not expose internal source IDs.', $lines);
    $this->assertContains('Source bullets include provenance. Linked source bullets point to local Help/Help Topic pages, trusted package docs, module-owned context, or public documentation when a safe URL is available.', $lines);
  }

  /**
   * Tests source evidence is redacted before context formatting.
   */
  public function testSourceLinesRedactSourceTextAndUnsafeUrls(): void {
    $action = new class(
      [],
      $this->createMock(PrivateTempStoreFactory::class),
      $this->createMock(AccountProxyInterface::class),
      new RequestStack(),
      new GuidanceRedactor(),
    ) extends GuidanceReadOnlyActionBase {

      /**
       * {@inheritdoc}
       */
      public function listContexts(): array {
        return [];
      }

      /**
       * Exposes source line formatting for tests.
       */
      public function exposeSourceLines(array $sources, string $prefix = 'S'): array {
        return $this->sourceLines($sources, $prefix, 2);
      }

    };

    $lines = $action->exposeSourceLines([
      new GuidanceSource(
        id: 'help:secret',
        canonicalId: 'help.secret',
        title: 'Secret source',
        type: 'help_topic',
        text: 'Do not expose Bearer abcdefghijklmnop or github_pat_abcdefghijklmnopqrstuvwxyz.',
        citations: [
          'url' => '/admin/help/topic/example?token=secret#fragment',
          'access_token' => 'github_pat_abcdefghijklmnopqrstuvwxyz',
        ],
        metadata: [
          'source_url' => 'https://docs.example.com/private?api_key=secret',
          'secret' => 'sk-abcdefghijklmnop',
        ],
      ),
      new GuidanceSource(
        id: 'help:metadata-secret',
        canonicalId: 'help.metadata_secret',
        title: 'Metadata URL source',
        type: 'help_topic',
        text: 'Metadata source URL should be safe.',
        metadata: [
          'source_url' => 'https://docs.example.com/private?api_key=secret#fragment',
        ],
      ),
    ]);

    $context = implode("\n", $lines);
    $this->assertContains('- [S1] [Secret source](/admin/help/topic/example) — Drupal Help Topic from an installed module', $lines);
    $this->assertContains('- [S2] [Metadata URL source](https://docs.example.com/private) — Drupal Help Topic from an installed module', $lines);
    $this->assertStringContainsString('[redacted]', $context);
    $this->assertStringNotContainsString('Bearer abcdefghijklmnop', $context);
    $this->assertStringNotContainsString('github_pat_abcdefghijklmnopqrstuvwxyz', $context);
    $this->assertStringNotContainsString('token=secret', $context);
    $this->assertStringNotContainsString('api_key=secret', $context);
    $this->assertStringNotContainsString('sk-abcdefghijklmnop', $context);
  }

  /**
   * Tests non-admin source formatting hides raw config identifiers.
   */
  public function testSourceLinesRedactAdminConfigIdentifiersForNonAdmin(): void {
    $current_user = $this->createMock(AccountProxyInterface::class);
    $current_user->method('hasPermission')->willReturn(FALSE);

    $action = new class(
      [],
      $this->createMock(PrivateTempStoreFactory::class),
      $current_user,
      new RequestStack(),
      new GuidanceRedactor(),
    ) extends GuidanceReadOnlyActionBase {

      /**
       * {@inheritdoc}
       */
      public function listContexts(): array {
        return [];
      }

      /**
       * Exposes source line formatting for tests.
       */
      public function exposeSourceLines(array $sources, string $prefix = 'C'): array {
        return $this->sourceLines($sources, $prefix, 1);
      }

    };

    $lines = $action->exposeSourceLines([
      new GuidanceSource(
        id: 'site_architecture:surface_index',
        canonicalId: 'site_architecture.surface_index',
        title: 'Generated site architecture surface index',
        type: 'site_architecture_context',
        text: 'Provenance: `eca.eca.auth_redirects`, `webform.webform.contact`, `views.view.frontpage`, drupal://site/contracts/eca.eca.auth_redirects.',
      ),
    ]);

    $context = implode("\n", $lines);
    $this->assertStringContainsString('[admin-only config identifier]', $context);
    $this->assertStringNotContainsString('eca.eca.auth_redirects', $context);
    $this->assertStringNotContainsString('webform.webform.contact', $context);
    $this->assertStringNotContainsString('views.view.frontpage', $context);
    $this->assertStringNotContainsString('drupal://site/contracts/eca.eca.auth_redirects', $context);
  }

  /**
   * Tests caller context is whitelisted and source text is redacted.
   */
  public function testRequestContextIsWhitelistedAndRedacted(): void {
    $request_stack = new RequestStack();
    $request_stack->push(Request::create(
      '/api/deepchat',
      'POST',
      [],
      [],
      [],
      [],
      json_encode([
        'contexts' => [
          'current_route' => '/node/1/edit?destination=/admin/content',
          'unsafe' => 'do not pass this through',
          'visible_page_messages' => [
            [
              'type' => 'error',
              'text' => 'Token github_pat_abcdefghijklmnopqrstuvwxyz should be redacted.',
            ],
            [
              'type' => 'debug',
              'text' => 'A debug type should become status.',
            ],
          ],
          'current_form' => [
            'form_id' => 'node_article_form',
            'action' => '/node/add/article?destination=/admin/content',
            'method' => 'post',
            'fields' => [
              [
                'name' => 'title[0][value]',
                'label' => 'Title github_pat_abcdefghijklmnopqrstuvwxyz',
                'type' => 'text',
                'required' => TRUE,
              ],
              [
                'name' => 'search_api_exclude[exclude]',
                'label' => 'Prevent this node from being indexed',
                'type' => 'checkbox',
                'required' => FALSE,
              ],
              [
                'name' => 'path[0][alias]',
                'label' => 'URL alias',
                'type' => 'text',
                'required' => FALSE,
              ],
              [
                'name' => 'revision_log[0][value]',
                'label' => 'Revision log message',
                'type' => 'textarea',
                'required' => FALSE,
              ],
            ],
            'submit_buttons' => ['Save'],
          ],
        ],
      ], JSON_THROW_ON_ERROR),
    ));

    $action = new class(
      [],
      $this->createMock(PrivateTempStoreFactory::class),
      $this->createMock(AccountProxyInterface::class),
      $request_stack,
      new GuidanceRedactor(),
    ) extends GuidanceReadOnlyActionBase {

      /**
       * {@inheritdoc}
       */
      public function listContexts(): array {
        return [];
      }

      /**
       * Exposes sanitized request context for tests.
       */
      public function exposeRequestContext(): array {
        return $this->requestContext();
      }

    };

    $context = $action->exposeRequestContext();

    $this->assertSame('/node/1/edit?destination=/admin/content', $context['current_route']);
    $this->assertArrayNotHasKey('unsafe', $context);
    $this->assertSame('error', $context['visible_page_messages'][0]['type']);
    $this->assertSame('status', $context['visible_page_messages'][1]['type']);
    $this->assertStringContainsString('[redacted]', $context['visible_page_messages'][0]['text']);
    $this->assertStringNotContainsString('github_pat_', $context['visible_page_messages'][0]['text']);
    $this->assertSame('node_article_form', $context['current_form']['form_id']);
    $this->assertSame('/node/add/article', $context['current_form']['action']);
    $this->assertStringContainsString('[redacted]', $context['current_form']['fields'][0]['label']);
    $form_context = json_encode($context['current_form'], JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('search_api_exclude', $form_context);
    $this->assertStringNotContainsString('URL alias', $form_context);
    $this->assertStringNotContainsString('Revision log', $form_context);
    $this->assertSame(['Save'], $context['current_form']['submit_buttons']);
  }

}
