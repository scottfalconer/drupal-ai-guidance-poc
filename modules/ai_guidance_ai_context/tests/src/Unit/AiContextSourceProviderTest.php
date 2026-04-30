<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_guidance_ai_context\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\Core\Session\AccountInterface;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceSource;
use Drupal\ai_guidance\Value\GuidanceState;
use Drupal\ai_guidance_ai_context\Source\AiContextSourceProvider;

/**
 * Tests CCC and site architecture guidance source projection.
 *
 * @group ai_guidance
 */
final class AiContextSourceProviderTest extends UnitTestCase {

  /**
   * Tests site behavior contracts keep their operating-contract fields.
   */
  public function testSiteArchitectureProjectionIncludesOperatingContractFields(): void {
    $repository = new class {
      /**
       * Lists generated contracts.
       *
       * @return array<int, array<string, mixed>>
       *   Contracts.
       */
      public function list(bool $include_internal = FALSE): array {
        return [
          [
            'schema_version' => 'drupal.site_behavior_contract.v0',
            'contract_id' => 'view:news:page_1',
            'resource_uri' => 'drupal://site/contracts/view:news:page_1',
            'source_hash' => 'abc123',
            'path' => '/news',
            'contract_type' => 'view_collection',
            'effective_responder' => ['type' => 'drupal_view'],
            'semantic_surface' => ['type' => 'collection'],
            'content_source' => [
              'entity_type' => 'node',
              'bundle' => 'article',
            ],
            'audience' => ['tags' => ['public_candidate', 'content_agent']],
            'confidence' => ['level' => 'medium'],
          ],
        ];
      }

      /**
       * Gets the default structured payload projection.
       *
       * @return array<string, mixed>|null
       *   Payload.
       */
      public function getPayload(string $contract_id, bool $include_internal = FALSE): ?array {
        return [
          'schema_version' => 'drupal.site_behavior_contract.v0',
          'contract_id' => $contract_id,
          'resource_uri' => 'drupal://site/contracts/view:news:page_1',
          'source_hash' => 'abc123',
          'path' => '/news',
          'contract_type' => 'view_collection',
          'effective_responder' => ['type' => 'drupal_view'],
          'semantic_surface' => ['type' => 'collection'],
          'content_source' => [
            'entity_type' => 'node',
            'bundle' => 'article',
          ],
          'audience' => ['tags' => ['public_candidate', 'content_agent']],
          'confidence' => ['level' => 'medium'],
          'action_owners' => [
            'add_item' => [
              'owner' => 'node.article',
              'action' => 'create_content',
            ],
          ],
          'negative_contracts' => [
            'Do not create a static page at /news.',
          ],
          'validation' => [
            'checks' => [
              [
                'type' => 'view_contains_entity',
                'view_id' => 'news',
                'display_id' => 'page_1',
              ],
            ],
          ],
          'known_unknowns' => [
            [
              'type' => 'unsupported_filter',
              'message' => 'A custom filter requires review.',
            ],
          ],
          'provenance' => [
            'views.view.news',
            'node.type.article',
          ],
        ];
      }
    };

    $provider = new AiContextSourceProvider(new \stdClass(), $repository);
    $sources = iterator_to_array($provider->getSources(
      new GuidanceRequest(
        question: 'How do I add a news item?',
        account: $this->accountWithPermissions(['administer site configuration']),
      ),
      new GuidanceState(['route' => ['path' => '/news']]),
    ));

    $contract_sources = array_values(array_filter(
      $sources,
      static fn (GuidanceSource $source): bool => $source->id === 'site_architecture:view:news:page_1',
    ));
    self::assertCount(1, $contract_sources);

    $source = $contract_sources[0];
    self::assertSame('site_architecture_context', $source->type);
    self::assertSame('drupal.site_behavior_contract.v0', $source->metadata['schema_version']);
    self::assertSame('abc123', $source->metadata['source_hash']);
    self::assertSame('ai_context_site_architecture.contract_repository', $source->metadata['projection_source']);
    self::assertTrue($source->metadata['preserves_operating_contract_fields']);
    self::assertStringContainsString('## Action owners', $source->text);
    self::assertStringContainsString('`add_item`: owner=node.article; action=create_content', $source->text);
    self::assertStringContainsString('## Negative contracts', $source->text);
    self::assertStringContainsString('Do not create a static page at /news.', $source->text);
    self::assertStringContainsString('## Validation checks', $source->text);
    self::assertStringContainsString('type=view_contains_entity; view_id=news; display_id=page_1', $source->text);
    self::assertStringContainsString('## Known unknowns', $source->text);
    self::assertStringContainsString('unsupported_filter: A custom filter requires review.', $source->text);
    self::assertStringContainsString('## Provenance', $source->text);
    self::assertStringContainsString('`views.view.news`', $source->text);
    self::assertStringContainsString('Source hash: `abc123`', $source->text);
  }

