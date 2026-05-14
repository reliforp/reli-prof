#!/usr/bin/env python3
"""Insert many more synthetic problems referencing existing reli triggers.
Each problem gets N_TAGS tags. Scale matches the issue's problem count."""
import subprocess, time, sys, os

def mysql_query(sql):
    out = subprocess.check_output(
        ["docker", "exec", "-i", "zbx-mysql", "mysql", "-uroot", "-prootpw", "-Nse", sql, "zabbix"],
        stderr=subprocess.DEVNULL).decode()
    return [l.split("\t") for l in out.strip().splitlines() if l.strip()]

def mysql_exec(sql):
    subprocess.run(["docker", "exec", "-i", "zbx-mysql", "mysql", "-uroot", "-prootpw", "zabbix"],
                   input=sql.encode(), check=True, stderr=subprocess.DEVNULL)

N_PROBLEMS = int(os.environ.get("N_PROBLEMS", "3000"))
N_TAGS = int(os.environ.get("N_TAGS", "15"))
TAG_VALUE_LEN = int(os.environ.get("TAG_VALUE_LEN", "200"))

triggers = mysql_query("""SELECT t.triggerid, t.priority, t.event_name, h.host
                          FROM triggers t
                          JOIN functions f ON f.triggerid=t.triggerid
                          JOIN items i ON i.itemid=f.itemid
                          JOIN hosts h ON h.hostid=i.hostid
                          WHERE t.description LIKE 'reli problem on %'""")
print(f"reli triggers: {len(triggers)}")
if not triggers: sys.exit(1)

max_event = int(mysql_query("SELECT COALESCE(MAX(eventid), 0) FROM events")[0][0])
max_pt    = int(mysql_query("SELECT COALESCE(MAX(problemtagid), 0) FROM problem_tag")[0][0])
next_id = max_event + 1
next_pt = max_pt + 1
now = int(time.time())

tag_val = "v" * TAG_VALUE_LEN

events = []
problems = []
ptags = []
for i in range(N_PROBLEMS):
    tid, prio, ename, host = triggers[i % len(triggers)]
    eid = next_id; next_id += 1
    name = f"{host}: synthetic problem #{i:05d}"
    events.append(f"({eid},0,0,{tid},{now-i},1,0,{i},'{name}',{prio})")
    problems.append(f"({eid},0,0,{tid},{now-i},{i},NULL,0,0,NULL,NULL,'{name}',0,{prio})")
    for k in range(N_TAGS):
        ptags.append(f"({next_pt},{eid},'tag{k:03d}','{tag_val}-p{i}-t{k}')")
        next_pt += 1

print(f"prepared: events={len(events)} problems={len(problems)} problem_tags={len(ptags)}")

def chunked(seq, n):
    for i in range(0, len(seq), n):
        yield seq[i:i+n]

for chunk in chunked(events, 1000):
    mysql_exec("INSERT INTO events (eventid,source,object,objectid,clock,value,acknowledged,ns,name,severity) VALUES " + ",".join(chunk) + ";")
for chunk in chunked(problems, 1000):
    mysql_exec("INSERT INTO problem (eventid,source,object,objectid,clock,ns,r_eventid,r_clock,r_ns,correlationid,userid,name,acknowledged,severity) VALUES " + ",".join(chunk) + ";")
for chunk in chunked(ptags, 500):
    mysql_exec("INSERT INTO problem_tag (problemtagid,eventid,tag,value) VALUES " + ",".join(chunk) + ";")
print("inserted")
