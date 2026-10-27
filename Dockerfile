# -----------------------------------------------------------------------------
# Base PHP-FPM image
# -----------------------------------------------------------------------------
# PHP-FPM is used because Nginx will handle HTTP requests
# and forward PHP execution to this container through FastCGI.
FROM php:8.5-fpm AS app

# -----------------------------------------------------------------------------
# Build arguments
# -----------------------------------------------------------------------------
# WITH_XDEBUG:
#   true  => install Xdebug, useful for local/dev only
#   false => skip Xdebug, recommended for staging/production
#
# INSTALL_NODE:
#   true  => install Node.js, useful for local/dev only
#   false => skip Node.js, recommended for staging/production
#
# NODE_MAJOR:
#   Specify the major version of Node.js to install (e.g., 18, 20).
#
# WWWUSER / WWWGROUP:
#   Used to create a container user matching the host deploy user.
#   This helps avoid Laravel storage/log permission issues.
ARG WITH_XDEBUG=false
ARG INSTALL_NODE=false
ARG NODE_MAJOR=24
ARG WWWUSER=1000
ARG WWWGROUP=1000

# -----------------------------------------------------------------------------
# Install system dependencies and PHP extensions
# -----------------------------------------------------------------------------
# Required extensions for typical Laravel applications:
# - pdo_mysql : MySQL/MariaDB database connection
# - mbstring  : string handling
# - xml       : XML support
# - zip       : ZIP archive support
# - gd        : image processing
# - intl      : localization/formatting
# - bcmath    : precise decimal calculations
# - pcntl     : queue workers / process control
# - exif      : image metadata
# - redis     : cache, queues, sessions
RUN set -eux; \
  apt-get update; \
  apt-get install -y --no-install-recommends \
    git \
    unzip \
    zip \
    curl \
    bash \
    libpq-dev \
    libsqlite3-dev \
    libfcgi-bin \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
  ; \
  docker-php-ext-configure gd --with-freetype --with-jpeg; \
  docker-php-ext-install -j"$(nproc)" \
    gd \
    mbstring \
    xml \
    zip \
    pdo \
    pdo_mysql \
    pdo_sqlite \
    pcntl \
    exif \
    intl \
    bcmath \
  ; \
  pecl install redis; \
  docker-php-ext-enable redis; \
  rm -rf /var/lib/apt/lists/*

# -----------------------------------------------------------------------------
# Optional Xdebug installation
# -----------------------------------------------------------------------------
# Xdebug should be enabled only in local/dev environments.
# Never enable it in production because it adds overhead and may expose debug info.
RUN if [ "$WITH_XDEBUG" = "true" ]; then \
    echo ">>> Installing Xdebug ..."; \
    pecl install xdebug; \
    docker-php-ext-enable xdebug; \
  fi

# -----------------------------------------------------------------------------
# Optional Node.js installation
# -----------------------------------------------------------------------------
# Node.js is not required for Laravel itself, but many projects use it for frontend tooling (e.g., Webpack, Vite).
# Install it only in local/dev environments to keep staging/production images lean.
RUN if [ "$INSTALL_NODE" = "true" ]; then \
    echo ">>> Installing Node.js ${NODE_MAJOR}.x ..."; \
    curl -fsSL https://deb.nodesource.com/setup_${NODE_MAJOR}.x | bash - && \
    apt-get install -y --no-install-recommends nodejs && \
    node -v && npm -v && \
    rm -rf /var/lib/apt/lists/* ; \
  fi

# -----------------------------------------------------------------------------
# Install Composer
# -----------------------------------------------------------------------------
# Composer is copied from the official Composer image instead of installing it
# manually with curl.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# -----------------------------------------------------------------------------
# PHP custom configuration
# -----------------------------------------------------------------------------
# This file contains upload limits, memory limit, execution time, etc.
COPY php.ini /usr/local/etc/php/conf.d/90-custom.ini
COPY php-fpm.conf /usr/local/etc/php-fpm.d/zz-pool.conf

# -----------------------------------------------------------------------------
# Working directory
# -----------------------------------------------------------------------------
WORKDIR /var/www/html

# -----------------------------------------------------------------------------
# PHP-FPM listens on port 9000
# -----------------------------------------------------------------------------
EXPOSE 9000

# -----------------------------------------------------------------------------
# Default command
# -----------------------------------------------------------------------------
CMD ["php-fpm"]
