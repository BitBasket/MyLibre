FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        gnupg \
        unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock /app/
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY bin bin
COPY src src
COPY public public
COPY docker/entrypoint.sh /usr/local/bin/mylibre-entrypoint

RUN composer dump-autoload --optimize --no-dev \
    && php bin/link-pwa.php \
    && chmod +x /usr/local/bin/mylibre-entrypoint \
    && mkdir -p /app/data/keys /app/public

ENV APP_ENV=production

# Readiness = the public API is listening, so /api/keys can take the user's
# public key. The old check looked for published snapshots, which only exist
# *after* enrollment — so a fresh install was never healthy and Caddy could
# start (and serve the dashboard) before enrollment was possible.
HEALTHCHECK --interval=15s --timeout=5s --start-period=60s --retries=5 \
    CMD php -r 'exit(@fsockopen("127.0.0.1", (int) (getenv("PORT") ?: 8765)) ? 0 : 1);'

ENTRYPOINT ["mylibre-entrypoint"]
