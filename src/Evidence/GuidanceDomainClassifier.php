<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Evidence;

/**
 * Classifies a user question into guidance evidence domains.
 */
final class GuidanceDomainClassifier {

  /**
   * Classifies a user question.
   *
   * @return string[]
   *   Guidance domains.
   */
  public function classify(string $question): array {
    $question = strtolower($question);
    $domains = [];

    $rules = [
      'access' => [
        'can i',
        'can this user',
        'permission',
        'permissions',
        'role',
        'why can',
        'why cannot',
        'why can\'t',
      ],
      'workflow' => [
        'draft',
        'moderation',
        'publish',
        'published',
        'state',
        'transition',
        'unpublish',
        'workflow',
      ],
      'content_visibility' => [
        'appear',
        'front page',
        'homepage',
        'not showing',
        'shown on',
        'visibility',
        'visible',
        'why is this content not',
      ],
      'views_listing' => [
        'agenda',
        'excluded',
        'filter',
        'listing',
        'sort',
        'view',
        'views',
      ],
      'page_composition' => [
        'block',
        'canvas',
        'component',
        'layout',
        'paragraph',
        'section',
      ],
      'field_model' => [
        'bundle',
        'content type',
        'field',
        'required',
        'what makes',
      ],
      'navigation_url' => [
        'alias',
        'canonical',
        'menu',
        'navigation',
        'redirect',
        'url',
      ],
      'search_indexing' => [
        'facet',
        'index',
        'indexed',
        'search',
      ],
      'cache_metadata' => [
        'cache',
        'cached',
        'invalidate',
        'old content',
        'purge',
        'stale',
      ],
      'form_submission' => [
        'form',
        'handler',
        'submit',
        'submission',
        'webform',
      ],
      'automation' => [
        'automation',
        'eca',
        'email fired',
        'event',
        'notification',
      ],
      'ai_feature_access' => [
        'ai',
        'assistant',
        'chatbot',
        'provider',
      ],
      'outside_agent_handoff' => [
        'agent',
        'code',
        'coding agent',
        'developer',
        'handoff',
        'outside',
        'pull request',
      ],
    ];

    foreach ($rules as $domain => $needles) {
      if ($this->containsAny($question, $needles)) {
        $domains[] = $domain;
      }
    }

    if (str_contains($question, 'what happens when')
      && !$this->containsAny($question, ['form', 'submit', 'submission', 'webform'])
    ) {
      $domains[] = 'automation';
    }

    if ($domains === []) {
      $domains[] = 'general_drupal_guidance';
    }

    return array_values(array_unique($domains));
  }

  /**
   * Returns external evidence domains suggested by the question.
   *
   * @return string[]
   *   Missing external evidence domains.
   */
  public function externalEvidenceDomains(string $question): array {
    $question = strtolower($question);
    $domains = [];
    if ($this->containsAny($question, ['cdn', 'edge', 'proxy', 'varnish'])) {
      $domains[] = 'cdn_edge_headers';
      $domains[] = 'cdn_purge_status';
    }
    if ($this->containsAny($question, ['dam', 'asset browser', 'asset sync'])) {
      $domains[] = 'external_dam_status';
    }
    if ($this->containsAny($question, ['sso', 'single sign-on', 'identity provider'])) {
      $domains[] = 'external_sso_mapping';
    }
    if ($this->containsAny($question, ['environment', 'deployment', 'production', 'staging'])) {
      $domains[] = 'environment_deployment_status';
    }

    return array_values(array_unique($domains));
  }

  /**
   * Checks whether haystack contains any needle.
   *
   * @param string $haystack
   *   Text to inspect.
   * @param string[] $needles
   *   Terms.
   */
  private function containsAny(string $haystack, array $needles): bool {
    foreach ($needles as $needle) {
      if (str_contains($haystack, $needle)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
