#!/usr/bin/env bash
# Long-target sampling-tool benchmarks. Designed to give stable
# medians on tools where short targets are dominated by environmental
# noise. Includes baseline + sampling/light-instrumentation configs
# only — full-instrumentation configs would take 10+ minutes per cell
# at this scale.
#
# Usage: bash docs/bench/run-long.sh > docs/bench/results/long.csv
set -euo pipefail

EXTDIR="$(/usr/bin/php8.4 -r 'echo PHP_EXTENSION_DIR;')"
SCRIPTS_DIR="$(cd "$(dirname "$0")" && pwd)/scripts"
PROFILERS_DIR="$(cd "$(dirname "$0")" && pwd)/profilers"
RUNS="${RUNS:-10}"

PHPSPY=/usr/local/bin/phpspy
RELI_PHP=/usr/bin/php8.5
RELI=/home/user/reli-prof/reli
PHPSPY_RATE_HZ=1000
RELI_SLEEP_NS=1000000

# In-process configs: same as run.sh but only the cheap ones.
# "name|env_vars|extra-php-flags"
INPROC_CONFIGS=(
    "baseline||"
    "excimer-1ms||-dextension=${EXTDIR}/excimer.so -dauto_prepend_file=${PROFILERS_DIR}/excimer_wrap.php"
    "spx-sample-1ms|SPX_ENABLED=1 SPX_REPORT=full SPX_DATA_DIR=/tmp SPX_SAMPLING_PERIOD=1000|-dextension=${EXTDIR}/spx.so -dspx.builtins=1 -dspx.http_enabled=0"
    "datadog-prof||-dextension=${EXTDIR}/datadog-profiling.so -ddatadog.profiling.enabled=1 -ddatadog.trace.enabled=0 -ddatadog.appsec.enabled=0 -ddatadog.profiling.log_level=off -ddatadog.instrumentation_telemetry_enabled=0"
)

# Each long bench: ~1-3s baseline.
# "name|script.php|args"
BENCHES=(
    "fib-37|fib.php|37"
    "sort-100k-x100|sort.php|100000 100"
    "mandelbrot-500|mandelbrot.php|500 500 300"
    "depth-30-2M|depth.php|30 2000000 100"
    "depth-100-1M|depth.php|100 1000000 50"
)

echo "config,bench,run,wall_seconds"

# In-process configs.
for cfg in "${INPROC_CONFIGS[@]}"; do
    IFS='|' read -r name envs flags <<<"$cfg"
    for b in "${BENCHES[@]}"; do
        IFS='|' read -r bname script args <<<"$b"
        for ((i=1; i<=RUNS; i++)); do
            if [[ -n "$envs" ]]; then
                out=$(env $envs /usr/bin/php8.4 -n $flags "${SCRIPTS_DIR}/${script}" $args 2>&1 >/dev/null || true)
            else
                out=$(/usr/bin/php8.4 -n $flags "${SCRIPTS_DIR}/${script}" $args 2>&1 >/dev/null || true)
            fi
            secs=$(printf '%s\n' "$out" | grep -oE 'in [0-9]+\.[0-9]+s' | tail -1 | awk '{print $2}' | tr -d 's')
            echo "${name},${bname},${i},${secs:-NA}"
        done
    done
done

# External samplers.
echo "# priming reli cache..." >&2
"$RELI_PHP" "$RELI" inspector:trace --sleep-ns 10000000 -F rbt -o /tmp/bench-reli-prime.rbt \
    -- /usr/bin/php8.4 -n -r 'usleep(500000);' >/dev/null 2>&1 || true

for b in "${BENCHES[@]}"; do
    IFS='|' read -r bname script args <<<"$b"
    for ((i=1; i<=RUNS; i++)); do
        # phpspy: attach by PID after a startup delay
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

        # reli: launch target itself
        "$RELI_PHP" "$RELI" inspector:trace --sleep-ns "$RELI_SLEEP_NS" -F rbt \
            -o /tmp/bench-reli.rbt \
            -- /usr/bin/php8.4 -n "${SCRIPTS_DIR}/${script}" $args 2>/tmp/bench-tgt.err
        secs=$(grep -oE 'in [0-9]+\.[0-9]+s' /tmp/bench-tgt.err | tail -1 | awk '{print $2}' | tr -d 's')
        echo "reli-1khz,${bname},${i},${secs:-NA}"
    done
done
