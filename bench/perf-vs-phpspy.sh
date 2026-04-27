#!/usr/bin/env bash
# Profiler overhead: reli rbt+JIT+thread vs phpspy daemon
# Outputs to /tmp/perf-final.txt for the harness to scrape.
set -u
N=${N:-8}
DURATION=${DURATION:-5}
SLEEP_NS=10000000          # 10ms / 100 Hz
PHP=/opt/php-8.5/bin/php
PHPSPY=/tmp/phpspy-master/phpspy
RELI=/home/user/reli-prof
RESULT=/tmp/perf-final.txt
> $RESULT

cleanup() {
    pkill -f 'bench/target.php' 2>/dev/null || true
    pkill -f 'phpspy' 2>/dev/null || true
    pkill -f 'inspector:daemon' 2>/dev/null || true
    sleep 0.3
}
trap cleanup EXIT

spawn_targets() {
    TARGETS=()
    for i in $(seq 1 $N); do
        $PHP $RELI/bench/target.php >/dev/null 2>&1 &
        TARGETS+=($!)
    done
    sleep 0.5
}

# Read process tree's utime+stime from procfs. Returns total CPU microseconds.
cpu_us() {
    local pid=$1
    local total=0
    for p in $pid $(pgrep -P $pid 2>/dev/null) \
                  $(pgrep -P $pid 2>/dev/null | xargs -r -I{} pgrep -P {} 2>/dev/null); do
        # /proc/$p/stat fields: pid (comm) state ppid pgrp ... utime(14) stime(15)
        local stat=$(cat /proc/$p/stat 2>/dev/null) || continue
        local rest=${stat##*) }
        set -- $rest
        local utime=${12:-0}
        local stime=${13:-0}
        total=$((total + (utime + stime) * 10000))   # 100 Hz clock ticks → us
    done
    echo $total
}

# Read peak RSS+PSS of process tree from procfs
mem_kb() {
    local pid=$1
    local rss=0 pss=0
    for p in $pid $(pgrep -P $pid 2>/dev/null) \
                  $(pgrep -P $pid 2>/dev/null | xargs -r -I{} pgrep -P {} 2>/dev/null); do
        local r=$(awk '/^VmHWM:/ {print $2}' /proc/$p/status 2>/dev/null)
        local s=$(awk '/^Pss:/ {sum+=$2} END {print sum+0}' /proc/$p/smaps_rollup 2>/dev/null)
        rss=$((rss + ${r:-0}))
        pss=$((pss + ${s:-0}))
    done
    echo "$rss $pss"
}

run_profiler() {
    local name=$1; shift
    spawn_targets

    setsid "$@" >/tmp/${name}.out 2>/tmp/${name}.err &
    local pid=$!
    # setsid makes pid the pgrp leader; we can signal the entire group with -$pid

    # warmup window so the steady-state measure isn't dominated by attach
    sleep 1
    local cpu0=$(cpu_us $pid)
    local t0=$(date +%s%N)

    sleep $((DURATION - 1))

    # snapshot mem just before INT (after that, /proc entries vanish quickly)
    read peak_rss peak_pss < <(mem_kb $pid)
    local t1=$(date +%s%N)
    local cpu1=$(cpu_us $pid)

    # send INT to entire process group so PHP signal handlers in workers
    # (incl. embed's pcntl_signal handler that flushes the rbt) actually fire
    kill -INT -$pid 2>/dev/null
    # graceful flush window
    for i in 1 2 3 4 5 6 7 8; do
        kill -0 $pid 2>/dev/null || break
        sleep 0.5
    done
    kill -KILL -$pid 2>/dev/null
    wait $pid 2>/dev/null

    local wall_us=$(( (t1 - t0) / 1000 ))
    local cpu_us_delta=$(( cpu1 - cpu0 ))
    local cpu_pct
    if [ $wall_us -gt 0 ]; then
        cpu_pct=$(awk "BEGIN { printf \"%.1f\", $cpu_us_delta * 100.0 / $wall_us }")
    else
        cpu_pct="?"
    fi
    echo "$name wall_ms=$((wall_us/1000)) cpu_us=$cpu_us_delta cpu_pct=${cpu_pct}% peak_rss_kb=$peak_rss peak_pss_kb=$peak_pss" | tee -a $RESULT
}

count_phpspy_samples() {
    # phpspy default text format: each trace separated by blank line, frames
    # are non-blank lines starting with "0 " (depth 0) etc. Easier: count
    # trace dividers (blank lines).
    awk 'BEGIN{n=1} /^$/{n++} END{print n}' /tmp/phpspy.out 2>/dev/null
}

count_reli_samples() {
    local total=0
    for f in /tmp/rbt-out/*.rbt; do
        if [ -f "$f" ]; then
            local n=$($PHP $RELI/reli rbt:analyze --top=1 --no-line --crop=20 < "$f" 2>/dev/null \
                | grep -oE '[0-9]+ samples' | head -1 | grep -oE '[0-9]+')
            total=$((total + ${n:-0}))
        fi
    done
    echo $total
}

echo "=== N=$N targets, $DURATION s wall, $SLEEP_NS ns sleep (~100 Hz) ==="
echo "=== phpspy daemon (-T $N, -P -f) ==="
run_profiler phpspy $PHPSPY -P "-f php.+bench/target.php" -T $N -s $SLEEP_NS -o /tmp/phpspy.out
echo "phpspy_samples=$(count_phpspy_samples)" | tee -a $RESULT
cleanup
sleep 0.5

echo "=== reli daemon thread mode (-T $N, JIT on, rbt) ==="
rm -rf /tmp/rbt-out; mkdir /tmp/rbt-out
run_profiler reli $PHP -d zend.max_allowed_stack_size=-1 \
    $RELI/bench/reli-thread-launcher.php inspector:daemon \
    -P 'bench/target\.php' -T $N -F rbt -o /tmp/rbt-out -s $SLEEP_NS
echo "reli_samples=$(count_reli_samples)" | tee -a $RESULT
cleanup

echo
echo "=== summary ==="
cat $RESULT
