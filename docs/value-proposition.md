# AI Guidance Value Proposition

AI Guidance exists to make Drupal explain itself.

The value is not that the model knows more generic Drupal facts. The value is
that the existing AI Assistant can receive read-only, access-aware evidence from
the live Drupal site before it answers. That changes the assistant from a
general Drupal explainer into a site-specific guide that can say what is true
here, what the current user can do, what requires an administrator or site
builder, and which sources support the answer.

## The User Problem

Drupal users rarely ask abstract architecture questions first. They ask
questions like:

- What can I do on this page?
- Why can I draft content but not publish it?
- Why can I ask the assistant questions but not configure AI providers?
- Why is this content not showing in a listing, menu, search result, or front
  page section?
- Which View, workflow, form, block, component, or automation controls this
  behavior?

Stock AI can explain common Drupal concepts, but these answers depend on the
actual site. Drupal behavior is spread across routes, roles, permissions,
entity access, content types, fields, workflows, Views, blocks, forms, Help
Topics, and optional contrib modules. A useful in-product assistant needs that
evidence.

## Value Over Stock AI Assistant

AI Guidance adds value in five ways.

1. **Site-specific correctness.** It grounds answers in live Drupal state rather
   than only model prior knowledge or a small generic pre-prompt.
2. **Role-aware guidance.** It can separate what the current user can do from
   what an administrator, site builder, or developer must do.
3. **Cited answers.** It packages Help, Help Topics, site summaries, contracts,
   and trusted guidance as display-safe sources so users can inspect why the
   assistant said something.
4. **Read-only safety.** V1 supplies context only. It does not mutate content,
   configuration, permissions, or tools.
5. **Extensible evidence.** The base module focuses on Drupal-native evidence.
   Other Drupal modules can add richer evidence providers later without putting
   vendor-specific or platform-specific diagnosis in this module.

The intended difference is practical:

```text
Stock assistant:
  "In Drupal, you may need to check permissions, Views, publishing status, or
  the Promoted to front page checkbox."

AI Guidance:
  "With your current role, you can create or edit this draft, but you cannot
  configure AI providers or change permissions. That requires administrator
  permissions. Ask an administrator to grant only editor-facing AI usage
  permissions and keep provider, assistant, prompt, and permission
  administration restricted."
```

## Current Evaluation Signal

The local same-model evaluation matrix compares stock answers, configured-stock
baselines, and AI Guidance variants with the same model and persona. The latest
local run used `gpt-4.1-mini`, a `content_editor` persona, and 104 total
answers across the available test sites and variants.

The strongest current signals are:

- Guidance variants produced display citations on every Umami and Vision25
  answer in the matrix; stock and configured-stock baselines produced none.
- The role/permission questions are the clearest product demo: Guidance can
  explain why a content editor can draft or edit content but cannot administer
  AI providers, assistants, or permissions, then provide an administrator-facing
  checklist.
- Action-led answers improved over stock baselines. In the Umami run, Guidance
  plus Best Practices produced actionable next steps on 6 of 8 questions,
  compared with 1 to 3 of 8 for the stock and configured-stock baselines. In
  the Vision25 run, Guidance produced actionable next steps on 4 of 8 questions,
  compared with 1 to 3 of 8 for the stock and configured-stock baselines.
- The evaluation is same-model, so the observed difference is primarily the
  evidence package and response rules, not model choice.

The evaluation also exposes work that still matters before overclaiming:

- Public-safe front-page visibility evidence needs more generic extraction so
  limited-permission users still get useful, accurate "why is this not showing"
  answers without exposing admin-only configuration internals.
- Final answers must never display raw evidence IDs or internal implementation
  terms.
- More real-page tests and authenticated personas are needed beyond the demo
  setup route.

That is the point of the POC: prove where live Drupal evidence changes answer
quality, then use the misses to harden the evidence providers.

## How This Helps Learn Drupal AI

Learn Drupal AI gives the community structured learning paths for using AI with
Drupal. AI Guidance is the in-product complement: it applies those lessons to
the site a user is actually working on.

The relationship is direct:

- **Hands-on first.** Learning content can teach the concept; AI Guidance can
  turn it into a safe next step on the current site, such as creating exactly
  one draft with success criteria and a verification step.
- **Drupal problems, not generic AI.** The assistant answers from Drupal state:
  roles, permissions, workflows, Views, forms, Help, content models, and trusted
  guidance sources.
- **Progressive learning.** Beginners can get one safe exercise. Editors can
  get role-appropriate next steps. Site builders can get architecture and
  configuration explanations. Developers and outside coding agents can get
  review-oriented handoff context.
- **Community-maintainable sources.** Help Topics, hook_help output, Drupal AI
  Best Practices, and future course materials can become trusted source
  documents rather than hidden prompt text.
- **Open core, richer optional bridges.** The public module can stay focused on
  Drupal-native evidence while other modules contribute additional evidence when
  their domain needs deeper inspection.

This means Learn Drupal AI and AI Guidance solve different parts of the same
adoption problem. Courses teach patterns. AI Guidance helps users apply those
patterns safely inside the live Drupal site.

The fit by learning track is:

- **AI-assisted Drupal development:** Guidance can produce outside-agent briefs
  grounded in actual site architecture, coding expectations, and test
  boundaries.
- **AI-powered site building:** Guidance can explain how content types,
  workflows, Views, forms, blocks, and page composition affect the page the user
  is working on.
- **AI for content teams:** Guidance can turn safe-editor-AI lessons into
  role-aware product guidance: what editors can use, what should stay
  administrator-only, and how to verify a rollout.
- **Drupal for AI builders:** Guidance can show why Drupal's structured content,
  permissions, workflows, and publishing model matter when building AI-assisted
  experiences.

## Success Criteria

This POC is working when a user can ask a practical site question and the
assistant can:

- identify the Drupal evidence relevant to the question;
- distinguish current-user actions from administrator, site-builder, or
  developer actions;
- cite real sources or clearly state what cannot be confirmed;
- avoid unsafe mutation or permission advice;
- provide one concrete next step and one way to verify it worked.

The module does not need to replace documentation, training, support, or
contrib-module expertise. It needs to make the existing Drupal site easier to
understand at the moment a user is blocked.
