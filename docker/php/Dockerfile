FROM php:8.5-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libzip-dev zlib1g-dev libpq-dev \
    && rm -rf /var/lib/apt/lists/*

RUN pecl install opentelemetry && docker-php-ext-enable opentelemetry
RUN docker-php-ext-install zip pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html
