# syntax=docker/dockerfile:1

# CloudBot Manager runtime image.
#
# Two runtime targets are built from this file:
#   app   - PHP-FPM serving the application, and the queue/scheduler workers
#   nginx - static front end that proxies PHP to the app container

# ---------------------------------------------------------------------------
# Base: PHP with the extensions this application needs.
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS base

# postgresql-dev and icu-dev are needed to build the extensions; the -libs
# packages are what the compiled extensions link against at runtime.
RUN apk add --no-cache \
        icu-libs \
        libpq \
        libzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        linux-headers \
        postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip \
    && pecl install redis-6.1.0 \
    && docker-php-ext-enable redis \
    && apk del --no-network .build-deps \
    && rm -rf /tmp/pear

# Defaults for the placeholders in the ini files below. Override per service.
ENV PHP_MEMORY_LIMIT=256M \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-cloudbot.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# Build: production dependencies plus the application source.
# ---------------------------------------------------------------------------
FROM base AS build

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Dependencies are resolved before the source is copied, so editing application
# code does not invalidate this layer.
COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --no-autoloader \
        --prefer-dist

COPY . .

# The autoloader is generated once the source is present, so the class map is
# complete. Scripts stay off: package discovery needs runtime configuration and
# happens on first boot, where the cache directory is writable.
RUN composer dump-autoload --no-dev --optimize --no-interaction --no-scripts

# ---------------------------------------------------------------------------
# App: PHP-FPM runtime.
# ---------------------------------------------------------------------------
FROM base AS app

COPY --from=build /var/www/html /var/www/html
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Runs unprivileged: a compromise in application code must not be root.
USER www-data

EXPOSE 9000

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Nginx: serves static files, proxies PHP to the app container.
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS nginx

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/html/public

EXPOSE 80
