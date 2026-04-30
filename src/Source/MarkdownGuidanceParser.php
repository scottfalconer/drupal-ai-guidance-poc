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
    if (!is_readable($path) || !str_ends_with($path, '.md')) {
      return NULL;
    }

    $contents = file_get_contents($path);
    if ($contents === FALSE || trim($contents) === '') {
      return NULL;
    }

    return $this->parse($contents, $package, $path, $source_type);
  }

  /**
   * Parses Markdown text into a source.
   */
  public function parse(string $contents, string $package, string $path, string $source_type = 'generic_guidance'): ?GuidanceSource {
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

    return new GuidanceSource(
      id: $package . ':' . $relative_path,
      canonicalId: $canonical_id,
      title: (string) $title,
      type: $source_type,
      text: $text,
      priority: $priority,
      citations: array_filter([
        'source_url' => is_string($source_url) ? $source_url : NULL,
        'path' => $relative_path,
        'version' => $metadata['version'] ?? NULL,
      ]),
      metadata: $metadata + [
        'package' => $package,
        'path' => $relative_path,
        'source_class' => $source_type === 'best_practices' ? 'best_practices' : 'generic_guidance',
      ],
      accessNotes: ['Markdown guidance file is public package content.'],
      tokenEstimate: GuidanceSource::estimateTokens($text),
    );
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
