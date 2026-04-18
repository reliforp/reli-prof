# rmem:serve — Persistent Query Server

## Status

Design proposal. Not yet implemented.

## Problem

`rmem:query` and `rmem:explore` both pay CSR substrate construction
cost on every invocation. For large rmem files (34M nodes, 60M edges),
this takes 10-30 seconds. When an AI assistant or human analyst needs
to run a series of exploratory queries, this per-invocation cost
makes interactive investigation impractical.

## Goal

A persistent process that loads the rmem once and serves queries
over a Unix domain socket. Both CLI tools and AI assistants can
connect, send a JSON query, receive a JSON response, and disconnect
— without paying the loading cost again.

## Architecture

```
┌──────────────────────────────────────────────────┐
│  rmem:serve (long-lived process)                 │
│                                                  │
│  ┌──────────┐  ┌──────────┐  ┌───────────────┐  │
│  │  Reader   │→ │Substrate │→ │   RmemModel   │  │
│  │ (mmap'd) │  │(FFI CSR) │  │ (labels, locs)│  │
│  └──────────┘  └──────────┘  └───────────────┘  │
│                      ↑                           │
│              ┌───────┴────────┐                  │
│              │  Query Router  │                  │
│              └───────┬────────┘                  │
│                      │                           │
│              ┌───────┴────────┐                  │
│              │  Socket Server │                  │
│              │  (Unix domain) │                  │
│              └────────────────┘                  │
└──────────────────────────────────────────────────┘
        ↕ /tmp/rmem-<hash>.sock
┌──────────────┐  ┌──────────────┐  ┌─────────────┐
│  rmem:query   │  │ AI assistant │  │ rmem:explore│
│  --server=... │  │ (Bash tool)  │  │ (future)    │
└──────────────┘  └──────────────┘  └─────────────┘
```

## Protocol

### Transport

Unix domain socket (SOCK_STREAM). Path convention:
`/tmp/rmem-serve-<pid>.sock` or user-specified `--socket=PATH`.

The server accepts one connection at a time (single-threaded PHP).
Each connection is a sequence of newline-delimited JSON requests,
each producing a newline-delimited JSON response. Connection can
be closed and reopened without restarting the server.

### Request format

```json
{"action": "roots"}
{"action": "children", "node_id": 123}
{"action": "children", "node_id": 123, "all_edges": true, "sort": "link"}
{"action": "parents", "node_id": 123}
{"action": "node_detail", "node_id": 123}
{"action": "sandwich", "node_id": 123}
{"action": "class_ranking"}
{"action": "class_ranking", "limit": 20}
{"action": "type_ranking"}
{"action": "nodes_by_class", "class": "App\\Models\\User"}
{"action": "nodes_by_type", "type": "ZendArray"}
{"action": "top_retained", "limit": 50}
{"action": "find_by_address", "address": "0x7f4a2c001230"}
{"action": "find_function_def", "name": "App\\Services\\Foo::bar"}
{"action": "find_class_def", "name": "App\\Models\\User"}
{"action": "path_to_root", "node_id": 123}
{"action": "filter", "node_id": 123, "pattern": "password"}
{"action": "subtree_stats", "node_id": 123}
{"action": "ping"}
{"action": "shutdown"}
```

### Response format

```json
{"ok": true, "data": [...]}
{"ok": true, "data": {"type": "ObjectContext", "class": "User", ...}}
{"ok": false, "error": "Node not found: 999999"}
```

### Composite actions

`sandwich` returns parents + node_detail + children in one response,
avoiding 3 round-trips:

```json
{
  "ok": true,
  "data": {
    "parents": [...],
    "detail": {"type": "...", "class": "...", ...},
    "children": [...]
  }
}
```

`subtree_stats` returns type/class breakdown under a node:

```json
{
  "ok": true,
  "data": {
    "total_retained": 12345678,
    "node_count": 456,
    "type_breakdown": [{"type": "ZendArray", "count": 200, "total": 5000000}, ...],
    "class_breakdown": [{"class": "User", "count": 50, "total": 3000000}, ...]
  }
}
```

## Implementation

### Server command

