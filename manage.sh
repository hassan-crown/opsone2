#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# Unified Docker manager script
# =============================================================================
# This script manages Laravel Docker infrastructure using:
#
#   docker-compose.yml          -> shared services
#   docker-compose.prod.yml     -> Traefik production/stage override
#
# Environment is controlled by .env:
#   ENVIRONMENT=local|dev|stage|prod
#
# Usage:
#   ./manage.sh start
#   ./manage.sh stop
#   ./manage.sh restart
#   ./manage.sh build
#   ./manage.sh install
#   ./manage.sh node-build
#   ./manage.sh deploy
#   ./manage.sh jobs
#   ./manage.sh logs
#   ./manage.sh ps
# =============================================================================

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

# Git Bash / MSYS2 on Windows: keep container paths such as /var/www/html untranslated.
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

# -----------------------------------------------------------------------------
# Portable helpers (Linux, macOS with bash 3.2, Windows Git Bash)
# -----------------------------------------------------------------------------
is_windows_shell() {
  case "$(uname -s)" in
    MINGW*|MSYS*|CYGWIN*) return 0 ;;
    *) return 1 ;;
  esac
}

# Replace or append KEY=VALUE in a dotenv file without sed -i (GNU/BSD differ).
set_env_file_key() {
  local file="$1" key="$2" val="$3" found=0 line tmp
  tmp="$(mktemp "${file}.tmp.XXXXXX")"
  while IFS= read -r line || [ -n "$line" ]; do
    line="${line%$'\r'}"
    if [[ "$line" == "$key="* ]]; then
      if [ "$found" -eq 0 ]; then printf '%s=%s\n' "$key" "$val" >> "$tmp"; found=1; fi
    else
      printf '%s\n' "$line" >> "$tmp"
    fi
  done < "$file"
  if [ "$found" -eq 0 ]; then printf '%s=%s\n' "$key" "$val" >> "$tmp"; fi
  chmod 600 "$tmp" 2>/dev/null || true
  mv "$tmp" "$file"
}

random_secret() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex 24
  else
    od -An -tx1 -N24 /dev/urandom | tr -d ' \n'
  fi
}

# Docker Desktop (Windows) needs a Linux-style id; Linux/macOS use the developer's own ids.
host_uid() { if is_windows_shell; then echo 1000; else id -u; fi; }
host_gid() { if is_windows_shell; then echo 1000; else id -g; fi; }

# True when something on the host already listens on TCP port $1.
port_in_use() {
  local port="$1"
  if is_windows_shell; then
    # Listening sockets have a foreign address ending in :0 (locale-independent).
    netstat -ano 2>/dev/null | tr -d '\r' | awk -v p="$port" '
      $1 == "TCP" { n = split($2, a, ":"); m = split($3, b, ":"); if (a[n] == p && b[m] == "0") f = 1 }
      END { exit !f }'
  elif [ "$(uname -s)" = "Darwin" ]; then
    lsof -nP -iTCP:"$port" -sTCP:LISTEN 2>/dev/null | grep -q LISTEN
  elif command -v ss >/dev/null 2>&1; then
    # Linux: ss lists all listeners (including root-owned docker-proxy) without sudo.
    ss -ltn 2>/dev/null | awk -v p="$port" 'NR > 1 { n = split($4, a, ":"); if (a[n] == p) f = 1 } END { exit !f }'
  else
    (exec 3<>"/dev/tcp/127.0.0.1/$port") >/dev/null 2>&1
  fi
}

# -----------------------------------------------------------------------------
# First run: ./manage.sh init [APP_NAME]
# -----------------------------------------------------------------------------
if [ "${1:-}" = "init" ]; then
  if [ -f .env ]; then
    echo "ERROR: .env already exists. Edit it directly; init never overwrites it." >&2
    exit 1
  fi
  if [ ! -f .infra_env.example ]; then
    echo "ERROR: .infra_env.example not found." >&2
    exit 1
  fi
  init_app_name="${2:-}"
  if [ -n "$init_app_name" ] && [[ ! "$init_app_name" =~ ^[a-zA-Z0-9_]+$ ]]; then
    echo "ERROR: APP_NAME must match [a-zA-Z0-9_]+" >&2
    exit 1
  fi
  cp .infra_env.example .env
  chmod 600 .env 2>/dev/null || true
  [ -z "$init_app_name" ] || set_env_file_key .env APP_NAME "$init_app_name"
  set_env_file_key .env DB_ROOT_PASSWORD "$(random_secret)"
  set_env_file_key .env DB_PASSWORD "$(random_secret)"
  set_env_file_key .env WWWUSER "$(host_uid)"
  set_env_file_key .env WWWGROUP "$(host_gid)"
  echo ">>> [manage] Created .env (random DB passwords, WWWUSER/WWWGROUP for this OS)."
  # Port selection below runs once the configuration is loaded.
