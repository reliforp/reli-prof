#!/bin/bash
# JSON output parity: 0.12.0 vs PR tip on the same target.
# Both produce a JSON dump of the heap; downstream tooling expects
# byte-stable structure. Compares the JSON object/key shape between
# the two versions.

set -uo pipefail
PR=/tmp/reli-pr773
OLD=/tmp/reli-012
WORK=/tmp/sqlite-validation
OUT=$WORK/out/json_parity
mkdir -p "$OUT"

# Use a target that exercises a wide variety of types — circular_refs
# has objects with cycles, strings, arrays, nested arrays. Should
# stress every JSON output code path.
TARGET=/tmp/sqlite-validation/target_circular_refs.php

start_target() {
    rm -f "$WORK/target.pid"
    php "$TARGET" 4 20 15 </dev/null > "$OUT/target.out" 2> "$OUT/target.err" &
    for _ in $(seq 1 60); do [[ -s "$WORK/target.pid" ]] && break; sleep 0.1; done
    cat "$WORK/target.pid"
}
stop() { kill -TERM "$1" 2>/dev/null; wait "$1" 2>/dev/null || true; }

echo "== capture from same target through 0.12.0 then PR tip =="

# 0.12.0 first
pid=$(start_target)
echo "0.12.0 target pid=$pid"
php "$OLD/reli" inspector:memory -p "$pid" -f json -o "$OUT/v012.json" \
    > "$OUT/v012.log" 2>&1
v012_rc=$?
stop "$pid"
echo "0.12.0 rc=$v012_rc size=$(stat -c %s "$OUT/v012.json" 2>/dev/null || echo MISSING)"

# PR tip
pid=$(start_target)
echo "PR target pid=$pid"
php "$PR/reli" inspector:memory -p "$pid" -f json -o "$OUT/vpr.json" \
    > "$OUT/vpr.log" 2>&1
vpr_rc=$?
stop "$pid"
echo "PR rc=$vpr_rc size=$(stat -c %s "$OUT/vpr.json" 2>/dev/null || echo MISSING)"

[[ -s "$OUT/v012.json" && -s "$OUT/vpr.json" ]] || { echo "missing output"; exit 1; }

echo
echo "== top-level structure comparison =="
echo "0.12.0 top-level keys (jq):"
jq -r 'keys[]' "$OUT/v012.json" 2>&1 | sort
echo
echo "PR top-level keys (jq):"
jq -r 'keys[]' "$OUT/vpr.json" 2>&1 | sort

echo
echo "== summary key-set diff =="
jq -r '.summary | if type == "object" then keys[] else .[].key end' "$OUT/v012.json" 2>&1 | sort -u > "$OUT/v012_summary_keys.txt"
jq -r '.summary | if type == "object" then keys[] else .[].key end' "$OUT/vpr.json"  2>&1 | sort -u > "$OUT/vpr_summary_keys.txt"
diff "$OUT/v012_summary_keys.txt" "$OUT/vpr_summary_keys.txt" | head -40 || true

echo
echo "== a sample context node — same shape? =="
echo "0.12.0 sample node (first):"
jq '.context.nodes[0] // .nodes[0] // empty' "$OUT/v012.json" 2>&1 | head -20
echo
echo "PR sample node (first):"
jq '.context.nodes[0] // .nodes[0] // empty' "$OUT/vpr.json"  2>&1 | head -20

echo
echo "== a sample edge — same shape? =="
echo "0.12.0 sample edge (first):"
jq '.context.edges[0] // .edges[0] // empty' "$OUT/v012.json" 2>&1 | head -10
echo
echo "PR sample edge (first):"
jq '.context.edges[0] // .edges[0] // empty' "$OUT/vpr.json"  2>&1 | head -10

echo
echo "== row counts in each top-level array =="
for f in v012 vpr; do
    echo "$f:"
    jq -r 'paths(arrays) as $p | [$p | join("."), (getpath($p) | length)] | @tsv' "$OUT/$f.json" 2>&1 \
        | sort -u | head -20
    echo
done

echo "json_parity done"
