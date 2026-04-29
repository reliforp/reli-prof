#!/usr/bin/env bash
# Measure recorded output size per profiler on the same workload
# (Laravel route × 2000 dispatches). One run each — the order of
# magnitude is what we want.
#
# Output: docs/bench/results/output-sizes.csv
set -uo pipefail

EXTDIR="$(/usr/bin/php8.4 -r 'echo PHP_EXTENSION_DIR;')"
SCRIPTS_DIR="$(cd "$(dirname "$0")" && pwd)/scripts"
PROFILERS_DIR="$(cd "$(dirname "$0")" && pwd)/profilers"
LARAVEL_DIR="${LARAVEL_DIR:-/tmp/bench-laravel}"
ITERS="${ITERS:-2000}"

PHPSPY=/usr/local/bin/phpspy
RELI_PHP=/usr/bin/php8.5
RELI=/home/user/reli-prof/reli

JIT_FLAGS="-dzend_extension=${EXTDIR}/opcache.so -dopcache.enable_cli=1 -dopcache.jit=tracing -dopcache.jit_buffer_size=64M"
LARAVEL_EXTS="-dextension=curl -dextension=zip -dextension=mbstring -dextension=iconv -dextension=tokenizer -dextension=phar -dextension=dom -dextension=xml -dextension=simplexml -dextension=xmlwriter -dextension=ctype -dextension=fileinfo -dextension=pdo -dextension=pdo_sqlite -dextension=sqlite3 -dextension=sockets -dextension=intl -dextension=ffi"

# Clean leftover outputs.
rm -f /tmp/outsize-*.{rbt,out,txt} /tmp/outsize-cachegrind.* /tmp/outsize-spx*.json
rm -rf /tmp/outsize-spx-dir && mkdir -p /tmp/outsize-spx-dir

echo "tool,workload,bytes,format"

# ---- xhprof ----
/usr/bin/php8.4 -n $JIT_FLAGS $LARAVEL_EXTS \
    -dextension=${EXTDIR}/xhprof.so \
    -dauto_prepend_file=${PROFILERS_DIR}/xhprof_wrap.php \
    "${SCRIPTS_DIR}/laravel-route.php" "$ITERS" "$LARAVEL_DIR" >/dev/null 2>&1
mv /tmp/bench-xhprof.out /tmp/outsize-xhprof.out 2>/dev/null
echo "xhprof,laravel-route-${ITERS},$(stat -c %s /tmp/outsize-xhprof.out 2>/dev/null || echo 0),serialized PHP array"

# ---- excimer at 200 Hz (same rate as external samplers below) ----
# Use a custom auto_prepend that sets period 0.005 (200 Hz) instead of 1 ms.
cat > /tmp/outsize-excimer-200hz.php <<'PHPEOF'
<?php
$prof = new ExcimerProfiler();
$prof->setPeriod(0.005);
$prof->setEventType(EXCIMER_REAL);
$prof->start();
register_shutdown_function(function () use ($prof) {
    $prof->stop();
    file_put_contents('/tmp/outsize-excimer.txt',
        $prof->getLog()->formatCollapsed());
});
PHPEOF
/usr/bin/php8.4 -n $JIT_FLAGS $LARAVEL_EXTS \
    -dextension=${EXTDIR}/excimer.so \
    -dauto_prepend_file=/tmp/outsize-excimer-200hz.php \
    "${SCRIPTS_DIR}/laravel-route.php" "$ITERS" "$LARAVEL_DIR" >/dev/null 2>&1
echo "excimer-200hz,laravel-route-${ITERS},$(stat -c %s /tmp/outsize-excimer.txt 2>/dev/null || echo 0),collapsed text"

# ---- xdebug profile ----
rm -f /tmp/cachegrind.out.*
/usr/bin/php8.4 -n $JIT_FLAGS $LARAVEL_EXTS \
    -dzend_extension=${EXTDIR}/xdebug.so \
    -dxdebug.mode=profile -dxdebug.start_with_request=yes \
    -dxdebug.output_dir=/tmp -dxdebug.use_compression=0 \
    "${SCRIPTS_DIR}/laravel-route.php" "$ITERS" "$LARAVEL_DIR" >/dev/null 2>&1
xdebug_size=$(stat -c %s /tmp/cachegrind.out.* 2>/dev/null | sort -rn | head -1)
echo "xdebug-profile,laravel-route-${ITERS},${xdebug_size:-0},cachegrind"

# ---- SPX default (full instrumentation) — JIT off because SPX
# corrupts execution under tracing JIT (see docs/bench/RESULTS.md).
rm -rf /tmp/outsize-spx-dir && mkdir -p /tmp/outsize-spx-dir
SPX_ENABLED=1 SPX_REPORT=full \
    /usr/bin/php8.4 -n $LARAVEL_EXTS \
    -dextension=${EXTDIR}/spx.so -dspx.builtins=1 -dspx.http_enabled=0 \
    -dspx.data_dir=/tmp/outsize-spx-dir \
    "${SCRIPTS_DIR}/laravel-route.php" "$ITERS" "$LARAVEL_DIR" >/dev/null 2>&1
