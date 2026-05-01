# Lesson 1 Demo Script

## Claim

This is the existing Drupal AI Assistant using read-only Drupal Guidance context. The lesson lives inside Drupal, uses the actual site as the lab, and asks the learner to do the work manually. The assistant explains what is safe for the current role and evaluates the attempt from Drupal evidence.

The recording should prove page awareness. Use the setup page only for setup and lesson overview, then move to real Drupal pages for the task, verification, evaluation, front-page question, and recap. Invite viewers to continue the discussion in Drupal Slack `#ai-learners`.

## Preflight

```bash
cd /Users/scott/dev/umami-ai-guidance-clean-test
ddev drush cset ai.settings default_providers.chat.model_id gpt-5.4 -y
ddev drush cr
ddev drush ai-guidance:setup-demo
ddev drush ai-guidance:reset-lesson-1
ddev drush ai:chat "Reply with ok." --model=gpt-5.4 --system="Return exactly ok."
```

Start a local debug transcript before recording:

```bash
cd /Users/scott/dev/drupal-ai-guidance-poc
python3 scripts/append-demo-chat-log.py --init --log .demo-logs/latest-demo-chat.jsonl --label "Lesson 1 demo"
```

During an MCP-driven demo, capture a browser snapshot after each assistant
answer and append it to that JSONL log. The browser snapshot script is
[`scripts/demo-chat-browser-snapshot.js`](../scripts/demo-chat-browser-snapshot.js);
the appender is [`scripts/append-demo-chat-log.py`](../scripts/append-demo-chat-log.py).
Each JSONL snapshot records the current URL, page title, page headings, and any
new user/assistant chat messages. The `.demo-logs/` directory is ignored by git.

For recording, reveal each assistant answer with
[`scripts/demo-chat-scroll.js`](../scripts/demo-chat-scroll.js). It starts the
latest assistant response at the top, pauses, then scrolls in small eased
segments so viewers can read the answer without the chat jumping to the bottom.

Open as administrator:

```text
http://127.0.0.1:59889/admin/config/ai/guidance/demo
```

Refresh the demo assistant, reset Lesson 1 content if needed, and use the expanded chat panel. The demo setup page has a **Lesson 1 recording flow** section with the same links and prompts.

Then switch to the content editor learner account for the lesson flow. If the setup page is not accessible to the content editor, start the lesson from `/node/add/article` or the Lesson 1 Help Topic instead.

## Recording Flow

### 1. Admin setup

Page:

```text
/admin/config/ai/guidance/demo
```

Do:

- Refresh demo assistant.
- Reset Lesson 1 content.
- Confirm readiness checks are green.

Narration:

> I am doing setup as an administrator. The actual lesson runs as a content editor so the assistant can explain the real role boundary.

### 2. Show the overview, then start

Page:

```text
/admin/config/ai/guidance/demo
```

or:

```text
/admin/help/topic/ai_guidance.lesson_1_safe_drupal_ai
```

Prompt:

```text
Show me the Lesson 1 overview.
```

Expected answer: what the learner will learn, what they will practice, how Drupal will check the work, and the instruction to reply `Ok, start Lesson 1.`.

Then prompt:

```text
Ok, start Lesson 1.
```

Expected answer: Goal, Practice task, What Drupal concept this teaches, Success criteria, Start here, Sources.

Narration:

> This is not a lesson that assumes a generic Drupal site. It is using this site, this page, and this user role as the lab.

### 3. Ask from the actual task page

Page:

```text
/node/add/article
```

Prompt:

```text
What can I do on this page?
```

Expected answer:

- Identifies the Article creation form.
- Names visible required fields and runtime form buttons when available.
- Says the learner can create one draft Article.
- Explains the Article workflow state/transition boundary from site evidence.
- Separates editor content work from administrator/site-builder follow-up.
- Gives the safe next step: fill required fields and save as draft/unpublished.
- Sources distinguish live Drupal evidence from linked references. State labels such as `[A1]`, `[R1]`, `[F1]`, and `[W1]` are live evidence from this request/site; Help Topics and Drupal.org documentation should render as links when used.

Narration:

> The assistant is explaining the actual page I am on and what this role can practice here.

### 4. Create the draft manually

Page:

```text
/node/add/article
```

Create:

```text
Title: Lesson 1 test article
Body: This is a draft article created for the Drupal AI Guidance lesson.
```

Save as draft or unpublished.

Narration:

> The assistant did not create this. The learner performs the task manually.

### 5. Verify manually

Page:

```text
/admin/content
```

Show that the draft Article appears. Open or preview it.

Optional prompt:

```text
What does this page tell me about my Lesson 1 draft?
```

Expected answer:

- Identifies the content administration listing.
- Explains how to verify the draft exists.
- Connects the content listing back to the draft workflow and review step.

### 6. Evaluate from the saved draft edit page

Page:

```text
/node/{nid}/edit
```

Prompt:

```text
Evaluate my Lesson 1 attempt. Did I complete the task safely?
```

Expected answer:

- Result: Fully verified / Core task complete / Partially complete / Cannot confirm.
- Evidence confirmed from the current Article entity.
- Current form and workflow evidence when the edit form is open.
- Evidence not confirmed.
- Safe actions completed.
- Admin/site-builder boundaries preserved.
- Next safe step.
- Sources.

Expected fallback:

> Cannot confirm. Open the draft Article edit page or `/admin/content`, then ask again.

