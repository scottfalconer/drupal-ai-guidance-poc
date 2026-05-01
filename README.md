# AI Guidance

Public proof-of-concept repository for a Drupal AI Guidance module.

AI Guidance provides read-only context actions for the existing Drupal AI
Assistant. It exposes safe site state, local Help / Help Topic sources,
fallback site configuration summaries, optional Context Control Center context,
and optional AI Best Practices Markdown to existing AI Assistant chat consumers.

V1 is intentionally not an agent. It does not create content, mutate
configuration, change permissions, execute Tool API tools, expose MCP resources,
require AI Search, or provide a custom chat UI.

## Why this matters

Stock chat can explain Drupal in general. AI Guidance is meant to explain this
Drupal site, for this user, from read-only evidence that Drupal already knows:
routes, roles, permissions, Help, content models, workflows, Views, forms,
automation, and optional trusted source documents.

See [`docs/value-proposition.md`](docs/value-proposition.md) for the POC value
case, the current evaluation signal, and how this complements the Learn Drupal
AI education effort.

For maintainer-facing review boundaries, public extension points, security
expectations, and the final review-packet checklist, see
[`docs/maintainer-review.md`](docs/maintainer-review.md).

## Modules

- `ai_guidance`: read-only AI Assistant context actions, safe state providers,
  Help / Help Topic sources, a bundled safe-editor-AI Help Topic, fallback site
  configuration summary, source normalization, redaction, and a debug page.
- `ai_guidance_best_practices`: optional source bridge for Markdown guidance in
  `drupal/ai_best_practices`.
- `ai_guidance_ai_context`: optional source bridge for Context Control Center.
- `ai_guidance_cms_demo`: setup form and Drush command that create or update a
  dedicated read-only Drupal Guidance Assistant using the context actions. This
  submodule is experimental demo support, not the production install path.

## Packaged lessons

Learn Drupal AI-style lessons are packaged as hand-editable Markdown files with
YAML front matter in `guidance_lessons/*.md`. Drupal can use those files as
trusted AI Assistant sources, and other tools can parse the same Markdown
without rendering Drupal Help Topic Twig.

The bundled Lesson 1 and Lesson 2 packages live in:

```text
guidance_lessons/lesson_1_safe_draft_content.md
guidance_lessons/lesson_2_context_control_center.md
```

See [`docs/lessons.md`](docs/lessons.md) for the front matter fields, expected
Markdown shape, and extension pattern for other modules.

Use Drush to inspect the compiled lesson shape:

```bash
drush ai-guidance:lesson-list
drush ai-guidance:lesson-export lesson_1 --format=json
drush ai-guidance:lesson-validate
```

## Install on Drupal CMS

AI Guidance was validated on a clean Drupal CMS 2.x site with Drupal core 11.3,
Drupal AI 1.3, and the Drupal CMS AI recipe applied.

Prerequisites:

- Drupal CMS with `drupal/ai` installed.
- `ai_assistant_api` enabled. The Drupal CMS AI recipe enables this for you.
- `ai_chatbot` enabled only if you want to use the existing chat UI or the demo
  assistant.
- A configured default chat provider/model only for model-backed chat. The
  debug page and deterministic tests do not require model-provider credits.

Composer install, once this module is available as a package:

```bash
composer require drupal/ai_guidance
drush pm:enable ai_guidance -y
drush cr
```

Local POC install from this repository:

```bash
mkdir -p web/modules/contrib/ai_guidance
rsync -a \
  --exclude='.git' \
  --exclude='.ddev' \
  --exclude='vendor' \
  --exclude='web' \
  --exclude='docroot' \
  --exclude='node_modules' \
  --exclude='test_outputs' \
  /path/to/drupal-ai-guidance-poc/ \
  web/modules/contrib/ai_guidance/

drush pm:enable ai_guidance -y
drush cr
```

For the current POC, apply the temporary AI Assistant API patch before relying
on read-only context actions in chat:

```bash
cd web/modules/contrib/ai
patch -p1 < /path/to/drupal-ai-guidance-poc/patches/ai-assistant-api-context-actions.patch
drush cr
```

Without that patch, the base module still installs and the requirements page
warns about missing context-action support, but the generated assistant will not
receive the read-only context package in stock Drupal AI 1.3.4.

After enabling the base module:

- Grant `administer ai guidance` to trusted administrators.
- Visit `/admin/config/ai/guidance/debug` to inspect the context package for a
  sample question without calling a model.
- Grant `view ai guidance site inventory` only to roles that should receive
  fuller site inventory summaries.

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

