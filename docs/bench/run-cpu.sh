#!/usr/bin/env bash
# Profiler-side CPU benchmark.
#
# The other suites all measure target wall time. This one zooms in on
# what each profiler costs the *sampler* process, and how that cost
# scales with target stack depth — the dimension where reli's
# bulk-stack-copy + cached binary analysis is supposed to pay off
# against phpspy's per-pointer process_vm_readv calls.
#
# Method:
#   - External samplers (reli, phpspy): launch via `/usr/bin/time -v`,
#     parse User+System time and max RSS for the sampler process.
#   - In-process samplers (Excimer, Datadog): launch the target via
#     `/usr/bin/time -v` and report the same numbers; the sampler's
#     work is folded into the target's totals. Baseline (no profiler)
#     measured the same way as a reference.
#
# Output: docs/bench/results/cpu.csv
#   columns: profiler, depth, run, target_wall, sampler_user, sampler_sys, sampler_rss_kb
# (For in-process configs, "sampler_*" columns hold the target-process
# numbers; subtract baseline to estimate sampler-side cost.)
set -euo pipefail

EXTDIR="$(/usr/bin/php8.4 -r 'echo PHP_EXTENSION_DIR;')"
SCRIPTS_DIR="$(cd "$(dirname "$0")" && pwd)/scripts"
PROFILERS_DIR="$(cd "$(dirname "$0")" && pwd)/profilers"
RUNS="${RUNS:-5}"

PHPSPY=/usr/local/bin/phpspy
RELI_PHP=/usr/bin/php8.5
RELI=/home/user/reli-prof/reli
PHPSPY_RATE_HZ=1000
RELI_SLEEP_NS=1000000

# Each row: depth iters work — calibrated to ~5 s baseline.
DEPTHS=(
    "10 10000000 50"
    "30 6000000 50"
    "100 2500000 50"
    "200 1300000 50"
)

# Parse `/usr/bin/time -v` output written to stderr.
# Outputs a single line: USER_S SYS_S RSS_KB
parse_time_v() {
    local f="$1"
    awk '
        /User time/      { u=$NF }
        /System time/    { s=$NF }
        /Maximum resident/ { r=$NF }
        END { printf "%s %s %s\n", u, s, r }
    ' "$f"
}

# Get target self-reported wall from its stderr capture.
parse_target_wall() {
    grep -oE 'in [0-9]+\.[0-9]+s' "$1" | tail -1 | awk '{print $2}' | tr -d 's'
}

echo "profiler,depth,run,target_wall,sampler_user,sampler_sys,sampler_rss_kb"

# Prime reli cache so the analysis-of-binary cost is amortised.
echo "# priming reli cache..." >&2
"$RELI_PHP" "$RELI" inspector:trace --sleep-ns 10000000 -f rbt \
    -o /tmp/cpubench-prime.rbt \
    -- /usr/bin/php8.4 -n -r 'usleep(500000);' >/dev/null 2>&1 || true

for spec in "${DEPTHS[@]}"; do
    read -r depth iters work <<<"$spec"

    for ((i=1; i<=RUNS; i++)); do
        # ---- baseline: time the target itself with no profiler ----
        /usr/bin/time -v -o /tmp/cpubench-time.txt \
            /usr/bin/php8.4 -n "${SCRIPTS_DIR}/depth.php" "$depth" "$iters" "$work" \
            2>/tmp/cpubench-tgt.err >/dev/null
        wall=$(parse_target_wall /tmp/cpubench-tgt.err)
        read -r u s r < <(parse_time_v /tmp/cpubench-time.txt)
        echo "baseline,$depth,$i,$wall,$u,$s,$r"

        # ---- in-process: Excimer ----
        /usr/bin/time -v -o /tmp/cpubench-time.txt \
            /usr/bin/php8.4 -n -dextension="${EXTDIR}/excimer.so" \
            -dauto_prepend_file="${PROFILERS_DIR}/excimer_wrap.php" \
            "${SCRIPTS_DIR}/depth.php" "$depth" "$iters" "$work" \
            2>/tmp/cpubench-tgt.err >/dev/null
        wall=$(parse_target_wall /tmp/cpubench-tgt.err)
        read -r u s r < <(parse_time_v /tmp/cpubench-time.txt)
        echo "excimer-1ms,$depth,$i,$wall,$u,$s,$r"

        # ---- in-process: Datadog ----
        /usr/bin/time -v -o /tmp/cpubench-time.txt \
            /usr/bin/php8.4 -n -dextension="${EXTDIR}/datadog-profiling.so" \
            -ddatadog.profiling.enabled=1 -ddatadog.trace.enabled=0 \
            -ddatadog.appsec.enabled=0 -ddatadog.profiling.log_level=off \
            -ddatadog.instrumentation_telemetry_enabled=0 \
            "${SCRIPTS_DIR}/depth.php" "$depth" "$iters" "$work" \
            2>/tmp/cpubench-tgt.err >/dev/null
        wall=$(parse_target_wall /tmp/cpubench-tgt.err)
        read -r u s r < <(parse_time_v /tmp/cpubench-time.txt)
        echo "datadog-prof,$depth,$i,$wall,$u,$s,$r"

        # ---- external: phpspy ----
        BENCH_ATTACH_DELAY_MS=300 \
            /usr/bin/php8.4 -n "${SCRIPTS_DIR}/run_with_delay.php" \
            "${SCRIPTS_DIR}/depth.php" "$depth" "$iters" "$work" \
            2>/tmp/cpubench-tgt.err >/dev/null &
        tpid=$!
        sleep 0.05
        /usr/bin/time -v -o /tmp/cpubench-time.txt \
            "$PHPSPY" -p "$tpid" -H "$PHPSPY_RATE_HZ" -o /tmp/cpubench-phpspy.out \
            >/dev/null 2>/dev/null &
        spid=$!
        wait "$tpid" 2>/dev/null || true
        kill "$spid" 2>/dev/null || true
        wait "$spid" 2>/dev/null || true
        wall=$(parse_target_wall /tmp/cpubench-tgt.err)
        read -r u s r < <(parse_time_v /tmp/cpubench-time.txt)
        echo "phpspy-1khz,$depth,$i,$wall,$u,$s,$r"

        # ---- external: reli (target launched by reli) ----
        # `time -v` wraps reli; reli wraps the target.
        /usr/bin/time -v -o /tmp/cpubench-time.txt \
            "$RELI_PHP" "$RELI" inspector:trace --sleep-ns "$RELI_SLEEP_NS" \
            -f rbt -o /tmp/cpubench-reli.rbt \
            -- /usr/bin/php8.4 -n "${SCRIPTS_DIR}/depth.php" "$depth" "$iters" "$work" \
            2>/tmp/cpubench-tgt.err >/dev/null
        wall=$(parse_target_wall /tmp/cpubench-tgt.err)
        read -r u s r < <(parse_time_v /tmp/cpubench-time.txt)
        # Note: this measurement folds the target's CPU into reli's,
        # because reli forked the target. We back this out below in
        # summarize-cpu.php using the baseline row.
        echo "reli-1khz,$depth,$i,$wall,$u,$s,$r"
    done
done
