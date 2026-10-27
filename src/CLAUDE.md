# OpsOne — Laravel app (`src/`)

> Root `CLAUDE.md` and `.claude/rules/*.md` take precedence. Reports and plans in Arabic; code in English.

## What this app is

- OpsOne **tenant app**: bilingual (ar/en) operations SaaS for made-to-order businesses (kitchens, furniture,
  curtains) with an optional ready-goods store. Scope: domains 01–07 and 09, plus signed-license **verification**
  from domain 08.
- **Single-tenant code, one isolated deployment per tenant** (own DB, files, Redis, `APP_KEY`, secrets) —
  `docs/decisions/0002-tenant-isolation.md`. Do NOT add `tenant_id` scoping or a tenancy package.
- The central app (plans site, subscriber accounts, provisioning, license signing) lives in a separate repository —
  `docs/decisions/0003-central-app-separation.md`. This app holds only the license public key, never a private key.

## Stack (installed versions in `docs/tech-stack.md`)

- Laravel 13, PHP 8.5, MariaDB 11, Redis 7; React 19 + Inertia 3 + TypeScript + Tailwind 4 + shadcn/ui.
- Official React starter kit; auth via Fortify (email verification, 2FA, passkeys, password confirmation). Public
  registration is disabled: staff join by invitation only (M-01).
- Wayfinder for typed routes; output in `resources/js/{actions,routes,wayfinder}` is generated — never edit it.
- Build: Vite+ (`vp`) with pnpm 12 (`packageManager` pinned).

## Layout

- `app/Access` — central Gate (`Access::can($user, 'P-xx', $record)`), permission catalogue, scope resolver,
  session revocation — `docs/decisions/0005-access-model.md`. Domain records implement `Access\Contracts\ScopedRecord`.
- `app/Notifications`, `app/Integrations` — in-app notifications + outbound outbox (`Outbox`, `Notifier`; send mail only through them) and facility SMTP/SMS settings — `docs/decisions/0006-notifications-outbox.md`.
- `app/Actions/StateMachines/M{nn}*/T{nn}*` — one Action per transition; log via `TransitionRecorder` (`state_transitions`).
- `app/Licensing` — signed-license verification (ADR-0004); use `CurrentLicense` (`allows()`, `limit()`, `status()`), never read the file.
  The Gate's license step and seat/branch limits (`Access\LicenseLimits`, inside the adding transaction) are in ADR-0005.
- `app/Actions/Fortify` — Fortify actions; `app/Http/{Controllers,Requests,Middleware}`; `app/Concerns` — shared validation rules.
- `resources/js/pages` — Inertia pages; `resources/js/components/ui` — shadcn/ui components.
- `routes/web.php`, `routes/settings.php`, `routes/account.php` (facility settings, plan/usage, ownership transfer, my sessions). Use `Route::get($uri, fn () => redirect($to))`, not `Route::redirect()`.
- `tests/Feature`, `tests/Unit` — Pest 5 (`tests/Pest.php`); kit tests are class-based PHPUnit and run under Pest.

## Commands (run from repo root, through Docker)

- `./manage.sh artisan test` — test suite (Pest).
- `./manage.sh artisan migrate` — never `migrate:fresh` / `db:wipe` without explicit approval.
- `./manage.sh node-build` — Wayfinder generate + `pnpm install` + build.
- `./manage.sh node-dev` — Vite dev server.
- `docker exec opsone_local_app ./vendor/bin/pint --test` / `./vendor/bin/phpstan analyse` (level 7).
- `docker compose --profile tooling run --rm node-build sh -lc 'pnpm run check && pnpm run types:check'`.

## Conventions

- Validate with Form Requests, authorize with policies/gates; keep controllers thin, use Actions for workflows.
- All user-facing strings translatable (ar/en); support RTL layouts.
- Laravel Boost is dev-only and exposed via root `.mcp.json`. Do NOT run `boost:install`.
