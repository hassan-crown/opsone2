#!/usr/bin/env bash
set -euo pipefail
# Synchronize initial Laravel .env with root infrastructure values.
# Does not change application APP_KEY or any unrelated custom configuration.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
if [ ! -f .env ]; then echo 'ERROR: Missing root .env' >&2; exit 1; fi
if [ ! -f src/.env.example ]; then echo 'ERROR: Missing src/.env.example' >&2; exit 1; fi
if [ ! -e src/.env ]; then cp src/.env.example src/.env; fi

read_value() {
  local key="$1" line
  line="$(grep -E "^${key}=" .env | tail -n 1 || true)"
  [ -n "$line" ] || { echo "ERROR: Missing ${key} in root .env" >&2; return 1; }
  line="${line#*=}"
  line="${line%$'\r'}"
  if [[ "$line" == \"*\" && "$line" == *\" ]] || [[ "$line" == \'*\' && "$line" == *\' ]]; then
    line="${line:1:${#line}-2}"
  fi
  printf '%s' "$line"
}

# Escape Laravel Dotenv string values: quote strings containing whitespace, #,
# dollars, or other special characters. The double-quote escaping below is
# compatible with phpdotenv, but review unusual credentials manually.
dotenv_value() {
  local v="$1"
  if [[ "$v" =~ ^[a-zA-Z0-9_./:@-]+$ ]]; then printf '%s' "$v"; return; fi
  v="${v//\\/\\\\}"
  v="${v//\"/\\\"}"
  printf '"%s"' "$v"
}

set_key() {
  local key="$1" val="$2" found=0 line tmp
  tmp="$(mktemp "${ROOT}/src/.env.tmp.XXXXXX")"
  while IFS= read -r line || [ -n "$line" ]; do
    if [[ "$line" == "$key="* ]]; then
      if [ "$found" -eq 0 ]; then printf '%s=%s\n' "$key" "$val" >> "$tmp"; found=1; fi
    else
      printf '%s\n' "$line" >> "$tmp"
    fi
  done < src/.env
  if [ "$found" -eq 0 ]; then printf '%s=%s\n' "$key" "$val" >> "$tmp"; fi
  chmod --reference=src/.env "$tmp" 2>/dev/null || chmod 600 "$tmp"
  mv "$tmp" src/.env
}

app_name="$(read_value APP_NAME)"
environment="$(read_value ENVIRONMENT)"
password="$(read_value DB_PASSWORD)"
app_port="$(read_value APP_PORT)"
web_domain="$(read_value WEB_DOMAIN)"
if [[ ! "$app_name" =~ ^[a-zA-Z0-9_]+$ ]] || [[ ! "$environment" =~ ^(local|dev|stage|prod)$ ]]; then
  echo 'ERROR: APP_NAME must be alphanumeric/underscore; ENVIRONMENT must be local/dev/stage/prod.' >&2; exit 1
fi
case "$environment" in
  prod) app_env=production;;
  stage) app_env=staging;;
  *) app_env=local;;
esac

set_key APP_ENV "$app_env"
set_key APP_DEBUG "$([ "$environment" = prod ] || [ "$environment" = stage ] && echo false || echo true)"
if [ "$environment" = local ]; then
  set_key APP_URL "http://localhost:${app_port}"
else
  set_key APP_URL "https://${web_domain}"
fi
set_key DB_CONNECTION mysql
set_key DB_HOST mariadb
set_key DB_PORT 3306
set_key DB_DATABASE "${app_name}_${environment}_db"
set_key DB_USERNAME "${app_name}_${environment}_user"
set_key DB_PASSWORD "$(dotenv_value "$password")"
set_key CACHE_STORE redis
set_key QUEUE_CONNECTION redis
set_key SESSION_DRIVER redis
set_key REDIS_HOST redis
set_key REDIS_PORT 6379
set_key REDIS_CLIENT phpredis
# No APP_KEY reset; never regenerate existing encryption keys.
echo '>>> Updated src/.env database, queue, cache, and session settings.'
echo '>>> Review APP_URL and credentials before starting the application.'
