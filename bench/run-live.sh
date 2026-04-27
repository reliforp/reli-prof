#!/usr/bin/env bash
# Spawn N target PHP processes, run live bench in {process,thread} modes,
# clean up afterwards. Usage:
#   bench/run-live.sh N
set -euo pipefail
N=${1:-8}
PHP=${PHP:-/opt/php-8.5/bin/php}

cd "$(dirname "$0")/.."

pids=()
for i in $(seq 1 "$N"); do
    "$PHP" bench/target.php >/dev/null 2>&1 &
    pids+=($!)
done
trap 'kill "${pids[@]}" 2>/dev/null || true; wait 2>/dev/null || true' EXIT

# Give the targets a moment to settle
sleep 0.5

TARGETS=$(IFS=,; echo "${pids[*]}")

echo "process N=$N"
RELI_BENCH_TARGETS="$TARGETS" "$PHP" bench/bench-runner-live.php "$N" 2>&1 | grep -E '^(mode=|  worker|TOTAL)'

echo
echo "thread N=$N"
RELI_BENCH_TARGETS="$TARGETS" "$PHP" -d zend.max_allowed_stack_size=-1 bench/bench-launcher-live.php "$N" 2>&1 | grep -E '^(mode=|  worker|TOTAL)'
