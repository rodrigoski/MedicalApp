# =============================================================================
# ClinicApp - API principal (Laravel 12 / PHP 8.3)
# -----------------------------------------------------------------------------
# Construccion en capas:
#   deps      -> dependencias del sistema y extensiones PHP (cacheable)
#   app       -> codigo de la aplicacion + vendor
# =============================================================================

# ------------------------------------------------------------------ deps ----
FROM php:8.3-cli-alpine AS deps

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1

RUN set -eux; \
    apk add --no-cache git unzip icu-libs libpq libzip; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev linux-headers; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        opcache \
        pcntl \
        zip; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/*

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Se copia primero solo el manifiesto para aprovechar la cache de capas de Docker:
# mientras composer.json no cambie, la instalacion de vendor no se reejecuta.
COPY composer.json composer.lock* ./

RUN set -eux; \
    if [ -f composer.lock ]; then \
        composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress; \
    else \
        echo ">>> composer.lock no encontrado: se resuelve con 'composer update'."; \
        echo ">>> Commitea el composer.lock generado para builds reproducibles."; \
        composer update --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress; \
    fi

# ------------------------------------------------------------------- app ----
FROM php:8.3-cli-alpine AS app

ARG APP_ENV=local
ENV APP_ENV=${APP_ENV} \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

RUN set -eux; \
    apk add --no-cache git unzip icu-libs libpq libzip su-exec; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev linux-headers; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        opcache \
        pcntl \
        zip; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/*

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Configuracion endurecida de PHP (ver docker/php/php.ini-development)
COPY docker/php/php.ini-development /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

COPY --from=deps /var/www/html/vendor ./vendor
COPY . .

RUN set -eux; \
    composer dump-autoload --optimize --no-dev --classmap-authoritative; \
    mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/public \
        bootstrap/cache; \
    chmod -R 775 storage bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache; \
    chmod +x docker/entrypoint.sh

# El entrypoint arranca como root para poder crear secretos y permisos y luego
# baja privilegios a www-data con su-exec (defensa: la app nunca corre como root).
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/clinicapp-entrypoint

USER root

EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=5 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:8000/api/v1/health') ? 0 : 1);"

ENTRYPOINT ["clinicapp-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
