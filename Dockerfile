FROM php:8.3-cli
RUN apt-get update && apt-get install -y git unzip libonig-dev libsqlite3-dev libxml2-dev libcurl4-openssl-dev && docker-php-ext-install pdo_mysql pdo_sqlite mbstring pcntl curl dom xml xmlwriter && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache && composer install --no-interaction --prefer-dist
