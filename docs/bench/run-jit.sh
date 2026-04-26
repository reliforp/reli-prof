#!/usr/bin/env bash
# JIT-on variant of the bench. Kept small enough to finish in
# reasonable time:
#   - light tools (sampling, SPX-sample) run on the long targets;
#   - full instrumentation tools (xhprof, Xdebug, SPX default) run
#     on the short targets, since each cell would take 5-10 minutes
#     each at long-target sizes;
#   - reli is also tested with its OWN PHP JIT enabled (PHP 8.5 has
#     opcache built into the binary; no .so to load).
#
# Output: bench/results/jit.csv
set -euo pipefail

EXTDIR="$(/usr/bin/php8.4 -r 'echo PHP_EXTENSION_DIR;')"
SCRIPTS_DIR="$(cd "$(dirname "$0")" && pwd)/scripts"
PROFILERS_DIR="$(cd "$(dirname "$0")" && pwd)/profilers"
RUNS_LIGHT="${RUNS_LIGHT:-10}"
RUNS_HEAVY="${RUNS_HEAVY:-3}"

PHPSPY=/usr/local/bin/phpspy
RELI_PHP=/usr/bin/php8.5
RELI=/home/user/reli-prof/reli
PHPSPY_RATE_HZ=1000
RELI_SLEEP_NS=1000000

JIT_FLAGS="-dzend_extension=${EXTDIR}/opcache.so -dopcache.enable_cli=1 -dopcache.jit=tracing -dopcache.jit_buffer_size=64M"
# PHP 8.5 has opcache built in; just enable.
RELI_JIT_FLAGS="-dopcache.enable_cli=1 -dopcache.jit=tracing -dopcache.jit_buffer_size=64M"

LIGHT_CONFIGS=(
    "baseline-jit||"
    "excimer-1ms-jit||-dextension=${EXTDIR}/excimer.so -dauto_prepend_file=${PROFILERS_DIR}/excimer_wrap.php"
    "datadog-prof-jit||-dextension=${EXTDIR}/datadog-profiling.so -ddatadog.profiling.enabled=1 -ddatadog.trace.enabled=0 -ddatadog.appsec.enabled=0 -ddatadog.profiling.log_level=off -ddatadog.instrumentation_telemetry_enabled=0"
    # NOTE: SPX is excluded from this JIT-on suite. It produces visibly
    # wrong fib values (e.g. fib(25) returns 1407295 instead of 75025)
    # and exits in ~1ms when PHP 8.4 tracing JIT is enabled, in either
    # default or SPX_SAMPLING_PERIOD mode. See bench/README.md.
)

HEAVY_CONFIGS=(
    "xhprof-jit||-dextension=${EXTDIR}/xhprof.so -dauto_prepend_file=${PROFILERS_DIR}/xhprof_wrap.php"
    "xdebug-profile-jit||-dzend_extension=${EXTDIR}/xdebug.so -dxdebug.mode=profile -dxdebug.start_with_request=yes -dxdebug.output_dir=/tmp -dxdebug.use_compression=0"
    # SPX excluded — see note above.
)

LONG_BENCHES=(
    "fib-37|fib.php|37"
    "sort-100k-x100|sort.php|100000 100"
    "mandelbrot-500|mandelbrot.php|500 500 300"
    "depth-30-2M|depth.php|30 2000000 100"
    "depth-100-1M|depth.php|100 1000000 50"
)

SHORT_BENCHES=(
    "fib-32|fib.php|32"
    "sort-100k-x10|sort.php|100000 10"
    "mandelbrot-200|mandelbrot.php|200 200 200"
    "depth-30|depth.php|30 200000 100"
    "depth-100|depth.php|100 100000 50"
)

