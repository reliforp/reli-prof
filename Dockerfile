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

# Compile out bare assert() calls in the production image. The reli codebase
# uses assert() for two distinct purposes: type narrowing for Psalm (the
# downstream code is strictly typed and would crash with TypeError on
# violation, so the assert is purely informational), and runtime contracts
# whose failure would silently corrupt output (those are explicitly written
# as Webmozart\Assert::* and are unaffected by this setting). With
# zend.assertions=-1 the bare assert() calls are stripped at compile time;
# the dev image (Dockerfile-dev) leaves the PHP default of 1 in place so
# the type-narrowing asserts still fire during phpunit.
RUN echo 'zend.assertions=-1' > /usr/local/etc/php/conf.d/zz-assertions.ini

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --no-dev

ENTRYPOINT ["php", "reli"]
