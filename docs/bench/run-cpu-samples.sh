#!/usr/bin/env bash
# Spot-check: capture sample count alongside CPU at each depth, so
# we can tell whether the previous CPU bench's "reli wins at deep
# stacks" was real or an artefact of reli silently dropping samples.
#
# 1 run per depth; we just want the order-of-magnitude.
#
# Output: docs/bench/results/cpu-samples.csv
set -euo pipefail

EXTDIR="$(/usr/bin/php8.4 -r 'echo PHP_EXTENSION_DIR;')"
SCRIPTS_DIR="$(cd "$(dirname "$0")" && pwd)/scripts"
PHPSPY=/usr/local/bin/phpspy
RELI_PHP=/usr/bin/php8.5
RELI=/home/user/reli-prof/reli
PHPSPY_RATE_HZ=1000
RELI_SLEEP_NS=1000000

DEPTHS=(
    "10 10000000 50"
    "30 6000000 50"
    "100 2500000 50"
    "200 1300000 50"
)

# Count rbt samples by parsing through reli's analyzer.
count_rbt_samples() {
    "$RELI_PHP" "$RELI" rbt:analyze < "$1" 2>&1 \
        | grep -oE 'trace: [0-9]+ samples' | awk '{print $2}' || echo "?"
}

# Count phpspy stack records (separated by blank lines).
count_phpspy_stacks() {
    awk 'BEGIN{c=0; in_stack=0} /^$/{if(in_stack){c++; in_stack=0}} /^[0-9]/{in_stack=1} END{if(in_stack) c++; print c}' "$1"
}

echo "# priming reli cache..." >&2
"$RELI_PHP" "$RELI" inspector:trace --sleep-ns 10000000 -f rbt \
    -o /tmp/cpus-prime.rbt -- /usr/bin/php8.4 -n -r 'usleep(500000);' >/dev/null 2>&1 || true

echo "profiler,depth,target_wall,samples,expected_at_1khz"

