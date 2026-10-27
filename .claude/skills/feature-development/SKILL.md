---
name: feature-development
description: Plan, implement, test, and document a feature in an existing Laravel application using the company standards. Use when adding or modifying a feature, API, page, or workflow.
---

# Feature development workflow

Communicate in Arabic, keep technical terms and code in their native form.

1. Read `CLAUDE.md`, relevant `.claude/rules`, `docs/architecture.md`, `docs/tech-stack.md`, and `src/CLAUDE.md` if present.
2. Read the feature request and locate affected routes, models, policies, controllers, tests, and UI; verify existing patterns.
3. Define scope, acceptance criteria, permissions, edge cases, migrations, integrations, testing, rollback and files to change.
4. Present a concise implementation plan; seek explicit approval for destructive schema changes or new architecture/dependencies.
5. Implement in `src/` by default, follow framework conventions; avoid infrastructure changes unless justified.
6. Add regression tests and authorization coverage; run relevant checks and report what was actually executed.
7. Update docs for significant behavior/design changes and provide PR-ready summary, risks, verification and follow-ups.
