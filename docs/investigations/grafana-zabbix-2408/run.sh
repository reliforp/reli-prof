#!/usr/bin/env bash
# End-to-end repro driver for grafana-zabbix#2408 — see ../grafana-zabbix-2408.md
set -euo pipefail

DIR=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$DIR/../../.." && pwd)

echo "[1/6] Ensuring dockerd is running..."
if ! docker info > /dev/null 2>&1; then
    dockerd --storage-driver=vfs --bridge=none --iptables=false --ip6tables=false \
        > /tmp/dockerd.log 2>&1 &
    for i in 1 2 3 4 5 6 7 8 9 10; do
        docker info > /dev/null 2>&1 && break
        sleep 1
    done
fi

echo "[2/6] Ensuring zbx-net user-defined bridge..."
docker network inspect zbx-net > /dev/null 2>&1 || docker network create zbx-net

echo "[3/6] Bringing up the Zabbix stack..."
cd "$DIR"
docker compose up -d

echo "[4/6] Waiting for Zabbix API to be ready..."
for i in $(seq 1 120); do
    resp=$(curl -sS -X POST http://127.0.0.1:18080/api_jsonrpc.php \
        -H 'Content-Type: application/json-rpc' \
        -d '{"jsonrpc":"2.0","method":"apiinfo.version","params":{},"id":1}' || true)
    if echo "$resp" | grep -q '"result"'; then break; fi
    sleep 2
done
echo "  apiinfo.version: $resp"

mkdir -p /tmp/zbx
AUTH=$(curl -sS -X POST http://127.0.0.1:18080/api_jsonrpc.php \
    -H 'Content-Type: application/json-rpc' \
    -d '{"jsonrpc":"2.0","method":"user.login","params":{"user":"Admin","password":"zabbix"},"id":1}' \
    | python3 -c 'import sys,json;print(json.load(sys.stdin)["result"])')
echo -n "$AUTH" > /tmp/zbx/auth.txt
echo "  auth: $AUTH"

echo "[5/6] Seeding hosts, items, triggers, and synthetic problems..."
N_HOSTS=${N_HOSTS:-80} N_TAGS_PER_TRIGGER=${N_TAGS_PER_TRIGGER:-12} python3 "$DIR/seed_via_api.py"
N_PROBLEMS=${N_PROBLEMS:-5000} N_TAGS=${N_TAGS:-15} TAG_VALUE_LEN=${TAG_VALUE_LEN:-200} \
    python3 "$DIR/scale_problems.py"

echo "[6/6] Profiling problem.get with reli..."
cd "$REPO"
[ -d vendor ] || composer install --no-interaction
mkdir -p /tmp/zbx/profiles && rm -f /tmp/zbx/profiles/*.rbt
mkdir -p /tmp/zbx/dumps && rm -f /tmp/zbx/dumps/*

# CPU profile across all zabbix-pool php-fpm workers
php "$REPO/reli" inspector:daemon -P 'php-fpm: pool zabbix' -T 8 \
    -f rbt -o /tmp/zbx/profiles -s 1000000 \
    --php-version=v83 --php-regex='php-fpm83$' > /tmp/zbx/daemon.err 2>&1 &
DAEMON=$!
sleep 8

PAYLOAD="{\"jsonrpc\":\"2.0\",\"method\":\"problem.get\",\"params\":{\"output\":\"extend\",\"selectTags\":\"extend\",\"selectAcknowledges\":\"extend\",\"selectSuppressionData\":\"extend\",\"recent\":true},\"auth\":\"$AUTH\",\"id\":1}"
echo "  --- 4 requests with output:extend ---"
for i in 1 2 3 4; do
    curl -sS -X POST http://127.0.0.1:18080/api_jsonrpc.php \
        -H 'Content-Type: application/json-rpc' -d "$PAYLOAD" -o /dev/null \
        -w "    req $i: size=%{size_download} time=%{time_total}\n"
done

sleep 2
kill -INT $DAEMON 2>/dev/null
wait $DAEMON 2>/dev/null || true

cat /tmp/zbx/profiles/*.rbt > /tmp/zbx/combined.rbt
echo
echo "=== rbt:analyze (top hot frames) ==="
php "$REPO/reli" rbt:analyze --top=20 --no-line --crop=140 < /tmp/zbx/combined.rbt | head -60

# Comparison: output excludes opdata
echo
echo "  --- 3 requests with minimal output (no opdata) ---"
PAYLOAD_MIN="{\"jsonrpc\":\"2.0\",\"method\":\"problem.get\",\"params\":{\"output\":[\"eventid\",\"name\",\"clock\",\"severity\",\"objectid\",\"acknowledged\"],\"selectTags\":\"extend\",\"recent\":true},\"auth\":\"$AUTH\",\"id\":1}"
for i in 1 2 3; do
    curl -sS -X POST http://127.0.0.1:18080/api_jsonrpc.php \
        -H 'Content-Type: application/json-rpc' -d "$PAYLOAD_MIN" -o /dev/null \
        -w "    req $i: size=%{size_download} time=%{time_total}\n"
done

echo
echo "Done. Inspect /tmp/zbx/profiles/*.rbt with rbt:analyze, or take memory:dumps with"
echo "inspector:watch and run inspector:memory:analyze -f report on the resulting .rdump."