1. Install and enable the base module as described above.
2. Configure a default chat provider/model in AI settings. For recording or
   evaluation, use the strongest current chat model available; the latest local
   stress run used `gpt-5.4` to make the stock baseline more competitive.
3. Enable `ai_guidance_cms_demo`.
4. Optionally enable `ai_guidance_best_practices`.
5. Enable `ai_guidance_ai_context` only when Context Control Center /
   `ai_context` is installed.
6. Visit `/admin/config/ai/guidance/demo` or run
   `drush ai-guidance:setup-demo`.
7. Select the generated `Drupal Guidance Assistant` in an existing AI Assistant
   chat block.

The demo assistant injects guidance through the AI Assistant action context API
before the first model call. It does not expose executable actions.

The first demo prompt is intentionally framed as a packaged Learn Drupal
AI-style lesson: `Show me the Lesson 1 overview.` The overview should explain
what the learner will learn, then ask the learner to reply `Ok, start Lesson 1.`
The guided task and recap should ground the lesson in the current page, role,
permissions, and packaged lesson source instead of giving generic AI training
advice. Each lesson should follow the same arc: overview, guided task,
evidence-based evaluation, recap.

Lesson 2 demonstrates module-provided policy context: Context Control Center
([`ai_context`](https://www.drupal.org/project/ai_context)) can provide brand
voice, editorial standards, accessibility expectations, and governance rules
that AI Guidance uses as cited evidence. The policy context guides suggestions;
it does not grant permissions, publish content, configure providers, change
workflows, edit Views, alter page composition, trigger automation, or replace
editorial review. See
[`docs/lesson-2-demo-script.md`](docs/lesson-2-demo-script.md).

On a stock clean Drupal CMS install, Context Control Center is not present. The
Lesson 2 setup command should fail closed with a warning instead of inventing
policy context:

```bash
drush ai-guidance:setup-lesson-2
```

Stronger models improve generic stock answers, but they do not remove the need
for Drupal evidence. In the latest `gpt-5.4` spot check, stock answers became
better shaped and more cautious, while AI Guidance still supplied the
site-specific permissions, route Help, Lesson 1 source, and citations that stock
answers could not infer.

## Evidence Providers

AI Guidance uses a small evidence-provider layer for Drupal-native
introspection. The base module can classify questions and collect structured
evidence from live Drupal state, such as access, workflow, content visibility,
Views, forms, automation, AI feature access, and outside-agent handoff.

Optional Drupal modules can add their own evidence providers later. The base
module should name external evidence that is missing, but should not diagnose or
implement systems outside Drupal itself.

The primary public extension point is
`Drupal\ai_guidance\Evidence\GuidanceEvidenceProviderInterface`. Providers
receive a `GuidanceRequest` and permission-filtered `GuidanceState`, then return
compact `GuidanceEvidence` with known unknowns and safe next steps. Evidence
providers must not return raw secrets, raw config dumps, prompt text, or
unredacted source content.

Human-authored source documents can use `GuidanceSource`; new Drupal
introspection should prefer evidence providers.

## Testing

See [`docs/testing.md`](docs/testing.md) for deterministic test commands,
optional-module matrix coverage, manual smoke checks, and model-evaluation
boundaries. Normal unit/kernel/functional tests should not require OpenAI or
other model-provider credits.

## Current POC dependency

This POC currently requires small `ai_assistant_api` changes for context-only
assistants. See `patches/ai-assistant-api-context-actions.patch` and
`docs/poc-notes.md`.

The patch is not needed for the base module to install, but it is needed for the
generated read-only assistant to receive context in stock Drupal AI 1.3.4. Run
`drush core:requirements` and check `AI Guidance AI Assistant API support`
before recording or evaluating chat behavior.

## Debugging

Visit `/admin/config/ai/guidance/debug` to inspect the read-only AI Assistant
contexts for a sample question. This page shows enabled context actions,
selected sources, citations, redactions, and context text without exposing
hidden system prompt internals.

For local demo recordings, use `scripts/append-demo-chat-log.py` with
`scripts/demo-chat-browser-snapshot.js` to keep an ignored JSONL transcript of
the current page, prompts, and returned chat answers in `.demo-logs/`.
Use `scripts/demo-chat-scroll.js` after each assistant response when recording
so the latest chat answer starts at the top, pauses, then scrolls at a
viewer-readable pace through the Sources section.
See [`docs/lesson-1-demo-script.md`](docs/lesson-1-demo-script.md#debug-transcript).

## License

GPL-2.0-or-later.
