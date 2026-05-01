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
title: "Lesson 1: Create and verify draft content using your role"
canonical_id: "ai_guidance.lesson_1_safe_draft_content"
lesson_id: "lesson_1"
version: 1
priority: 90
audience:
  - content_editor
tags:
  - learn_drupal_ai
  - workflow
stage_prompts:
  overview: "Show me the Lesson 1 overview."
  start: "Ok, start Lesson 1."
  evaluate: "Evaluate my Lesson 1 attempt. Did I complete the task safely?"
  recap: "Recap Lesson 1."
source_url: "/admin/help/topic/ai_guidance.lesson_1_safe_drupal_ai"
---
```

Recommended fields:

- `title`: Human-readable lesson title.
- `canonical_id`: Stable machine-readable ID for citations and external tools.
- `lesson_id`: Short lesson identifier such as `lesson_1`.
- `version`: Integer package version.
- `priority`: Source-selection priority.
- `audience`: Intended user roles or personas.
- `tags`: Discovery and routing terms.
- `stage_prompts`: Overview, start, evaluation, and recap prompts.
- `source_url`: Optional local Help Topic or public documentation URL.

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
The Help context action can then cite the packaged lesson alongside route Help
and Help Topics.

## Other Tool Use

Other tools can read `guidance_lessons/*.md` directly:

- Parse the YAML front matter for IDs, prompts, tags, and source URLs.
- Treat the Markdown body as the lesson content.
- Preserve `canonical_id` in logs, citations, exports, and evaluation fixtures.
- Do not require Drupal rendering or Twig to consume the lesson.