fi

if [ ! -f .env ]; then
  echo "ERROR: .env not found. Run: ./manage.sh init <APP_NAME>" >&2
  exit 1
fi

# -----------------------------------------------------------------------------
# Read required infra values safely from .env
# -----------------------------------------------------------------------------
get_env_value() {
  local key="$1"
  local value

  value="$(
    grep -E "^${key}=" .env 2>/dev/null \
      | tail -n 1 \
      | cut -d '=' -f2- \
      || true
  )"

  # Remove optional surrounding single/double quotes and Windows CR if present.
  value="$(printf '%s' "$value" | tr -d '\r' | sed -E "s/^[\"']//; s/[\"']$//")"

  printf '%s' "$value"
}

APP_NAME="$(get_env_value APP_NAME)"
ENVIRONMENT="$(get_env_value ENVIRONMENT)"
GIT_BRANCH="$(get_env_value GIT_BRANCH)"

if [ -z "$APP_NAME" ]; then
  echo "ERROR: APP_NAME is not set in .env"
  exit 1
fi

if [ -z "$ENVIRONMENT" ]; then
  echo "ERROR: ENVIRONMENT is not set in .env"
  exit 1
fi

if [ -z "$GIT_BRANCH" ]; then
  echo "ERROR: GIT_BRANCH is not set in .env"
  exit 1
fi

ENV_LOWER="$(echo "$ENVIRONMENT" | tr '[:upper:]' '[:lower:]')"

case "$ENV_LOWER" in
  local|dev|stage|prod)
    ;;
  *)
    echo "ERROR: Unknown ENVIRONMENT value: '$ENV_LOWER'"
    echo "Expected one of: local, dev, stage, prod"
    exit 1
    ;;
esac

PROJECT_NAME="$(echo "${APP_NAME}_${ENV_LOWER}" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//')"

# -----------------------------------------------------------------------------
# Compose files
# -----------------------------------------------------------------------------
COMPOSE_ARGS=(-p "$PROJECT_NAME" -f docker-compose.yml)

# stage/prod use Traefik override
if [[ "$ENV_LOWER" == "stage" || "$ENV_LOWER" == "prod" || "$ENV_LOWER" == "dev" ]]; then
  COMPOSE_ARGS+=(-f docker-compose.prod.yml)
fi

dc() {
  docker compose "${COMPOSE_ARGS[@]}" "$@"
}

# Wayfinder TypeScript routes need PHP, which the node image lacks; generate them in the app container.
wayfinder_generate() {
  if ! grep -q "wayfinder" ./src/composer.json 2>/dev/null; then
    return 0
  fi
  if [ -z "$(dc ps -q app)" ]; then
    echo "ERROR: app container is not running (needed for wayfinder:generate). Run ./manage.sh start first." >&2
    exit 1
  fi
  echo ">>> [${ENV_LOWER}] Generating Wayfinder types..."
  dc exec -T app php artisan wayfinder:generate --with-form
}

# One-off containers (node-dev, run) are not removed by "down" while running; remove only this project's.
remove_oneoff_containers() {
  local id
  for id in $(docker ps -aq --filter "label=com.docker.compose.project=${PROJECT_NAME}" --filter "label=com.docker.compose.oneoff=True" 2>/dev/null); do
    docker rm -f "$id" >/dev/null
  done
}

# -----------------------------------------------------------------------------
# Host ports: if a configured port is busy, move it to the next free one in .env
# -----------------------------------------------------------------------------
PORT_KEYS="APP_PORT FORWARD_DB_PORT FORWARD_REDIS_PORT PHPMYADMIN_PORT VITE_PORT"

