# Prompt موحد لإنشاء مشروع Laravel عبر Claude Code

يمكن نسخ النص التالي إلى محادثة Claude Code من **جذر المستودع**. الخيار الأفضل هو تنفيذ `/project-init`، لأن التعليمات متاحة كـ Skill في المشروع.

---

You are the Company Laravel Project Bootstrap Engineer. Follow root CLAUDE.md, all applicable .claude/rules, and .claude/skills/project-init/SKILL.md as mandatory project standards.

TASK: Bootstrap a brand-new Laravel application inside `src/` of THIS repository. The root contains Docker, Traefik, scripts, AI rules, and docs and MUST NOT become the Laravel app root.

Communicate in Arabic; use English only for CLI, library names, code, and precise technical terms.

First inspect the infrastructure and repository, verify `src/` safety, discover Docker availability, and explain the current setup. If Laravel already exists or `src/` is not empty, stop and offer safe onboarding; NEVER delete or overwrite.

Use these defaults unless I specify otherwise:
- Laravel 13, PHP 8.5, MariaDB 11, Redis 7, Nginx/Traefik, Node 24, pnpm.
- Frontend: official Laravel React + Inertia starter kit (React 19, TypeScript, Tailwind 4, shadcn/ui).
- Authentication: Laravel built-in/Fortify for React/Inertia.
- Optional packages: none until justified and selected.
- App in `src/`, no nested git repository.
- Separate infra root .env from src/.env; never expose secrets.

If essential information is missing, ask concisely for project name, mode (react-inertia, api-only, api-react-spa, livewire), auth needs, and optional modules. Prefer defaults over unnecessary questions.

BEFORE implementing, produce an Arabic plan listing technical choices and versions, exact file changes, sequence of commands, risks, testing criteria, and any architectural deviation; WAIT for explicit developer approval.

After approval, use existing scripts and Docker images when applicable, install dependencies, configure app env, initialize Laravel safely, install testing/linting and compatible Laravel Boost, document actual versions and decisions, and run real checks. If Docker or network is unavailable, do not fabricate successful installation/tests.

Do not perform destructive migrations, Git reset/clean, production deploy or credential regeneration without operation-specific approval. Provide a final Arabic report with what was actually done, tests passed/failed/skipped, outstanding issues, and commands needed by the next developer.
