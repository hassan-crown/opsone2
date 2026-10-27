#!/usr/bin/env bash
set -euo pipefail

# Bootstrap Laravel INSIDE src using the same PHP image used in production.
# The script creates no git repo and deliberately does not run DB migrations.
# Usage:
#   LARAVEL_PRESET=react LARAVEL_VERSION=13 ./scripts/new-laravel-from-docker.sh
#   LARAVEL_PRESET=plain LARAVEL_VERSION=13 ./scripts/new-laravel-from-docker.sh
#   LARAVEL_PRESET=livewire LARAVEL_VERSION=13 ./scripts/new-laravel-from-docker.sh
# The default preset is react (official Laravel React + Inertia starter kit).

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# Git Bash / MSYS2 on Windows: keep container paths such as /tmp/composer untranslated.
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'
PRESET="${LARAVEL_PRESET:-react}"
VERSION="${LARAVEL_VERSION:-13}"

if ! command -v docker >/dev/null 2>&1; then
  echo 'ERROR: Docker CLI is required.' >&2; exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
  echo 'ERROR: Docker Compose v2 is required.' >&2; exit 1
fi
if [ ! -f .env ]; then
  echo 'ERROR: Root .env is missing. Run: ./manage.sh init <APP_NAME>' >&2; exit 1
fi
if [ -e src/artisan ]; then
  echo 'ERROR: Laravel already exists in src/. Refusing to overwrite.' >&2; exit 1
fi
mkdir -p src
if [ -n "$(ls -A src)" ]; then
  echo 'ERROR: src/ must be empty before initializing Laravel.' >&2; exit 1
fi
case "$PRESET" in
  react)
    [ "$VERSION" = 13 ] || { echo 'ERROR: Official React preset is pinned to Laravel 13 in this template.' >&2; exit 1; }
    PACKAGE='laravel/react-starter-kit'; CONSTRAINT='dev-main';; # Laravel 13 starter kits are published on main
  livewire)
    [ "$VERSION" = 13 ] || { echo 'ERROR: Official Livewire preset is pinned to Laravel 13 in this template.' >&2; exit 1; }
    PACKAGE='laravel/livewire-starter-kit'; CONSTRAINT='dev-main';; # Laravel 13 starter kits are published on main
  plain)
    case "$VERSION" in
      13|12) PACKAGE='laravel/laravel'; CONSTRAINT="^${VERSION}.0";;
      *) echo 'ERROR: plain preset supports Laravel 12 or 13.' >&2; exit 1;;
    esac;;
  *) echo 'ERROR: Choose react, livewire, or plain. For API+SPA see project-init Skill.' >&2; exit 1;;
esac

# Validate stack and build PHP with the extensions Laravel actually requires.
./manage.sh build

# Commands operate in /var/www/html, bind-mounted from ./src.
# --no-scripts prevents default sqlite migrations / app setup before infra DB is configured.
# Temporary Composer home prevents a non-empty src/ before create-project.
# --no-deps ensures a DB need not be running merely to create the skeleton.
echo ">>> Creating $PACKAGE ($VERSION) in src/"
args=(create-project "$PACKAGE" .)
[ -z "$CONSTRAINT" ] || args+=("$CONSTRAINT")
args+=(--prefer-dist --no-interaction --no-scripts)
./manage.sh run -e COMPOSER_HOME=/tmp/composer -e XDG_CACHE_HOME=/tmp/cache \
  app composer "${args[@]}"

if [ ! -f src/artisan ]; then
  echo 'ERROR: bootstrap did not produce src/artisan. Check Composer output.' >&2; exit 1
fi
./scripts/sync-app-env.sh

# Company conventions for the new app (Docker tool caches, Wayfinder in containers, src/CLAUDE.md tracked).
./manage.sh run -T app php < scripts/post-create-app.php
printf '\n>>> Laravel skeleton ready. Continue: ./manage.sh start && ./manage.sh install && ./manage.sh node-build\n'
printf '>>> Review src/.env before install, then use Claude Code /project-init for quality tools and optional modules.\n'
