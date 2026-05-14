#!/usr/bin/env python3
"""Insert N_HIST_PER_ITEM history rows per reli item, spread across the
last 24 hours, so history.get over a wide time window returns a meaty
response."""
import subprocess, time, os, random

def mysql_query(sql):
    out = subprocess.check_output(
        ["docker", "exec", "-i", "zbx-mysql", "mysql", "-uroot", "-prootpw", "-Nse", sql, "zabbix"],
        stderr=subprocess.DEVNULL).decode()
    return [l.split("\t") for l in out.strip().splitlines() if l.strip()]

def mysql_exec(sql):
    subprocess.run(["docker", "exec", "-i", "zbx-mysql", "mysql", "-uroot", "-prootpw", "zabbix"],
                   input=sql.encode(), check=True, stderr=subprocess.DEVNULL)

items = [r[0] for r in mysql_query("SELECT itemid FROM items WHERE key_ LIKE 'reli.trap%'")]
print(f"items: {len(items)}")

N_PER_ITEM = int(os.environ.get("N_HIST_PER_ITEM", "2000"))
WINDOW_SEC = int(os.environ.get("WINDOW_SEC", "86400"))  # 24h
now = int(time.time())
mysql_exec("DELETE FROM history_uint WHERE itemid IN (" + ",".join(items) + ")")
print("cleared prior history")

rows = []
for itemid in items:
    base = random.randint(100, 9999)
    for k in range(N_PER_ITEM):
        # spread evenly with jitter across the window
        clock = now - WINDOW_SEC + (k * WINDOW_SEC // N_PER_ITEM) + random.randint(0, 30)
        value = base + (k % 50)
        ns = random.randint(0, 999_999_999)
        rows.append(f"({itemid},{clock},{value},{ns})")

print(f"prepared {len(rows)} rows")
BATCH = 5000
for i in range(0, len(rows), BATCH):
    sql = "INSERT INTO history_uint (itemid, clock, value, ns) VALUES " + ",".join(rows[i:i+BATCH]) + ";"
    mysql_exec(sql)
    if (i // BATCH) % 5 == 0:
        print(f"  inserted {i+BATCH}/{len(rows)}")
print("done")
