# Maintainer Review Notes

AI Guidance is a Track A Drupal-native introspection module. It should explain
what Drupal can prove from the current site, current page, current user, Help,
and optional module-owned context. Platform/vendor adapters belong in separate
modules that contribute evidence through the same model.

## Module boundaries

- `ai_guidance`: read-only state, evidence, source, redaction, citation, and
  AI Assistant context-action pipeline.
- `ai_guidance_ai_context`: optional Context Control Center bridge. Context is
  site policy evidence, not authorization.
- `ai_guidance_best_practices`: transitional Markdown guidance-source bridge.
- `ai_guidance_cms_demo`: experimental demo setup and lesson support. It is not
  the production install path.

The assistant remains read-only. It must not claim to create content, publish,
change permissions, configure providers, edit Views, alter page composition, or
execute tools.

## Public extension points

The primary public API is:

- `GuidanceEvidenceProviderInterface`
- `GuidanceEvidence`
- `GuidanceRequest`
- `GuidanceState`

Evidence providers should:

- Return compact, permission-filtered, current-account evidence.
- Include known unknowns when a claim cannot be proven.
- Distinguish Drupal evidence from external/platform evidence that is missing.
- Never expose raw secrets, prompt text, raw config dumps, hidden admin policy,
  or unredacted source text.

`GuidanceSource` is public for modules that provide human-authored source
documents such as Help Topics or policy context. New Drupal introspection should
prefer evidence providers.

`GuidanceSourceProviderInterface` is internal to the current source-action
pipeline and should not be treated as the long-term extension point.

## Access and security expectations

- Admin-derived site inventory requires `view ai guidance site inventory`,
  `administer ai guidance`, `administer site configuration`, or equivalent high
  trust permission.
- Role-matrix inspection requires permission administration. Role IDs such as
  `administrator` must not bypass permission checks.
- Caller-provided route context is advisory. Only local paths are accepted; query
  strings, fragments, absolute URLs, control characters, and suspicious values
  are rejected or stripped before route matching.
- Source text, citations, metadata, visible page messages, and state arrays are
  redacted before model-visible context is built.
- Low-permission users should receive limited summaries and next safe steps, not
  raw Views config, Canvas internals, raw policy IDs, or admin-only identifiers.
- Swallowed provider exceptions should log sanitized debug diagnostics only:
  exception class and provider/route identifiers, never source text, raw config,
  prompt contents, or secrets.

## Source and citation model

The assistant should distinguish:

- Live Drupal evidence labels such as `[A1]`, `[R1]`, `[E1]`, `[F1]`, `[W1]`.
  These are current-site evidence labels, not external links.
- Help, Help Topics, Context Control Center policy context, and package docs.
- Drupal.org or module documentation links, used as background references for
  Drupal concepts rather than proof of the current site state.

## Known limitations before contrib stabilization

- The POC currently depends on context-only AI Assistant API behavior. Keep the
  requirements warning and upstream or remove the patch dependency before a
  stable release.
- `SiteConfigurationSourceProvider`, `GuidanceAssistantSetupManager`, and the
  demo form remain large. Split them only where review risk is high and keep
  behavior intact during cleanup.
- The fallback site summary is fallback inventory, not an authoritative site
  architecture contract. Prefer `ai_context_site_architecture` contracts when
  available.
- Demo lessons are product examples. They should not be required for production
  installation.

## Review packet checklist

Before sharing code, include:

- Exact branch and diff scope.
- Exact validation commands and results.
- Known limitations and deferred issues.
- Whether optional modules were present or absent during testing.
- Manual browser smoke notes for Lesson 1 and Lesson 2.
- Drupal.org AI-generated-content disclosure reminder.

Drupal.org contributors should disclose AI assistance where required, review and
understand all generated output, test it, fix issues before posting, and
collaborate constructively with maintainers.
