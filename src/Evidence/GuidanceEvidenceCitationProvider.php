<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Evidence;

/**
 * Builds display-safe citation labels for structured Drupal evidence.
 */
final class GuidanceEvidenceCitationProvider {

  /**
   * Builds citation label lines for the site-state context.
   *
   * @param array<string, mixed> $state
   *   Safe state array.
   * @param array<string, mixed> $evidence
   *   Evidence collector report.
   *
   * @return string[]
   *   Prompt-visible citation label lines.
   */
  public function citationLines(array $state, array $evidence): array {
    $lines = [
      'Evidence and reference labels available for final Sources:',
      'Live Drupal evidence labels come from this request and this installed site; they are not external links. Drupal.org documentation labels are background references, not proof of the current site state.',
      '- [A1] Live Drupal evidence: current user roles and permissions for this request.',
      '- [R1] Live Drupal evidence: current page route and path for this request.',
    ];

    $entity = (array) ($state['entity'] ?? []);
    if (!empty($entity['type'])) {
      $label = trim((string) ($entity['label'] ?? ''));
      $bundle = trim((string) ($entity['bundle'] ?? ''));
      $lines[] = '- [E1] Current content item from Drupal Entity API'
        . ($bundle !== '' ? ': ' . $bundle : '')
        . ($label !== '' ? ' "' . $label . '"' : '')
        . ' (live Drupal evidence from this installed site).';
    }

    foreach ((array) ($evidence['evidence'] ?? []) as $item) {
      if (!is_array($item)) {
        continue;
      }
      $provider_id = (string) ($item['provider_id'] ?? '');
      $drupal = (array) ($item['drupal_evidence'] ?? []);

      if ($provider_id === 'drupal.access') {
        if (!empty($drupal['lesson_1_evaluation'])) {
          $lesson = (array) $drupal['lesson_1_evaluation'];
          $lines[] = '- [L1] Live Drupal evidence: Lesson 1 evaluation state: ' . (string) ($lesson['result_label'] ?? 'available') . '.';
        }
        if (!empty($drupal['visible_page_messages'])) {
          $lines[] = '- [M1] Live Drupal evidence: visible page status, warning, and error messages.';
        }
      }

      if ($provider_id === 'drupal.current_form') {
        $target = (array) ($drupal['entity_form_target'] ?? []);
        $label = trim(implode(' ', array_filter([
          (string) ($target['operation'] ?? ''),
          (string) ($target['entity_type'] ?? ''),
          (string) ($target['bundle'] ?? ''),
        ])));
        $lines[] = '- [F1] Live Drupal evidence: current form fields and buttons' . ($label !== '' ? ': ' . $label : '') . '.';
      }

      if ($provider_id === 'drupal.workflow') {
        $workflow = (array) ($drupal['current_workflow'] ?? []);
        $label = (string) ($workflow['label'] ?? $workflow['id'] ?? '');
        $lines[] = '- [W1] Live Drupal evidence: workflow and moderation' . ($label !== '' ? ': ' . $label : '') . '.';
      }

      if ($provider_id === 'drupal.webform') {
        $lines[] = '- [WF1] Live Drupal evidence: Webform configuration from this installed site.';
      }

      if ($provider_id === 'drupal.eca') {
        $lines[] = '- [EC1] Live Drupal evidence: ECA automation models from this installed site.';
      }
    }

    $doc_lines = $this->documentationLines($state, $evidence);
    if ($doc_lines !== []) {
      $lines[] = 'Background documentation references available for final Sources when useful:';
      array_push($lines, ...$doc_lines);
    }

    $lines[] = 'In final Sources, keep live evidence and background documentation distinct. Do not invent links for live Drupal evidence labels. When explaining a Drupal concept beyond the current site fact, include one relevant [D*] documentation link when available.';
    return array_values(array_unique($lines));
  }

  /**
   * Builds relevant Drupal.org documentation reference labels.
   *
   * @param array<string, mixed> $state
   *   Safe state array.
   * @param array<string, mixed> $evidence
   *   Evidence collector report.
   *
   * @return string[]
   *   Prompt-visible documentation source lines.
   */
  private function documentationLines(array $state, array $evidence): array {
    $domains = array_map('strval', (array) ($evidence['classified_domains'] ?? []));
    $provider_ids = [];
    foreach ((array) ($evidence['evidence'] ?? []) as $item) {
      if (is_array($item) && !empty($item['provider_id'])) {
        $provider_ids[] = (string) $item['provider_id'];
      }
    }

    $entity = (array) ($state['entity'] ?? []);
    $references = [];

    if (($entity['type'] ?? '') === 'node' || $this->hasAny($domains, ['field_model', 'content_visibility'])) {
      $references[] = [
        'Drupal docs: About nodes',
        'https://www.drupal.org/docs/core-modules-and-themes/core-modules/node-module/about-nodes',
      ];
    }
    if ($this->hasAny($domains, ['field_model', 'form_submission']) || in_array('drupal.current_form', $provider_ids, TRUE)) {
      $references[] = [
        'Drupal docs: Field UI overview',
        'https://www.drupal.org/docs/8/core/modules/field-ui/overview',
      ];
    }
    if ($this->hasAny($domains, ['workflow']) || in_array('drupal.workflow', $provider_ids, TRUE)) {
      $references[] = [
        'Drupal docs: Content moderation overview',
        'https://www.drupal.org/docs/8/core/modules/content-moderation/overview',
      ];
      $references[] = [
        'Drupal docs: Workflows overview',
        'https://www.drupal.org/docs/8/core/modules/workflows/overview',
      ];
    }
    if ($this->hasAny($domains, ['views_listing', 'content_visibility'])) {
      $references[] = [
        'Drupal docs: Views module',
        'https://www.drupal.org/docs/8/core/modules/views',
      ];
    }
    if ($this->hasAny($domains, ['form_submission']) || in_array('drupal.webform', $provider_ids, TRUE)) {
      $references[] = [
        'Drupal docs: Webform module',
        'https://www.drupal.org/docs/contributed-modules/webform',
      ];
    }
    if ($this->hasAny($domains, ['automation']) || in_array('drupal.eca', $provider_ids, TRUE)) {
      $references[] = [
        'Drupal docs: ECA for site builders',
        'https://www.drupal.org/docs/contributed-modules/eca-event-condition-action/eca-for-site-builders',
      ];
    }
    if ($this->hasAny($domains, ['ai_feature_access'])) {
      $references[] = [
        'Drupal project: AI module',
        'https://www.drupal.org/project/ai',
      ];
      $references[] = [
        'Drupal project: Context Control Center',
        'https://www.drupal.org/project/ai_context',
      ];
    }

    $lines = [];
    $seen = [];
    $index = 1;
    foreach ($references as [$title, $url]) {
      if (isset($seen[$url])) {
        continue;
      }
      $seen[$url] = TRUE;
      $lines[] = sprintf('- [D%d] [%s](%s)', $index, $title, $url);
      $index++;
      if ($index > 8) {
        break;
      }
    }
    return $lines;
  }

  /**
   * Checks whether an array contains any of the listed values.
   *
   * @param string[] $values
   *   Values to inspect.
   * @param string[] $needles
   *   Values to find.
   */
  private function hasAny(array $values, array $needles): bool {
    foreach ($needles as $needle) {
      if (in_array($needle, $values, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
