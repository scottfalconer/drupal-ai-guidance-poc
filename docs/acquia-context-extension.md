# Acquia Context Extension Notes

AI Guidance V1 should stay Drupal-native: read-only AI Assistant context
actions, Help sources, site state, site configuration summaries, and optional
site architecture contracts.

Acquia-specific guidance is a valuable next layer, but it should live in an
optional Acquia-owned extension rather than in the base Drupal contrib module.
That keeps the public module useful without recreating Acquia Cloud, Edge, Site
Studio, Search, DAM, or Site Factory support systems inside Drupal.

## Proposed Module Shape

An optional extension such as `ai_guidance_acquia` could provide read-only
context actions for Acquia-hosted sites:

- Acquia environment context: `AH_SITE_NAME`, `AH_SITE_ENVIRONMENT`, Cloud
  application/environment labels, and whether the current site is Dev, Stage, or
  Prod.
- Cache and purge context: response cache headers, Drupal cache state, Acquia
  Purge queue depth, and Edge/Varnish purge status when available.
- Configuration workflow context: active-vs-staged config state, config
  read-only status, recent deployment metadata, and environment warnings.
- Acquia Search context: Search API index state, Acquia Search connection
  health, queue depth, document limits when available, and cron status.
- Site Studio context: API connection health, rebuild state, generated asset
  paths, and sync/import status when modules expose safe APIs.
- Acquia DAM context: API/auth status, sync queue state, mapping config, and
  last sync/webhook status when modules expose safe APIs.
- Site Factory context: site ID, domain mapping, theme assignment, and Factory
  management state when available through safe platform APIs.

These actions should emit facts and diagnostics only. They should not clear
caches, trigger deployments, run rebuilds, mutate configuration, change domains,
or call support/platform APIs that perform writes.

## Demo-Worthy Questions

- Why do anonymous users still see old content after I published this page?
- Why does the page update when I am logged in but not when I log out?
- Why did my View revert after a deployment?
- Is it safe to change this configuration directly on Production?
- Why has this new article not appeared in Acquia Search?
- Do I need a Site Studio rebuild on this environment?
- Why is this Acquia DAM asset metadata not updated in Drupal yet?
- Why is this Site Factory domain resolving to the platform but not this site?

## Guardrails

- Prefer official module APIs and Acquia platform APIs over log scraping.
- Keep secrets and credentials out of context. Redact environment variables and
  API responses before they reach the assistant.
- Include environment warnings for Production. Phrase write operations as
  administrator/platform-owner requests, not assistant actions.
- Distinguish confirmed facts from missing telemetry. If purge status, Cloud
  logs, Solr limits, or DAM sync state are unavailable, say so.
- Keep the base `ai_guidance` module independent of Acquia services. The base
  module can explain Drupal state; the optional Acquia extension can explain
  platform state.

## Non-Goals For Base AI Guidance

- Do not add Acquia Cloud credentials, support-ticket access, log downloads, or
  platform API clients to `ai_guidance`.
- Do not hard-code Acquia product workflows into the Drupal-only assistant
  prompt.
- Do not make Acquia-specific telemetry required for the Drupal Guidance demo.
- Do not turn context actions into platform automation tools.
