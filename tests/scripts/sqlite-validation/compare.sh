#!/bin/bash
# Cross-compare the SQLite outputs produced by run.sh for one label.
# - PRAGMA integrity_check on each .sqlite3
# - per-table row counts across default / shared_mmap / from_rmem
# - hash of materialised top-100 heaviest objects (by size) for each variant
#
# Usage: compare.sh <label>

set -uo pipefail
label="${1:?label}"
OUT="/tmp/sqlite-validation/out/$label"

dbs=("$OUT/default.sqlite3" "$OUT/shared_mmap.sqlite3" "$OUT/from_rmem.sqlite3")

echo "== $label =="
for db in "${dbs[@]}"; do
    [[ -f "$db" ]] || { echo "  [missing] $db"; continue; }
    sz=$(stat -c %s "$db")
    integ=$(sqlite3 "$db" 'PRAGMA integrity_check;' 2>&1 | head -1)
    echo "  [$db]  size=$sz  integrity=$integ"
done

echo "  -- per-table row counts --"
# Use the first present DB to discover tables
for db in "${dbs[@]}"; do [[ -f "$db" ]] && master="$db" && break; done
[[ -z "${master:-}" ]] && { echo "  no dbs"; exit 0; }
tables=$(sqlite3 "$master" "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name;")
printf "  %-32s" "table"
for db in "${dbs[@]}"; do
    [[ -f "$db" ]] || continue
    printf " %14s" "$(basename "$db" .sqlite3)"
done
echo
for t in $tables; do
    printf "  %-32s" "$t"
    for db in "${dbs[@]}"; do
        [[ -f "$db" ]] || continue
        c=$(sqlite3 "$db" "SELECT COUNT(*) FROM $t;" 2>/dev/null)
        printf " %14s" "${c:-ERR}"
    done
    echo
done

echo "  -- top-50 heaviest objects (by size) hash --"
sql="SELECT address, size FROM zend_objects ORDER BY size DESC, address LIMIT 50;"
for db in "${dbs[@]}"; do
    [[ -f "$db" ]] || continue
    h=$(sqlite3 "$db" "$sql" 2>/dev/null | sha256sum | cut -c1-16)
    echo "    $h  $(basename "$db")"
done

echo "  -- top-50 heaviest strings hash --"
sql_s="SELECT address, length FROM zend_strings ORDER BY length DESC, address LIMIT 50;"
for db in "${dbs[@]}"; do
    [[ -f "$db" ]] || continue
    h=$(sqlite3 "$db" "$sql_s" 2>/dev/null | sha256sum | cut -c1-16)
    echo "    $h  $(basename "$db")"
done

echo "  -- top-50 heaviest arrays hash --"
sql_a="SELECT address, num_elements FROM zend_arrays ORDER BY num_elements DESC, address LIMIT 50;"
for db in "${dbs[@]}"; do
    [[ -f "$db" ]] || continue
    h=$(sqlite3 "$db" "$sql_a" 2>/dev/null | sha256sum | cut -c1-16)
    echo "    $h  $(basename "$db")"
done
