FROM php:8.4-cli

# Build deps + ptrace tools for external samplers used inside the container.
RUN apt-get update && apt-get install -y --no-install-recommends \
      build-essential autoconf pkg-config git \
      libffi-dev libzip-dev libcap2-bin \
      libjudy-dev libjudydebian1 \
      zlib1g-dev \
      curl ca-certificates \
      strace gdb \
    && rm -rf /var/lib/apt/lists/*

# Core extensions reli needs.
RUN docker-php-ext-install ffi pcntl zip \
    && setcap cap_sys_ptrace=eip /usr/local/bin/php

# Composer for the composer-install benchmark.
COPY --from=composer /usr/bin/composer /usr/bin/composer

# --- profilers as PECL/source extensions, all installed but disabled by default ---

# Xdebug 3.x (profile mode, full per-function instrumentation).
RUN pecl install xdebug \
    && echo "; xdebug disabled by default — enable per-run with -dzend_extension=xdebug" \
       > /usr/local/etc/php/conf.d/xdebug.ini.disabled

# longxinH/xhprof — currently maintained xhprof fork.
RUN pecl install xhprof \
    && echo "; xhprof disabled by default" > /usr/local/etc/php/conf.d/xhprof.ini.disabled

# Excimer — Wikimedia sampling profiler.
RUN pecl install excimer \
    && echo "; excimer disabled by default" > /usr/local/etc/php/conf.d/excimer.ini.disabled

# php-memprof — allocation tracking + leak detection. Needs Judy.
RUN pecl install memprof \
    && echo "; memprof disabled by default" > /usr/local/etc/php/conf.d/memprof.ini.disabled

# SPX — build from source (not on PECL).
RUN cd /tmp && git clone --depth 1 https://github.com/NoiseByNorthwest/php-spx.git \
    && cd php-spx \
    && phpize && ./configure && make -j"$(nproc)" && make install \
    && echo "; spx disabled by default" > /usr/local/etc/php/conf.d/spx.ini.disabled \
    && cd / && rm -rf /tmp/php-spx

# Datadog Continuous Profiler — install via official setup script.
# Profiler-only, no tracer; sends nowhere.
RUN curl -sLO "https://github.com/DataDog/dd-trace-php/releases/latest/download/datadog-setup.php" \
    && php datadog-setup.php --enable-profiling --php-bin=all \
    && rm datadog-setup.php \
    && for f in /usr/local/etc/php/conf.d/*ddtrace*.ini /usr/local/etc/php/conf.d/*datadog*.ini; do \
         [ -f "$f" ] && mv "$f" "$f.disabled" || true; \
       done

# phpspy — external sampler in C.
RUN cd /tmp && git clone --depth 1 https://github.com/adsr/phpspy.git \
    && cd phpspy && make && cp phpspy /usr/local/bin/ \
    && cd / && rm -rf /tmp/phpspy

# Sanity: list installed extensions and verify nothing loads automatically.
RUN php -m | grep -E '^(xdebug|xhprof|excimer|memprof|spx|datadog)' || true

WORKDIR /app
COPY . /app

CMD ["bash"]
