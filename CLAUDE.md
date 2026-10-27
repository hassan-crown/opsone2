# Company Laravel Project Standard

> These rules apply to this entire repository. Read them before generating files or running commands.
> Give explanations, plans, questions, and reports **in Arabic**. Keep code, CLI commands,
> identifiers and technical terminology in their natural language (usually English).

## Non-negotiable repository boundary
- The repository root owns **infrastructure, Docker/Traefik, scripts, AI instructions, and docs**.
- `src/` owns **all Laravel application code**, including React/TypeScript/Inertia or a separately approved React SPA.
- Do not install Laravel in the root, initialize a nested Git repository, or relocate infrastructure files into `src/`.
- Do not delete, overwrite, or recreate a non-empty `src/`. Detect existing `src/artisan` and inspect before edits.
- Root `.env` is for Docker Compose; `src/.env` is for Laravel. Both are secrets and never committed.
- Never display real passwords/tokens in chat output or terminal logs. Never put secrets in docs.

## Approved baseline for NEW apps (not a mandate to upgrade existing apps)
- Laravel 13, PHP 8.5, MariaDB 11, Redis 7, Nginx + PHP-FPM, Docker Compose, Traefik v3 on dev/stage/prod.
- Node.js 24 LTS and pnpm, Vite, TypeScript, React 19 + Inertia 3 + Tailwind CSS 4 + shadcn/ui.
- Default project mode: full-stack Laravel React/Inertia, using the official Laravel starter kit.
- Alternatives after explicit project selection: API-only Laravel, Laravel API + React SPA, Livewire/Filament.
- Keep versions compatible and verify upstream versions before adding packages. Do not force upgrades on existing apps.
- Authentication: built-in Laravel/Fortify for Inertia. Select Sanctum for token/SPA/mobile API,
  Passport only for an actual OAuth2 authorization-server requirement.
- Optional packages require a documented use-case: Spatie Permission, Reverb, Horizon, Media Library, multi-tenancy.

## What to read
1. `README.md` (setup and repo map)
2. `docs/architecture.md` and `docs/tech-stack.md` (architecture and choices)
3. `.claude/rules/*.md` (implementation standards)
4. `docs/decisions/` (record accepted architecture choices)
5. `src/CLAUDE.md` and `src/composer.json` only if the app exists.

## Entry points
- Every project starts as a new repository created from the GitHub template `asbahi-m/laravel-vibe-starter`
  ("Use this template"). Do not bootstrap or develop an app inside the template repository itself.
- New project bootstrap: **`/project-init`** (`.claude/skills/project-init/SKILL.md`).
- New or changed functionality: **`/feature-development`**.
- Code review / PR: **`/code-review`**.
- Alternative copy/paste prompt: `docs/project-init-prompt.md`.

## Execution and approvals
- For a new project, inspect first and show an Arabic setup plan including mode, versions, packages,
  files to change, risks and validations. Wait for the developer's approval BEFORE creating the app.
- No `migrate:fresh`, `db:wipe`, `down-volumes`, forced git clean/reset, destructive Docker actions,
  production deployment, or secret regeneration without explicit, operation-specific permission.
- Do not install every optional dependency. Ask only questions that materially affect architecture;
  otherwise choose documented defaults.
- Prefer running PHP and Composer through `./manage.sh` / Docker. Run Node and pnpm in its tool container.
- Report which commands actually executed and their results; do not claim tests/deployment without evidence.

## Project lifecycle
- Keep a concise ADR in `docs/decisions/` for major deviations.
- Update `docs/tech-stack.md` after initial install so versions reflect **installed** versions, not just defaults.
- Add tests for business logic, authorization, API behavior, and regression fixes.
- Check `docs/development.md` and `.claude/rules/testing.md` before PR.
