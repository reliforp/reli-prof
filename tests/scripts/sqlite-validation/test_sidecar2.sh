#!/bin/bash
# Sidecar end-to-end test, take 2 — using composer autoload.

set -uo pipefail
PR=/tmp/reli-pr773
WORK=/tmp/sqlite-validation
OUT=$WORK/out/sidecar
mkdir -p "$OUT/dumps"
chmod 0700 "$OUT"
SOCK=$OUT/sidecar.sock
rm -f "$SOCK" "$OUT/dumps"/*

echo "=== starting sidecar daemon ==="
php "$PR/reli" inspector:sidecar -s "$SOCK" -o "$OUT/dumps" -t test=round5 \
    > "$OUT/sidecar.log" 2>&1 &
SIDECAR=$!
for _ in $(seq 1 60); do [[ -S "$SOCK" ]] && break; sleep 0.1; done
[[ -S "$SOCK" ]] || { echo "sidecar didn't start"; cat "$OUT/sidecar.log"; exit 1; }
echo "  sidecar pid=$SIDECAR sock=$SOCK"

cat > "$OUT/sidecar_target.php" <<PHP
<?php
require '$PR/vendor/autoload.php';
\$sock = \$argv[1] ?? '';
\$label = \$argv[2] ?? 'unnamed';

\$rows = [];
for (\$i = 0; \$i < 1000; \$i++) {
    \$rows[] = ['id' => \$i, 'name' => "row_\$i", 'tag' => "tag-" . (\$i % 5)];
}

\$client = new \\Reli\\Sidecar\\Client\\SidecarClient(\$sock);
try {
    \$resp = \$client->requestDump(getmypid(), null, null, \$label, ['shape' => 'small_array']);
    fwrite(STDERR, "OK label=\$label resp=" . json_encode(\$resp) . "\n");
} catch (\\Throwable \$e) {
    fwrite(STDERR, "ERROR label=\$label: " . get_class(\$e) . ': ' . \$e->getMessage() . "\n");
}
PHP

for i in 1 2 3; do
    echo
    echo "=== target #$i ==="
    php "$OUT/sidecar_target.php" "$SOCK" "label_$i" 2>&1 | head -3
done
sleep 2

echo
echo "=== sidecar log ==="
tail -20 "$OUT/sidecar.log"

echo
echo "=== dumps produced ==="
ls -la "$OUT/dumps/"

echo
echo "=== analyze each dump ==="
shopt -s nullglob
for d in "$OUT/dumps"/*; do
    [[ -f "$d" ]] || continue
    case "$d" in
        *.rdump|*.dump|*.bin) ;;
        *) continue ;;
    esac
    out="${d%.*}.sqlite3"
    rm -f "$out"
    php "$PR/reli" inspector:memory:analyze "$d" -f sqlite3 -o "$out" \
        > "$out.log" 2>&1
    rc=$?
    integ=$(sqlite3 "$out" 'PRAGMA integrity_check;' 2>&1 | head -1)
    edges=$(sqlite3 "$out" 'SELECT COUNT(*) FROM context_edges;' 2>/dev/null)
    nodes=$(sqlite3 "$out" 'SELECT COUNT(*) FROM context_nodes;' 2>/dev/null)
    label=$(sqlite3 "$out" "SELECT value FROM summary WHERE key IN ('label','snapshot_label','dump_label') LIMIT 1;" 2>/dev/null)
    printf "  %-44s rc=%s integ='%s' edges=%-6s nodes=%-6s label='%s'\n" \
        "$(basename "$d")" "$rc" "$integ" "$edges" "$nodes" "$label"
    [[ $rc -ne 0 ]] && head -8 "$out.log" | sed 's/^/    /'
done

# Verify the multi-run-via-sidecar dream: build merged.sqlite3 from
# all 3 sidecar dumps
echo
echo "=== merge 3 sidecar dumps into one multi-run sqlite ==="
MERGED=$OUT/merged.sqlite3
rm -f "$MERGED"
i=0
for d in "$OUT/dumps"/*; do
    [[ -f "$d" ]] || continue
    case "$d" in *.rdump|*.dump|*.bin) ;; *) continue ;; esac
    i=$((i+1))
    rmem="$OUT/dumps/$(basename "$d").rmem"
    rm -f "$rmem"
    php "$PR/reli" inspector:memory:analyze "$d" -f rmem -o "$rmem" >/dev/null 2>&1 || continue
    if [[ $i -eq 1 ]]; then
        php "$PR/reli" inspector:memory:export-sqlite "$rmem" "$MERGED" >/dev/null 2>&1
    else
        # export-sqlite refuses overwrite. The natural multi-run is
        # via inspector:memory live capture, which DOES append.
        # For sidecar dumps, emulate by analyzing each into a separate
        # sqlite then noting they're independently usable.
        :
    fi
done

echo "  merged size=$(stat -c %s "$MERGED" 2>/dev/null) integ=$(sqlite3 "$MERGED" 'PRAGMA integrity_check;' 2>&1 | head -1)"
echo "  runs in merged: $(sqlite3 "$MERGED" 'SELECT GROUP_CONCAT(run_id) FROM runs;' 2>/dev/null)"

# Cleanup
echo
kill -TERM "$SIDECAR" 2>/dev/null
wait "$SIDECAR" 2>/dev/null
echo "sidecar2 done"