for spec in "${DEPTHS[@]}"; do
    read -r depth iters work <<<"$spec"

    # phpspy (no pause-process — default, attach-only)
    BENCH_ATTACH_DELAY_MS=300 \
        /usr/bin/php8.4 -n "${SCRIPTS_DIR}/run_with_delay.php" \
        "${SCRIPTS_DIR}/depth.php" "$depth" "$iters" "$work" \
        2>/tmp/cpus-tgt.err >/dev/null &
    tpid=$!
    sleep 0.05
    "$PHPSPY" -p "$tpid" -H "$PHPSPY_RATE_HZ" -o /tmp/cpus-phpspy.out \
        >/dev/null 2>/dev/null &
    spid=$!
    wait "$tpid" 2>/dev/null || true
    kill "$spid" 2>/dev/null || true
    wait "$spid" 2>/dev/null || true
    wall=$(grep -oE 'in [0-9]+\.[0-9]+s' /tmp/cpus-tgt.err | tail -1 | awk '{print $2}' | tr -d 's')
    samples=$(count_phpspy_stacks /tmp/cpus-phpspy.out)
    expected=$(awk "BEGIN { printf \"%.0f\", $wall * 1000 }")
    echo "phpspy-1khz,$depth,$wall,$samples,$expected"

    # phpspy with -S (--pause-process, marked "unsafe for production!")
    BENCH_ATTACH_DELAY_MS=300 \
        /usr/bin/php8.4 -n "${SCRIPTS_DIR}/run_with_delay.php" \
        "${SCRIPTS_DIR}/depth.php" "$depth" "$iters" "$work" \
        2>/tmp/cpus-tgt.err >/dev/null &
    tpid=$!
    sleep 0.05
    "$PHPSPY" -p "$tpid" -H "$PHPSPY_RATE_HZ" -S -o /tmp/cpus-phpspy-s.out \
        >/dev/null 2>/dev/null &
    spid=$!
    wait "$tpid" 2>/dev/null || true
    kill "$spid" 2>/dev/null || true
    wait "$spid" 2>/dev/null || true
    wall=$(grep -oE 'in [0-9]+\.[0-9]+s' /tmp/cpus-tgt.err | tail -1 | awk '{print $2}' | tr -d 's')
    samples=$(count_phpspy_stacks /tmp/cpus-phpspy-s.out)
    expected=$(awk "BEGIN { printf \"%.0f\", $wall * 1000 }")
    echo "phpspy-1khz-S,$depth,$wall,$samples,$expected"

    # phpspy with bigger buffer (-b 65536, vs default 4096 = PIPE_BUF)
    # to see whether sample drop at deep stacks is partly write-blocking.
    BENCH_ATTACH_DELAY_MS=300 \
        /usr/bin/php8.4 -n "${SCRIPTS_DIR}/run_with_delay.php" \
        "${SCRIPTS_DIR}/depth.php" "$depth" "$iters" "$work" \
        2>/tmp/cpus-tgt.err >/dev/null &
    tpid=$!
    sleep 0.05
    "$PHPSPY" -p "$tpid" -H "$PHPSPY_RATE_HZ" -b 65536 -o /tmp/cpus-phpspy-b.out \
        >/dev/null 2>/dev/null &
    spid=$!
    wait "$tpid" 2>/dev/null || true
    kill "$spid" 2>/dev/null || true
    wait "$spid" 2>/dev/null || true
    wall=$(grep -oE 'in [0-9]+\.[0-9]+s' /tmp/cpus-tgt.err | tail -1 | awk '{print $2}' | tr -d 's')
    samples=$(count_phpspy_stacks /tmp/cpus-phpspy-b.out)
    expected=$(awk "BEGIN { printf \"%.0f\", $wall * 1000 }")
    echo "phpspy-1khz-b65k,$depth,$wall,$samples,$expected"

    # phpspy with -S AND big buffer
    BENCH_ATTACH_DELAY_MS=300 \
        /usr/bin/php8.4 -n "${SCRIPTS_DIR}/run_with_delay.php" \
        "${SCRIPTS_DIR}/depth.php" "$depth" "$iters" "$work" \
        2>/tmp/cpus-tgt.err >/dev/null &
    tpid=$!
    sleep 0.05
    "$PHPSPY" -p "$tpid" -H "$PHPSPY_RATE_HZ" -S -b 65536 -o /tmp/cpus-phpspy-sb.out \
        >/dev/null 2>/dev/null &
    spid=$!
    wait "$tpid" 2>/dev/null || true
    kill "$spid" 2>/dev/null || true
    wait "$spid" 2>/dev/null || true
    wall=$(grep -oE 'in [0-9]+\.[0-9]+s' /tmp/cpus-tgt.err | tail -1 | awk '{print $2}' | tr -d 's')
    samples=$(count_phpspy_stacks /tmp/cpus-phpspy-sb.out)
    expected=$(awk "BEGIN { printf \"%.0f\", $wall * 1000 }")
    echo "phpspy-1khz-Sb65k,$depth,$wall,$samples,$expected"

    # reli (no stop-process — default)
    "$RELI_PHP" "$RELI" inspector:trace --sleep-ns "$RELI_SLEEP_NS" \
        -f rbt -o /tmp/cpus-reli.rbt \
        -- /usr/bin/php8.4 -n "${SCRIPTS_DIR}/depth.php" "$depth" "$iters" "$work" \
        2>/tmp/cpus-tgt.err >/dev/null
    wall=$(grep -oE 'in [0-9]+\.[0-9]+s' /tmp/cpus-tgt.err | tail -1 | awk '{print $2}' | tr -d 's')
    samples=$(count_rbt_samples /tmp/cpus-reli.rbt)
    expected=$(awk "BEGIN { printf \"%.0f\", $wall * 1000 }")
    echo "reli-1khz,$depth,$wall,$samples,$expected"

    # reli with -S (stop target during each read — should reduce drops)
    "$RELI_PHP" "$RELI" inspector:trace --sleep-ns "$RELI_SLEEP_NS" -S \
        -f rbt -o /tmp/cpus-reli-s.rbt \
        -- /usr/bin/php8.4 -n "${SCRIPTS_DIR}/depth.php" "$depth" "$iters" "$work" \
        2>/tmp/cpus-tgt.err >/dev/null
    wall=$(grep -oE 'in [0-9]+\.[0-9]+s' /tmp/cpus-tgt.err | tail -1 | awk '{print $2}' | tr -d 's')
    samples=$(count_rbt_samples /tmp/cpus-reli-s.rbt)
    expected=$(awk "BEGIN { printf \"%.0f\", $wall * 1000 }")
    echo "reli-1khz-S,$depth,$wall,$samples,$expected"
done
