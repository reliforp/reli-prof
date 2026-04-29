#!/usr/bin/env bash
# Sample rate sweep, to put 1 ms (1000 Hz) results in context. Most
# production phpspy / reli usage isn't at 1 kHz; the previous CPU
# bench is effectively a stress test.
#
# We sweep over { 49, 99, 200, 500, 1000 } Hz at three depths
# {30, 100, 200}, and report wall time, sample efficiency, and
# samples-per-target-second.
#
# Output: docs/bench/results/cpu-rates.csv
set -euo pipefail

EXTDIR="$(/usr/bin/php8.4 -r 'echo PHP_EXTENSION_DIR;')"
SCRIPTS_DIR="$(cd "$(dirname "$0")" && pwd)/scripts"
PHPSPY=/usr/local/bin/phpspy
RELI_PHP=/usr/bin/php8.5
RELI=/home/user/reli-prof/reli

DEPTHS=(
    "30 6000000 50"
    "100 2500000 50"
    "200 1300000 50"
)

RATES_HZ=(49 99 200 500 1000)

count_rbt_samples() {
    "$RELI_PHP" "$RELI" rbt:analyze < "$1" 2>&1 \
        | grep -oE 'trace: [0-9]+ samples' | awk '{print $2}' || echo "0"
}
count_phpspy_stacks() {
    awk 'BEGIN{c=0; in_stack=0} /^$/{if(in_stack){c++; in_stack=0}} /^[0-9]/{in_stack=1} END{if(in_stack) c++; print c}' "$1"
}

echo "# priming reli cache..." >&2
"$RELI_PHP" "$RELI" inspector:trace --sleep-ns 10000000 -f rbt \
    -o /tmp/cpur-prime.rbt -- /usr/bin/php8.4 -n -r 'usleep(500000);' >/dev/null 2>&1 || true

echo "profiler,rate_hz,depth,target_wall,samples,expected"

for spec in "${DEPTHS[@]}"; do
    read -r depth iters work <<<"$spec"
    for hz in "${RATES_HZ[@]}"; do
        sleep_ns=$((1000000000 / hz))

        # phpspy default buffer
        BENCH_ATTACH_DELAY_MS=300 \
            /usr/bin/php8.4 -n "${SCRIPTS_DIR}/run_with_delay.php" \
            "${SCRIPTS_DIR}/depth.php" "$depth" "$iters" "$work" \
            2>/tmp/cpur-tgt.err >/dev/null &
        tpid=$!
        sleep 0.05
        "$PHPSPY" -p "$tpid" -H "$hz" -o /tmp/cpur-phpspy.out \
            >/dev/null 2>/dev/null &
        spid=$!
        wait "$tpid" 2>/dev/null || true
        kill "$spid" 2>/dev/null || true
        wait "$spid" 2>/dev/null || true
        wall=$(grep -oE 'in [0-9]+\.[0-9]+s' /tmp/cpur-tgt.err | tail -1 | awk '{print $2}' | tr -d 's')
        samples=$(count_phpspy_stacks /tmp/cpur-phpspy.out)
        expected=$(awk "BEGIN { printf \"%.0f\", $wall * $hz }")
        echo "phpspy,$hz,$depth,$wall,$samples,$expected"

        # reli
        "$RELI_PHP" "$RELI" inspector:trace --sleep-ns "$sleep_ns" \
            -f rbt -o /tmp/cpur-reli.rbt \
            -- /usr/bin/php8.4 -n "${SCRIPTS_DIR}/depth.php" "$depth" "$iters" "$work" \
            2>/tmp/cpur-tgt.err >/dev/null
        wall=$(grep -oE 'in [0-9]+\.[0-9]+s' /tmp/cpur-tgt.err | tail -1 | awk '{print $2}' | tr -d 's')
        samples=$(count_rbt_samples /tmp/cpur-reli.rbt)
        expected=$(awk "BEGIN { printf \"%.0f\", $wall * $hz }")
        echo "reli,$hz,$depth,$wall,$samples,$expected"
    done
done
