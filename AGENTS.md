# Agent Instructions

This repository is a public proof-of-concept for the Drupal AI Guidance module.

## Working Rules

- Keep diffs small and scoped.
- Run targeted lint/tests before claiming a change is working.
- Never claim tests passed unless the exact command was run.
- Preserve Drupal coding standards and Drupal editor/site-builder experience.
- Treat `ai_guidance` as a read-only AI Assistant context-action package, not a separate chatbot or autonomous agent.
- Do not add secrets, raw API responses, local screenshots, or generated evaluation dumps to git.

## Fix Known Issues When the Path Is Clear

When a review, evaluation, or user note identifies a concrete defect and the
fix is clear, implement the fix in the same pass. Do not leave it as a prose
recommendation or "future work" unless it is high risk, out of scope, blocked
by missing access, or requires a product decision.

For this POC specifically:

- If an answer-quality issue maps to prompt/source/context code, fix the code
  and add or update a targeted test.
- If source links render without display citation IDs, harden the source bullet
  shape rather than only asking the model to behave better.
- If response modes leak into the wrong intent, fix the source/context gating
  that caused the leak and add a regression test.
- Keep future-work notes for genuinely new capabilities such as full Views,
  workflow, block visibility, media/text-format, Search API, multilingual, or
  field-display inspectors.

## Contribution Posture

This is a POC repo. Drupal.org/GitLab contribution text should still be reviewed
by a human before posting, and AI-generated work should follow Drupal's AI
disclosure and issue etiquette guidance.