spx_default_size=$(stat -c %s /tmp/outsize-spx-dir/* 2>/dev/null | awk '{s+=$1} END{print s+0}')
echo "spx-default,laravel-route-${ITERS},${spx_default_size:-0},SPX JSON (JIT off)"

# ---- SPX with SPX_SAMPLING_PERIOD=1000 (record-thinning) — JIT off ----
rm -rf /tmp/outsize-spx-dir && mkdir -p /tmp/outsize-spx-dir
SPX_ENABLED=1 SPX_REPORT=full SPX_SAMPLING_PERIOD=1000 \
    /usr/bin/php8.4 -n $LARAVEL_EXTS \
    -dextension=${EXTDIR}/spx.so -dspx.builtins=1 -dspx.http_enabled=0 \
    -dspx.data_dir=/tmp/outsize-spx-dir \
    "${SCRIPTS_DIR}/laravel-route.php" "$ITERS" "$LARAVEL_DIR" >/dev/null 2>&1
spx_sampled_size=$(stat -c %s /tmp/outsize-spx-dir/* 2>/dev/null | awk '{s+=$1} END{print s+0}')
echo "spx-period-1ms,laravel-route-${ITERS},${spx_sampled_size:-0},SPX JSON (JIT off)"

# ---- phpspy at 200 Hz ----
/usr/bin/php8.4 -n $JIT_FLAGS $LARAVEL_EXTS \
    "${SCRIPTS_DIR}/laravel-route.php" "$ITERS" "$LARAVEL_DIR" >/dev/null 2>/tmp/outsize-tgt.err &
tpid=$!
sleep 0.05
"$PHPSPY" -p "$tpid" -H 200 -o /tmp/outsize-phpspy.out >/dev/null 2>&1 &
spid=$!
wait "$tpid" 2>/dev/null || true
kill "$spid" 2>/dev/null; wait "$spid" 2>/dev/null || true
echo "phpspy,laravel-route-${ITERS}@200hz,$(stat -c %s /tmp/outsize-phpspy.out 2>/dev/null || echo 0),phpspy text"

# ---- reli at 200 Hz, plain rbt ----
"$RELI_PHP" "$RELI" inspector:trace --sleep-ns 5000000 -f rbt \
    -o /tmp/outsize-reli.rbt \
    -- /usr/bin/php8.4 -n $JIT_FLAGS $LARAVEL_EXTS \
    "${SCRIPTS_DIR}/laravel-route.php" "$ITERS" "$LARAVEL_DIR" >/dev/null 2>&1 || true
echo "reli-200hz,laravel-route-${ITERS},$(stat -c %s /tmp/outsize-reli.rbt 2>/dev/null || echo 0),rbt binary"

# ---- reli at 200 Hz, compressed rbt (--rbt-compress) ----
"$RELI_PHP" "$RELI" inspector:trace --sleep-ns 5000000 -f rbt --rbt-compress \
    -o /tmp/outsize-reli-z.rbt \
    -- /usr/bin/php8.4 -n $JIT_FLAGS $LARAVEL_EXTS \
    "${SCRIPTS_DIR}/laravel-route.php" "$ITERS" "$LARAVEL_DIR" >/dev/null 2>&1 || true
echo "reli-200hz-z,laravel-route-${ITERS},$(stat -c %s /tmp/outsize-reli-z.rbt 2>/dev/null || echo 0),rbt binary (gzip)"

# ---- -S variants for stable size measurement.
# `--pause-process` extends target wall but ensures every requested
# sample lands and reli's --rbt-compress flush completes (the FFI race
# we hit on non-`-S` reli runs against fast-mutating zend_strings can
# leave a 0-byte gzip output).

# reli plain rbt with -S
"$RELI_PHP" "$RELI" inspector:trace --sleep-ns 5000000 -S -f rbt \
    -o /tmp/outsize-reli-S.rbt \
    -- /usr/bin/php8.4 -n $JIT_FLAGS $LARAVEL_EXTS \
    "${SCRIPTS_DIR}/laravel-route.php" "$ITERS" "$LARAVEL_DIR" >/dev/null 2>&1 || true
echo "reli-200hz-S,laravel-route-${ITERS},$(stat -c %s /tmp/outsize-reli-S.rbt 2>/dev/null || echo 0),rbt binary -S"

# reli --rbt-compress with -S
"$RELI_PHP" "$RELI" inspector:trace --sleep-ns 5000000 -S -f rbt --rbt-compress \
    -o /tmp/outsize-reli-zS.rbt \
    -- /usr/bin/php8.4 -n $JIT_FLAGS $LARAVEL_EXTS \
    "${SCRIPTS_DIR}/laravel-route.php" "$ITERS" "$LARAVEL_DIR" >/dev/null 2>&1 || true
echo "reli-200hz-zS,laravel-route-${ITERS},$(stat -c %s /tmp/outsize-reli-zS.rbt 2>/dev/null || echo 0),rbt binary --rbt-compress -S"

# phpspy with -S (for parity with reli -S above)
/usr/bin/php8.4 -n $JIT_FLAGS $LARAVEL_EXTS \
    "${SCRIPTS_DIR}/laravel-route.php" "$ITERS" "$LARAVEL_DIR" \
    >/dev/null 2>/tmp/outsize-tgt.err &
tpid=$!
sleep 0.05
"$PHPSPY" -p "$tpid" -H 200 -S -o /tmp/outsize-phpspy-S.out \
    >/dev/null 2>/dev/null &
spid=$!
wait "$tpid" 2>/dev/null || true
kill "$spid" 2>/dev/null; wait "$spid" 2>/dev/null || true
echo "phpspy-200hz-S,laravel-route-${ITERS},$(stat -c %s /tmp/outsize-phpspy-S.out 2>/dev/null || echo 0),phpspy text -S"
