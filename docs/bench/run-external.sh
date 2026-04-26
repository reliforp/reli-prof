#!/usr/bin/env bash
# External-sampler benchmarks: phpspy and reli, both attaching to the
# target benchmark process. We measure the *target's* self-reported wall
# time with the sampler attached vs not (= baseline already in raw.csv).
#
# Two attach strategies:
#   - phpspy: launch target in background with a startup delay wrapper,
#     attach phpspy by PID.
#   - reli: have reli launch the target via `-- cmd args`. This way
#     reli's startup happens before the timed code starts and reli is
#     ready to sample. We pre-prime reli's cache before measuring.
#
# Usage: bash bench/run-external.sh > bench/results/external.csv
set -euo pipefail

SCRIPTS_DIR="$(cd "$(dirname "$0")" && pwd)/scripts"
RUNS="${RUNS:-3}"
PHPSPY=/usr/local/bin/phpspy
RELI_PHP=/usr/bin/php8.5
RELI=/home/user/reli-prof/reli

# 1ms period (1000 Hz) for both samplers.
PHPSPY_RATE_HZ=1000
RELI_SLEEP_NS=1000000

# Each bench is "name|script.php|args"
BENCHES=(
    "fib-32|fib.php|32"
    "sort-100k-x10|sort.php|100000 10"
    "mandelbrot-200|mandelbrot.php|200 200 200"
    "depth-30|depth.php|30 200000 100"
    "depth-100|depth.php|100 100000 50"
)

# Prime reli's cache so subsequent runs don't pay startup analysis cost.
echo "# priming reli cache..." >&2
"$RELI_PHP" "$RELI" inspector:trace --sleep-ns 10000000 -F rbt -o /tmp/bench-reli-prime.rbt \
    -- /usr/bin/php8.4 -n -r 'usleep(500000);' >/dev/null 2>&1 || true
echo "# done. running benchmarks." >&2

echo "config,bench,run,wall_seconds"

for b in "${BENCHES[@]}"; do
    IFS='|' read -r bname script args <<<"$b"
    for ((i=1; i<=RUNS; i++)); do
        # ---- phpspy: attach by PID after a startup delay ----
        BENCH_ATTACH_DELAY_MS=300 \
            /usr/bin/php8.4 -n "${SCRIPTS_DIR}/run_with_delay.php" \
            "${SCRIPTS_DIR}/${script}" $args 2>/tmp/bench-tgt.err &
        tpid=$!
        sleep 0.05
        "$PHPSPY" -p "$tpid" -H "$PHPSPY_RATE_HZ" -o /tmp/bench-phpspy.out >/dev/null 2>&1 &
        spid=$!
        wait "$tpid" 2>/dev/null || true
        kill "$spid" 2>/dev/null || true
        wait "$spid" 2>/dev/null || true
        secs=$(grep -oE 'in [0-9]+\.[0-9]+s' /tmp/bench-tgt.err | tail -1 | awk '{print $2}' | tr -d 's')
        echo "phpspy-1khz,${bname},${i},${secs:-NA}"

        # ---- reli: launch target itself ----
        "$RELI_PHP" "$RELI" inspector:trace --sleep-ns "$RELI_SLEEP_NS" -F rbt \
            -o /tmp/bench-reli.rbt \
            -- /usr/bin/php8.4 -n "${SCRIPTS_DIR}/${script}" $args 2>/tmp/bench-tgt.err
        secs=$(grep -oE 'in [0-9]+\.[0-9]+s' /tmp/bench-tgt.err | tail -1 | awk '{print $2}' | tr -d 's')
        echo "reli-1khz,${bname},${i},${secs:-NA}"
    done
done
