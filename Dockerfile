FROM php:8.5-cli

# libcap2-bin provides setcap; the actual `setcap` invocation is deferred to
# the final RUN below — see the comment there for why.
RUN apt-get update && apt-get install -y \
      libffi-dev \
      libzip-dev \
      libcap2-bin \
    && docker-php-ext-install ffi \
    && docker-php-ext-install pcntl \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# Compile out bare assert() calls in the production image. The reli codebase
# uses assert() for two distinct purposes: type narrowing for Psalm (the
# downstream code is strictly typed and would crash with TypeError on
# violation, so the assert is purely informational), and runtime contracts
# whose failure would silently corrupt output (those are written as
# regular runtime checks — either Webmozart\Assert::* or an explicit
# `if (...) throw` — and are unaffected by this setting). With
# zend.assertions=-1 the bare assert() calls are stripped at compile time;
# the dev image (Dockerfile-dev) leaves the PHP default of 1 in place so
# the type-narrowing asserts still fire during phpunit.
RUN echo 'zend.assertions=-1' > /usr/local/etc/php/conf.d/zz-assertions.ini

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

# `.git` is in .dockerignore and composer.json has no `version` field, so
# without help Composer can't infer the root package version and falls back
# to `1.0.0+no-version-set`. That makes `reli --version` lie. The build arg
# lets the publish workflow inject the right value (tag for stable builds,
# `<branch>-dev` alias for floating release-branch builds,
# `dev-manual-<sha>` for one-off dispatch builds). For unset / empty arg
# (local `docker build .`) we don't export the env var at all, so Composer
# keeps its usual fallback chain (composer.json → COMPOSER_ROOT_VERSION →
# VCS → `1.0.0+no-version-set`) without an empty string masking the VCS
# step.
ARG COMPOSER_ROOT_VERSION
RUN if [ -n "${COMPOSER_ROOT_VERSION}" ]; then \
        COMPOSER_ROOT_VERSION="${COMPOSER_ROOT_VERSION}" composer install --no-dev; \
    else \
        composer install --no-dev; \
    fi

# Build a setcap'd shadow copy of the PHP binary at /opt/reli/php-ptrace/php.
# Wrappers that actually need ptrace (docker:print-wrapper --profile=full)
# override the container entrypoint to invoke this binary; that copy carries
# cap_sys_ptrace=eip, so ptrace works under `--user <non-root>` even though
# Linux strips effective caps at exec for non-zero uid.
#
# /usr/local/bin/php itself is left untouched on purpose. A binary with `=eip`
# file capabilities returns EPERM from execve() in any environment whose
# bounding set lacks the requested cap (default Docker, BuildKit, k8s without
# explicit cap-add, etc.). Putting the cap on /usr/local/bin/php would force
# every `docker run reliforp/reli-prof <cmd>` invocation to require
# `--cap-add=SYS_PTRACE`, even for offline-only commands like rbt:analyze
# / inspector:memory:report / converter:* that never touch a live process.
# A separate ptrace-enabled binary keeps the default ENTRYPOINT path usable
# without extra caps and confines the regression to the wrapper-emitted
# command line, which already passes --cap-add=SYS_PTRACE.
#
# Why the basename stays `php` and the variant goes in the directory: when
# reli attaches to a process started from this binary, it filters
# /proc/<pid>/maps lines through TargetPhpSettings::PHP_REGEX_DEFAULT, which
# is anchored on `(php|php-fpm)` (with optional version) or `libphp*.so`.
# A binary literally named `php-ptrace` does not match — dogfooding (reli
# attached to wrapper-launched reli) then fails inside fingerprint creation
# with "regex matched nothing". Putting the file at `/opt/reli/php-ptrace/php`
# keeps the basename `php` (default regex hits) while still labelling the
# variant in the directory name, so `ps` / `--entrypoint` output reads as
# `/opt/reli/php-ptrace/php` and the role is obvious.
#
# Why `cp` and not `ln`: file capabilities are stored as xattrs on the
# inode, so a hardlink would silently apply the cap to /usr/local/bin/php
# as well, defeating the split. A real copy gives us a separate inode.
RUN mkdir -p /opt/reli/php-ptrace \
    && cp -p /usr/local/bin/php /opt/reli/php-ptrace/php \
    && setcap cap_sys_ptrace=eip /opt/reli/php-ptrace/php

# Absolute path on purpose. The wrapper emitted by docker:print-wrapper
# overrides WORKDIR to the host's $PWD (so bind-mounted input/output paths
# resolve naturally), and PHP CLI does not search PATH for script
# arguments — a relative `reli` would fail to open whenever the host CWD
# is not the reli source tree.
ENTRYPOINT ["php", "/app/reli"]
