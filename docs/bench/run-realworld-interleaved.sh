#!/usr/bin/env bash
# Interleaved-order rerun for external samplers on the real-world
# workloads. Each "round" runs baseline → phpspy → reli back-to-back
# so that any host-state drift between rounds affects all three
# cells equally (rather than e.g. baseline running early and the
# samplers running 10 minutes later under different load).
#
# Usage: run-realworld-interleaved.sh laravel-route|composer-install
set -uo pipefail

EXTDIR="$(/usr/bin/php8.4 -r 'echo PHP_EXTENSION_DIR;')"
SCRIPTS_DIR="$(cd "$(dirname "$0")" && pwd)/scripts"
RUNS="${RUNS:-5}"

LARAVEL_DIR="${LARAVEL_DIR:-/tmp/bench-laravel}"
COMPOSER_DIR="${COMPOSER_DIR:-/tmp/bench-composer-lv}"

PHPSPY=/usr/local/bin/phpspy
RELI_PHP=/usr/bin/php8.5
RELI=/home/user/reli-prof/reli
PHPSPY_RATE_HZ=200
RELI_SLEEP_NS=5000000

JIT_FLAGS="-dzend_extension=${EXTDIR}/opcache.so -dopcache.enable_cli=1 -dopcache.jit=tracing -dopcache.jit_buffer_size=64M"
LARAVEL_EXTS="-dextension=curl -dextension=zip -dextension=mbstring -dextension=iconv -dextension=tokenizer -dextension=phar -dextension=dom -dextension=xml -dextension=simplexml -dextension=xmlwriter -dextension=ctype -dextension=fileinfo -dextension=pdo -dextension=pdo_sqlite -dextension=sqlite3 -dextension=sockets -dextension=intl -dextension=ffi"
COMPOSER_EXTS="-dextension=curl -dextension=zip -dextension=mbstring -dextension=iconv -dextension=tokenizer -dextension=phar -dextension=dom -dextension=xml -dextension=simplexml -dextension=xmlwriter -dextension=ctype -dextension=fileinfo"

extract_secs() {
    grep -oE 'in [0-9]+\.[0-9]+s' "$1" 2>/dev/null \
        | tail -1 | awk '{print $2}' | tr -d 's' || true
}

workload="${1:-}"
echo "config,bench,run,wall_seconds"

case "$workload" in
laravel-route)
    echo "# priming reli cache..." >&2
    "$RELI_PHP" "$RELI" inspector:trace --sleep-ns 10000000 -F rbt \
        -o /tmp/bench-reli-prime.rbt \
        -- /usr/bin/php8.4 -n $JIT_FLAGS -r 'usleep(500000);' >/dev/null 2>&1 || true

    for ((i=1; i<=RUNS; i++)); do
        # ---- baseline-jit ----
        /usr/bin/php8.4 -n $JIT_FLAGS $LARAVEL_EXTS \
            "${SCRIPTS_DIR}/laravel-route.php" 2000 "$LARAVEL_DIR" \
            >/dev/null 2>/tmp/bench-tgt.err
        secs=$(extract_secs /tmp/bench-tgt.err)
        echo "baseline-il,laravel-route-2000,${i},${secs:-NA}"

        # ---- phpspy ----
        /usr/bin/php8.4 -n $JIT_FLAGS $LARAVEL_EXTS \
            "${SCRIPTS_DIR}/laravel-route.php" 2000 "$LARAVEL_DIR" \
            >/dev/null 2>/tmp/bench-tgt.err &
        tpid=$!
        sleep 0.05
        "$PHPSPY" -p "$tpid" -H "$PHPSPY_RATE_HZ" -o /tmp/bench-phpspy.out \
            >/dev/null 2>/dev/null &
        spid=$!
        wait "$tpid" 2>/dev/null || true
        kill "$spid" 2>/dev/null || true
        wait "$spid" 2>/dev/null || true
        secs=$(extract_secs /tmp/bench-tgt.err)
        echo "phpspy-200hz-il,laravel-route-2000,${i},${secs:-NA}"

        # ---- reli ----
        "$RELI_PHP" "$RELI" inspector:trace --sleep-ns "$RELI_SLEEP_NS" \
            -F rbt -o /tmp/bench-reli.rbt \
            -- /usr/bin/php8.4 -n $JIT_FLAGS $LARAVEL_EXTS \
            "${SCRIPTS_DIR}/laravel-route.php" 2000 "$LARAVEL_DIR" \
            >/dev/null 2>/tmp/bench-tgt.err || true
        secs=$(extract_secs /tmp/bench-tgt.err)
        echo "reli-200hz-il,laravel-route-2000,${i},${secs:-NA}"
    done
    ;;

composer-install)
    echo "# priming reli cache..." >&2
    "$RELI_PHP" "$RELI" inspector:trace --sleep-ns 10000000 -F rbt \
        -o /tmp/bench-reli-prime.rbt \
        -- /usr/bin/php8.4 -n $JIT_FLAGS -r 'usleep(500000);' >/dev/null 2>&1 || true

    for ((i=1; i<=RUNS; i++)); do
        # ---- baseline ----
        rm -rf "$COMPOSER_DIR/vendor"
        cd "$COMPOSER_DIR"
        t_start=$(/usr/bin/php8.4 -n -r 'echo microtime(true);')
        COMPOSER_ALLOW_SUPERUSER=1 \
            /usr/bin/php8.4 -n $JIT_FLAGS $COMPOSER_EXTS \
            /usr/local/bin/composer install \
            --no-interaction --prefer-dist --no-dev --no-scripts --quiet \
            >/dev/null 2>/dev/null
        t_end=$(/usr/bin/php8.4 -n -r 'echo microtime(true);')
        cd - >/dev/null
        secs=$(/usr/bin/php8.4 -n -r "echo $t_end - $t_start;")
        echo "baseline-il,composer-install,${i},${secs}"

        # ---- phpspy ----
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
        echo "phpspy-200hz-il,composer-install,${i},${secs}"

        # ---- reli ----
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
            || true
        t_end=$(/usr/bin/php8.4 -n -r 'echo microtime(true);')
        cd - >/dev/null
        secs=$(/usr/bin/php8.4 -n -r "echo $t_end - $t_start;")
        echo "reli-200hz-il,composer-install,${i},${secs}"
    done
    ;;

*)
    echo "usage: $0 laravel-route|composer-install" >&2
    exit 1
    ;;
esac
