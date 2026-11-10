# syntax=docker/dockerfile:1.7
#
# Laravel API image. One image, three entrypoints (CLAUDE.md §9):
#   api        -> php-fpm                     (HTTP, behind the Nginx gateway)
#   worker     -> php artisan horizon         (queue consumers)
#   scheduler  -> php artisan schedule:work   (exactly one per deployment)
#
# Build from the REPOSITORY ROOT:
#   docker build -f apps/api/Dockerfile -t sfc-api:local .
#
# Pin digests in deployed environments; a tag alone is not reproducible.
ARG PHP_VERSION=8.4.27
ARG COMPOSER_VERSION=2.11.1

# ---------------------------------------------------------------------------
# base - PHP runtime with the extensions Laravel + PostgreSQL + Redis need
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS base

# Runtime libraries only; build toolchains are removed in the same layer.
RUN set -eux; \
    apk add --no-cache \
        fcgi \
        icu-libs \
        libpq \
        libzip \
    ; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        linux-headers \
        postgresql-dev \
    ; \
    docker-php-ext-configure intl; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    ; \
    # phpredis is used for cache, queues and Horizon.
    pecl install redis-6.1.0; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    rm -rf /tmp/pear

WORKDIR /var/www/html

# Read by php.ini through ${...} expansion. 0 is the production behaviour; the
# dev compose override sets it to 1.
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

COPY apps/api/docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY apps/api/docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf

# ---------------------------------------------------------------------------
# toolchain - base plus Composer. Also the image used to generate the lockfile
# and to run Pint / PHPStan / Pest, so local tooling needs no PHP on the host.
# ---------------------------------------------------------------------------
FROM base AS toolchain
ARG COMPOSER_VERSION
RUN set -eux; \
    apk add --no-cache curl git unzip; \
    curl -sS https://getcomposer.org/installer \
      | php -- --install-dir=/usr/local/bin --filename=composer --version="${COMPOSER_VERSION}"; \
    composer --version
ENV COMPOSER_ALLOW_SUPERUSER=1

# ---------------------------------------------------------------------------
# vendor - production Composer dependencies, resolved from the lockfile alone
# ---------------------------------------------------------------------------
FROM toolchain AS vendor

# Only the manifests first, so the dependency layer caches independently of
# application code.
COPY apps/api/composer.json apps/api/composer.lock ./
RUN --mount=type=cache,id=composer,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --no-autoloader

# ---------------------------------------------------------------------------
# dev - full dependency tree, used by CI and by local tooling
# ---------------------------------------------------------------------------
FROM vendor AS dev
RUN --mount=type=cache,id=composer,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader
COPY apps/api/ ./
# Laravel reads .env through dotenv, which emits a notice when the file is
# absent. Containers are configured by environment variables, so the file is
# created empty: dotenv is satisfied, no secrets are baked in, and real
# environment variables still take precedence over anything in the file.
#
# php-fpm serves as www-data, so the two trees Laravel must write have to be
# owned by it here as well as in the runtime stage. Without this the first log
# line fails, and a failing logger turns every error into an opaque 500. The
# chmod belongs here too: without it the image keeps the build context
# permissions, and a host that strips the owner write bit leaves storage/
# read-only, which breaks logging and /docs/api.
RUN set -eux; \
    touch .env; \
    mkdir -p storage/framework/cache/data storage/framework/sessions \
             storage/framework/views storage/logs storage/phpstan bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache; \
    chmod -R u=rwX,g=rX,o= storage bootstrap/cache; \
    composer dump-autoload --optimize
# Xdebug is deliberately absent: a large attack surface and a big slowdown.
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# runtime - production image, non-root
# ---------------------------------------------------------------------------
FROM base AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false

COPY --from=vendor /var/www/html/vendor ./vendor
COPY apps/api/ ./

# Autoload map is built here, after the application code is present. Composer
# comes from the toolchain stage and is removed again: a production image has
# no business carrying a dependency installer.
COPY --from=toolchain /usr/local/bin/composer /usr/local/bin/composer
RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize --no-dev --classmap-authoritative \
 && rm -f /usr/local/bin/composer

# Laravel must be able to write only these two trees.
RUN set -eux; \
    mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache; \
    # Empty .env so dotenv does not warn; configuration comes from the
    # environment, which takes precedence over the file in any case.
    touch .env; \
    chown -R www-data:www-data storage bootstrap/cache; \
    chmod -R u=rwX,g=rX,o= storage bootstrap/cache

# config:cache and route:cache are NOT run here. They would freeze the build
# environment's variables into the image; the entrypoint caches at boot, once
# the real environment is present.
COPY apps/api/docker/entrypoint.sh /usr/local/bin/entrypoint
COPY apps/api/docker/healthcheck.sh /usr/local/bin/healthcheck
RUN chmod +x /usr/local/bin/entrypoint /usr/local/bin/healthcheck

USER www-data
EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD ["/usr/local/bin/healthcheck"]

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["php-fpm"]
