# Tech Stack

الجدول الأول هو المعيار الافتراضي للشركة؛ القسم الثاني يسجّل الإصدارات **المثبتة فعليًا** في OpsOne (من `src/composer.lock` و`src/pnpm-lock.yaml` وحاويات Docker).

| Domain | Default | Exceptions |
|---|---|---|
| PHP | 8.5 FPM | require explicit compatible version override |
| Laravel | 13 | Laravel 12 legacy compatibility with plain skeleton only |
| Frontend | React 19 + Inertia 3 + TypeScript | API-only, separated React SPA, Livewire |
| CSS/UI | Tailwind 4 / shadcn/ui | design-system-specific choice |
| Web | Nginx | behind external Traefik on remote environments |
| DB | MariaDB 11 | MySQL requires a coordinated Compose change |
| Queue/Cache | Redis 7 | project-specific decisions |
| Node | 24 LTS | pin project's `packageManager` to pnpm version |
| Bundler | Vite | SSR runtime requires extra infrastructure |
| Auth | Laravel/Fortify | Sanctum for APIs, Passport for OAuth2 auth-server only |
| Testing | Pest (new projects), Pint, Larastan | respect existing test framework |
| AI | Claude Code instructions, Laravel Boost (dev-only after compatibility check) | - |

## Installed app record (filled by /project-init)

- Project identifier: **opsone** (`APP_NAME=opsone`, `ENVIRONMENT=local`)
- Product: OpsOne tenant app — single-tenant, one isolated deployment per tenant (see ADR-0002, ADR-0003)
- Frontend mode: **react-inertia** (official Laravel React starter kit)
- Starter kit: `laravel/react-starter-kit` `dev-main` @ `dbd4817d2d2936be14bc1ca7c94692fcef20b6b1`
  (2026-10-23, "Ignore Codex configuration in starter kits"); `install:features` run with all auth features:
  email-verification, registration, 2fa, passkeys, password-confirmation
- Last verification date: **2026-10-27** (local Docker)

### Runtime (containers)

| Component | Installed |
|---|---|
| PHP (app) | 8.5.12 FPM — ext: pdo_mysql, pdo_sqlite, redis, intl, gd, zip, bcmath |
| MariaDB | 11.8.9 |
| Redis | 7.4.11 |
| Node (tool image) | 24.22.1 |
| pnpm | 12.15.0 (`packageManager` pinned in `src/package.json`) |

### PHP packages (`src/composer.lock`)

| Package | Version |
|---|---|
| laravel/framework | v13.38.0 |
| inertiajs/inertia-laravel | v3.5.2 |
| laravel/fortify | v1.41.0 |
| laravel/passkeys | v0.2.1 |
| laravel/wayfinder | v0.1.23 |
| laravel/tinker | v3.0.2 |
| laravel/chisel | v0.1.1 (kit installer; `install:features` already run and removed from composer scripts) |
| pestphp/pest (dev) | v5.3.0 |
| pestphp/pest-plugin-laravel (dev) | v5.0.1 |
| phpunit/phpunit (dev, via Pest) | 13.3.6 |
| larastan/larastan (dev) | v3.13.2 (phpstan 2.3.2) |
| laravel/pint (dev) | v1.32.1 |
| laravel/boost (dev) | v2.10.3 (MCP only via root `.mcp.json`; `boost:install` NOT run) |
| laravel/pail, laravel/pao, laravel/sail (dev) | v1.2.7, v1.1.5, v1.69.0 |
| nunomaduro/collision (dev) | v8.9.5 |

### JS packages (`src/pnpm-lock.yaml`)

| Package | Version |
|---|---|
| react / react-dom | 19.3.1 |
| @inertiajs/react, @inertiajs/vite | 3.9.1 |
| typescript | 5.9.3 |
| vite (alias of @voidzero-dev/vite-plus-core) / vite-plus | 1.4.0 |
| tailwindcss, @tailwindcss/vite | 4.3.3 |
| laravel-vite-plugin | 3.2.0 |
| @laravel/vite-plugin-wayfinder | 0.1.11 |
| @laravel/passkeys | 0.2.0 |
| UI | shadcn/ui components (Radix UI), lucide-react 0.475.0 |

### Deviations from defaults

- Kit `phpunit/phpunit ^12.5` requirement removed; PHPUnit 13 now comes through Pest 5. Kit tests stay
  class-based PHPUnit tests and run under Pest; new tests should be Pest.
- No optional modules (Spatie, Reverb, Horizon, Media Library, tenancy package) — tenancy is handled by
  deployment isolation (ADR-0002).
