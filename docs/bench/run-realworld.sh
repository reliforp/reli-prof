#!/usr/bin/env bash
# Real-world-ish benchmarks. Two workloads:
#
# - laravel-route: bootstrap a Laravel skeleton and dispatch 2000
#   `/` requests via the HTTP kernel. Real framework code path:
#   routing, middleware, view rendering. Much more diverse function
#   names than the synthetic depth.php (so reli's cache won't be
#   flattered as much).
#
# - composer-install: a single `composer install` of a Laravel-core
#   composer.json from a primed cache. Wipes vendor/ before each
#   run. Real PHP-CLI workload with significant file I/O between
#   PHP-execution bursts.
#
# Both run with opcache + tracing JIT enabled (the production
# default). SPX is excluded because it produces wrong values under
# tracing JIT (see bench/README.md).
#
# Output: bench/results/realworld.csv
set -euo pipefail

EXTDIR="$(/usr/bin/php8.4 -r 'echo PHP_EXTENSION_DIR;')"
SCRIPTS_DIR="$(cd "$(dirname "$0")" && pwd)/scripts"
PROFILERS_DIR="$(cd "$(dirname "$0")" && pwd)/profilers"

RUNS_LIGHT="${RUNS_LIGHT:-5}"
RUNS_HEAVY="${RUNS_HEAVY:-2}"

LARAVEL_DIR="${LARAVEL_DIR:-/tmp/bench-laravel}"
COMPOSER_DIR="${COMPOSER_DIR:-/tmp/bench-composer-lv}"

PHPSPY=/usr/local/bin/phpspy
RELI_PHP=/usr/bin/php8.5
RELI=/home/user/reli-prof/reli
PHPSPY_RATE_HZ=200
RELI_SLEEP_NS=5000000   # 200 Hz

# JIT + the extensions Laravel and Composer want.
JIT_FLAGS="-dzend_extension=${EXTDIR}/opcache.so -dopcache.enable_cli=1 -dopcache.jit=tracing -dopcache.jit_buffer_size=64M"
# Note: session, pcntl, hash, json, filter are built into the apt
# php8.4 binary. With -n we don't add them as -dextension=, otherwise
# PHP prints "Unable to load dynamic library" to *stdout* (display_errors
# defaults to stdout under -n) which corrupts our CSV stream.
LARAVEL_EXTS="-dextension=curl -dextension=zip -dextension=mbstring -dextension=iconv -dextension=tokenizer -dextension=phar -dextension=dom -dextension=xml -dextension=simplexml -dextension=xmlwriter -dextension=ctype -dextension=fileinfo -dextension=pdo -dextension=pdo_sqlite -dextension=sqlite3 -dextension=sockets -dextension=intl -dextension=ffi"
COMPOSER_EXTS="-dextension=curl -dextension=zip -dextension=mbstring -dextension=iconv -dextension=tokenizer -dextension=phar -dextension=dom -dextension=xml -dextension=simplexml -dextension=xmlwriter -dextension=ctype -dextension=fileinfo"

# Light configs (sampling): tested on both workloads.
LIGHT_CONFIGS=(
    "baseline||"
    "excimer||-dextension=${EXTDIR}/excimer.so -dauto_prepend_file=${PROFILERS_DIR}/excimer_wrap.php"
    "datadog-prof||-dextension=${EXTDIR}/datadog-profiling.so -ddatadog.profiling.enabled=1 -ddatadog.trace.enabled=0 -ddatadog.appsec.enabled=0 -ddatadog.profiling.log_level=off -ddatadog.instrumentation_telemetry_enabled=0"
)

# Heavy configs (full instrumentation): Laravel route only (composer
# install would take too long with these). xhprof on Laravel route at
# 2000 iters at 1071× from synthetic numbers would be ~hours — keep
# heavy iters lower for these via separate variant.
HEAVY_CONFIGS=(
    "xhprof||-dextension=${EXTDIR}/xhprof.so -dauto_prepend_file=${PROFILERS_DIR}/xhprof_wrap.php"
    "xdebug-profile||-dzend_extension=${EXTDIR}/xdebug.so -dxdebug.mode=profile -dxdebug.start_with_request=yes -dxdebug.output_dir=/tmp -dxdebug.use_compression=0"
)

