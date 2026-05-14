#!/usr/bin/env python3
"""Create hosts + items + triggers via Zabbix API, then trip the triggers
via direct DB insert into events/problem/problem_tag.
Tag count per problem is the knob we care about — grafana-zabbix selectTags
is what bloats the response.
"""
import json, os, time, urllib.request, urllib.error, sys

URL = "http://127.0.0.1:18080/api_jsonrpc.php"
AUTH = open("/tmp/zbx/auth.txt").read().strip()

def call(method, params):
    body = {"jsonrpc": "2.0", "method": method, "params": params, "auth": AUTH, "id": 1}
    if method in ("apiinfo.version",) or method.startswith("user.login"):
        body.pop("auth", None)
    req = urllib.request.Request(URL, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json-rpc"})
    with urllib.request.urlopen(req, timeout=60) as f:
        r = json.loads(f.read())
    if "error" in r:
        raise RuntimeError(f"{method}: {r['error']}")
    return r["result"]

# 1) Create host group
try:
    groupid = call("hostgroup.create", {"name": "ReliInvestigation"})["groupids"][0]
except RuntimeError:
    groupid = call("hostgroup.get", {"filter": {"name": ["ReliInvestigation"]}})[0]["groupid"]
print("hostgroup:", groupid)

# 2) Create N hosts (bulk)
N_HOSTS = int(os.environ.get("N_HOSTS", "100"))
existing = {h["host"]: h["hostid"] for h in call("host.get", {"output": ["hostid", "host"],
        "filter": {"host": [f"reli-h{i:04d}" for i in range(N_HOSTS)]}})}
to_create = []
for i in range(N_HOSTS):
    name = f"reli-h{i:04d}"
    if name in existing: continue
    to_create.append({
        "host": name, "name": name,
        "groups": [{"groupid": groupid}],
        "interfaces": [{"type": 1, "main": 1, "useip": 1, "ip": "127.0.0.1",
                        "dns": "", "port": "10050"}],
    })
if to_create:
    BATCH = 50
    for i in range(0, len(to_create), BATCH):
        ids = call("host.create", to_create[i:i+BATCH])["hostids"]
        for h, hid in zip(to_create[i:i+BATCH], ids):
            existing[h["host"]] = hid
        print(f"created hosts {i+len(ids)}/{len(to_create)}")
print(f"total hosts: {len(existing)}")

# 3) Create 1 trapper item + 1 trigger per host
items_per_host = int(os.environ.get("N_ITEMS_PER_HOST", "1"))
host_items = {}  # hostid -> list of (itemid, key)
for hostid, hostname in [(v, k) for k, v in existing.items()]:
    existing_items = {it["key_"]: it["itemid"] for it in
                      call("item.get", {"hostids": hostid, "output": ["itemid", "key_"]})}
    new_items = []
    for j in range(items_per_host):
        key = f"reli.trap[{j}]"
        if key in existing_items: continue
        new_items.append({"hostid": hostid, "name": f"trap {j}", "key_": key,
                          "type": 2, "value_type": 3, "delay": 0})
    if new_items:
        BATCH = 100
        for i in range(0, len(new_items), BATCH):
            ids = call("item.create", new_items[i:i+BATCH])["itemids"]
            for it, iid in zip(new_items[i:i+BATCH], ids):
                existing_items[it["key_"]] = iid
    host_items[hostid] = [(existing_items[f"reli.trap[{j}]"], f"reli.trap[{j}]")
                          for j in range(items_per_host)]
print(f"items per host: {items_per_host}; total items: {sum(len(v) for v in host_items.values())}")

# 4) Create triggers (one per item) with N_TAGS tags each
n_tags = int(os.environ.get("N_TAGS_PER_TRIGGER", "10"))
all_triggers = []
new_triggers = []
existing_descriptions = set()
for hostid, hostname in [(v, k) for k, v in existing.items()]:
    items = host_items[hostid]
    for itemid, key in items:
        desc = f"reli problem on {hostname} {key}"
        existing_descriptions.add((hostname, desc))
        # We'll create triggers in bulk below
        new_triggers.append({
            "description": desc,
            "expression": f"last(/{hostname}/{key})<>0",
            "priority": 4,
            "tags": [{"tag": f"opdata{k}", "value": f"value-of-tag-{k}-" + "x"*100}
                     for k in range(n_tags)],
            "opdata": "operational data " + "y"*200,
            "manual_close": 1,
            "event_name": f"{hostname}: {desc}",
        })

# Filter out existing
existing_t = call("trigger.get", {"output": ["triggerid", "description"],
                                    "filter": {"description": [t["description"] for t in new_triggers]}})
existing_descs = {t["description"] for t in existing_t}
new_triggers = [t for t in new_triggers if t["description"] not in existing_descs]
print(f"triggers to create: {len(new_triggers)}")

BATCH = 50
created = 0
for i in range(0, len(new_triggers), BATCH):
    chunk = new_triggers[i:i+BATCH]
    call("trigger.create", chunk)
    created += len(chunk)
    if (i // BATCH) % 5 == 0:
        print(f"  created {created}/{len(new_triggers)}")

print("done creating triggers")
