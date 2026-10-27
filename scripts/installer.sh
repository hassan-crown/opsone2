#!/usr/bin/env bash
set -euo pipefail

echo ">>> [installer] Starting application setup..."

cd /var/www/html

# -----------------------------------------------------------------------------
# 0) Basic directories and permissions
# -----------------------------------------------------------------------------
echo ">>> [installer] Using APP_USER=${WWWUSER:-1000} APP_GROUP=${WWWGROUP:-1000}"

umask 002

mkdir -p \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

# These commands may fail when running as non-root; that is acceptable.
# Ownership should normally be fixed once on the host using deploy:deploy.
chown -R "${WWWUSER:-1000}:${WWWGROUP:-1000}" storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

# Directories 775, files 664 (more disciplined than 777)
# find storage bootstrap/cache -type d -exec chmod 775 {} \; 2>/dev/null || true
# find storage bootstrap/cache -type f -exec chmod 664 {} \; 2>/dev/null || true

# -----------------------------------------------------------------------------
# 1) Ensure .env file exists
# -----------------------------------------------------------------------------
if [ ! -f .env ]; then
  echo ">>> [installer] .env not found, creating from .env.example..."

  if [ -f .env.example ]; then
    cp .env.example .env
  else
    echo ">>> [installer] ERROR: .env.example not found!"
    exit 1
  fi
else
  echo ">>> [installer] .env already exists, skipping copy."
fi

# -----------------------------------------------------------------------------
# 2) Resolve environments
# -----------------------------------------------------------------------------
# APP_ENV is read from the container environment if available.
# If not available, read it from .env.
APP_ENVIRONMENT="${APP_ENV:-}"

if [ -z "$APP_ENVIRONMENT" ] && [ -f .env ]; then
  APP_ENVIRONMENT="$(grep -E '^APP_ENV=' .env | cut -d '=' -f2- | tr -d '"' | tr -d "'" || true)"
fi

APP_ENVIRONMENT="${APP_ENVIRONMENT:-local}"
INFRA_ENVIRONMENT="${ENVIRONMENT:-local}"

echo ">>> [installer] APP_ENV=$APP_ENVIRONMENT"
echo ">>> [installer] INFRA ENVIRONMENT=$INFRA_ENVIRONMENT"

if [ "$INFRA_ENVIRONMENT" = "prod" ] && [ "$APP_ENVIRONMENT" != "production" ]; then
  echo ">>> [installer] WARNING:"
  echo ">>>   INFRA ENVIRONMENT is prod, but Laravel APP_ENV is '$APP_ENVIRONMENT'."
  echo ">>>   You should set APP_ENV=production in /var/www/html/.env."
fi

if [ "$INFRA_ENVIRONMENT" = "stage" ] && [ "$APP_ENVIRONMENT" != "staging" ]; then
  echo ">>> [installer] WARNING:"
  echo ">>>   INFRA ENVIRONMENT is stage, but Laravel APP_ENV is '$APP_ENVIRONMENT'."
  echo ">>>   You should set APP_ENV=staging in /var/www/html/.env."
fi

if [ ! -f composer.json ]; then
  echo ">>> [installer] ERROR: composer.json not found in /var/www/html."
  echo ">>> [installer] Make sure the Laravel project exists inside ./src before running installer."
  exit 1
fi

# -----------------------------------------------------------------------------
# 3) Composer install
# -----------------------------------------------------------------------------
mkdir -p "${COMPOSER_HOME}"
chmod -R ug+rwX "${COMPOSER_HOME}" 2>/dev/null || true

if [ ! -d vendor ]; then
  echo ">>> [installer] vendor directory not found, running composer install..."
else
  echo ">>> [installer] vendor directory exists, ensuring dependencies are installed..."
fi

if [ "$APP_ENVIRONMENT" = "production" ] || [ "$APP_ENVIRONMENT" = "staging" ] || [ "$INFRA_ENVIRONMENT" = "prod" ] || [ "$INFRA_ENVIRONMENT" = "stage" ]; then
  echo ">>> [installer] Installing Composer dependencies for production/staging..."
  composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
else
  echo ">>> [installer] Installing Composer dependencies for local/development..."
  composer install --no-interaction --prefer-dist
