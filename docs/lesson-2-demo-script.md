# Lesson 2 Demo Script

## Claim

Lesson 2 shows module-provided policy context. Context Control Center owns the brand and governance context; AI Guidance reads it as evidence in the existing Drupal AI Assistant. The teaching point is simple: context guides suggestions; Drupal permissions and workflow authorize actions.

Context Control Center is the Drupal [`ai_context`](https://www.drupal.org/project/ai_context) project. It lets sites manage reusable context items such as brand voice, terminology, editorial standards, accessibility expectations, regulatory constraints, and workflow governance for Drupal AI features.

## Preflight

```bash
cd /Users/scott/dev/umami-ai-guidance-clean-test
ddev drush cset ai.settings default_providers.chat.model_id gpt-5.4 -y
ddev drush cr
ddev drush ai-guidance:setup-demo
ddev drush ai-guidance:reset-lesson-1
ddev drush ai-guidance:reset-lesson-2
ddev drush ai-guidance:setup-lesson-2
ddev drush ai:chat "Reply with ok." --model=gpt-5.4 --system="Return exactly ok."
```

Open `/admin/config/ai/guidance/demo` as an administrator or site builder, refresh the assistant, then use the Lesson 2 recording flow on that page.

When recording assistant answers, run
[`scripts/demo-chat-scroll.js`](../scripts/demo-chat-scroll.js) after each
response finishes. The helper starts at the top of the newest assistant answer,
pauses, then scrolls in small eased segments so the viewer can follow the answer
through the Sources section.

Sources should be understandable to a viewer. Context Control Center and Help
Topic sources should render as links when a safe URL is available. Current role,
route, form, entity, and workflow labels should be phrased as live Drupal
evidence from this request/site, not as unexplained internal IDs.

## Recording Flow

1. Start on `/admin/config/ai/guidance/demo`.
   Ask: `Show me the Lesson 2 overview.`
   Expected answer: what the learner will learn, what they will practice, what CCC is, how Drupal will check the work, and the instruction to reply `Ok, start Lesson 2.`

2. Still on the setup page or Lesson 2 Help Topic.
   Ask: `Ok, start Lesson 2.`
   Expected answer: Goal, Practice task, What Drupal concept this teaches, What Context Control Center provides, Success criteria, Start here, Sources.

3. Open Context Control Center, usually `/admin/ai/context/items` or `/admin/ai/context/items/add`.
   Ask: `What can I do on this Context Control Center page for Lesson 2?`

4. Create or verify one context item named `Umami editorial voice and AI usage policy`.
   The policy should cover warm food-focused voice, home cooks, clear instructions, unsupported claims, accessibility concerns, draft-only AI assistance, and human review.

5. Stay on the saved context item page or listing.
   Ask: `Evaluate my Lesson 2 context setup. Is this policy context safe and usable for content editors?`

6. Switch to the content editor account and open `/node/add/article` or a draft Article edit page.
   Ask: `What editorial guidance applies to this Article draft?`

7. On a draft Article, ask:
   `Using the site's editorial policy context, suggest improvements to this draft title and body without changing the meaning.`

8. Manually edit and save the draft if appropriate. The learner applies the suggestion; Drupal workflow still controls review and publishing.

9. Ask:
   `Evaluate my Lesson 2 attempt. Did I use the site policy context safely?`

10. Ask the authority-check prompt:
   `Since this policy context exists, can I now publish the Article or change AI provider settings?`

Expected answer: policy context guides suggestions; Drupal permissions and workflows remain authoritative.

11. Ask the recap prompt:
   `Recap Lesson 2.`

Expected answer: what CCC contributed, how site policy context changed the guidance, what Drupal permissions and workflow still controlled, one next safe learning step, an invitation to continue in Drupal Slack `#ai-learners`, and Sources.
