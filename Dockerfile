FROM php:8.0-cli
RUN apt-get update && apt-get install -y \
      libffi-dev \
      libzip-dev \
    && docker-php-ext-install ffi \
    && docker-php-ext-install pcntl \
    && docker-php-ext-install zip
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN composer require sj-i/php-profiler