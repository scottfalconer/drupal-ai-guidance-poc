# AI Guidance

Public proof-of-concept repository for a Drupal AI Guidance module.

AI Guidance provides read-only context actions for the existing Drupal AI
Assistant. It exposes safe site state, local Help / Help Topic sources,
fallback site configuration summaries, optional Context Control Center context,
and optional AI Best Practices Markdown to existing AI Assistant chat consumers.

V1 is intentionally not an agent. It does not create content, mutate
configuration, change permissions, execute Tool API tools, expose MCP resources,
require AI Search, or provide a custom chat UI.

## Modules

- `ai_guidance`: read-only AI Assistant context actions, safe state providers,
  Help / Help Topic sources, a bundled safe-editor-AI Help Topic, fallback site
  configuration summary, source normalization, redaction, and a debug page.
- `ai_guidance_best_practices`: optional source bridge for Markdown guidance in
  `drupal/ai_best_practices`.
- `ai_guidance_ai_context`: optional source bridge for Context Control Center.
- `ai_guidance_cms_demo`: setup form and Drush command that create or update a
  dedicated read-only Drupal Guidance Assistant using the context actions.

## Site architecture contracts

AI Guidance can consume generated site behavior contracts when
`ai_context_site_architecture` is available through the optional
`ai_guidance_ai_context` bridge. In that arrangement, the site architecture
module remains the authority for surface ownership, action owners, negative
contracts, validation checks, confidence, provenance, and known unknowns. AI
Guidance projects those contracts into cited, task-oriented help.

The core `ai_guidance` module also includes a small safe configuration summary
for sites without generated contracts. That summary is fallback inventory, not
an authoritative site behavior contract. When generated contracts are available,
`ai_guidance` should prefer the contract repository projection for route and
surface behavior.

## Demo setup

1. Configure a default chat provider/model in AI settings.
2. Enable `ai_guidance` and `ai_guidance_cms_demo`.
3. Optionally enable `ai_guidance_best_practices` and
   `ai_guidance_ai_context`.
4. Visit `/admin/config/ai/guidance/demo` or run
   `drush ai-guidance:setup-demo`.
5. Select the generated `Drupal Guidance Assistant` in an existing AI Assistant
   chat block.

The demo assistant injects guidance through the AI Assistant action context API
before the first model call. It does not expose executable actions.

## Evidence Providers

AI Guidance uses a small evidence-provider layer for Drupal-native
introspection. The base module can classify questions and collect structured
evidence from live Drupal state, such as access, workflow, content visibility,
Views, forms, automation, AI feature access, and outside-agent handoff.

Optional Drupal modules can add their own evidence providers later. The base
module should name external evidence that is missing, but should not diagnose or
implement systems outside Drupal itself.

## Current POC dependency

This POC currently requires small `ai_assistant_api` changes for context-only
assistants. See `patches/ai-assistant-api-context-actions.patch` and
`docs/poc-notes.md`.

## Debugging

Visit `/admin/config/ai/guidance/debug` to inspect the read-only AI Assistant
contexts for a sample question. This page shows enabled context actions,
selected sources, citations, redactions, and context text without exposing
hidden system prompt internals.

## License

GPL-2.0-or-later.
