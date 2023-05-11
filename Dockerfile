FROM php:8.1-cli

RUN apt-get update && apt-get install -y \
      libffi-dev \
      libzip-dev \
    && docker-php-ext-install ffi \
    && docker-php-ext-install pcntl \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --no-dev

ENTRYPOINT ["php", "reli"]
