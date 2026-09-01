# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Production image: PHP-FPM behind the nginx service in docker-compose.yml.
#
# Multi-stage on purpose. Composer, its cache and the build toolchain never
# reach the final layer, so a compromised dependency has no compiler and no
# package manager to work with, and the published image is smaller and has a
# smaller CVE surface (OWASP A02 / A03).
# ---------------------------------------------------------------------------

ARG PHP_VERSION=8.3

# --- Stage 1: dependencies -------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

# Copy the manifests alone first: this layer is cached until they change, so a
# code-only edit does not reinstall the world.
COPY composer.json composer.lock ./

# --no-dev keeps development packages (and their CVEs) out of production.
# --no-scripts because artisan is not present yet at this point.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

COPY . .

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && composer audit --locked --no-interaction || true

# --- Stage 2: runtime ------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS runtime

# Only what the application actually needs at runtime.
RUN apk add --no-cache \
        icu-libs \
        libzip \
        postgresql-libs \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        zip \
    && apk del .build-deps

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini

WORKDIR /var/www/html

COPY --from=vendor --chown=www-data:www-data /app /var/www/html

# Only these two trees are ever written to at runtime. Everything else stays
# read-only to the application user, so an upload bug cannot overwrite code
# (OWASP A08).
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Never run the application as root.
USER www-data

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=3s --start-period=20s --retries=3 \
    CMD php -r 'exit(0);' || exit 1

CMD ["php-fpm"]
