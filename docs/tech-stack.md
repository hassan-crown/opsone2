# Tech Stack — معيار افتراضي قبل تثبيت التطبيق

هذه **نسخة معيارية مقترحة** وليست تقريرًا عن إصدارات مثبتة بالفعل. بعد `/project-init` يجب تحديثها بإصدارات `composer.lock` و`pnpm-lock.yaml` المنفّذة.

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

- Project identifier: **pending**
- Laravel runtime version: **not installed**
- Frontend mode: **not installed**
- Installed package versions: **not installed**
- Last verification date: **not executed**