run_inproc() {
    local cfg="$1" benches_var="$2" runs="$3"
    IFS='|' read -r name envs flags <<<"$cfg"
    local -n benches="$benches_var"
    for b in "${benches[@]}"; do
        IFS='|' read -r bname script args <<<"$b"
        for ((i=1; i<=runs; i++)); do
            if [[ -n "$envs" ]]; then
                out=$(env $envs /usr/bin/php8.4 -n $JIT_FLAGS $flags "${SCRIPTS_DIR}/${script}" $args 2>&1 >/dev/null || true)
            else
                out=$(/usr/bin/php8.4 -n $JIT_FLAGS $flags "${SCRIPTS_DIR}/${script}" $args 2>&1 >/dev/null || true)
            fi
            secs=$(printf '%s\n' "$out" | grep -oE 'in [0-9]+\.[0-9]+s' | tail -1 | awk '{print $2}' | tr -d 's')
            echo "${name},${bname},${i},${secs:-NA}"
        done
    done
}

echo "config,bench,run,wall_seconds"

# In-process light tools at long targets.
for cfg in "${LIGHT_CONFIGS[@]}"; do
    run_inproc "$cfg" LONG_BENCHES "$RUNS_LIGHT"
done

# Also baseline at short targets so the heavy multipliers have a denominator.
run_inproc "baseline-jit-short||" SHORT_BENCHES "$RUNS_LIGHT"

# In-process heavy tools at short targets.
for cfg in "${HEAVY_CONFIGS[@]}"; do
    run_inproc "$cfg" SHORT_BENCHES "$RUNS_HEAVY"
done

# External samplers at long targets — target JIT-on, reli's PHP without JIT.
echo "# priming reli cache (no own-JIT)..." >&2
"$RELI_PHP" "$RELI" inspector:trace --sleep-ns 10000000 -F rbt -o /tmp/bench-reli-prime.rbt \
    -- /usr/bin/php8.4 -n $JIT_FLAGS -r 'usleep(500000);' >/dev/null 2>&1 || true

for b in "${LONG_BENCHES[@]}"; do
    IFS='|' read -r bname script args <<<"$b"
    for ((i=1; i<=RUNS_LIGHT; i++)); do
        # phpspy
        BENCH_ATTACH_DELAY_MS=300 \
            /usr/bin/php8.4 -n $JIT_FLAGS "${SCRIPTS_DIR}/run_with_delay.php" \
            "${SCRIPTS_DIR}/${script}" $args 2>/tmp/bench-tgt.err &
        tpid=$!
        sleep 0.05
        "$PHPSPY" -p "$tpid" -H "$PHPSPY_RATE_HZ" -o /tmp/bench-phpspy.out >/dev/null 2>&1 &
        spid=$!
        wait "$tpid" 2>/dev/null || true
        kill "$spid" 2>/dev/null || true
        wait "$spid" 2>/dev/null || true
        secs=$(grep -oE 'in [0-9]+\.[0-9]+s' /tmp/bench-tgt.err | tail -1 | awk '{print $2}' | tr -d 's')
        echo "phpspy-1khz-jit,${bname},${i},${secs:-NA}"

        # reli — target JIT on; reli's own PHP runs default (no JIT for reli's process).
        "$RELI_PHP" "$RELI" inspector:trace --sleep-ns "$RELI_SLEEP_NS" -F rbt \
            -o /tmp/bench-reli.rbt \
            -- /usr/bin/php8.4 -n $JIT_FLAGS "${SCRIPTS_DIR}/${script}" $args 2>/tmp/bench-tgt.err
        secs=$(grep -oE 'in [0-9]+\.[0-9]+s' /tmp/bench-tgt.err | tail -1 | awk '{print $2}' | tr -d 's')
        echo "reli-1khz-jit,${bname},${i},${secs:-NA}"

        # reli with reli's *own* PHP JIT also on. PHP 8.5 has opcache built in.
        "$RELI_PHP" $RELI_JIT_FLAGS "$RELI" inspector:trace --sleep-ns "$RELI_SLEEP_NS" \
            -F rbt -o /tmp/bench-reli.rbt \
            -- /usr/bin/php8.4 -n $JIT_FLAGS "${SCRIPTS_DIR}/${script}" $args 2>/tmp/bench-tgt.err
        secs=$(grep -oE 'in [0-9]+\.[0-9]+s' /tmp/bench-tgt.err | tail -1 | awk '{print $2}' | tr -d 's')
        echo "reli-1khz-relijit,${bname},${i},${secs:-NA}"
    done
done
