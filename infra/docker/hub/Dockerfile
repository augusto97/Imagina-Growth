# Imagen del hub (Laravel 13 + FrankenPHP, PHP 8.4). hub/CLAUDE.md §18.
# Construir desde la raíz del monorepo:
#   docker build -f infra/docker/hub/Dockerfile -t imagina-growth/hub .

ARG PHP_VERSION=8.4

FROM dunglas/frankenphp:1-php${PHP_VERSION}-bookworm AS base
RUN install-php-extensions pdo_mysql redis intl zip pcntl opcache sockets \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY infra/docker/hub/php.ini $PHP_INI_DIR/conf.d/zz-igr.ini
WORKDIR /app

# Dependencias de PHP (sin las de desarrollo).
FROM composer:2 AS vendor
WORKDIR /app
COPY hub/composer.json hub/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY hub/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# SPA (Vite).
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY hub/package.json hub/package-lock.json ./
RUN npm ci
COPY hub/ ./
RUN npm run build

# Desarrollo: código montado como volumen y dependencias de desarrollo.
FROM base AS dev
RUN install-php-extensions xdebug @composer && echo "xdebug.mode=off" > $PHP_INI_DIR/conf.d/zz-xdebug.ini
ENV APP_ENV=local

# Producción.
FROM base AS prod
ENV APP_ENV=production SERVER_NAME=:8080
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
RUN rm -rf /app/tests /app/node_modules \
 && chown -R www-data:www-data /app/storage /app/bootstrap/cache
USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s CMD curl -fsS http://127.0.0.1:8080/up || exit 1
