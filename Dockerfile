FROM php:8.5-cli

# libcap2-bin provides setcap; the file-capability set on /usr/local/bin/php
# lets reli use CAP_SYS_PTRACE when the container is run as a non-root
# `--user` (the normal mode under docker:print-wrapper). Without it, the
# capability is in the bounding set only and memory reads fail with EPERM.
RUN apt-get update && apt-get install -y \
      libffi-dev \
      libzip-dev \
      libcap2-bin \
    && docker-php-ext-install ffi \
    && docker-php-ext-install pcntl \
    && docker-php-ext-install zip \
    && setcap cap_sys_ptrace=eip /usr/local/bin/php \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --no-dev

ENTRYPOINT ["php", "reli"]
