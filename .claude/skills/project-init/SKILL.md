---
name: project-init
description: Initialize a NEW Laravel application in this company's Docker/Traefik starter. Use when a developer wants to create/setup/bootstrap a Laravel project or choose Laravel + React/Inertia/API/Livewire stack in src/.
---

# Project Initialization / تهيئة مشروع جديد

Respond to the developer in Arabic; technical names/commands remain English.

## Hard boundaries
- Projects always start from the GitHub template. If `git remote get-url origin` points to
  `laravel-vibe-starter` (the template itself), STOP: explain that a project repository must first be created with
  "Use this template" or `gh repo create <name> --template asbahi-m/laravel-vibe-starter --private --clone`,
  then opened in Claude Code. Never bootstrap an app inside the template repository.
- Read root `CLAUDE.md`, `README.md`, `docs/architecture.md`, and `.claude/rules/` before proceeding.
- `src/` is the ONLY application directory. Never move or replace infrastructure in repo root.
- If `src/artisan` exists, STOP new app creation. Inspect and offer non-destructive existing-app onboarding.
- If `src/` is non-empty but has no `artisan`, STOP and ask what should be done; never clean it.
- No `sudo`, no recursive deletions, no Git reset, no destructive migrations.
- No secrets in chat/logs; never commit root `.env` or `src/.env`.
- Do not auto-create deploy/production credentials, OAuth clients or payment integrations.

## Stage A — Inspect / اقرأ أولًا
0. Identify the host OS (Linux, macOS, Windows). On Windows run commands from Git Bash or WSL2, never PowerShell/cmd.
   `manage.sh` and `scripts/*.sh` handle Git Bash path conversion themselves; when calling `docker` directly from
   Git Bash, prefix `MSYS_NO_PATHCONV=1`.
1. Check root layout, script syntax, Docker Compose variants, project `src/`, existing Git status, available Docker.
2. Identify `ENVIRONMENT`, PHP image, Node image, DB, Redis, Traefik routes and host UID/GID.
3. Flag mismatches, blockers and implications; do not assume Docker works if it wasn't run.
4. Check current upstream compatibility for selected framework and packages when internet is available.

## Stage B — Ask only the necessary configuration questions
Use defaults when the developer gives no preference. Ask about architectural choices as a single short conversational question or compact list:
- Project identifier (APP_NAME, `[a-zA-Z0-9_]+`), environment (default `local`).
- Mode, default `react-inertia`: `react-inertia`, `api-only`, `api-react-spa`, `livewire`.
- Authentication: default Laravel/Fortify for react-inertia/livewire; API-only requires a choice of no authentication vs Sanctum; use Passport ONLY if serving OAuth2 clients.
- Optional modules: roles/permissions, Reverb, Horizon, Media Library, multi-tenancy. Default none.
- Database defaults to MariaDB; Laravel 13 + PHP 8.5, Node 24, Redis, pnpm.
- If project has a preferred version, validate compatibility before changing it.

## Stage C — PLAN and APPROVAL GATE
Before creating files or executing installation commands, produce an Arabic setup plan with:
1. Name, environment, mode, exact version constraints, DB, auth, frontend and selected modules.
2. Which files inside/outside `src/` would change (explicitly identify any infrastructure changes).
3. Install sequence; network/CLI requirements and approval-relevant risks.
4. Validation commands and rollback strategy for bootstrap.
5. Unknowns, incompatibilities and tradeoffs.
**Wait for developer approval of the plan**; do not treat this Skill invocation as approval to install.

## Stage D — Execute ONLY after approval
1. Create root `.env` with `./manage.sh init <APP_NAME>` if absent. It generates DB credentials, sets
   WWWUSER/WWWGROUP for the host OS and moves any busy host port to a free one. Then set ENVIRONMENT/WEB_DOMAIN
   if needed and review locally. Do not echo secret values.
