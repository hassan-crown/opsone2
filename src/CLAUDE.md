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
- `app/Notifications`, `app/Integrations` — in-app notifications + outbound outbox (`Outbox`, `Notifier`; send mail only through them) and facility SMTP/SMS settings — `docs/decisions/0007-notifications-outbox.md`.
- `app/Actions/StateMachines/M{nn}*/T{nn}*` — one Action per transition; log via `TransitionRecorder` (`state_transitions`).
- `app/Licensing` — signed-license verification (ADR-0004); use `CurrentLicense` (`allows()`, `limit()`, `status()`), never read the file.
  The Gate's license step and seat/branch limits (`Access\LicenseLimits`, inside the adding transaction) are in ADR-0005.
- `app/Audit` — append-only audit trail: `Audit::record(AuditAction::X, $subject, $actor, before:, after:)` INSIDE the
  change's transaction; `AuditLog` screen (P-63). `app/Files` — private attachments (`AttachmentService`, `AllowedAttachment`
  rule, signed links re-checking access on the owner, `storage_mb` quota) — `docs/decisions/0006-audit-and-attachments.md`.
- `app/Catalog` + `app/Actions/Catalog` + `routes/catalog.php` — made-to-order products, materials, ready goods/variants (P-11/P-12/P-13, `ready_goods`): money in minor units (`MinorUnits`), JSON `{ar,en}` text (`App\Support\Translatable`), append-only `catalog_versions`, soft-disable, single write path `CatalogWriter` — `docs/decisions/0010-catalog-versioning.md`.
- `app/SupportAccess` + `M38SupportAccess` — OpsOne support delegation: `is_support` identities act only through a live,
  time-boxed, read-only grant (`EnforceSupportAccess`) — `docs/decisions/0008-support-access.md`.
- `app/Customers`, `app/Actions/Customers`, `M03Interest` — customers, contacts and interests (phone normalization, scoped lists via `Access\ScopedQuery`, hidden fields per P-10) — `docs/decisions/0009-customers-and-interests.md`. Bilingual JSON columns use `App\Support\Translatable\TranslatableString`.
- `app/Orders`, `app/Actions/Orders`, `app/Actions/BranchTransfer`, `M04CustomOrder`/`M05ReadyOrder`, `routes/orders.php` — orders (custom opens in progress without price, ready as draft), frozen lines (`catalog_version_id` + snapshot), gapless numbers (`Support\GaplessSequence` inside `Support\LockingTransaction`), idempotent create (`Support\Idempotency`), `lock_version` (409 `StaleRecord`), money only with P-37, ready part needs `ready_goods` via `LicensedRecord`, atomic branch transfer — `docs/decisions/0011-orders.md`, contract `docs/p4-orders-api.md`.
- `app/Workflow`, `app/Actions/StateMachines/M06Stage|M07Task|M16Appointment|M23Flow|M27ActionResult`, `M04CustomOrder/T01..T03`, `app/Actions/Workflow` — workflow engine (P5): fixed action catalogue (`Workflow\Catalogue\ActionType`), one facility flow with immutable versions pinned per order, `FlowCheck`, `StageEngine` (the flow service), tasks with department inbox and eligibility, appointments with member-row-locked conflict checks, measurements, `WorkSettings` (Q-31); results from P6/P7 only via `ActionResults::verifyFromSource()` — `docs/decisions/0012-workflow-engine.md`, service contract `docs/p5-engine-api.md`.
- `app/Tasks`, `app/Http/Controllers/Tasks`, `routes/tasks.php` — P5 task screens (list/inbox, assign, task page, mobile executor home, measurement entry, home cards) on the engine's task Actions — `docs/p5-tasks-api.md`.
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
