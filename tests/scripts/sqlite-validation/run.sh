#!/bin/bash
# Driver for SQLite output validation against a single target.
#
# Usage: ./run.sh <label> <target.php> [args...]
#
# Produces in /tmp/sqlite-validation/out/<label>/:
#   default.sqlite3         - rmem→sqlite via the parallel-shard ingest path
#   shared_mmap.sqlite3     - same but with RELI_SHARED_MMAP_INGEST=1
#                             RELI_PARALLEL_INDEX=1 RELI_FORMAT_DIRECT_INDEX=1
#   capture.rmem            - intermediate rmem capture
#   from_rmem.sqlite3       - sqlite produced via inspector:memory:export-sqlite
#   capture.json            - JSON output from the same target snapshot for parity
#   *.log                   - command stderr+stdout

set -uo pipefail
label="${1:?label}"
target="${2:?target.php}"
shift 2

ROOT=/tmp/reli-pr773
WORK=/tmp/sqlite-validation
OUT="$WORK/out/$label"
mkdir -p "$OUT"

start_target() {
    rm -f "$WORK/target.pid"
    # Redirect ALL fds away — otherwise the backgrounded php inherits stdout
    # of the calling subshell and $(start_target) waits for EOF on it forever.
    php "$target" "$@" </dev/null > "$OUT/target.out" 2> "$OUT/target.err" &
    local launcher_pid=$!
    # wait for READY line
    for _ in $(seq 1 60); do
        if [[ -s "$WORK/target.pid" ]]; then break; fi
        sleep 0.1
    done
    if [[ ! -s "$WORK/target.pid" ]]; then
        echo "target failed to come up" >&2
        cat "$OUT/target.err" >&2
        kill "$launcher_pid" 2>/dev/null
        exit 1
    fi
    cat "$WORK/target.pid"
}

stop_target() {
    local pid=$1
    kill -TERM "$pid" 2>/dev/null
    wait "$pid" 2>/dev/null
}

# 1) rmem capture (the canonical intermediate)
pid=$(start_target)
echo "[$label] target pid=$pid"

php "$ROOT/reli" inspector:memory -p "$pid" \
    -f rmem -o "$OUT/capture.rmem" \
    > "$OUT/rmem.log" 2>&1
echo "[$label] rmem: $(stat -c %s "$OUT/capture.rmem" 2>/dev/null || echo MISSING) bytes"

# 2) default sqlite (rmem→sqlite parallel-shard ingest)
php "$ROOT/reli" inspector:memory -p "$pid" \
    -f sqlite3 -o "$OUT/default.sqlite3" \
    > "$OUT/default.log" 2>&1
echo "[$label] default.sqlite3: $(stat -c %s "$OUT/default.sqlite3" 2>/dev/null || echo MISSING) bytes"

# 3) experimental: shared mmap + parallel index + format-direct index
RELI_SHARED_MMAP_INGEST=1 RELI_PARALLEL_INDEX=1 RELI_FORMAT_DIRECT_INDEX=1 \
    php "$ROOT/reli" inspector:memory -p "$pid" \
    -f sqlite3 -o "$OUT/shared_mmap.sqlite3" \
    > "$OUT/shared_mmap.log" 2>&1
echo "[$label] shared_mmap.sqlite3: $(stat -c %s "$OUT/shared_mmap.sqlite3" 2>/dev/null || echo MISSING) bytes"

# 4) JSON for parity (single capture of same target)
php "$ROOT/reli" inspector:memory -p "$pid" \
    -f json -o "$OUT/capture.json" \
    > "$OUT/json.log" 2>&1
echo "[$label] capture.json: $(stat -c %s "$OUT/capture.json" 2>/dev/null || echo MISSING) bytes"

stop_target "$pid"

# 5) export-sqlite: rmem→sqlite via the new dedicated command
php "$ROOT/reli" inspector:memory:export-sqlite \
    "$OUT/capture.rmem" "$OUT/from_rmem.sqlite3" \
    > "$OUT/export_sqlite.log" 2>&1
echo "[$label] from_rmem.sqlite3: $(stat -c %s "$OUT/from_rmem.sqlite3" 2>/dev/null || echo MISSING) bytes"

# 6) integrity_check on every produced sqlite
for f in "$OUT"/*.sqlite3; do
    [[ -f "$f" ]] || continue
    res=$(sqlite3 "$f" 'PRAGMA integrity_check;' 2>&1 | head -3)
    echo "[$label] integrity $(basename "$f"): $res"
done

echo "[$label] done"