port_default() {
  case "$1" in
    APP_PORT) echo 8080 ;;
    FORWARD_DB_PORT) echo 3306 ;;
    FORWARD_REDIS_PORT) echo 6379 ;;
    PHPMYADMIN_PORT) echo 8081 ;;
    VITE_PORT) echo 5173 ;;
  esac
}

port_service() {
  case "$1" in
    APP_PORT) echo web ;;
    FORWARD_DB_PORT) echo mariadb ;;
    FORWARD_REDIS_PORT) echo redis ;;
    PHPMYADMIN_PORT) echo phpmyadmin ;;
    VITE_PORT) echo node-dev ;;
  esac
}

configured_port() {
  local value
  value="$(get_env_value "$1")"
  printf '%s' "${value:-$(port_default "$1")}"
}

# A candidate must not collide with another port key of this project.
port_taken_by_other_key() {
  local self="$1" candidate="$2" key
  for key in $PORT_KEYS; do
    [ "$key" = "$self" ] && continue
    [ "$(configured_port "$key")" = "$candidate" ] && return 0
  done
  return 1
}

ensure_free_port() {
  local key="$1" port candidate service
  port="$(configured_port "$key")"
  case "$port" in
    ''|*[!0-9]*) echo "ERROR: $key must be a number (got '$port')." >&2; exit 1 ;;
  esac

  if ! port_in_use "$port"; then
    [ -n "$(get_env_value "$key")" ] || set_env_file_key .env "$key" "$port"
    return 0
  fi

  # Busy because this project's own container already publishes it: nothing to do.
  service="$(port_service "$key")"
  if [ -n "$(dc ps -q "$service" 2>/dev/null)" ]; then
    return 0
  fi

  candidate="$port"
  while :; do
    candidate=$((candidate + 1))
    if [ "$candidate" -gt 65535 ] || [ "$candidate" -gt $((port + 200)) ]; then
      echo "ERROR: $key=$port is in use and no free port found up to $candidate. Set $key in .env manually." >&2
      exit 1
    fi
    port_taken_by_other_key "$key" "$candidate" && continue
    port_in_use "$candidate" || break
  done

  set_env_file_key .env "$key" "$candidate"
  echo ">>> [manage] Port $port is in use; set $key=$candidate in .env"

  # Keep Laravel's local APP_URL aligned with the published web port.
  if [ "$key" = "APP_PORT" ] && [ "$ENV_LOWER" = "local" ] && [ -f ./src/.env ]; then
    set_env_file_key ./src/.env APP_URL "http://localhost:${candidate}"
    echo ">>> [manage] Updated src/.env APP_URL=http://localhost:${candidate}"
  fi
}

# Only local publishes web/db/redis/pma ports; dev/stage/prod reset them (docker-compose.prod.yml).
ensure_free_ports() {
  local key
  for key in "$@"; do
    if [ "$key" = "VITE_PORT" ] || [ "$ENV_LOWER" = "local" ]; then
      ensure_free_port "$key"
    fi
  done
}

show_ports() {
  local key
  for key in $PORT_KEYS; do
    printf '  %-19s %s\n' "$key" "$(configured_port "$key")"
  done
}

# -----------------------------------------------------------------------------
# Helpers
# -----------------------------------------------------------------------------
ensure_src_dir() {
  if [ ! -d ./src ]; then
    echo "ERROR: ./src directory is missing."
    echo "Create it or clone the Laravel application into ./src."
    exit 1
  fi
}

ensure_git_repo() {
  ensure_src_dir

  if [ ! -d ./.git ]; then
    echo "ERROR: Project root is not a Git repository."
    echo "Deploy expects the full project root, including infrastructure and src, to be initialized with Git."
    exit 1
  fi
}

