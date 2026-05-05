#!/bin/bash
# Drive inspector:memory -f postgresql against a small target, then
# cross-compare to the same target's sqlite3 output for parity.
# Re-uses the existing rmem capture to make sure both drivers ingest
# byte-identical input.

set -uo pipefail
ROOT=/tmp/reli-pr773
WORK=/tmp/sqlite-validation
OUT=$WORK/out/pg
mkdir -p "$OUT"

PG_HOST=127.0.0.1
PG_PORT=5432
PG_USER=reli
PG_PASS=reli
PG_DB=reli

reset_pg() {
    PGPASSWORD=$PG_PASS psql -q -h $PG_HOST -U $PG_USER -d $PG_DB <<'SQL' >/dev/null 2>&1
DROP TABLE IF EXISTS context_node_attributes CASCADE;
DROP TABLE IF EXISTS context_node_locations CASCADE;
DROP TABLE IF EXISTS context_edges CASCADE;
DROP TABLE IF EXISTS context_nodes CASCADE;
DROP TABLE IF EXISTS class_objects_summary CASCADE;
DROP TABLE IF EXISTS location_types_summary CASCADE;
DROP TABLE IF EXISTS summary CASCADE;
DROP TABLE IF EXISTS runs CASCADE;
DROP VIEW IF EXISTS v_arrays;
DROP VIEW IF EXISTS v_node_paths;
SQL
}

start_target() {
    rm -f "$WORK/target.pid"
    php "$1" "${@:2}" </dev/null > "$OUT/target.out" 2> "$OUT/target.err" &
    for _ in $(seq 1 60); do [[ -s "$WORK/target.pid" ]] && break; sleep 0.1; done
    cat "$WORK/target.pid"
}
stop() { kill -TERM "$1" 2>/dev/null; wait "$1" 2>/dev/null || true; }

reset_pg
echo "== postgres clean =="

# Use circular_refs as a representative shape (mid-size, lots of cycles)
pid=$(start_target /tmp/sqlite-validation/target_circular_refs.php 4 20 15)
echo "target pid=$pid"

# rmem capture once, then ingest into postgres AND sqlite from the same rmem
php "$ROOT/reli" inspector:memory -p "$pid" -f rmem -o "$OUT/capture.rmem" \
    > "$OUT/rmem.log" 2>&1
echo "rmem $(stat -c %s "$OUT/capture.rmem") bytes"

stop "$pid"

# Ingest into postgres via export-sqlite? no — export-sqlite is sqlite-only.
# Drive a fresh inspector:memory against a fresh-attached target instead.

# Re-attach: spawn target again, do a single capture into pg
pid=$(start_target /tmp/sqlite-validation/target_circular_refs.php 4 20 15)
echo "second-attached pid=$pid"

t_pg_start=$(date +%s%N)
php "$ROOT/reli" inspector:memory -p "$pid" -f postgresql \
    --db-host=$PG_HOST --db-port=$PG_PORT --db-user=$PG_USER --db-password=$PG_PASS --db-name=$PG_DB \
    > "$OUT/pg.log" 2>&1
pg_rc=$?
t_pg_end=$(date +%s%N)
echo "pg ingest rc=$pg_rc elapsed=$(( (t_pg_end - t_pg_start)/1000000 ))ms"

if [[ $pg_rc -ne 0 ]]; then
    echo "--- pg.log ---"
    head -30 "$OUT/pg.log"
fi

# Capture once more into sqlite for parity
rm -f "$OUT/sqlite.sqlite3"
php "$ROOT/reli" inspector:memory -p "$pid" -f sqlite3 -o "$OUT/sqlite.sqlite3" \
    > "$OUT/sqlite.log" 2>&1
echo "sqlite ingest rc=$? size=$(stat -c %s "$OUT/sqlite.sqlite3" 2>/dev/null) integrity=$(sqlite3 "$OUT/sqlite.sqlite3" 'PRAGMA integrity_check;' 2>&1 | head -1)"

stop "$pid"

echo
echo "== schema parity =="
echo "sqlite tables:"
sqlite3 "$OUT/sqlite.sqlite3" "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name;" 2>&1
echo
echo "pg tables:"
PGPASSWORD=$PG_PASS psql -q -h $PG_HOST -U $PG_USER -d $PG_DB -tc \
    "SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename;" 2>&1

echo
echo "== row count parity =="
for tbl in summary runs context_nodes context_edges context_node_locations context_node_attributes class_objects_summary location_types_summary; do
    s=$(sqlite3 "$OUT/sqlite.sqlite3" "SELECT COUNT(*) FROM $tbl;" 2>/dev/null)
    p=$(PGPASSWORD=$PG_PASS psql -q -h $PG_HOST -U $PG_USER -d $PG_DB -tAc "SELECT COUNT(*) FROM $tbl;" 2>/dev/null)
    if [[ "$s" == "$p" ]]; then
        printf "  %-32s sqlite=%-8s pg=%-8s OK\n" "$tbl" "$s" "$p"
    else
        printf "  %-32s sqlite=%-8s pg=%-8s ≠\n" "$tbl" "$s" "$p"
    fi
done

echo
echo "== content-hash parity (sorted full row dump) =="
for tbl in summary context_nodes context_edges context_node_locations context_node_attributes; do
    s_h=$(sqlite3 "$OUT/sqlite.sqlite3" "SELECT * FROM $tbl ORDER BY 1,2,3,4,5;" 2>/dev/null | sha256sum | cut -c1-12)
    p_h=$(PGPASSWORD=$PG_PASS psql -q -h $PG_HOST -U $PG_USER -d $PG_DB -tAc \
        "SELECT * FROM $tbl ORDER BY 1,2,3,4,5;" 2>/dev/null | sha256sum | cut -c1-12)
    eq="≠"
    [[ "$s_h" == "$p_h" ]] && eq="OK"
    printf "  %-32s sqlite=%s pg=%s  %s\n" "$tbl" "$s_h" "$p_h" "$eq"
done

echo
echo "== second pg ingest into existing pg DB (multi-run) =="
pid=$(start_target /tmp/sqlite-validation/target_splobjectstorage.php 1500)
echo "target pid=$pid"
php "$ROOT/reli" inspector:memory -p "$pid" -f postgresql \
    --db-host=$PG_HOST --db-port=$PG_PORT --db-user=$PG_USER --db-password=$PG_PASS --db-name=$PG_DB \
    > "$OUT/pg2.log" 2>&1
pg2_rc=$?
echo "second pg ingest rc=$pg2_rc"
if [[ $pg2_rc -ne 0 ]]; then
    echo "--- pg2.log ---"
    head -30 "$OUT/pg2.log"
fi
runs_pg=$(PGPASSWORD=$PG_PASS psql -q -h $PG_HOST -U $PG_USER -d $PG_DB -tAc "SELECT string_agg(run_id::text, ',' ORDER BY run_id) FROM runs;" 2>/dev/null)
echo "runs in pg: [$runs_pg]"
edges_by_run=$(PGPASSWORD=$PG_PASS psql -q -h $PG_HOST -U $PG_USER -d $PG_DB -tAc \
    "SELECT run_id || '=' || COUNT(*) FROM context_edges GROUP BY run_id ORDER BY run_id;" 2>/dev/null | tr '\n' ' ')
echo "context_edges by run_id: $edges_by_run"

stop "$pid"

echo
echo "test_postgres done"