```php
final class RmemServeCommand extends Command
{
    // inspector:rmem:serve output.rmem [--socket=PATH] [--timeout=SECONDS]
}
```

- Load Reader + Substrate (skipScc: true) + RmemModel once at startup
- Bind Unix domain socket
- Main loop: `stream_socket_accept` → read lines → route → respond → close
- `--timeout`: auto-shutdown after N seconds of inactivity (default: 3600)
- Signal handling: SIGTERM/SIGINT → clean shutdown + socket unlink

### Query router

Reuses RmemModel methods directly:

| Action | Method |
|--------|--------|
| roots | `$model->getRootChildren()` |
| children | `$model->getChildren($id, $allEdges, $sort)` |
| parents | `$model->getParents($id)` |
| node_detail | `$model->nodeDetail($id)` |
| class_ranking | `$model->getClassRanking()` |
| type_ranking | `$model->getTypeRanking()` |
| nodes_by_class | `$model->getNodesByClass($class)` |
| nodes_by_type | `$model->getNodesByType($type)` |
| top_retained | `$model->getTopRetained($limit)` |
| find_by_address | `$model->findNodeByAddress($addr)` |
| path_to_root | `$model->pathToRoot($id)` |
| subtree_stats | new method: walk subtree, aggregate |
| filter | `$model->getChildren($id)` + filter |

### Client integration

For CLI:

```bash
# One-shot query
echo '{"action":"children","node_id":123}' | \
  nc -U /tmp/rmem-serve-12345.sock

# Or a thin PHP wrapper
php reli rmem:query --server=/tmp/rmem-serve-12345.sock \
  --node=123 --children
```

For AI assistants:

```bash
# Direct socket query via Bash tool
echo '{"action":"sandwich","node_id":123}' | \
  nc -U /tmp/rmem-serve-12345.sock | jq .
```

### rmem:explore integration (future)

explore could optionally connect to a running serve instance
instead of loading its own substrate. This would allow:
- Multiple explore instances sharing one substrate
- explore + AI querying the same loaded data

## Resource management

### Memory

The server holds the full substrate + model in memory. Same footprint
as a single rmem:explore invocation. ~2-5 GB for a 34M-node graph.

### Socket lifecycle

- Server creates socket on startup, unlinks on shutdown
- `--timeout` auto-shutdown prevents orphaned processes
- PID written to `<socket_path>.pid` for management
- `rmem:serve --status` or `rmem:serve --stop` for lifecycle control

### Concurrency

Single-threaded, one connection at a time. Sufficient for the use case
(one analyst or AI querying sequentially). Connection queueing is
handled by the kernel's listen backlog.

If concurrent access is needed, multiple serve instances can run
on different sockets with the same rmem file (read-only access).

## Alternatives considered

### HTTP server

Heavier than needed. JSON over TCP adds HTTP parsing overhead.
Unix socket is simpler, faster, and avoids port management.
Could add HTTP as a future option if remote access is needed.

### stdin/stdout line mode

```bash
php reli rmem:serve output.rmem --interactive
```

Works well for single-session use but:
- Cannot be shared between explore and AI
- Background process stdin management is awkward
- No clean way for multiple tools to connect

### MCP (Model Context Protocol) server

Good integration with Claude Code but:
- Vendor-specific protocol
- CLI users get no benefit
- Socket server is more universal — MCP adapter can wrap it later

### Embedded in explore

explore TUI with a `:query` command mode. Works for human use but
AI cannot drive a TUI effectively. See the integrated mode below
for a better approach.

## Explore-integrated serve mode

### Concept

Instead of a standalone serve process, explore itself opens a socket
and serves queries while the human uses the TUI. One process, one
substrate, shared between human and AI.

```
┌─────────────────────────────────────────────┐
│  rmem:explore --serve=/tmp/rmem.sock        │
│                                             │
│  ┌───────────┐    ┌──────────────────────┐  │
│  │  RmemModel │←──│  TUI (human via tty) │  │
│  │ (substrate,│    └──────────────────────┘  │
│  │  labels,   │    ┌──────────────────────┐  │
│  │  locations)│←──│ Socket (AI via query) │  │
│  └───────────┘    └──────────────────────┘  │
└─────────────────────────────────────────────┘
```

