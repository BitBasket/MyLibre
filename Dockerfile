FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        gnupg \
        unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY bin bin
COPY src src
COPY public public
COPY docker/entrypoint.sh /usr/local/bin/mylibre-entrypoint

RUN composer dump-autoload --optimize --no-dev \
    && chmod +x /usr/local/bin/mylibre-entrypoint \
    && mkdir -p /app/data/keys /app/public

ENV APP_ENV=production

HEALTHCHECK --interval=60s --timeout=5s --start-period=90s --retries=3 \
    CMD php -r 'exit(is_readable("/app/public/current.json.asc") || is_readable("/app/public/status.json.asc") ? 0 : 1);'

ENTRYPOINT ["mylibre-entrypoint"]
