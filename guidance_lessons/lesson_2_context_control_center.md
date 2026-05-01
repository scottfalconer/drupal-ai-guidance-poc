---
title: "Lesson 2: Use module-provided policy context while editing draft content"
canonical_id: "ai_guidance.lesson_2_context_control_center"
lesson_id: "lesson_2"
version: 1
priority: 90
audience:
  - site_builder
  - content_editor
tags:
  - learn_drupal_ai
  - context_control_center
  - ai_context
  - editorial_policy
stage_prompts:
  overview: "Show me the Lesson 2 overview."
  start: "Ok, start Lesson 2."
  evaluate: "Evaluate my Lesson 2 attempt. Did I use the site policy context safely?"
  recap: "Recap Lesson 2."
source_url: "/admin/help/topic/ai_guidance.lesson_2_context_control_center"
external_links:
  context_control_center: "https://www.drupal.org/project/ai_context"
---

# Lesson 2: Use module-provided policy context while editing draft content

## Overview

Lesson 2 teaches how a Drupal module can provide site policy context that improves AI guidance while Drupal permissions and workflow remain authoritative.

## What You Will Learn

- What Context Control Center is.
- How module-provided context can describe brand voice, editorial standards, accessibility expectations, and governance rules.
- How content editors can apply policy context while working on draft content.
- Why context guides suggestions while Drupal permissions and workflow authorize actions.

## What Context Control Center Provides

[Context Control Center](https://www.drupal.org/project/ai_context), also known by the project machine name `ai_context`, manages reusable context items that can ground Drupal AI features in a site's content, rules, terminology, and editorial policies.

## What You Will Practice

Create or verify one site policy context item for Umami editorial guidance, then use it to improve a draft Article as a content editor.

## Suggested Policy

- Use a warm, practical, food-focused voice.
- Write for home cooks.
- Prefer clear, concise instructions.
- Avoid exaggerated claims, unsupported health claims, and unsupported nutrition claims.
- Preserve the author's intent.
- Flag accessibility concerns such as vague link text or missing image alt text.
- Treat AI output as draft assistance only.
- Editors must review AI suggestions before saving or publishing.

## Guided Task

1. As a site builder or administrator, open Context Control Center.
2. Create or verify the Umami editorial policy context.
3. Switch to a content editor account.
4. Open or create a draft Article.
5. Ask: `What editorial guidance applies to this Article draft?`
6. Ask the assistant to suggest improvements using the site policy context.
7. Manually edit the draft if appropriate.
8. Ask: `Evaluate my Lesson 2 attempt. Did I use the site policy context safely?`

## Success Criteria

- The context describes brand voice or editorial standards.
- The context includes at least one accessibility or governance rule.
- The context is scoped to editor-facing AI guidance, Article content, or the relevant site section.
- The assistant can use the context when advising a content editor.
- The learner can explain the difference between policy context, editor suggestions, and Drupal authority.

## Authority Stays With Drupal

Context guides suggestions. Drupal permissions, workflows, and editorial review still decide who may save, publish, configure AI, change site structure, or approve content.

## Recap

After evaluation, ask: `Recap Lesson 2.` The recap should explain what Context Control Center contributed, how the context changed the assistant's guidance, what Drupal permissions and workflow still controlled, and the next learning step. Continue the discussion in Drupal Slack `#ai-learners`.