show_help() {
  cat <<EOF
  Usage: $0 <command>

  Environment:
    APP_NAME=$APP_NAME
    ENVIRONMENT=$ENV_LOWER
    PROJECT_NAME=$PROJECT_NAME
    Compose: ${COMPOSE_ARGS[*]}

  Commands:
    init          First run: create .env (secrets, UID/GID, free ports). Usage: ./manage.sh init <APP_NAME>
    ports         Show host ports; move any busy port to a free one in .env
    start         Start main containers in background
    stop          Stop and remove containers
    restart       Recreate app/web; preserve jobs profile if it was active
    build         Build PHP image
    build-node    Build dedicated pnpm/Node image
    install       Run Laravel installer service
    node-build    Build frontend assets using node-build service
    node-dev      Start Vite development server (foreground)
    deploy        Full deploy flow: git pull + build + install + node-build + start
    jobs          Start jobs profile containers: worker and scheduler
    stop-jobs     Stop jobs profile containers
    pma           Start phpMyAdmin tooling service
    logs          Follow app logs
    logs-web      Follow web/nginx logs
    logs-worker   Follow worker logs
    ps            Show containers status
    config        Render final Docker Compose config
    shell         Open shell inside app container
    artisan       Run artisan command, example: ./manage.sh artisan migrate
    composer      Run composer command, example: ./manage.sh composer install
    run           One-off tooling container (docker compose run --rm --no-deps), example: ./manage.sh run app php -v
    down-volumes  DANGER: Stop containers and remove volumes

  Examples:
    ./manage.sh init my_project
    ./manage.sh start
    ./manage.sh install
    ./manage.sh node-build
    ./manage.sh jobs
    ./manage.sh artisan migrate
EOF
}

COMMAND="${1:-help}"
shift || true

# Do not allow deployments/local services to use sample credentials verbatim.
validate_credentials() {
  local key value
  for key in DB_PASSWORD DB_ROOT_PASSWORD; do
    value="$(get_env_value "$key")"
    if [ -z "$value" ] || [[ "$value" == strong_*_password_here ]] || [[ "$value" == change_this* ]]; then
      echo "ERROR: Set a strong unique $key in root .env; sample credentials are forbidden." >&2
      exit 1
    fi
  done
}
case "$COMMAND" in
  start|restart|install|jobs|deploy|pma) validate_credentials ;;
esac

