<?php

declare(strict_types=1);

namespace Drupal\ai_guidance_best_practices\Source;

use Drupal\ai_guidance\Source\GuidanceSourceProviderInterface;
use Drupal\ai_guidance\Source\MarkdownGuidanceParser;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Reads Markdown guidance from drupal/ai_best_practices when installed.
 */
final class BestPracticesSourceProvider implements GuidanceSourceProviderInterface {

  /**
   * Constructs the source provider.
   */
  public function __construct(
    private readonly MarkdownGuidanceParser $parser,
    private readonly string $appRoot,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getSources(GuidanceRequest $request, GuidanceState $state): iterable {
    foreach ($this->candidateRoots() as $root) {
      if (!is_dir($root)) {
        continue;
      }
      $count = 0;
      foreach ($this->markdownFiles($root) as $file) {
        $source = $this->parser->parseFile($file, 'ai_best_practices', 'best_practices');
        if ($source) {
          yield $source;
          $count++;
        }
        if ($count >= 200) {
          break;
        }
      }
      return;
    }
  }

  /**
   * Returns candidate package roots.
   */
  private function candidateRoots(): array {
    return [
      dirname($this->appRoot) . '/vendor/drupal/ai_best_practices',
      $this->appRoot . '/vendor/drupal/ai_best_practices',
      dirname($this->appRoot, 2) . '/vendor/drupal/ai_best_practices',
    ];
  }

  /**
   * Finds Markdown files in the package.
   */
  private function markdownFiles(string $root): iterable {
    $files = [];
    $dirs = [
      $root . '/guidance',
      $root . '/docs',
      $root . '/skills',
    ];
    foreach ($dirs as $dir) {
      if (!is_dir($dir)) {
        continue;
      }
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
      );
      foreach ($iterator as $file) {
        if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'md') {
          $files[] = $file->getPathname();
        }
      }
    }
    sort($files, SORT_STRING);
    yield from $files;
  }

}
