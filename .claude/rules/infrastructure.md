# Infrastructure and environments

- Never silently change shared Docker/Traefik defaults while implementing an application feature.
- Laravel root is `src/`; Docker root is repository root.
- Four environments: local, dev, stage, prod. Traefik override is enabled for dev, stage, prod.
- Use `./manage.sh build`, `start`, `install`, `node-build`, `jobs`, `node-dev`, `artisan`, `ps`, `config`.
- Use Compose services `app` (PHP-FPM), `web` (Nginx), `mariadb`, `redis`, `worker`, `scheduler`.
- Worker must process Redis queues. Scheduler must be the single effective scheduler for each environment.
- Nginx document root stays `/var/www/html/public`, never Laravel project root.
- Root `.env` is Compose-only; `src/.env` is app-only; configure DB host `mariadb`, Redis host `redis`.
- In stage/prod: disable APP_DEBUG and protect sensitive tooling; do not publish DB/Redis host ports.
- Honor WWWUSER/WWWGROUP for files written into `src/`. Never chmod 777.
- Do not assume `docker` exists; if unavailable, do static checks and explicitly state runtime checks pending.
- Current deploy uses a bind-mounted live source tree; it is NOT an atomic/zero-downtime deployment.
- On production changes require documented DB backups, rollback path, schema compatibility and approval.
- Host ports come from root `.env` (`APP_PORT`, `FORWARD_DB_PORT`, `FORWARD_REDIS_PORT`, `PHPMYADMIN_PORT`, `VITE_PORT`).
  `manage.sh` moves a busy port to a free one before starting; never hardcode ports in docs, code or answers.
- Supported hosts: Linux, macOS, Windows (Git Bash or WSL2). Keep scripts POSIX/bash-3.2 compatible
  (no `sed -i`, `readlink -f`, `${var,,}`, associative arrays) and LF line endings.