fi

# -----------------------------------------------------------------------------
# 4) Clear old Laravel caches after dependencies are available
# -----------------------------------------------------------------------------
if [ -f artisan ] && [ -f vendor/autoload.php ]; then
  echo ">>> [installer] Clearing old Laravel caches..."
  php artisan optimize:clear || true
fi

# -----------------------------------------------------------------------------
# 5) APP_KEY
# -----------------------------------------------------------------------------
CURRENT_APP_KEY="$(grep -E '^APP_KEY=' .env | cut -d '=' -f2- || true)"

if [ -z "$CURRENT_APP_KEY" ]; then
  echo ">>> [installer] APP_KEY is empty, generating new key..."
  php artisan key:generate --force
else
  echo ">>> [installer] APP_KEY already set, skipping key:generate."
fi

# -----------------------------------------------------------------------------
# 6) storage:link
# -----------------------------------------------------------------------------
if [ ! -L public/storage ]; then
  echo ">>> [installer] storage symlink not found, creating..."
  php artisan storage:link || true
else
  echo ">>> [installer] storage symlink already exists, skipping."
fi

# -----------------------------------------------------------------------------
# 7) Database migrations
# -----------------------------------------------------------------------------
INSTALL_MODE="${1:-update}"

echo ">>> [installer] Running database migrations in $INSTALL_MODE mode..."
if [ "$INSTALL_MODE" = "refresh" ]; then
  if [ "$APP_ENVIRONMENT" = "production" ] || [ "$APP_ENVIRONMENT" = "staging" ] || [ "$INFRA_ENVIRONMENT" = "prod" ] || [ "$INFRA_ENVIRONMENT" = "stage" ]; then
    echo ">>> [installer] ERROR: Refresh mode is not allowed in production/staging environments."
    exit 1
  fi

  echo ">>> [installer] Refresh mode detected, running migrate:fresh..."
  php artisan migrate:fresh --force --no-interaction
else
  echo ">>> [installer] Update mode detected, running migrate..."
  php artisan migrate --force --no-interaction
fi

# -----------------------------------------------------------------------------
# 8) Database seeding
# -----------------------------------------------------------------------------
if [ "$INSTALL_MODE" = "refresh" ]; then
  if [ "$APP_ENVIRONMENT" = "production" ] || [ "$APP_ENVIRONMENT" = "staging" ] || [ "$INFRA_ENVIRONMENT" = "prod" ] || [ "$INFRA_ENVIRONMENT" = "stage" ]; then
    echo ">>> [installer] ERROR: Seeding is not allowed in production/staging environments."
    exit 1
  fi

  echo ">>> [installer] Refresh mode detected, running db:seed..."
  php artisan db:seed --force --no-interaction
else
  echo ">>> [installer] Update mode detected, skipping db:seed."
fi

# -----------------------------------------------------------------------------
# 9) Laravel Passport setup, if installed
# -----------------------------------------------------------------------------
if [ ! -d "vendor/laravel/passport" ]; then
  echo ">>> [installer] Laravel Passport is not installed. Skipping Passport setup."
else
  echo ">>> [installer] Laravel Passport detected. Running Passport setup..."

  if [ ! -f storage/oauth-private.key ] || [ ! -f storage/oauth-public.key ]; then
    echo ">>> [installer] Passport keys not found, generating..."
    php artisan passport:keys --force
  else
    echo ">>> [installer] Passport keys already exist, skipping."
  fi

  echo ">>> [installer] Passport clients are NOT automatically recreated."
  echo ">>> [installer] Provision clients explicitly using audited commands as needed."
fi

# -----------------------------------------------------------------------------
# 10) Final optimization
# -----------------------------------------------------------------------------
if [ "$APP_ENVIRONMENT" = "production" ] || [ "$APP_ENVIRONMENT" = "staging" ] || [ "$INFRA_ENVIRONMENT" = "prod" ] || [ "$INFRA_ENVIRONMENT" = "stage" ]; then
  echo ">>> [installer] Optimizing application for production/staging..."
  php artisan optimize
else
  echo ">>> [installer] Local/development detected. Keeping caches cleared..."
  php artisan optimize:clear || true
fi

echo ">>> [installer] Setup completed successfully."
