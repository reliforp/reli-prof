#!/usr/bin/env bash
# Targeted rerun for the Datadog cells only, since the original
# raw.csv / long.csv were generated with `zend_extension=` which
# silently failed to load the Datadog profiler (PHP printed
# "doesn't appear to be a valid Zend extension" on stderr but
# continued, so those cells actually measured baseline).
#
# This script writes its CSV output as a drop-in replacement for
# the affected `datadog-prof` rows in `long.csv` / `raw.csv`.
#
# Usage: bash bench/run-datadog-fix.sh > bench/results/datadog-fix.csv
set -euo pipefail

EXTDIR="$(/usr/bin/php8.4 -r 'echo PHP_EXTENSION_DIR;')"
SCRIPTS_DIR="$(cd "$(dirname "$0")" && pwd)/scripts"
RUNS="${RUNS:-10}"

# Note: extension= (not zend_extension=). See bench/README.md.
CFG="-dextension=${EXTDIR}/datadog-profiling.so -ddatadog.profiling.enabled=1 -ddatadog.trace.enabled=0 -ddatadog.appsec.enabled=0 -ddatadog.profiling.log_level=off -ddatadog.instrumentation_telemetry_enabled=0"

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

run_one() {
    local label="$1" benches_var="$2"
    local -n benches="$benches_var"
    for b in "${benches[@]}"; do
        IFS='|' read -r bname script args <<<"$b"
        for ((i=1; i<=RUNS; i++)); do
            out=$(/usr/bin/php8.4 -n $CFG "${SCRIPTS_DIR}/${script}" $args 2>&1 >/dev/null || true)
            secs=$(printf '%s\n' "$out" | grep -oE 'in [0-9]+\.[0-9]+s' | tail -1 | awk '{print $2}' | tr -d 's')
            echo "${label},${bname},${i},${secs:-NA}"
        done
    done
}

echo "config,bench,run,wall_seconds"
run_one "datadog-prof" LONG_BENCHES
run_one "datadog-prof-short" SHORT_BENCHES
