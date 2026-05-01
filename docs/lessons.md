# Packaged Lessons

AI Guidance lessons are packaged as Markdown files with YAML front matter. This
keeps lessons easy to edit by hand, usable by the Drupal AI Assistant context
actions, and portable for other tools that want to read the same learning
artifact.

## Location

Any enabled module can provide lessons in:

```text
guidance_lessons/*.md
```

The Markdown file is the canonical lesson package. Drupal Help Topics may link
to or mirror the same content for the in-Drupal Help UI, but tools should prefer
the Markdown lesson file when they need a portable source.

## Front Matter

Use YAML front matter for fields that tools need to discover, sort, and route
the lesson:

```yaml
---
schema_version: 1
title: "Lesson 1: Create and verify draft content using your role"
canonical_id: "ai_guidance.lesson_1_safe_draft_content"
lesson_id: "lesson_1"
kind: guided_task
status: draft
version: 1
priority: 90
audience:
  - content_editor
domains:
  - content_creation
  - workflow
  - role_permissions
  - content_visibility
tags:
  - learn_drupal_ai
  - workflow
stage_prompts:
  overview: "Show me the Lesson 1 overview."
  start: "Ok, start Lesson 1."
  evaluate: "Evaluate my Lesson 1 attempt. Did I complete the task safely?"
  recap: "Recap Lesson 1."
requires:
  modules:
    - node
  modules_optional:
    - content_moderation
  roles_any:
    - content_editor
evidence_providers:
  - access_explain
  - current_form_explain
  - current_entity_explain
  - workflow_explain
  - content_visibility_explain
exports:
  help: true
  chat: true
  mcp: true
source_url: "/admin/help/topic/ai_guidance.lesson_1_safe_draft_content"
community:
  slack: "#ai-learners"
---
```

Recommended fields:

- `schema_version`: Lesson package schema version.
- `title`: Human-readable lesson title.
- `canonical_id`: Stable machine-readable ID for citations and external tools.
- `lesson_id`: Short lesson identifier such as `lesson_1`.
- `kind`: Lesson shape, such as `guided_task`.
- `status`: Authoring status, such as `draft` or `stable`.
- `version`: Integer package version.
- `priority`: Source-selection priority.
- `audience`: Intended user roles or personas.
- `domains`: Learning and evidence domains the lesson exercises.
- `tags`: Discovery and routing terms.
- `stage_prompts`: Overview, start, evaluation, and recap prompts.
- `requires`: Required modules, optional modules, roles, or setup requirements.
- `evidence_providers`: Introspection surfaces the evaluation stage should use.
- `exports`: Supported compiled targets.
- `source_url`: Optional local Help Topic or public documentation URL.
- `community`: Follow-up community links or channels.

When a lesson needs a setup role and a separate learner role, keep that explicit:

```yaml
roles:
  setup:
    - site_builder
    - administrator
  learner:
    - content_editor
requires_setup_by:
  - site_builder
  - administrator
learner_role:
  - content_editor
fallback_if_missing: "Ask a site builder or administrator to enable the required module before starting this lesson."
```

## Markdown Shape

Keep the body readable without Drupal:

```markdown
# Lesson N: Title

## Overview
## What You Will Learn
## What You Will Practice
## Guided Task
## Success Criteria
## What Drupal Will Check
## Recap
```

Lesson text should teach Drupal concepts first. Safety boundaries should be
phrased as role, permission, workflow, or site-builder concepts rather than long
lists of forbidden actions.

## Drupal Use

`LessonSourceProvider` discovers lesson Markdown from enabled modules and turns
matching files into `GuidanceSource` objects with source type `lesson_package`.
The Help context action can then cite the packaged lesson alongside route Help.
For lesson questions, the Markdown package is the canonical source so Help Topic
mirrors are not injected as duplicate prompt context.

## Drush Commands

Use Drush to inspect and export the compiled lesson shape:

```bash
drush ai-guidance:lesson-list
drush ai-guidance:lesson-export lesson_1 --format=json
drush ai-guidance:lesson-validate
```

The export command returns normalized JSON with front matter, text, parsed
sections, citations, and a source hash. This gives external tools the same
lesson package the assistant uses without requiring Twig or Drupal rendering.

## Other Tool Use

Other tools can read `guidance_lessons/*.md` directly:

- Parse the YAML front matter for IDs, prompts, tags, and source URLs.
- Parse the Markdown headings into staged sections.
- Treat the Markdown body as the lesson content.
- Preserve `canonical_id` in logs, citations, exports, and evaluation fixtures.
- Do not require Drupal rendering or Twig to consume the lesson.
