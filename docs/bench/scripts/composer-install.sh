#!/usr/bin/env bash
# Real-world bench: a single `composer install` from a primed
# cache, vendor/ wiped first. Single PHP-CLI invocation so
# external samplers can attach by PID. The composer cache must be
# primed in advance (run once before benchmarking).
#
# Usage: composer-install.sh [project-dir]
#
# Optionally honours BENCH_ATTACH_DELAY_MS like the other scripts
# so external samplers have time to attach before the timed work.
set -euo pipefail

proj="${1:-/tmp/bench-composer}"
if [[ ! -d "$proj" ]]; then
    echo "no composer project at $proj" >&2
    exit 1
fi

# Wipe vendor/ before timing so each run is comparable.
rm -rf "$proj/vendor"

# Optional attach-delay sleep (in ms) — used by external-sampler runs.
delay_ms="${BENCH_ATTACH_DELAY_MS:-0}"
[ "$delay_ms" -gt 0 ] && /usr/bin/php8.4 -n -r "usleep(${delay_ms}*1000);"

cd "$proj"
t_start=$(/usr/bin/php8.4 -n -r 'echo microtime(true);')
COMPOSER_ALLOW_SUPERUSER=1 /usr/bin/php8.5 /usr/local/bin/composer install \
    --no-interaction --prefer-dist --no-dev --quiet 2>/dev/null
t_end=$(/usr/bin/php8.4 -n -r 'echo microtime(true);')
elapsed=$(/usr/bin/php8.4 -n -r "echo $t_end - $t_start;")
printf "composer-install in %.3fs\n" "$elapsed" >&2