### Why not separate processes

- Substrate memory: 2-5 GB for large rmem. Two processes = double.
- Data consistency: human and AI see exactly the same loaded data.
- No synchronization needed — single-threaded, interleaved.

### Event loop

Replace the current blocking `pollKey()` with `stream_select` over
both the tty file descriptor and the socket:

```php
while ($this->running) {
    $this->render();

    // Wait for tty input OR socket connection
    $read = [$ttyFd];
    if ($serverSocket) {
        $read[] = $serverSocket;
    }
    if ($clientSocket) {
        $read[] = $clientSocket;
    }
    stream_select($read, $w, $e, null); // block until either is ready

    if (in_array($ttyFd, $read)) {
        $key = $this->term->readKey();
        $this->dispatch($key);
    }
    if ($serverSocket && in_array($serverSocket, $read)) {
        $clientSocket = stream_socket_accept($serverSocket);
    }
    if ($clientSocket && in_array($clientSocket, $read)) {
        $line = fgets($clientSocket);
        $response = $this->handleQuery(json_decode($line, true));
        fwrite($clientSocket, json_encode($response) . "\n");
    }
}
```

### Navigation actions (AI → human screen)

Beyond query-only actions, the socket accepts navigation commands
that change the TUI display state:

```json
{"action": "navigate", "node_id": 12345}
{"action": "navigate_roots"}
{"action": "navigate_class_ranking"}
{"action": "navigate_sandwich", "node_id": 12345}
{"action": "navigate_back"}
```

When the AI sends a navigate action:
1. explore updates its internal state (enterSandwich, switchToRoots, etc.)
2. The next render() reflects the change
3. The human sees the screen update in real time

This enables a guided investigation workflow:

```
Human: "このメモリの出所を追って"
AI:    navigate to roots → query children → find largest
AI:    navigate to call_frames → query children → find hotspot
AI:    "call_frames が 1.5 GB で最大です。フレーム #8 を見ます"
AI:    navigate to frame #8 → sandwich view appears
AI:    "このフレームの $result 変数に 500 MB。User の配列です"
Human: "そこもうちょい見せて"
AI:    navigate into $result → children expand
AI:    "3000 個の User インスタンス、各 2.8 KB。Eloquent ですね"
```

### State query (AI ← human screen)

AI can also read what the human is looking at:

```json
{"action": "get_current_focus"}
→ {"ok": true, "data": {"node_id": 456, "label": "...", "mode": "sandwich"}}

{"action": "get_current_selection"}
→ {"ok": true, "data": {"pane": "children", "selected_node_id": 789}}
```

This lets the AI react to human navigation:

```
Human navigates to an object in explore
Human: "これ何？"
AI:    get_current_focus → node #456
AI:    query node_detail #456, path_to_root #456
AI:    "これは ObjectContext: PDOStatement で、
       call_frames → handle:42 → $stmt 経由で保持されてます"
```

### Standalone fallback

When `--serve` is not specified, explore works exactly as today —
no socket, no integration. The serve mode is opt-in.

When explore is not needed (CI, batch analysis), a standalone
`rmem:serve` command provides the same query interface without the
TUI overhead:

```bash
# Headless mode — AI-only investigation
php reli rmem:serve output.rmem --socket=/tmp/rmem.sock
```

This is a thin wrapper: load model, bind socket, run the same query
router in a simple accept loop.

## Implementation order

1. Socket server + query router (core) — standalone rmem:serve
2. Basic actions: roots, children, parents, node_detail, path_to_root
3. Rankings: class_ranking, type_ranking, top_retained
4. Search: find_by_address, find_function_def, find_class_def
5. Composite: sandwich, subtree_stats
6. Lifecycle: timeout, --status, --stop, PID file
7. Client wrapper: rmem:query --server=... flag
8. Explore integration: stream_select event loop, --serve flag
9. Navigation actions: navigate, navigate_back, get_current_focus
10. State queries: get_current_selection, get_current_filter
