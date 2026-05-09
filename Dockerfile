# syntax=docker/dockerfile:1.7
#
# authn.sh — multi-stage image for the Laravel app + worker + Horizon.
#
# Stages:
#
#   php-deps     composer install --no-dev (production vendor/ only)
#   php-deps-dev composer install (full deps; vendor/ for the dev stage)
#   node-deps    npm ci (cached node_modules)
#   node-build   inherits node-deps; runs vite build for prod assets
#   runtime      PHP-FPM + nginx + supervisord + horizon worker; this is
#                the default `production` target the docker-compose baseline
#                points at and the one CI publishes.
#   dev          extends runtime; pulls vendor + node_modules from the
#                cached deps stages. The override compose selects this.
#
# Image goal: < 350 MB compressed.

# ---------------------------------------------------------------------------
# Stage: php-deps
# ---------------------------------------------------------------------------
FROM composer:2 AS php-deps

WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1

# Copy only the manifest first so changes to source don't bust the
# composer-install cache.
COPY composer.json composer.lock ./
# The composer:2 image PHP only ships a minimal extension set; pin
# --ignore-platform-reqs for everything our composer.json declares but
# the runtime stage installs (pdo_pgsql, pgsql, redis, intl, bcmath,
# sodium, pcntl). The runtime stage re-validates the platform when it
# runs `php artisan optimize`.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --ignore-platform-reqs

# Copy the rest and finish the autoloader so post-install scripts have
# everything they need.
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# ---------------------------------------------------------------------------
# Stage: node-deps
# ---------------------------------------------------------------------------
FROM node:20-alpine AS node-deps

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# ---------------------------------------------------------------------------
# Stage: node-build
# ---------------------------------------------------------------------------
FROM node-deps AS node-build

COPY resources/ resources/
COPY vite.config.js tsconfig.json ./
RUN npm run build

# ---------------------------------------------------------------------------
# Stage: php-deps-dev
# ---------------------------------------------------------------------------
FROM composer:2 AS php-deps-dev

WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1

COPY composer.json composer.lock ./
RUN composer install \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --ignore-platform-reqs

# ---------------------------------------------------------------------------
# Stage: runtime (production target)
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS runtime

ARG TARGETARCH
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

RUN apk add --no-cache \
        nginx supervisor tini \
        nodejs npm \
        postgresql16-client \
        redis \
        bash curl tzdata icu-data-full \
        libpng libwebp libjpeg-turbo freetype \
        oniguruma libzip libsodium \
        libpq

# Build deps for native PHP extensions; pruned at the end of the layer.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        autoconf g++ make linux-headers \
        postgresql16-dev \
        icu-dev libpng-dev libwebp-dev libjpeg-turbo-dev freetype-dev \
        oniguruma-dev libzip-dev libsodium-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql pgsql intl gd bcmath opcache sodium pcntl zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/* /root/.composer

# MJML CLI — the email renderer (AU-14) shells out to it on template save.
RUN npm install -g mjml@4.15.3 && npm cache clean --force

# OPcache + PHP-FPM tuning baked in. Operator overrides via volume mount.
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-authn.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    # nginx runs as www-data inside the container; its state dirs ship
    # owned by the alpine `nginx` user (UID 100), so re-chown them to
    # www-data (UID 82) and create the bootstrap log path the binary
    # opens before reading nginx.conf.
    && mkdir -p /var/lib/nginx/tmp /var/lib/nginx/logs /var/log/nginx \
    && chown -R www-data:www-data /var/lib/nginx /var/log/nginx \
    && ln -sf /dev/stderr /var/lib/nginx/logs/error.log \
    && ln -sf /dev/stdout /var/lib/nginx/logs/access.log

WORKDIR /var/www/html
COPY --from=php-deps /app/vendor ./vendor
COPY --from=node-build /app/public/build ./public/build
COPY . .

# Storage / cache directories must be writable by the runtime user.
RUN mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && find storage bootstrap/cache -type d -exec chmod 0775 {} \;

# Caches (config / route / view / events) are built by the entrypoint
# AFTER the real .env is loaded. Baking them at image-build time would pin
# whatever defaults composer-stage saw — wrong DB host, wrong APP_URL, etc.
RUN rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php \
        bootstrap/cache/services.php bootstrap/cache/packages.php \
        bootstrap/cache/events.php

EXPOSE 8080
USER www-data

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/docker-entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisord.conf"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl --silent --fail http://127.0.0.1:8080/up || exit 1

# ---------------------------------------------------------------------------
# Stage: dev (override target)
# ---------------------------------------------------------------------------
FROM runtime AS dev

USER root
ENV APP_ENV=local \
    APP_DEBUG=true

RUN apk add --no-cache git

COPY --from=php-deps /usr/bin/composer /usr/local/bin/composer
COPY --from=php-deps-dev /app/vendor ./vendor
COPY --from=node-deps /app/node_modules ./node_modules

RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data vendor node_modules

USER www-data
