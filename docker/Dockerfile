ARG PHP_VERSION=8.3

FROM php:${PHP_VERSION}-cli-bookworm

ARG NODE_VERSION=22

COPY --from=mlocati/php-extension-installer:2.7 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql gd intl zip exif bcmath pcntl redis opcache

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends rsync git unzip curl ca-certificates procps \
    && curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && npm install -g pnpm@11 \
    && rm -rf /var/lib/apt/lists/*

COPY sync.sh /usr/local/bin/site-check-sync
RUN chmod +x /usr/local/bin/site-check-sync

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    npm_config_store_dir=/pnpm-store \
    PHP_CLI_SERVER_WORKERS=4

WORKDIR /app