  /**
   * Tests low-permission projections omit admin-derived identifiers.
   */
  public function testSiteArchitectureProjectionOmitsAdminDetailsForLimitedAccounts(): void {
    $repository = new class {
      /**
       * Lists generated contracts.
       *
       * @return array<int, array<string, mixed>>
       *   Contracts.
       */
      public function list(bool $include_internal = FALSE): array {
        return [
          [
            'schema_version' => 'drupal.site_behavior_contract.v0',
            'contract_id' => 'eca.eca.auth_redirects',
            'resource_uri' => 'drupal://site/contracts/eca.eca.auth_redirects',
            'source_hash' => 'abc123',
            'path' => '/news',
            'contract_type' => 'automation',
            'effective_responder' => ['type' => 'eca'],
            'semantic_surface' => ['type' => 'automation'],
            'content_source' => [
              'entity_type' => 'node',
              'bundle' => 'article',
            ],
            'audience' => ['tags' => ['public_candidate', 'content_agent']],
            'confidence' => ['level' => 'medium'],
            'provenance' => [
              'eca.eca.auth_redirects',
              'webform.webform.contact',
            ],
          ],
        ];
      }

      /**
       * Gets the default structured payload projection.
       *
       * @return array<string, mixed>|null
       *   Payload.
       */
      public function getPayload(string $contract_id, bool $include_internal = FALSE): ?array {
        return [
          'schema_version' => 'drupal.site_behavior_contract.v0',
          'contract_id' => $contract_id,
          'resource_uri' => 'drupal://site/contracts/eca.eca.auth_redirects',
          'source_hash' => 'abc123',
          'path' => '/news',
          'contract_type' => 'automation',
          'effective_responder' => ['type' => 'eca'],
          'semantic_surface' => ['type' => 'automation'],
          'content_source' => [
            'entity_type' => 'node',
            'bundle' => 'article',
          ],
          'audience' => ['tags' => ['public_candidate', 'content_agent']],
          'confidence' => ['level' => 'medium'],
          'provenance' => [
            'eca.eca.auth_redirects',
            'webform.webform.contact',
          ],
        ];
      }
    };

    $provider = new AiContextSourceProvider(new \stdClass(), $repository);
    $sources = iterator_to_array($provider->getSources(
      new GuidanceRequest(
        question: 'What should an outside coding agent know?',
        account: $this->accountWithPermissions([]),
      ),
      new GuidanceState(['route' => ['path' => '/news']]),
    ));

    $contract_sources = array_values(array_filter(
      $sources,
      static fn (GuidanceSource $source): bool => $source->title === 'Current page site architecture: /news',
    ));
    self::assertCount(1, $contract_sources);

    $source = $contract_sources[0];
    self::assertSame('limited_for_current_account', $source->metadata['details']);
    self::assertStringNotContainsString('Contract ID', $source->text);
    self::assertStringNotContainsString('Resource URI', $source->text);
    self::assertStringNotContainsString('Source hash', $source->text);
    self::assertStringNotContainsString('## Provenance', $source->text);
    self::assertStringNotContainsString('eca.eca.auth_redirects', $source->text);
    self::assertStringNotContainsString('webform.webform.contact', $source->text);
  }

  /**
   * Builds an account mock with specified permissions.
   *
   * @param string[] $permissions
   *   Granted permissions.
   */
  private function accountWithPermissions(array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));
    return $account;
  }

}