If the assistant can confirm the draft Article but cannot confirm the
`/admin/content` check or Preview step from the current page, it should say
**Core task complete** rather than **Complete**.

Narration:

> This is the new learning pattern: the user gets a task, does it in Drupal, then the assistant evaluates the attempt from evidence.

### 7. Explain the role boundary

Page:

```text
/node/{nid}/edit
```

Prompt:

```text
Why can I draft this Article, but not configure AI providers or permissions?
```

Expected answer:

- Content permissions are separate from AI provider and permission administration.
- Provider setup, model selection, assistant configuration, permission administration, workflow, and site configuration remain admin-only.
- Avoid broad permissions such as `administer ai`, `administer ai providers`, `administer ai_assistant`, `administer permissions`, and `administer site configuration`.
- Names admin paths like `/admin/people/permissions`, `/admin/config/ai`, and `/admin/config/ai/providers`.
- Sources.

Narration:

> Generic AI usually says "check your permissions." This assistant can explain the actual boundary.

### 8. Show the front-page trap

Page:

```text
/home
```

Prompt:

```text
I just made the Lesson 1 test Article. Why is it not shown here, and how can I add it to the front-page items?
```

Expected answer:

- Does not blindly recommend `Promoted to front page`.
- Explains actual front-page owner/composition when available.
- Separates editor next steps from site-builder/admin next steps.
- Cites sources.

Narration:

> This is where site-specific guidance beats generic Drupal advice. "Promoted to front page" is plausible advice, but on this site it is not the whole answer.

### 9. Architecture reflection

Page:

```text
/node/{nid}/edit
```

or:

```text
/home
```

Prompt:

```text
Explain how my draft Article relates to this site's content model, workflows, Views, and front page.
```

Expected answer:

- Article content type.
- Draft/unpublished workflow.
- Current Article form fields/buttons when the edit form is open.
- `/admin/content` verification.
- Public listings / Views.
- Canvas/front-page composition when available.
- Sources.

### 10. Lesson recap

Prompt:

```text
Recap Lesson 1.
```

Expected answer:

- What the learner practiced.
- What Drupal evidence was checked.
- What remains administrator or site-builder work.
- One next safe learning step.
- Invitation to continue the discussion in Drupal Slack `#ai-learners`.
- Sources.

### 11. Optional outside-agent bonus

Prompt:

```text
Create a short pasteable brief for an outside coding agent who needs to help with this site without breaking the editor workflow.
```

Expected answer:

- Starts with "Paste this to the outside coding agent:".
- Review-only unless explicitly authorized.
- No production mutation.
- No AI/permissions/workflow/front-page changes without admin review.
- Use Drupal APIs and config management.
- Preserve front-page/listing behavior.
- Add/update tests.

## Recording Checklist

| Moment | Page |
| --- | --- |
| Setup / refresh assistant | `/admin/config/ai/guidance/demo` |
| Optional lesson source | `/admin/help/topic/ai_guidance.lesson_1_safe_drupal_ai` |
| Overview and start | setup page, Help Topic, or first accessible learner page |
| Ask "What can I do on this page?" | `/node/add/article` |
| Create draft | `/node/add/article` |
| Verify draft exists | `/admin/content` |
| Evaluate attempt | `/node/{nid}/edit` |
| Explain role boundary | `/node/{nid}/edit` |
| Front-page trap | `/home` |
| Lesson recap | `/node/{nid}/edit` or `/home` |
| Optional site-builder inspection path | `/canvas/editor/canvas_page/1` |
| Optional AI provider boundary proof | `/admin/config/ai/providers` |
| Optional permissions boundary proof | `/admin/people/permissions` |

The two most important page choices are:

- Ask **What can I do on this page?** on `/node/add/article`, not only on the demo setup page.
- Ask **Evaluate my Lesson 1 attempt** on the saved draft Article edit page, not only on `/admin/content`.

## Debug Transcript

For Codex/MCP-driven recordings, keep `.demo-logs/latest-demo-chat.jsonl`
running as the local source of truth for what happened. After each prompt/answer
pair, capture the browser state with `scripts/demo-chat-browser-snapshot.js` and
pipe the returned JSON to:

```bash
python3 scripts/append-demo-chat-log.py --log .demo-logs/latest-demo-chat.jsonl
```

For the visible recording, run `scripts/demo-chat-scroll.js` after the assistant
finishes each response. The expected visual rhythm is: show the top of the
answer, pause for roughly a second, scroll about half a chat panel at a time,
pause between segments, and stop with the Sources section visible.

The appender deduplicates messages, so it is safe to run multiple times on the
same page while debugging. A typical event looks like:

```json
{
  "type": "snapshot",
  "captured_at": "2026-04-30T18:00:00Z",
  "page": {
    "url": "http://localhost:59889/node/add/article",
    "path": "/node/add/article",
    "title": "Create Article | Umami",
    "headings": ["Create Article"]
  },
  "new_messages": [
    {"role": "user", "text": "What can I do on this page?"},
    {"role": "assistant", "text": "Direct answer..."}
  ]
}
```

## Closing

Lesson 1 is not a video someone watches before using Drupal. The lesson is inside Drupal. The user receives a safe task, performs it on the real site, and asks Drupal to evaluate the result. The model is useful because Drupal supplies live evidence: role, permissions, route, Help, content model, workflow, and front-page architecture.
