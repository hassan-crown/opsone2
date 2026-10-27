# Laravel Docker Infrastructure

This repository provides a reusable Docker-based infrastructure for running Laravel applications on Linux servers using Docker, Docker Compose, Nginx, PHP-FPM, MariaDB, Redis, and optionally Traefik.

The Laravel application code lives inside the `src/` directory, while the infrastructure files live in the project root.

---

## 1. Stack Overview

The stack includes:

* **PHP-FPM**: PHP 8.4
* **Web Server**: Nginx
* **Database**: MariaDB 11
* **Cache / Queue**: Redis
* **Frontend Build**: Node.js + Corepack + PNPM
* **Optional DB UI**: phpMyAdmin
* **Reverse Proxy**: Traefik for `stage` and `prod`
* **Application**: Laravel inside `src/`

---

## 2. Project Structure

```text
project-root/
  Dockerfile
  docker-compose.yml
  docker-compose.prod.yml
  nginx.conf
  php.ini
  manage.sh
  .infra_env.example
  .env                    # Infra environment file, ignored by Git

  scripts/
    installer.sh
    new-laravel-from-docker.sh

  src/
    .env                  # Laravel application env
    artisan
    app/
    bootstrap/
    config/
    database/
    public/
    resources/
    routes/
    storage/
    vendor/
```

---

## 3. Environment Files

There are two different environment files:

### 3.1 Infrastructure `.env`

The root `.env` file is used by Docker Compose and infrastructure scripts.

It is created from:

```bash
cp .infra_env.example .env
```

Example:

```env
APP_NAME=laravel
ENVIRONMENT=local
WEB_DOMAIN=laravel.developer-web.net
PMA_DOMAIN=pma-laravel.developer-web.net
GIT_BRANCH=main

WITH_XDEBUG=false
INSTALL_NODE=false
NODE_MAJOR=20

DB_ROOT_PASSWORD=strong_root_password_here
DB_PASSWORD=strong_user_password_here

APP_PORT=8080
FORWARD_DB_PORT=3306
FORWARD_REDIS_PORT=6379
PHPMYADMIN_PORT=8081

WWWUSER=1000
WWWGROUP=1000
```

The root `.env` file must not be committed to Git.

---

### 3.2 Laravel `src/.env`

The Laravel application uses:

```text
src/.env
```

Database values must match the infrastructure naming convention:

```env
DB_CONNECTION=mysql
DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=${APP_NAME}_${ENVIRONMENT}_db
DB_USERNAME=${APP_NAME}_${ENVIRONMENT}_user
DB_PASSWORD=${DB_PASSWORD}
```

For example, if the root `.env` contains:

```env
APP_NAME=laravel
ENVIRONMENT=local
DB_PASSWORD=strong_user_password_here
```

Then Laravel should use:

```env
DB_CONNECTION=mysql
DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=laravel_local_db
DB_USERNAME=laravel_local_user
DB_PASSWORD=strong_user_password_here
```

---

## 4. Supported Environments

The root `.env` value `ENVIRONMENT` supports:

```text
local
dev
stage
prod
```

Compose behavior:

| Environment | Compose Files                                    |
| ----------- | ------------------------------------------------ |
| `local`     | `docker-compose.yml`                             |
| `dev`       | `docker-compose.yml`                             |
| `stage`     | `docker-compose.yml` + `docker-compose.prod.yml` |
| `prod`      | `docker-compose.yml` + `docker-compose.prod.yml` |

`stage` and `prod` use Traefik routing from `docker-compose.prod.yml`.

---

## 5. First-Time Setup

### 5.1 Make scripts executable

```bash
chmod +x manage.sh
chmod +x scripts/installer.sh
chmod +x scripts/new-laravel-from-docker.sh
```

---

### 5.2 Create infrastructure `.env`

```bash
cp .infra_env.example .env
```

Then edit:

```bash
nano .env
```

Set at least:

```env
APP_NAME=your_app_name
ENVIRONMENT=local
WEB_DOMAIN=your-domain.com
PMA_DOMAIN=pma-your-domain.com
GIT_BRANCH=main
DB_ROOT_PASSWORD=change_this
DB_PASSWORD=change_this
WWWUSER=1000
WWWGROUP=1000
```

---

### 5.3 Create a new Laravel project

If `src/` does not contain a Laravel project, create one using Docker:

```bash
./scripts/new-laravel-from-docker.sh
```

This script creates a Laravel project inside `src/` and adjusts `src/.env` for Docker services.

---

### 5.4 Build PHP image

```bash
./manage.sh build
```

---

### 5.5 Run Laravel installer

```bash
./manage.sh install
```

The installer handles:

* Composer install
* `.env` creation if missing
* `APP_KEY` generation
* `storage:link`
* Database migrations
* Optional seeding in local/development
* Optional Passport setup if installed
* Laravel optimization for staging/production

---

### 5.6 Build frontend assets

```bash
./manage.sh node-build
```

---

### 5.7 Start containers

```bash
./manage.sh start
```

---

## 6. Daily Commands

### Start containers

```bash
./manage.sh start
```

### Stop containers

```bash
./manage.sh stop
```

### Restart containers

```bash
./manage.sh restart
```

### Show containers status

```bash
./manage.sh ps
```

### Show final Docker Compose config

```bash
./manage.sh config
```

---

## 7. Laravel Commands

### Run Artisan command

```bash
./manage.sh artisan migrate
```

Examples:

```bash
./manage.sh artisan optimize:clear
./manage.sh artisan queue:restart
./manage.sh artisan config:cache
```

---

### Run Composer command

```bash
./manage.sh composer install
```

Examples:

```bash
./manage.sh composer dump-autoload
./manage.sh composer require package/name
```

---

### Open shell inside app container

```bash
./manage.sh shell
```