case "$COMMAND" in
  init)
    ensure_free_ports $PORT_KEYS
    echo ">>> [manage] Review .env (APP_NAME, ENVIRONMENT, WEB_DOMAIN). Host ports:"
    show_ports
    ;;

  ports)
    ensure_free_ports $PORT_KEYS
    show_ports
    ;;

  start)
    ensure_src_dir
    ensure_free_ports APP_PORT FORWARD_DB_PORT FORWARD_REDIS_PORT
    echo ">>> [${ENV_LOWER}] Starting containers..."
    dc up -d --remove-orphans
    ;;

  stop)
    echo ">>> [${ENV_LOWER}] Stopping containers..."
    remove_oneoff_containers
    dc --profile tooling --profile jobs --profile frontend down
    ;;

  restart)
    ensure_src_dir
    echo ">>> [${ENV_LOWER}] Restarting containers..."
    # Do not accidentally discard the running jobs profile on restart.
    had_jobs="$(dc ps -q worker || true)"
    remove_oneoff_containers
    dc --profile tooling --profile jobs --profile frontend down
    ensure_free_ports APP_PORT FORWARD_DB_PORT FORWARD_REDIS_PORT
    dc up -d --remove-orphans
    if [ -n "$had_jobs" ]; then
      dc --profile jobs up -d worker scheduler
    fi
    ;;

  build)
    echo ">>> [${ENV_LOWER}] Building PHP image..."
    dc build app "$@"
    ;;

  build-node)
    echo ">>> [${ENV_LOWER}] Building Node image..."
    dc --profile tooling build node-build
    ;;

  install)
    ensure_src_dir

    if [ -z "$(dc ps -q app)" ] || [ -z "$(dc ps -q mariadb)" ] || [ -z "$(dc ps -q redis)" ]; then
      echo "ERROR: Required containers are not running."
      echo
      echo "Please run:"
      echo "  ./manage.sh start"
      echo
      echo "Then run:"
      echo "  ./manage.sh install"
      exit 1
    fi

    echo ">>> [${ENV_LOWER}] Running installer..."
    dc --profile tooling run --rm installer "$@"
    ;;

  node-build)
    ensure_src_dir
    if [ ! -f ./src/package.json ]; then
      echo '>>> [manage] src/package.json is missing; no frontend build needed.'
      exit 0
    fi
    wayfinder_generate
    echo ">>> [${ENV_LOWER}] Building frontend assets..."
    dc --profile tooling run --rm --build node-build
    ;;

  node-dev)
    ensure_src_dir
    if [[ "$ENV_LOWER" == "stage" || "$ENV_LOWER" == "prod" ]]; then
      echo 'ERROR: node-dev may only run in local/dev.' >&2
      exit 1
    fi
    if [ ! -f ./src/package.json ]; then
      echo 'ERROR: src/package.json is missing (no frontend installed).' >&2
      exit 1
    fi
    ensure_free_ports VITE_PORT
    wayfinder_generate
    echo ">>> [${ENV_LOWER}] Starting Vite development server..."
    dc --profile frontend run --rm --service-ports node-dev
    ;;

  deploy)
    ensure_git_repo

    echo ">>> [${ENV_LOWER}] Deploy started..."

    echo ">>> [${ENV_LOWER}] Pulling latest application code from branch: $GIT_BRANCH"
    if [ -n "$(git status --porcelain)" ]; then
      echo 'ERROR: Deployment worktree has uncommitted changes. Aborting.' >&2
      exit 1
    fi
    git -C ./ pull --ff-only origin "$GIT_BRANCH"

    echo ">>> [${ENV_LOWER}] Building PHP image..."
    dc build app

    echo ">>> [${ENV_LOWER}] Starting application containers..."
    ensure_free_ports APP_PORT FORWARD_DB_PORT FORWARD_REDIS_PORT
    dc up -d --remove-orphans

    echo ">>> [${ENV_LOWER}] Running installer..."
    dc --profile tooling run --rm installer

    if [ -f ./src/package.json ]; then
      echo ">>> [${ENV_LOWER}] Building frontend assets..."
      dc --profile tooling run --rm --build node-build
    fi

    echo ">>> [${ENV_LOWER}] Starting jobs..."
    dc --profile jobs up -d --remove-orphans

    # Workers keep old code in memory; signal them to exit so restart policy reloads the new code.
    echo ">>> [${ENV_LOWER}] Restarting queue workers..."
    dc exec -T app php artisan queue:restart || true

    echo ">>> [${ENV_LOWER}] Deploy completed."
    ;;

  jobs)
    ensure_src_dir
    echo ">>> [${ENV_LOWER}] Starting jobs profile..."
    dc --profile jobs up -d --remove-orphans
    ;;

  stop-jobs)
    echo ">>> [${ENV_LOWER}] Stopping jobs profile containers..."
    dc stop worker scheduler 2>/dev/null || true
    dc rm -f worker scheduler 2>/dev/null || true
    ;;

  pma)
    ensure_free_ports PHPMYADMIN_PORT
    echo ">>> [${ENV_LOWER}] Starting phpMyAdmin..."
    dc --profile tooling up -d phpmyadmin
    ;;

  logs)
    dc logs -f app
    ;;

  logs-web)
    dc logs -f web
    ;;

  logs-worker)
    dc logs -f worker
    ;;

  ps)
    dc ps
    ;;

  config)
    dc config
    ;;

  shell)
    ensure_src_dir
    dc exec app bash
    ;;

  artisan)
    ensure_src_dir
    if [ "$#" -eq 0 ]; then
      echo "ERROR: Missing artisan command."
      echo "Example: ./manage.sh artisan migrate"
      exit 1
    fi
    dc exec app php artisan "$@"
    ;;

  composer)
    ensure_src_dir
    if [ "$#" -eq 0 ]; then
      echo "ERROR: Missing composer command."
      echo "Example: ./manage.sh composer install"
      exit 1
    fi
    dc --profile tooling run --rm --no-deps installer composer "$@"
    ;;

  run)
    if [ "$#" -eq 0 ]; then
      echo "ERROR: Missing service. Example: ./manage.sh run app php -v" >&2
      exit 1
    fi
    dc --profile tooling run --rm --no-deps "$@"
    ;;

  down-volumes)
    echo "WARNING: This will remove containers and named volumes for project: $PROJECT_NAME"
    echo "This can delete database data stored in Docker volumes."
    read -r -p "Type DELETE to continue: " CONFIRM

    if [ "$CONFIRM" != "DELETE" ]; then
      echo "Cancelled."
      exit 0
    fi

    dc --profile tooling --profile jobs down -v
    ;;

  help|-h|--help)
    show_help
    ;;

  *)
    echo "Unknown command: $COMMAND"
    echo
    show_help
    exit 1
    ;;
esac