run_laravel_inproc() {
    local cfg="$1" runs="$2" iters="$3"
    IFS='|' read -r name envs flags <<<"$cfg"
    for ((i=1; i<=runs; i++)); do
        if [[ -n "$envs" ]]; then
            out=$(env $envs /usr/bin/php8.4 -n $JIT_FLAGS $LARAVEL_EXTS $flags \
                "${SCRIPTS_DIR}/laravel-route.php" "$iters" "$LARAVEL_DIR" \
                2>&1 >/dev/null || true)
        else
            out=$(/usr/bin/php8.4 -n $JIT_FLAGS $LARAVEL_EXTS $flags \
                "${SCRIPTS_DIR}/laravel-route.php" "$iters" "$LARAVEL_DIR" \
                2>&1 >/dev/null || true)
        fi
        secs=$(printf '%s\n' "$out" | grep -oE 'in [0-9]+\.[0-9]+s' | tail -1 | awk '{print $2}' | tr -d 's')
        echo "${name},laravel-route-${iters},${i},${secs:-NA}"
    done
}

run_composer_inproc() {
    local cfg="$1" runs="$2"
    IFS='|' read -r name envs flags <<<"$cfg"
    for ((i=1; i<=runs; i++)); do
        rm -rf "$COMPOSER_DIR/vendor"
        cd "$COMPOSER_DIR"
        if [[ -n "$envs" ]]; then
            t_start=$(/usr/bin/php8.4 -n -r 'echo microtime(true);')
            env $envs COMPOSER_ALLOW_SUPERUSER=1 \
                /usr/bin/php8.4 -n $JIT_FLAGS $COMPOSER_EXTS $flags \
                /usr/local/bin/composer install \
                --no-interaction --prefer-dist --no-dev --no-scripts --quiet \
                2>/dev/null || true
            t_end=$(/usr/bin/php8.4 -n -r 'echo microtime(true);')
        else
            t_start=$(/usr/bin/php8.4 -n -r 'echo microtime(true);')
            COMPOSER_ALLOW_SUPERUSER=1 \
                /usr/bin/php8.4 -n $JIT_FLAGS $COMPOSER_EXTS $flags \
                /usr/local/bin/composer install \
                --no-interaction --prefer-dist --no-dev --no-scripts --quiet \
                2>/dev/null || true
            t_end=$(/usr/bin/php8.4 -n -r 'echo microtime(true);')
        fi
        cd - >/dev/null
        secs=$(/usr/bin/php8.4 -n -r "echo $t_end - $t_start;")
        echo "${name},composer-install,${i},${secs}"
    done
}

# Prime reli cache.
echo "# priming reli cache..." >&2
"$RELI_PHP" "$RELI" inspector:trace --sleep-ns 10000000 -F rbt -o /tmp/bench-reli-prime.rbt \
    -- /usr/bin/php8.4 -n $JIT_FLAGS -r 'usleep(500000);' >/dev/null 2>&1 || true

echo "config,bench,run,wall_seconds"

# In-process light tools on Laravel route at 2000 iters.
for cfg in "${LIGHT_CONFIGS[@]}"; do
    run_laravel_inproc "$cfg" "$RUNS_LIGHT" 2000
done

# In-process heavy tools on Laravel route at very low iters (50) —
# xhprof at expected 50-200× would otherwise blow past timeout.
for cfg in "${HEAVY_CONFIGS[@]}"; do
    run_laravel_inproc "$cfg" "$RUNS_HEAVY" 50
done

# baseline at 50 iters too, so heavy multipliers have a denominator.
run_laravel_inproc "baseline-50||" "$RUNS_LIGHT" 50

# In-process light tools on composer install.
for cfg in "${LIGHT_CONFIGS[@]}"; do
    run_composer_inproc "$cfg" "$RUNS_LIGHT"
done

