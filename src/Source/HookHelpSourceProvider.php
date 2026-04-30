<?php

declare(strict_types=1);

namespace Drupal\ai_guidance\Source;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\ai_guidance\Value\GuidanceRequest;
use Drupal\ai_guidance\Value\GuidanceSource;
use Drupal\ai_guidance\Value\GuidanceState;

/**
 * Provides source documents from hook_help().
 */
final class HookHelpSourceProvider implements GuidanceSourceProviderInterface {

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly RouteMatchInterface $routeMatch,
    private readonly RendererInterface $renderer,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getSources(GuidanceRequest $request, GuidanceState $state): iterable {
    $route = $state->get('route', []);
    $route_name = is_array($route) && !empty($route['name']) ? $route['name'] : $this->routeMatch->getRouteName();
    if ($route_name) {
      $route_url = is_array($route) && !empty($route['path']) && is_string($route['path']) ? $route['path'] : NULL;
      foreach ($this->invokeHelp($route_name, 100, [
        'route_name' => $route_name,
        'url' => $route_url,
      ]) as $source) {
        yield $source;
      }
    }
  }

  /**
   * Invokes help hooks for a route name.
   *
   * @return iterable<\Drupal\ai_guidance\Value\GuidanceSource>
   *   Help sources.
   */
  private function invokeHelp(string $route_name, int $priority, array $metadata): iterable {
    foreach (array_keys($this->moduleHandler->getModuleList()) as $module) {
      if (!$this->moduleHandler->hasImplementations('help', $module)) {
        continue;
      }
      try {
        $help = $this->moduleHandler->invoke($module, 'help', [$route_name, $this->routeMatch]);
      }
      catch (\Throwable) {
        continue;
      }
      if (empty($help)) {
        continue;
      }
      [$help, $cacheability] = $this->renderHelp($help);
      if (!is_string($help)) {
        continue;
      }

      $text = GuidanceTextNormalizer::normalize($help);
      if ($text === '') {
        continue;
      }

      yield new GuidanceSource(
        id: 'hook_help:' . $route_name . ':' . $module,
        canonicalId: 'hook_help.' . $route_name . '.' . $module,
        title: 'Help for current page',
        type: 'route_help',
        text: $text,
        priority: $priority,
        citations: [
          'route_name' => $route_name,
          'module' => $module,
          'url' => $metadata['url'] ?? NULL,
        ],
        metadata: $metadata + [
          'provider_module' => $module,
          'source_class' => 'route_help',
        ],
        accessNotes: ['hook_help() output is local installed-module help.'],
        cacheability: $cacheability,
        tokenEstimate: GuidanceSource::estimateTokens($text),
      );
    }
  }

  /**
   * Renders help output and returns cacheability metadata.
   *
   * @return array{0:mixed,1:array}
   *   The rendered help output and cacheability metadata.
   */
  private function renderHelp(mixed $help): array {
    if (!is_array($help)) {
      return [$help, []];
    }

    $context = new RenderContext();
    $rendered = $this->renderer->executeInRenderContext($context, function () use ($help): string {
      return (string) $this->renderer->render($help);
    });
    $cacheability = [];
    if (!$context->isEmpty()) {
      $metadata = $context->pop();
      $cacheability = [
        'contexts' => $metadata->getCacheContexts(),
        'tags' => $metadata->getCacheTags(),
        'max_age' => $metadata->getCacheMaxAge(),
      ];
    }

    return [$rendered, $cacheability];
  }

}