2. Install Laravel in `src/` with the SAME Docker PHP image and required extensions:
   - `react-inertia` → `LARAVEL_PRESET=react LARAVEL_VERSION=13 ./scripts/new-laravel-from-docker.sh`
   - `livewire` → `LARAVEL_PRESET=livewire LARAVEL_VERSION=13 ./scripts/new-laravel-from-docker.sh`
   - `api-only` → `LARAVEL_PRESET=plain LARAVEL_VERSION=13 ./scripts/new-laravel-from-docker.sh`
   - `api-react-spa` → start with `plain`, then scaffold React + Vite + TypeScript in `src/frontend/`; explicitly
     adapt Compose node working directories, production assets/deployment, SPA auth/CSRF/CORS and Nginx routing.
     This mode requires a separate architecture check; DO NOT claim it is supported by default Compose unchanged.
3. The bootstrap script uses `composer create-project --no-scripts`; after app env sync, run:
   `./manage.sh start`, then `./manage.sh install`, and `./manage.sh node-build` when a frontend exists.
   For new starter kits inspect their `composer.json` post-create/post-update requirements and run any necessary
   supported one-time install command (for example `artisan install:features` when present) only after the
   application environment is ready. Do not blindly rerun interactive account/team creation.
   - `install:features` (Chisel) prompts interactively and expects Node in the PHP container. Run it in `app` with
     `-e LARAVEL_INSTALLER_NO_NODE=true ... --no-interaction --answers='{"auth_features":[...]}'` (keep all defaults
     unless the developer chose otherwise), then run `pnpm run check:fix` in the node tool container.
3b. The bootstrap script applies the app conventions via `scripts/post-create-app.php` (Wayfinder command for the
   node containers, Docker tool caches ignored, `src/CLAUDE.md` tracked, `Route::get` + `redirect()` instead of
   `Route::redirect`). Confirm its `[post-create]` output; rerun it with
   `./manage.sh run -T app php < scripts/post-create-app.php` if needed (idempotent).
   With Pest added, create `tests/Pest.php` extending `Tests\TestCase` in `Feature`.
4. For a frontend project pin `packageManager` to a vetted `pnpm@` version (current stable from
   `npm view pnpm dist-tags`), generate and commit lockfile.
   Keep React/Inertia code in `src/resources/js/` unless SPA option was explicitly selected.
5. Install quality tools *after checking existing dependencies and PHP/Laravel compatibility*:
   - Laravel Pint; Pest for new projects (or preserve existing PHPUnit), Larastan/PHPStan;
   - TypeScript and kit-provided lint/format/check scripts;
   - Laravel Boost **dev-only** when compatible, and configure its MCP for Docker/root paths: root `.mcp.json` with
     `{"mcpServers":{"laravel-boost":{"command":"docker","args":["exec","-i","<APP_NAME>_<ENV>_app","php","artisan","boost:mcp"]}}}`.
   Never replace root `CLAUDE.md` or `.claude/rules/` with automatically generated Boost instructions;
   do not run `boost:install` (it generates conflicting CLAUDE.md/rules).
6. Apply optional modules only if explicitly selected. Choose built-in/Fortify for Inertia;
   Sanctum for API auth when needed; Passport only when OAuth2 provider needed.
7. Create/update `src/CLAUDE.md`, `docs/tech-stack.md` (installed actual versions), and an ADR for deviations.

## Stage E — Verify and report
- `bash -n manage.sh scripts/*.sh` (iterate files if shell glob doesn't expand as expected).
- `./manage.sh config`, `./manage.sh ps`, `./manage.sh ports` (report the final host ports; never hardcode them).
- `./manage.sh artisan --version` (or `php artisan --version` inside app service).
- `./manage.sh artisan migrate:status`, `./manage.sh artisan test` when test runner is available.
- `./manage.sh node-build` when frontend exists, typecheck/lint if supported.
- Test HTTP endpoint, Redis/DB connectivity, queue/scheduler startup; do not pretend these ran if unavailable.
- Deliver Arabic completion report: versions, files changed, commands run, pass/fail/skipped, remaining risks,
  exact next developer commands. Do not say 'production-ready' solely based on successful bootstrap.
