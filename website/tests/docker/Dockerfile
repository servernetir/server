FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev libicu-dev libonig-dev libpng-dev libsqlite3-dev libxml2-dev libzip-dev \
    && docker-php-ext-install -j2 curl dom gd intl mbstring pdo_mysql pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /workspace/website
