# AI Guidance POC Notes

This repository contains the `ai_guidance` module proof-of-concept as a
standalone Drupal module tree.

## What This POC Demonstrates

- Read-only AI Assistant context actions.
- Safe current-site and current-user state.
- Current-route Help and Help Topic grounding.
- Permission-aware site configuration summaries.
- Display-safe source citations.
- Optional bridges for Context Control Center / site architecture contracts and
  AI Best Practices Markdown.
- A demo setup form that creates a dedicated read-only Drupal Guidance
  Assistant.

## Temporary Upstream Dependency

The POC currently depends on small changes to `ai_assistant_api` so context-only
AI Assistant actions can inject deterministic read-only contexts without
executable actions.

The current patch is included at:

```text
patches/ai-assistant-api-context-actions.patch
```

Those changes should be upstreamed or replaced with equivalent AI module APIs
before treating this as a normal contrib module release.

## Evaluation Posture

Local evaluation outputs, screenshots, raw LLM responses, and model-matrix dumps
should stay out of git. Keep only reusable harness code, sanitized reports, and
small fixtures when they are intentionally part of the POC.
