<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Source;

use Drupal\ai_guidance\Value\GuidanceSource;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses Markdown guidance files with optional front matter.
 */
final class MarkdownGuidanceParser {

  /**
   * Parses a Markdown file into a source.
   */
  public function parseFile(string $path, string $package, string $source_type = 'generic_guidance'): ?GuidanceSource {
    $document = $this->parseFileDocument($path, $package, $source_type);
    if ($document === NULL) {
      return NULL;
    }

    return $this->sourceFromDocument($document);
  }

  /**
   * Parses a Markdown file into a normalized guidance document.
   *
   * @return array<string,mixed>|null
   *   Normalized document data, or NULL when the file is not usable.
   */
  public function parseFileDocument(string $path, string $package, string $source_type = 'generic_guidance'): ?array {
    if (!is_readable($path) || !str_ends_with($path, '.md')) {
      return NULL;
    }

    $contents = file_get_contents($path);
    if ($contents === FALSE || trim($contents) === '') {
      return NULL;
    }

    return $this->parseDocument($contents, $package, $path, $source_type);
  }

  /**
   * Parses Markdown text into a source.
   */
  public function parse(string $contents, string $package, string $path, string $source_type = 'generic_guidance'): ?GuidanceSource {
    $document = $this->parseDocument($contents, $package, $path, $source_type);
    if ($document === NULL) {
      return NULL;
    }

    return $this->sourceFromDocument($document);
  }

  /**
   * Parses Markdown text into a normalized guidance document.
   *
   * @return array<string,mixed>|null
   *   Normalized document data, or NULL when the body is empty.
   */
  public function parseDocument(string $contents, string $package, string $path, string $source_type = 'generic_guidance'): ?array {
    [$metadata, $body] = $this->splitFrontMatter($contents);

    $title = $metadata['title'] ?? NULL;
    if (!$title && preg_match('/^\s*#\s+(.+)$/m', $body, $matches)) {
      $title = trim($matches[1]);
    }
    $title = $title ?: basename($path, '.md');

    $relative_path = $this->relativePath($path, $package);
    $canonical_id = $metadata['canonical_id'] ?? $package . '.' . preg_replace('/[^a-z0-9_]+/', '.', strtolower($relative_path));
    $canonical_id = trim((string) $canonical_id, '.');
    $source_url = $metadata['source_url'] ?? NULL;
    $priority = isset($metadata['priority']) ? (int) $metadata['priority'] : 0;

    $text = GuidanceTextNormalizer::normalize($body);
    if ($text === '') {
      return NULL;
    }

    $metadata += [
      'package' => $package,
      'path' => $relative_path,
      'source_class' => match ($source_type) {
        'best_practices' => 'best_practices',
        'lesson_package' => 'lesson_package',
        default => 'generic_guidance',
      },
    ];

    return [
      'id' => $package . ':' . $relative_path,
      'canonical_id' => $canonical_id,
      'title' => (string) $title,
      'type' => $source_type,
      'text' => $text,
      'priority' => $priority,
      'citations' => array_filter([
        'source_url' => is_string($source_url) ? $source_url : NULL,
        'path' => $relative_path,
        'version' => $metadata['version'] ?? NULL,
      ]),
      'metadata' => $metadata,
      'sections' => $this->sections($body),
      'source_hash' => hash('sha256', $contents),
    ];
  }

  /**
   * Builds a GuidanceSource from a normalized guidance document.
   *
   * @param array<string,mixed> $document
   *   Normalized guidance document.
   */
  private function sourceFromDocument(array $document): GuidanceSource {
    return new GuidanceSource(
      id: (string) $document['id'],
      canonicalId: (string) $document['canonical_id'],
      title: (string) $document['title'],
      type: (string) $document['type'],
      text: (string) $document['text'],
      priority: (int) $document['priority'],
      citations: (array) $document['citations'],
      metadata: (array) $document['metadata'],
      accessNotes: ['Markdown guidance file is public package content.'],
      tokenEstimate: GuidanceSource::estimateTokens((string) $document['text']),
    );
  }

  /**
   * Splits optional YAML front matter from a Markdown body.
   *
   * @return array{0:array<string,mixed>,1:string}
   *   Metadata and body.
   */
  private function splitFrontMatter(string $contents): array {
    $metadata = [];
    $body = $contents;
    if (preg_match('/\A---\s*\n(.*?)\n---\s*(?:\n|\z)/s', $contents, $matches)) {
      try {
        $parsed = Yaml::parse($matches[1]);
      }
      catch (ParseException) {
        $parsed = [];
      }
      if (is_array($parsed)) {
        $metadata = $parsed;
      }
      $body = substr($contents, strlen($matches[0]));
    }

    return [$metadata, $body];
  }

  /**
   * Extracts Markdown sections keyed by stable heading IDs.
   *
   * @return array<string,array<string,mixed>>
   *   Normalized section data.
   */
  private function sections(string $body): array {
    $sections = [];
    $used_ids = [];
    $current = NULL;
    $lines = preg_split('/\R/', $body) ?: [];

    foreach ($lines as $line) {
      if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $matches)) {
        $this->flushSection($sections, $current);
        $title = trim($matches[2]);
        $id = $this->uniqueSectionId($title, $used_ids);
        $current = [
          'id' => $id,
          'title' => $title,
          'level' => strlen($matches[1]),
          'markdown_lines' => [],
        ];
        continue;
      }

      if ($current !== NULL) {
        $current['markdown_lines'][] = $line;
      }
    }
    $this->flushSection($sections, $current);

    return $sections;
  }

  /**
   * Adds the current section to the section list.
   *
   * @param array<string,array<string,mixed>> $sections
   *   Sections by ID.
   * @param array<string,mixed>|null $current
   *   Current section.
   */
  private function flushSection(array &$sections, ?array $current): void {
    if ($current === NULL) {
      return;
    }
    $markdown = trim(implode("\n", (array) $current['markdown_lines']));
    $sections[(string) $current['id']] = [
      'title' => (string) $current['title'],
      'level' => (int) $current['level'],
      'markdown' => $markdown,
      'text' => GuidanceTextNormalizer::normalize($markdown),
    ];
  }

  /**
   * Builds a unique, display-safe section ID.
   *
   * @param array<string,bool> $used_ids
   *   Already-used IDs.
   */
  private function uniqueSectionId(string $title, array &$used_ids): string {
    $base = strtolower(strip_tags($title));
    $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?: 'section';
    $base = trim($base, '_') ?: 'section';
    $id = $base;
    $suffix = 2;
    while (isset($used_ids[$id])) {
      $id = $base . '_' . $suffix;
      $suffix++;
    }
    $used_ids[$id] = TRUE;
    return $id;
  }

  /**
   * Gets a stable relative path for source metadata.
   */
  private function relativePath(string $path, string $package): string {
    $normalized = str_replace('\\', '/', $path);
    $needle = '/' . trim($package, '/') . '/';
    $position = strpos($normalized, $needle);
    if ($position !== FALSE) {
      return substr($normalized, $position + strlen($needle));
    }
    return basename($path);
  }

}