# External samplers on Laravel route at 2000 iters.
for ((i=1; i<=RUNS_LIGHT; i++)); do
    # phpspy
    /usr/bin/php8.4 -n $JIT_FLAGS $LARAVEL_EXTS \
        "${SCRIPTS_DIR}/laravel-route.php" 2000 "$LARAVEL_DIR" >/dev/null 2>/tmp/bench-tgt.err &
    tpid=$!
    sleep 0.05
    "$PHPSPY" -p "$tpid" -H "$PHPSPY_RATE_HZ" -o /tmp/bench-phpspy.out \
        >/dev/null 2>/dev/null &
    spid=$!
    wait "$tpid" 2>/dev/null || true
    kill "$spid" 2>/dev/null || true
    wait "$spid" 2>/dev/null || true
    secs=$(grep -oE 'in [0-9]+\.[0-9]+s' /tmp/bench-tgt.err | tail -1 | awk '{print $2}' | tr -d 's')
    echo "phpspy-200hz,laravel-route-2000,${i},${secs:-NA}"

    # reli
    "$RELI_PHP" "$RELI" inspector:trace --sleep-ns "$RELI_SLEEP_NS" \
        -F rbt -o /tmp/bench-reli.rbt \
        -- /usr/bin/php8.4 -n $JIT_FLAGS $LARAVEL_EXTS \
        "${SCRIPTS_DIR}/laravel-route.php" 2000 "$LARAVEL_DIR" >/dev/null 2>/tmp/bench-tgt.err \
        || true   # reli can hit a transient FFI\ParserException on fast-moving targets; tolerate
    secs=$(grep -oE 'in [0-9]+\.[0-9]+s' /tmp/bench-tgt.err | tail -1 | awk '{print $2}' | tr -d 's')
    echo "reli-200hz,laravel-route-2000,${i},${secs:-NA}"
done

# External samplers on composer install.
for ((i=1; i<=RUNS_LIGHT; i++)); do
    # phpspy
    rm -rf "$COMPOSER_DIR/vendor"
    cd "$COMPOSER_DIR"
    t_start=$(/usr/bin/php8.4 -n -r 'echo microtime(true);')
    COMPOSER_ALLOW_SUPERUSER=1 \
        /usr/bin/php8.4 -n $JIT_FLAGS $COMPOSER_EXTS \
        /usr/local/bin/composer install \
        --no-interaction --prefer-dist --no-dev --no-scripts --quiet \
        >/dev/null 2>/dev/null &
    tpid=$!
    sleep 0.05
    "$PHPSPY" -p "$tpid" -H "$PHPSPY_RATE_HZ" -o /tmp/bench-phpspy.out \
        >/dev/null 2>/dev/null &
    spid=$!
    wait "$tpid" 2>/dev/null || true
    kill "$spid" 2>/dev/null || true
    wait "$spid" 2>/dev/null || true
    t_end=$(/usr/bin/php8.4 -n -r 'echo microtime(true);')
    cd - >/dev/null
    secs=$(/usr/bin/php8.4 -n -r "echo $t_end - $t_start;")
    echo "phpspy-200hz,composer-install,${i},${secs}"

    # reli — launch composer via reli's `--`
    rm -rf "$COMPOSER_DIR/vendor"
    cd "$COMPOSER_DIR"
    t_start=$(/usr/bin/php8.4 -n -r 'echo microtime(true);')
    COMPOSER_ALLOW_SUPERUSER=1 \
        "$RELI_PHP" "$RELI" inspector:trace --sleep-ns "$RELI_SLEEP_NS" \
        -F rbt -o /tmp/bench-reli.rbt \
        -- /usr/bin/php8.4 -n $JIT_FLAGS $COMPOSER_EXTS \
        /usr/local/bin/composer install \
        --no-interaction --prefer-dist --no-dev --no-scripts --quiet \
        >/dev/null 2>/dev/null \
        || true   # see laravel-route comment above
    t_end=$(/usr/bin/php8.4 -n -r 'echo microtime(true);')
    cd - >/dev/null
    secs=$(/usr/bin/php8.4 -n -r "echo $t_end - $t_start;")
    echo "reli-200hz,composer-install,${i},${secs}"
done
