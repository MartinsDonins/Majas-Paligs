# syntax=docker/dockerfile:1.7
# Mājas Palīgs — multi-stage image (MVP-SPEC A.2).
# Stages: base → dev → vendor → assets → prod

ARG PHP_IMAGE=serversideup/php:8.4-fpm-nginx-trixie-v5.0.1

############################
# base: PHP 8.4 + extensions
############################
FROM ${PHP_IMAGE} AS base
USER root
# The image build FAILS without libsodium XChaCha20-Poly1305 (PlateVault) or Argon2id (PINs) — AC 20.
RUN install-php-extensions intl gd exif bcmath pdo_pgsql redis pcntl \
    && php -r 'exit(function_exists("sodium_crypto_aead_xchacha20poly1305_ietf_encrypt") && defined("PASSWORD_ARGON2ID") ? 0 : 1);' \
    || (echo "FATAL: libsodium XChaCha20-Poly1305 or Argon2id missing" >&2 && exit 1)
# nginx: privacy-preserving access log format (mp_noargs) + our security headers
COPY docker/nginx/nginx.conf.template /etc/nginx/nginx.conf.template
COPY docker/nginx/security.conf /etc/nginx/server-opts.d/security.conf
ENV HEALTHCHECK_PATH=/up \
    PHP_EXPOSE_PHP=Off
USER www-data

############################
# dev: composer + psql + xdebug (off), host UID remap (WSL2 bind mounts)
############################
FROM base AS dev
USER root
ARG USER_ID=1000
ARG GROUP_ID=1000
COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends postgresql-client && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions xdebug \
    && docker-php-serversideup-set-id www-data ${USER_ID}:${GROUP_ID} \
    && docker-php-serversideup-set-file-permissions --owner ${USER_ID}:${GROUP_ID} --service nginx
ENV XDEBUG_MODE=off
USER www-data

############################
# vendor: production composer install (no dev, authoritative classmap)
############################
FROM base AS vendor
USER root
COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --classmap-authoritative --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts

############################
# assets: Vite build (the Filament theme imports vendor/filament CSS)
############################
FROM node:24-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY --from=vendor /var/www/html/vendor/filament ./vendor/filament
COPY resources ./resources
COPY app ./app
COPY public ./public
COPY vite.config.js VERSION ./
RUN npm run build

############################
# prod: non-root runtime
############################
FROM base AS prod
USER root
WORKDIR /var/www/html
COPY --chown=www-data:www-data --from=vendor /var/www/html /var/www/html
COPY --chown=www-data:www-data --from=assets /app/public/build /var/www/html/public/build
RUN rm -rf DOC BRAIN tests .github node_modules .env .env.* storage/logs/* \
    && mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && php artisan package:discover --no-interaction \
    && php artisan vendor:publish --tag=livewire:assets --force --no-interaction \
    && php artisan filament:assets --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache public
ENV PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=false \
    AUTORUN_LARAVEL_MIGRATION=false \
    SSL_MODE=off \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr
USER www-data
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1
