#!/usr/bin/env bash
# Benchmark orchestrator. Runs each script with each profiler config and
# emits a CSV of (config, bench, run, target_wall_seconds).
#
# Run from repo root: bash bench/run.sh > bench/results/raw.csv
set -euo pipefail

EXTDIR="$(/usr/bin/php8.4 -r 'echo PHP_EXTENSION_DIR;')"
SCRIPTS_DIR="$(cd "$(dirname "$0")" && pwd)/scripts"
PROFILERS_DIR="$(cd "$(dirname "$0")" && pwd)/profilers"
RUNS="${RUNS:-5}"

# Each config is "name|env_vars|extra-php-flags".
# `-n` means: ignore php.ini and conf.d. Then we explicitly load only the
# extension we want and any env vars / config it needs.
CONFIGS=(
    "baseline||"
    "xdebug-profile||-dzend_extension=${EXTDIR}/xdebug.so -dxdebug.mode=profile -dxdebug.start_with_request=yes -dxdebug.output_dir=/tmp -dxdebug.use_compression=0"
    "xhprof||-dextension=${EXTDIR}/xhprof.so -dauto_prepend_file=${PROFILERS_DIR}/xhprof_wrap.php"
    "excimer-1ms||-dextension=${EXTDIR}/excimer.so -dauto_prepend_file=${PROFILERS_DIR}/excimer_wrap.php"
    "spx-instr|SPX_ENABLED=1 SPX_REPORT=full SPX_DATA_DIR=/tmp|-dextension=${EXTDIR}/spx.so -dspx.builtins=1 -dspx.http_enabled=0"
    "spx-sample-1ms|SPX_ENABLED=1 SPX_REPORT=full SPX_DATA_DIR=/tmp SPX_SAMPLING_PERIOD=1000|-dextension=${EXTDIR}/spx.so -dspx.builtins=1 -dspx.http_enabled=0"
    "datadog-prof||-dextension=${EXTDIR}/datadog-profiling.so -ddatadog.profiling.enabled=1 -ddatadog.trace.enabled=0 -ddatadog.appsec.enabled=0 -ddatadog.profiling.log_level=off -ddatadog.instrumentation_telemetry_enabled=0"
)

# Each bench is "name|script.php|args"
BENCHES=(
    "fib-32|fib.php|32"
    "sort-100k-x10|sort.php|100000 10"
    "mandelbrot-200|mandelbrot.php|200 200 200"
    "depth-30|depth.php|30 200000 100"
    "depth-100|depth.php|100 100000 50"
)

echo "config,bench,run,wall_seconds"

for cfg in "${CONFIGS[@]}"; do
    IFS='|' read -r name envs flags <<<"$cfg"
    for b in "${BENCHES[@]}"; do
        IFS='|' read -r bname script args <<<"$b"
        for ((i=1; i<=RUNS; i++)); do
            # Capture the script's own self-reported wall time from STDERR.
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
