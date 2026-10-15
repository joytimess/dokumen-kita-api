# AGENTS.md

Behavioral guidelines to reduce common LLM coding mistakes. Merge with project-specific instructions as needed.

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:

- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them - don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:

- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:

- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:

- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:

```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

---

**These guidelines are working if:** fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.

## Laravel backend in this project

- This is a PHP 8.3 Laravel app. Check installed package versions before using version-specific APIs.
- Read related code and project conventions first; use the relevant skills under `.agents/skills` for Laravel and testing work.
- Prefer Laravel Boost for app context. Use `search-docs` for Laravel API details and `database-schema` before changing tables. Follow `.ai/rules` if that directory exists.
- Create Laravel classes and migrations with `php artisan make:* --no-interaction`. Keep dependencies and new documentation out of scope unless requested.
- Run the narrowest meaningful PHPUnit tests for changed behavior; avoid duplicate tests. After changing PHP, run `vendor/bin/pint --dirty --format agent`.

## Backend development behavior

- Use the approved PRD and existing `/api/v1` contract as the scope. Trace a change through its route, request, policy, service, resource, job, and relevant tests before editing.
- Validate input and authorization at API boundaries. Keep document files private and return clear, structured errors without exposing another user's data.
- Preserve version and signing invariants: check the current draft and editor revision, lock workflow writes consistently, and invalidate the preview and signature positions when PDF content changes. Final PDFs must use the approved preview snapshot.
- Test changed behavior at the smallest useful level, including a failure case when it protects data or access. Distinguish passing tests from real queue, email, OAuth, or browser verification in the final report.
