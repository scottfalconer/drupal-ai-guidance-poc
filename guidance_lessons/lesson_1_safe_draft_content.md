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
  - draft_content
  - workflow
  - role_permissions
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

# Lesson 1: Create and verify draft content using your role

## Overview

Lesson 1 teaches how draft content works on the Drupal site you are using right now. The learning pattern is: orient yourself on the current page, practice a small editor task, then reflect on what Drupal evidence proved.

## What You Will Learn

- How Drupal content types shape editor tasks.
- How draft or unpublished content differs from published content.
- How the content administration listing helps verify work.
- How role permissions, workflow, and front-page placement are separate Drupal concepts.
- How to ask the assistant to explain what Drupal can confirm.

## What You Will Practice

Create exactly one draft content item using a content type your role can create, verify it in Drupal, then ask the assistant to evaluate what Drupal can confirm.

## Guided Task

1. Go to a content creation page your role can access. In the Umami demo, use `/node/add/article`. On a clean Drupal CMS site, use the available creation page such as `/node/add/page`.
2. On that content creation page, ask: `What can I do on this page?`
3. Create one draft content item titled `Lesson 1 test content`.
4. Save it as draft or unpublished.
5. Verify that it appears in `/admin/content`.
6. Open or preview the saved content item, then open its edit page.
7. On the saved content edit page, ask: `Evaluate my Lesson 1 attempt. Did I complete the task safely?`

## Success Criteria

- The content uses an existing content type your role can create.
- The content is draft or unpublished.
- The content appears in `/admin/content`.
- The content can be opened or previewed for review.
- The learner can explain why draft creation, publishing, and front-page placement are separate Drupal concepts.

## What Drupal Will Check

Drupal can check the current page, current role, current entity, visible form or page messages, content type, workflow state, and safe next steps. Some verification, such as whether the learner personally checked `/admin/content`, may still require the learner to confirm what they did.

## What This Lesson Covers

This lesson covers editor-facing content work: creating a draft, finding it in Drupal, opening it for review, and asking what Drupal evidence confirms. Administrator and site-builder work, such as AI provider setup, permissions, workflows, Views, and homepage composition, is introduced only as follow-up context.

## Recap

After evaluation, ask: `Recap Lesson 1.` The recap should summarize what was learned, what Drupal evidence was checked, why it matters, and the next safe learning step. Continue the discussion in Drupal Slack `#ai-learners`.