---

## 8. Jobs: Queue Worker and Scheduler

The queue worker and scheduler are managed through the `jobs` profile.

Start jobs:

```bash
./manage.sh jobs
```

Stop jobs:

```bash
./manage.sh stop-jobs
```

View worker logs:

```bash
./manage.sh logs-worker
```

The worker command uses:

```bash
php artisan queue:work redis --queue=default --sleep=1 --tries=3 --timeout=120 --memory=256 --max-time=3600 --no-interaction
```

The scheduler uses:

```bash
php artisan schedule:work --no-interaction
```

---

## 9. phpMyAdmin

phpMyAdmin is optional and runs only through the `tooling` profile.

Start phpMyAdmin:

```bash
./manage.sh pma
```

For `stage` and `prod`, phpMyAdmin is exposed through Traefik and protected using Basic Auth.

Generate Basic Auth value:

```bash
htpasswd -nb admin STRONG_PASSWORD
```

When adding it to `.env`, escape `$` by doubling it:

```env
PMA_BASIC_AUTH=admin:$$apr1$$xxxxxxxx$$xxxxxxxxxxxxxxxxxxxxxx
```

---

## 10. Deployment Flow

The repository is expected to be a Git repository from the project root.

The Laravel app is inside:

```text
src/
```

The infrastructure and application code are pulled together from the project root.

Run deployment:

```bash
./manage.sh deploy
```

Deployment flow:

1. Pull latest code from `GIT_BRANCH`
2. Build PHP image
3. Start MariaDB and Redis
4. Run Laravel installer
5. Build frontend assets
6. Start application containers
7. Start worker and scheduler

---

## 11. Production / Stage with Traefik

For `ENVIRONMENT=stage` or `ENVIRONMENT=prod`, `manage.sh` automatically uses:

```bash
docker-compose.yml
docker-compose.prod.yml
```

Make sure the external Traefik network exists:

```bash
docker network ls
```

If the network does not exist:

```bash
docker network create proxy
```

The application domain is controlled by:

```env
WEB_DOMAIN=example.com
```

phpMyAdmin domain is controlled by:

```env
PMA_DOMAIN=pma.example.com
```

---

## 12. Volumes

The stack uses named Docker volumes:

```text
db-data
redis
node-modules
pnpm-store
composer-cache
```

`db-data` contains database files.

Do not remove volumes unless you intentionally want to delete persistent data.

Dangerous command:

```bash
./manage.sh down-volumes
```

This command requires typing:

```text
DELETE
```

before removing volumes.

---

## 13. Permissions

The containers run using the host user and group IDs:

```env
WWWUSER=1000
WWWGROUP=1000
```

On the server, check the deploy user IDs:

```bash
id -u deploy
id -g deploy
```

Recommended ownership:

```bash
sudo chown -R deploy:deploy .
```

Laravel writable directories:

```bash
chmod -R ug+rwX src/storage src/bootstrap/cache
```

Avoid using:

```bash
chmod -R 777
```

---

## 14. PHP Configuration

Custom PHP settings are defined in:

```text
php.ini
```

The file is copied into the PHP image by the `Dockerfile`:

```dockerfile
COPY php.ini /usr/local/etc/php/conf.d/90-custom.ini
```

Current PHP settings include:

* Upload limit
* Post max size
* Memory limit
* Execution time
* OPcache
* Realpath cache

Because the current deployment model uses bind-mounted source code, OPcache uses:

```ini
opcache.validate_timestamps=1
opcache.revalidate_freq=2
```

If the project later moves to immutable Docker images, these can be changed for better production performance:

```ini
opcache.validate_timestamps=0
opcache.revalidate_freq=0
```

---

## 15. Nginx

Nginx serves Laravel from:

```text
/var/www/html/public
```

Important behavior:

* Only `public/index.php` is executed.
* Direct PHP execution outside `index.php` is blocked.
* PHP execution inside `/storage` is blocked.
* Hidden files such as `.env` and `.git` are denied.
* Static assets are cached.
* Upload size is aligned with PHP settings.

SSL is not configured in Nginx because Traefik handles HTTPS in `stage` and `prod`.

---

## 16. Security Notes

Recommended practices:

* Do not commit root `.env`.
* Do not commit `src/.env`.
* Do not expose MariaDB or Redis publicly.
* Keep DB and Redis ports bound to `127.0.0.1` only if exposed.
* Do not run application containers as root.
* Do not use `chmod 777`.
* Protect phpMyAdmin with Basic Auth.
* Prefer separate Linux users per project for sensitive production servers.
* Keep Traefik and Docker updated.

---

## 17. Useful Commands

Build PHP image:

```bash
./manage.sh build
```

Install Laravel dependencies and run setup:

```bash
./manage.sh install
```

Build frontend:

```bash
./manage.sh node-build
```

Start app:

```bash
./manage.sh start
```

Start jobs:

```bash
./manage.sh jobs
```

Open app shell:

```bash
./manage.sh shell
```

View logs:

```bash
./manage.sh logs
./manage.sh logs-web
./manage.sh logs-worker
```

Run migrations:

```bash
./manage.sh artisan migrate
```

Clear Laravel cache:

```bash
./manage.sh artisan optimize:clear
```

Restart queues:

```bash
./manage.sh artisan queue:restart
```

---

## 18. Recommended Initial Sequence

For a fresh project:

```bash
cp .infra_env.example .env
nano .env

chmod +x manage.sh
chmod +x scripts/installer.sh
chmod +x scripts/new-laravel-from-docker.sh

./scripts/new-laravel-from-docker.sh
./manage.sh build
./manage.sh install
./manage.sh node-build
./manage.sh start
./manage.sh jobs
```

For deployment after Git changes:

```bash
./manage.sh deploy
```
